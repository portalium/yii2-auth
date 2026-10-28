<?php

namespace portalium\auth\components;

use portalium\auth\Module;
use portalium\auth\models\SmsVerification;
use portalium\user\models\User;
use Yii;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Handles phone-number verification via SMS one-time codes.
 *
 * OVERVIEW
 * --------
 * Two public entry points:
 *
 *   1. requestCode($phone)  -> validates the request, generates a 6-digit code,
 *                              stores its HASH, and sends the code by SMS.
 *   2. verifyCode($code)    -> checks the code the user typed and, if correct,
 *                              marks the phone number as verified on the user.
 *
 * Every public method returns an array (never throws for expected failures):
 *   success: ['success' => true,  'status' => ..., 'message' => ...]
 *   failure: ['success' => false, 'status' => ..., 'error' => ..., 'http_status' => ...]
 * The caller (controller) is expected to map these to an HTTP response.
 *
 * CODE LIFECYCLE: "pending" vs "active"
 * -------------------------------------
 * A verification row (SmsVerification) can hold two codes at the same time:
 *
 *   - pending_*: a freshly generated code that has been saved to the DB but
 *                whose SMS has NOT yet been confirmed as accepted by the provider.
 *   - active_*:  a code whose SMS was accepted by the provider.
 *
 * Why two slots? The SMS is sent AFTER the DB transaction is committed (we never
 * hold a DB lock while calling an external provider). That leaves a gap in which
 * we don't yet know whether the SMS went out. Flow:
 *
 *   save as pending -> commit -> send SMS -> finalizePendingCode()
 *       accepted  => pending is promoted to active
 *       rejected  => pending is discarded
 *       unknown   => pending is left alone (user may still receive the SMS and
 *                    verifyCode() accepts pending codes too)
 *
 * Keeping the previous active code alive while a new one is pending means a user
 * who already got an SMS can still use it even if the resend fails.
 *
 * SECURITY FEATURES (all enforced under a row lock, see below)
 * ------------------------------------------------------------
 *   - Only users with a verified e-mail can verify a phone.
 *   - Codes are stored hashed (like passwords), never in plain text.
 *   - Resend cooldown, per-hour send limit, max wrong attempts, then lockout.
 *   - Anti-enumeration: if the phone belongs to ANOTHER user, the response looks
 *     exactly like a normal successful request (but no SMS is sent), so an
 *     attacker can't discover which numbers are registered.
 *
 * CONCURRENCY
 * -----------
 * Both public methods lock the user row (SELECT ... FOR UPDATE) first, then the
 * verification row. Locking in the same order every time avoids deadlocks, and
 * it serializes concurrent requests from the same user so the rate limits and
 * attempt counters can't be bypassed by sending many requests in parallel.
 */
class SmsVerificationService extends Component
{
    // Maybe move these to settings?

    /** How long a code stays valid, in seconds (5 minutes). */
    const CODE_TTL = 300;

    /** Minimum wait between two code requests, in seconds. */
    const RESEND_INTERVAL = 60;

    /** Length of the rate-limit window for sent SMS messages, in seconds (1 hour). */
    const SMS_WINDOW = 3600;

    /** Maximum number of SMS messages that may be sent within one SMS_WINDOW. */
    const MAX_SMS_PER_WINDOW = 5;

    /** Wrong code guesses allowed before the user gets locked out. */
    const MAX_ATTEMPTS = 5;

    /** How long the lockout lasts after too many wrong guesses, in seconds (15 minutes). */
    const LOCKOUT_DURATION = 900;

    // =====================================================================
    //  PUBLIC API
    // =====================================================================

