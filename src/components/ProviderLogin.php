<?php

namespace portalium\auth\components;

use portalium\auth\models\AuthProvider;
use portalium\auth\Module;
use portalium\user\models\User;
use Yii;

/**
 * The end of every corporate sign-in (LDAP, OIDC, SAML) once the provider vouched for the person: the account
 * (ProviderAccount), its status, the login itself and the login log.
 *
 * A corporate login keeps no remember-me cookie: the session ends with the browser, so a person removed from the
 * company's directory is out by their next sign-in at the latest.
 */
class ProviderLogin
{
    /** The provider routing a domain: verified, active, of a known type. */
    public static function forDomain(string $domain): ?AuthProvider
    {
        $domain = ltrim(mb_strtolower(trim($domain)), '@');
        if ($domain === '') {
            return null;
        }

        return AuthProvider::find()
            ->where(['domain' => $domain, 'status' => AuthProvider::STATUS_ACTIVE])
            ->andWhere(['not', ['date_verified' => null]])
            ->andWhere(['type' => array_keys(AuthProvider::typeLabels())])
            ->one();
    }

    /**
     * Signs the person in. Returns null on success, else the message for the login page.
     *
     * @param array $person id, email, username, first_name, last_name (as the provider gave them)
     */
    public static function finish(AuthProvider $provider, array $person): ?string
    {
        $email = (string) ($person['email'] ?? '');
        $user = ProviderAccount::resolve($provider, $person);
        if ($user === null) {
            AuthLogger::failed($email, null, (string) ProviderAccount::$refusal, $provider->type);
            return self::refusalMessage((string) ProviderAccount::$refusal, $provider);
        }
        if ((int) $user->status !== User::STATUS_ACTIVE) {
            AuthLogger::failed($email, $user, AccountStatus::of($user), $provider->type);
            return AccountStatus::inactiveMessage($user);
        }
        $refusals = ProviderAccount::join($user, $provider);
        if ($refusals !== []) {
            AuthLogger::failed($email, $user, 'sso_workspace_full', $provider->type);
            return $refusals[0];
        }
        AuthLogger::$nextMethod = $provider->type;
        if (!Yii::$app->user->login($user, 0)) {
            return Module::t('The sign-in could not be completed.');
        }

        return null;
    }

    public static function refusalMessage(string $refusal, AuthProvider $provider): string
    {
        switch ($refusal) {
            case ProviderAccount::REFUSE_DOMAIN:
                return Module::t('Your company account has no email address in {domain}.', ['domain' => $provider->domain]);
            case ProviderAccount::REFUSE_ADMIN:
                return Module::t('This account is an administrator account; sign in with its password.');
            case ProviderAccount::REFUSE_UNVERIFIED_ACCOUNT:
                return Module::t('A Suite account with this email exists but cannot be linked; sign in with its password or ask your administrator.');
            case ProviderAccount::REFUSE_LINKED_ELSEWHERE:
                return Module::t('This Suite account is already linked to another company account.');
            default:
                return Module::t('Your account could not be created. Ask your administrator.');
        }
    }

    /** Message for a sign-in that a provider refused or that could not reach it. */
    public static function unavailableMessage(): string
    {
        return Module::t('Your company sign-in is not reachable right now. Try again later.');
    }

    /** Records a refused corporate sign-in that never got to a person (wrong password, unreachable provider). */
    public static function recordFailure(AuthProvider $provider, string $typedName, string $reason): void
    {
        AuthLogger::failed($typedName, null, $reason, $provider->type);
    }
}
