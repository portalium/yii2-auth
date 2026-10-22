<?php

namespace portalium\auth\components;

use Yii;
use portalium\auth\Module;
use portalium\auth\models\SignupForm;
use portalium\user\models\User;

/**
 * Finds or creates the account for a Google/Apple user; web and API use the same rule.
 *
 * The account is matched by email, and an existing account's status is never changed: a pending or deleted
 * account is never activated this way. An existing account is only signed into if the provider reports the
 * email as verified; otherwise someone else's address carried in the token could sign in to that account
 * without a password. Likewise, when no account exists, a new one is not created with an address the provider
 * has not verified (decision 6, 2026-10-18): doing so would let the token holder take an address the real
 * owner hasn't signed up with yet. A new account follows the auth::user_status setting, skips email
 * verification (Card 2, decision A), and is marked verified.
 */
class OAuthAccount
{
    /** Refused: the provider did not report the email as verified (signing into an existing account, or a new account). */
    public const REASON_EMAIL_NOT_VERIFIED = 'provider_email_not_verified';

    /** No account exists and creating a new one is not allowed (auth::api_signup disabled on the API, decision 18). */
    public const REASON_SIGNUP_DISABLED = 'signup_disabled';

    /** @var User|null the account found or created; null if it could not be created or was refused */
    public $user;

    /** @var bool whether the account was created by this request */
    public $isNew = false;

    /** @var array field => messages if the account could not be created or was refused */
    public $errors = [];

    /** @var string|null refusal reason (a REASON_* constant); null if the account was not refused */
    public $reason;

    /** @var string|null user-facing message if refused */
    public $message;

    /** @var bool whether creating a new account was refused (unverified address) when none existed; the web warning then shows the signup link */
    public $signupRefused = false;

    /**
     * @param array $userData data extracted from OAuthClient::verifyIdToken or the Apple token (email, email_verified, given_name, family_name)
     * @param bool $allowSignup whether a new account may be created if none exists; false returns REASON_SIGNUP_DISABLED
     */
    public static function resolve(array $userData, bool $allowSignup = true): self
    {
        $account = new self();
        $email = trim((string) ($userData['email'] ?? ''));
        if ($email === '') {
            $account->errors = ['email' => ['Email is missing in the provider token.']];
            return $account;
        }
        $existing = User::findOne(['email' => $email]);
        if ($existing !== null) {
            if (!self::providerVerified($userData)) {
                return $account->refuse(self::REASON_EMAIL_NOT_VERIFIED, self::unverifiedMessage());
            }
            $account->user = $existing;
            return $account;
        }
        if (!$allowSignup) {
            return $account->refuse(self::REASON_SIGNUP_DISABLED, ApiAccess::signupDisabledMessage());
        }
        if (!self::providerVerified($userData)) {
            $account->signupRefused = true;
            return $account->refuse(self::REASON_EMAIL_NOT_VERIFIED, self::unverifiedSignupMessage());
        }

        $password = Yii::$app->security->generateRandomString(12);
        $form = new SignupForm([
            'username' => explode('@', $email)[0],
            'email' => $email,
            'password' => $password,
            'password_confirm' => $password,
            'first_name' => $userData['given_name'] ?? '',
            'last_name' => $userData['family_name'] ?? '',
        ]);
        $user = $form->signup(false);
        if ($user === null) {
            $account->errors = $form->getErrors();
            return $account;
        }

        $user->email_verify = User::EMAIL_VERIFY;
        $user->save(false);
        $account->user = $user;
        $account->isNew = true;

        return $account;
    }

    private function refuse(string $reason, string $message): self
    {
        $this->reason = $reason;
        $this->message = $message;
        $this->errors = ['email' => [$message]];

        return $this;
    }

    /**
     * Whether the provider reported the email as verified. Google sends a boolean; Apple sends either a
     * "true"/"false" string or a boolean. Missing field counts as not verified.
     */
    public static function providerVerified(array $userData): bool
    {
        return filter_var($userData['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public static function unverifiedMessage(): string
    {
        return Module::t(
            'The sign-in provider has not verified this email address, so it cannot be used to sign in to the existing account'
            . ' with this address. Sign in with your password instead.'
        );
    }

    /**
     * Refusal to sign up with an address the provider has not verified, when no account exists yet (decision 6).
     * Also used in the API response.
     */
    public static function unverifiedSignupMessage(): string
    {
        return Module::t('This address has not been verified by Google/Apple; sign up with your email address instead.');
    }

    /**
     * The same refusal, on the web login card: points to the «Sign up» link below it (final review U10).
     */
    public static function unverifiedSignupWebMessage(): string
    {
        return Module::t('This address has not been verified by Google/Apple; sign up with your email address using the «Sign up» link below.');
    }
}