    /**
     * Step 1 of verification: generate a code and send it to $phone.
     *
     * @param string $phone Phone number in E.164 format, e.g. "+905551234567".
     * @return array Result array (see class docblock). Possible 'status' values:
     *               'pending'          code generated and SMS accepted by provider
     *               'unknown'          SMS delivery couldn't be confirmed
     *               'already_verified' this phone is already verified for the user
     *               'locked' / 'not_sent' (+ 'error' code) on failures
     */
    public function requestCode($phone)
    {
        // ---------------------------------------------------------------
        // Phase 1: cheap validation (no DB locks yet)
        // ---------------------------------------------------------------

        // Must be E.164: a "+", a non-zero first digit, then up to 14 more digits.
        // "\z" (not "$") so a trailing newline can't sneak through.
        if (!is_string($phone) || !preg_match('/^\+[1-9]\d{1,14}\z/', $phone)) {
            return $this->failure('Invalid phone number.', 'invalid_phone_number', 422);
        }

        $user = Yii::$app->user->identity;
        if (!$user) {
            return $this->failure('User not found.', 'user_not_found', 404);
        }

        // Phone verification is only allowed after the e-mail has been verified.
        if ((int) $user->email_verify !== User::EMAIL_VERIFY) {
            return $this->failure('Email must be verified before phone verification.', 'email_not_verified', 403);
        }

        // Nothing to do if this exact number is already verified for this user.
        if ($user->tel_no === $phone && (int) $user->sms_verified === User::SMS_VERIFY) {
            return [
                'success' => true,
                'status' => 'already_verified',
                'message' => Module::t('This phone number is already verified.'),
            ];
        }

        // ---------------------------------------------------------------
        // Phase 2: rate limiting + code generation, inside a transaction
        // ---------------------------------------------------------------
        $now = time();
        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Lock the user row, then the verification row (always in this order).
            // Re-fetching inside the transaction gives us fresh, locked data
            // instead of the possibly stale identity loaded earlier.
            $user = $this->lockUserForUpdate();
            if (!$user) {
                return $this->finishTransaction($transaction, $this->failure(
                    'User not found.',
                    'user_not_found',
                    404
                ));
            }
            $verification = $this->findVerificationForUpdate();

            // --- Check A: is the user currently locked out? ---
            if ($verification && (int) $verification->locked_until > $now) {
                return $this->failure(
                    'Verification is temporarily locked. Try again later.',
                    'too_many_attempts',
                    429,
                    (int) $verification->locked_until - $now, // seconds until unlock
                    false,
                    'locked'
                );
            }

            // The lockout has expired: give the user a clean slate.
            // (Saved further down together with the new code.)
            if ($verification && (int) $verification->locked_until > 0) {
                $verification->attempts = 0;
                $verification->locked_until = null;
            }

            // --- Housekeeping: drop codes that have already expired ---
            if ($verification) {
                if ($verification->active_code_hash && (int) $verification->active_expires_at < $now) {
                    $this->clearActiveCode($verification);
                }
                if ($verification->pending_code_hash && (int) $verification->pending_expires_at < $now) {
                    $this->clearPendingCode($verification);
                }
            }

            // --- Check B: resend cooldown (one request per RESEND_INTERVAL) ---
            if ($verification && (int) $verification->last_sent_at > $now - self::RESEND_INTERVAL) {
                return $this->failure(
                    'Please wait before requesting another code.',
                    'resend_too_soon',
                    429,
                    max(1, self::RESEND_INTERVAL - ($now - (int) $verification->last_sent_at))
                );
            }

            // --- Check C: hourly cap (MAX_SMS_PER_WINDOW messages per SMS_WINDOW) ---
            // Only applies while we're still inside the current window.
            if ($verification && (int) $verification->window_started_at > 0
                && $now < (int) $verification->window_started_at + self::SMS_WINDOW
                && (int) $verification->sent_count >= self::MAX_SMS_PER_WINDOW
            ) {
                return $this->failure(
                    'SMS verification request limit reached. Try again later.',
                    'sms_rate_limited',
                    429,
                    (int) $verification->window_started_at + self::SMS_WINDOW - $now // seconds until window ends
                );
            }

            // --- Generate the code ---
            // Is this number already used by someone else? Remembered for later;
            // we deliberately do NOT reveal this to the caller (anti-enumeration).
            $taken = $this->phoneBelongsToAnotherUser($phone, $user);

            // Cryptographically secure 6-digit code, zero-padded (e.g. "004217").
            $code = sprintf('%06d', random_int(0, 999999));
            // Only the hash is stored; the plain code exists only in memory and in the SMS.
            $pendingCodeHash = Yii::$app->security->generatePasswordHash($code);

            // --- Store it as a PENDING code ---
            $verification = $verification ?: new SmsVerification(['id_user' => $user->id_user]);
            $verification->pending_phone = $phone;
            $verification->pending_code_hash = $pendingCodeHash;
            $verification->pending_expires_at = $now + self::CODE_TTL;
            $verification->last_sent_at = $now;

            // Start a new rate-limit window if none exists or the old one has ended.
            if ((int) $verification->window_started_at === 0
                || $now >= (int) $verification->window_started_at + self::SMS_WINDOW
            ) {
                $verification->window_started_at = $now;
                $verification->sent_count = 0;
            }

            // Count this request against the window (even if the number is "taken",
            // so the rate limits behave identically in both cases).
            $verification->sent_count = (int) $verification->sent_count + 1;
            $this->save($verification);
            $transaction->commit();

            // Anti-enumeration: the number belongs to another account, so we
            // never send an SMS, but we return the exact same response as the
            // happy path. (The pending code stays in the DB so timing and
            // rate-limit behaviour look the same to an attacker; it can never
            // be redeemed successfully, see phoneBelongsToAnotherUser() in verifyCode().)
            if ($taken) {
                return [
                    'success' => true,
                    'status' => 'pending',
                    'expires_in' => self::CODE_TTL,
                    'retry_after' => self::RESEND_INTERVAL,
                    'message' => Module::t('If the number is available, a verification code will be sent.'),
                ];
            }
        } finally {
            // Safety net: if we left the try block without committing
            // (early return on a failed check, or an exception), roll back.
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
        }

