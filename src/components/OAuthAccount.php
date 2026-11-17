<?php

namespace portalium\auth\components;

use Yii;
use portalium\auth\Module;
use portalium\auth\models\SignupForm;
use portalium\user\models\User;

/**
 * Finds or creates the account for a Google/Apple user; web and API use the same rule.
 *
 * An identity already linked to an account (OAuthLink, by the provider's user id) signs into that account. Otherwise,
 * if an account with the provider's email exists, it is not signed into: its owner signs in with the password and
 * links Google/Apple from the profile (decision 2026-11-04). A pending or deleted account is returned unchanged so the
 * caller shows its status (Card 2), but only if the provider verified the email; it is never activated or signed into
 * this way. A virtual (device) account is always refused. When no account exists, a new one is not created with an
 * address the provider has not verified (decision 6, 2026-10-18): doing so would let the token holder take an
 * address the real owner hasn't signed up with yet. A new account follows the auth::user_status setting, skips email
 * verification (Card 2, decision A), is marked verified and linked.
 */
class OAuthAccount
{
    /** Refused: the provider did not report the email as verified (signing into an existing account, or a new account). */
    public const REASON_EMAIL_NOT_VERIFIED = 'provider_email_not_verified';

    /** No account exists and creating a new one is not allowed (auth::api_signup disabled on the API, decision 18). */
    public const REASON_SIGNUP_DISABLED = 'signup_disabled';

    /** An account with this email exists and the identity is not linked to it; link it from the profile. */
    public const REASON_ACCOUNT_EXISTS = 'account_exists';
    public const REASON_CORPORATE_ONLY = 'corporate_only';

    /** @var string|null the provider (OAuthLink::GOOGLE / APPLE) */
    public $provider;

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
     * @param array $userData data extracted from OAuthClient::verifyIdToken or the Apple token
     *     (google_id / apple_id, email, email_verified, given_name, family_name)
     * @param bool $allowSignup whether a new account may be created if none exists; false returns REASON_SIGNUP_DISABLED
     */
    public static function resolve(array $userData, bool $allowSignup = true): self
    {
        $account = new self();
        $identity = OAuthLink::identityOf($userData);
        if ($identity === null) {
            $account->errors = ['id_token' => ['The provider user id is missing in the token.']];
            return $account;
        }
        [$provider, $providerId] = $identity;
        $account->provider = $provider;
        $linked = OAuthLink::findUser($provider, $providerId);
        if ($linked !== null) {
            if (ProviderAccount::corporateOnly($linked)) {
                return $account->refuse(self::REASON_CORPORATE_ONLY, Module::t('This account signs in with its company sign-in only.'));
            }
            $account->user = $linked;
            return $account;
        }
        $email = trim((string) ($userData['email'] ?? ''));
        if ($email === '') {
            $account->errors = ['email' => ['Email is missing in the provider token.']];
            return $account;
        }
        $existing = User::findOne(['email' => $email]);
        if ($existing !== null) {
            if ((int) $existing->status !== User::STATUS_ACTIVE && (int) $existing->is_virtual !== 1 && self::providerVerified($userData)) {
                $account->user = $existing;
                return $account;
            }
            return $account->refuse(self::REASON_ACCOUNT_EXISTS, OAuthLink::accountExistsMessage($provider));
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
        SignupSource::expect($provider === OAuthLink::APPLE ? SignupSource::APPLE : SignupSource::GOOGLE);
        $user = $form->signup(false);
        if ($user === null) {
            $account->errors = $form->getErrors();
            return $account;
        }

        $user->email_verify = User::EMAIL_VERIFY;
        $user->save(false);
        OAuthLink::link((int) $user->id_user, $provider, $providerId, $email);
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