        // ---------------------------------------------------------------
        // Phase 3: send the SMS (outside the transaction, no DB locks held)
        // ---------------------------------------------------------------
        try {
            $smsResult = Yii::$app->notification->sendSms(
                $phone,
                Module::t(
                    'Verification code {code}; valid for {minutes} minutes.',
                    ['code' => $code, 'minutes' => (int) (self::CODE_TTL / 60)]
                )
            );
        } catch (\Throwable $e) {
            Yii::error($e, __METHOD__);
            // An exception (e.g. timeout) doesn't tell us whether the provider
            // actually sent the message, so treat the outcome as unknown.
            $smsResult = ['success' => false, 'status' => 'unknown'];
        }

        // ---------------------------------------------------------------
        // Phase 4: act on what the provider told us
        // ---------------------------------------------------------------

        // 4a. Provider accepted the message -> promote pending code to active.
        if (!empty($smsResult['success']) && isset($smsResult['status']) && $smsResult['status'] === 'accepted') {
            $this->finalizePendingCode($pendingCodeHash, true);

            return [
                'success' => true,
                'status' => 'pending',
                'expires_in' => self::CODE_TTL,
                'retry_after' => self::RESEND_INTERVAL,
                'message' => Module::t('If the number is available, a verification code will be sent.'),
            ];
        }

        // 4b. Outcome unknown -> leave the code in "pending" on purpose.
        // The SMS may still arrive, and verifyCode() accepts pending codes.
        if (isset($smsResult['status']) && $smsResult['status'] === 'unknown') {
            return [
                'success' => true,
                'status' => 'unknown',
                'expires_in' => self::CODE_TTL,
                'retry_after' => self::RESEND_INTERVAL,
                'message' => Module::t('SMS delivery is uncertain. If you receive the code, enter it before it expires.'),
            ];
        }

        // 4c. Provider definitely did not send it -> discard the pending code
        // so it can't be used, and tell the caller whether retrying makes sense.
        $this->finalizePendingCode($pendingCodeHash, false);

        return $this->failure(
            'The verification message could not be sent.',
            'sms_not_sent',
            503,
            self::RESEND_INTERVAL,
            !empty($smsResult['retryable'])
        );
    }

    /**
     * Step 2 of verification: check the code the user entered.
     *
     * On success the phone number is saved on the user as verified.
     * On failure the attempt counter goes up; after MAX_ATTEMPTS wrong guesses
     * the user is locked out for LOCKOUT_DURATION seconds.
     *
     * @param string $code The 6-digit code entered by the user.
     * @return array Result array (see class docblock). Success has status 'verified'.
     */
    public function verifyCode($code)
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Same lock order as requestCode(): user row first, then verification row.
            $user = $this->lockUserForUpdate();
            if (!$user) {
                return $this->finishTransaction($transaction, $this->failure(
                    'User not found.',
                    'user_not_found',
                    404
                ));
            }
            $verification = $this->findVerificationForUpdate();
            if (!$verification) {
                // The user never requested a code.
                return $this->finishTransaction($transaction, $this->failure(
                    'No active verification code was found.',
                    'code_not_found',
                    422,
                    null,
                    false,
                    'expired'
                ));
            }

            $now = time();

            // --- Check 1: is the user locked out? ---
            if ((int) $verification->locked_until > $now) {
                return $this->finishTransaction($transaction, $this->failure(
                    'Verification is temporarily locked. Try again later.',
                    'too_many_attempts',
                    429,
                    (int) $verification->locked_until - $now,
                    false,
                    'locked'
                ));
            }

            // --- Check 2: is there at least one unexpired code to compare against? ---
            $activeValid = $verification->active_code_hash
                && (int) $verification->active_expires_at >= $now;
            $pendingValid = $verification->pending_code_hash
                && (int) $verification->pending_expires_at >= $now;

            if (!$activeValid && !$pendingValid) {
                // Both codes are expired: clean them up and report expiry.
                $this->clearActiveCode($verification);
                $this->clearPendingCode($verification);
                $this->save($verification);

                return $this->finishTransaction($transaction, $this->failure(
                    'The verification code has expired.',
                    'code_expired',
                    422,
                    null,
                    false,
                    'expired'
                ));
            }

            // --- Check 3: does the entered code match? ---
            // Compare against the pending code first, then the active one.
            // $matchedPhone ends up being the phone number the matching code was sent to.
            $matchedPhone = null;
            // Format check first so we don't run an expensive hash comparison on garbage input.
            if (is_string($code) && preg_match('/^\d{6}\z/', $code)) {
                if (
                    $pendingValid
                    && Yii::$app->security->validatePassword($code, $verification->pending_code_hash)
                ) {
                    $matchedPhone = $verification->pending_phone;
                } elseif (
                    $activeValid
                    && Yii::$app->security->validatePassword($code, $verification->active_code_hash)
                ) {
                    $matchedPhone = $verification->active_phone;
                }
            }

            // Wrong (or malformed) code: count the attempt, lock out if too many.
            if ($matchedPhone === null) {
                $verification->attempts = (int) $verification->attempts + 1;
                if ((int) $verification->attempts >= self::MAX_ATTEMPTS) {
                    // Burn all codes so the user must request a fresh one after the lockout.
                    $this->clearActiveCode($verification);
                    $this->clearPendingCode($verification);
                    $verification->locked_until = $now + self::LOCKOUT_DURATION;
                }
                $this->save($verification);
                $locked = (int) $verification->attempts >= self::MAX_ATTEMPTS;

                return $this->finishTransaction($transaction, $this->failure(
                    $locked
                        ? 'Verification is temporarily locked. Try again later.'
                        : 'The verification code is invalid or expired.',
                    $locked ? 'too_many_attempts' : 'invalid_code',
                    $locked ? 429 : 422,
                    $locked ? self::LOCKOUT_DURATION : null,
                    false,
                    $locked ? 'locked' : 'rejected'
                ));
            }

            // --- Check 4: the code is correct; make sure the number is still free ---
            // Someone else may have verified this number since the code was issued
            // (or the "taken" anti-enumeration path issued it in the first place).
            if ($this->phoneBelongsToAnotherUser($matchedPhone, $user)) {
                $this->clearChallenge($verification);
                $this->save($verification);

                return $this->finishTransaction($transaction, $this->failure(
                    'This phone number cannot be verified.',
                    'phone_unavailable',
                    422,
                    null,
                    false,
                    'rejected'
                ));
            }

            // --- Step 5: save the verified phone on the user ---
            // The nested transaction becomes a SAVEPOINT. This matters on PostgreSQL:
            // a failed statement (e.g. unique-constraint violation) aborts the whole
            // outer transaction, but rolling back to the savepoint lets us recover
            // and still save the cleared challenge below.
            $inner = Yii::$app->db->beginTransaction(); // for PostgreSQL
            try {
                $phoneSaved = $user->verifySmsPhone($matchedPhone);
                $inner->commit();
            } catch (IntegrityException $exception) {
                // Race condition: another user grabbed the number between our
                // phoneBelongsToAnotherUser() check and this save (unique index hit).
                $inner->rollBack();

                $this->clearChallenge($verification);
                $this->save($verification);

                return $this->finishTransaction($transaction, $this->failure(
                    'This phone number cannot be verified.',
                    'phone_unavailable',
                    422,
                    null,
                    false,
                    'rejected'
                ));
            } catch (\Throwable $e) {
                // Anything unexpected: undo the savepoint and let the error propagate
                // (the outer finally block rolls back the main transaction).
                $inner->rollBack();
                throw $e;
            }

            if (!$phoneSaved) {
                throw new \RuntimeException('Failed to save the verified phone number.');
            }

            // Success: the codes are single-use, so wipe them and reset the counters.
            $this->clearChallenge($verification);
            $this->save($verification);

            $transaction->commit();

            return [
                'success' => true,
                'status' => 'verified',
                'tel_no' => $user->tel_no,
                'sms_verified' => (int) $user->sms_verified,
                'message' => Module::t('Phone number verified successfully.'),
            ];
        } finally {
            // Safety net: roll back if we exit without having committed.
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
        }
    }

    // =====================================================================
    //  SMS RESULT HANDLING
    // =====================================================================

    /**
     * Called after the SMS provider responded, to settle the pending code.
     *
     * Runs in its own short transaction. The $codeHash comparison makes sure we
     * only touch the code WE created: if the user requested another code in the
     * meantime, the pending slot holds a different hash and we leave it alone.
     *
     * @param string $codeHash Hash of the code that was just sent.
     * @param bool   $accepted True if the provider accepted the message:
     *                         promote the pending code to active.
     *                         False: simply discard the pending code.
     */
    private function finalizePendingCode($codeHash, $accepted)
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $this->lockUserForUpdate(); // lock order: user first (result not needed)
            $verification = $this->findVerificationForUpdate();
            if ($verification && $verification->pending_code_hash === $codeHash) {
                if ($accepted) {
                    // Promote pending -> active.
                    $verification->active_phone = $verification->pending_phone;
                    $verification->active_code_hash = $verification->pending_code_hash;
                    $verification->active_expires_at = $verification->pending_expires_at;
                }
                // In both cases the pending slot is emptied.
                $this->clearPendingCode($verification);
                $this->save($verification);
            }
            $transaction->commit();
        } finally {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
        }
    }

    // =====================================================================
    //  DATABASE / LOCKING HELPERS
    // =====================================================================

    /**
     * Loads the current user's verification row, locked with SELECT ... FOR UPDATE
     * on databases that support row locks. Must be called inside a transaction.
     */
    private function findVerificationForUpdate()
    {
        return $this->fetchWithLock(
            SmsVerification::find()->where(['id_user' => Yii::$app->user->id])
        );
    }

    /**
     * Loads the current user's row, locked with SELECT ... FOR UPDATE.
     * This is the "outer" lock that serializes all verification work per user.
     */
    private function lockUserForUpdate()
    {
        return $this->fetchWithLock(
            User::find()->where(['id_user' => Yii::$app->user->id])
        );
    }

    /**
     * Execute an ActiveQuery with row-level locking on MySQL / PostgreSQL.
     *
     * NOTE: Query::forUpdate() is absent in this Yii build. 
     * Emitting the clause directly keeps row locks 
     * working on any Yii 2 release, current or future.
     * 
     * getRawSql() inlines the bound parameters and 
     * the only dynamic value here is the integer user id, so this is safe.
     * 
     * 
     * On other drivers (e.g. SQLite in tests) the query runs without a lock.
     *
     * @param \yii\db\ActiveQuery $query
     * @return \yii\db\ActiveRecord|null
     */
    private function fetchWithLock($query)
    {
        if (!in_array(Yii::$app->db->getDriverName(), ['mysql', 'pgsql'], true)) {
            return $query->one();
        }

        $sql  = rtrim($query->createCommand()->getRawSql(), " \t\n\r\0\x0B;");
        $sql .= ' FOR UPDATE';

        /** @var string $modelClass */
        $modelClass = $query->modelClass;

        return $modelClass::findBySql($sql)->one();
    }

    /**
     * Commits the transaction and passes the result array through.
     * Lets an early exit read as: return $this->finishTransaction($tx, $result);
     * Used when we modified data (e.g. attempt counters) that must be persisted
     * even though the overall operation failed.
     */
    private function finishTransaction($transaction, array $result)
    {
        $transaction->commit();

        return $result;
    }

    /**
     * Is $phone already used by a DIFFERENT user?
     * (The current user's own number doesn't count.)
     */
    private function phoneBelongsToAnotherUser($phone, User $user)
    {
        return User::find()
            ->where(['tel_no' => $phone])
            ->andWhere(['<>', 'id_user', $user->id_user])
            ->exists();
    }

    // =====================================================================
    //  CODE-SLOT HELPERS (these only modify the model in memory; call save() after)
    // =====================================================================

    /** Empties the "active" slot (code whose SMS was accepted). */
    private function clearActiveCode(SmsVerification $verification)
    {
        $verification->active_phone = null;
        $verification->active_code_hash = null;
        $verification->active_expires_at = null;
    }

    /** Empties the "pending" slot (code whose SMS outcome is not settled yet). */
    private function clearPendingCode(SmsVerification $verification)
    {
        $verification->pending_phone = null;
        $verification->pending_code_hash = null;
        $verification->pending_expires_at = null;
    }

    /**
     * Fully resets the challenge: both code slots plus the attempt counter and lockout.
     * Used after a successful verification or when the number turns out to be unavailable.
     * (Rate-limit fields such as last_sent_at / sent_count are intentionally kept,
     * so verifying or failing doesn't reset the resend limits.)
     */
    private function clearChallenge(SmsVerification $verification)
    {
        $this->clearActiveCode($verification);
        $this->clearPendingCode($verification);
        $verification->attempts = 0;
        $verification->locked_until = null;
    }

    /**
     * Persists the verification row, throwing if it can't be saved.
     * Passing false skips validation: this is an internal model whose values
     * are all generated by this service, not user input.
     */
    private function save(SmsVerification $verification)
    {
        if (!$verification->save(false)) {
            throw new \RuntimeException('Failed to persist the SMS verification challenge.');
        }
    }

    // =====================================================================
    //  RESPONSE HELPER
    // =====================================================================

    /**
     * Builds a standard failure result array.
     *
     * @param string      $message     Human-readable message (translated via Module::t()).
     * @param string      $error       Machine-readable error code, e.g. 'resend_too_soon'.
     * @param int         $httpStatus  HTTP status the controller should respond with.
     * @param int|null    $retryAfter  Seconds the client should wait before retrying (optional).
     * @param bool        $retryable   True if retrying the same request may succeed (optional).
     * @param string      $status      Coarse state for the client: 'not_sent', 'locked', 'expired', 'rejected'.
     * @return array
     */
    private function failure($message, $error, $httpStatus, $retryAfter = null, $retryable = false, $status = 'not_sent')
    {
        $result = [
            'success' => false,
            'status' => $status,
            'error' => $error,
            'message' => Module::t($message),
            'http_status' => $httpStatus,
        ];

        if ($retryAfter !== null) {
            $result['retry_after'] = $retryAfter;
        }
        if ($retryable) {
            $result['retryable'] = true;
        }

        return $result;
    }
}