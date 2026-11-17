<?php

namespace portalium\auth\components;

use Yii;
use yii\db\Expression;
use yii\db\IntegrityException;
use yii\db\Query;
use portalium\auth\Module;
use portalium\user\models\User;

/**
 * Google/Apple identities linked to accounts (auth_oauth_identity).
 *
 * An identity is stored by the provider's stable user id ("sub"), so a later sign-in finds the account even if the
 * email changes. An identity is linked only when an account is created with Google/Apple, or by its owner while
 * signed in, from the profile (decision 2026-11-04): signing in with Google/Apple never takes over an existing
 * account that has no link. An account has at most one identity per provider.
 */
class OAuthLink
{
    public const GOOGLE = 'google';
    public const APPLE = 'apple';

    /** Seconds a connect request (the OAuth "state" of the profile's [Link] button) stays valid. */
    public const CONNECT_TTL = 600;

    /** The identity is already linked to another account. */
    public const ERROR_TAKEN = 'identity_taken';

    /** The account already has another identity of this provider. */
    public const ERROR_PROVIDER_LINKED = 'provider_linked';

    /** The connect request is missing, used or expired. */
    public const ERROR_EXPIRED = 'connect_expired';

    /**
     * The provider and its user id in the data from OAuthClient (google_id) or the Apple token (apple_id).
     *
     * @return array{0:string, 1:string}|null
     */
    public static function identityOf(array $userData): ?array
    {
        foreach ([self::GOOGLE => 'google_id', self::APPLE => 'apple_id'] as $provider => $key) {
            $id = trim((string) ($userData[$key] ?? ''));
            if ($id !== '') {
                return [$provider, $id];
            }
        }

        return null;
    }

    public static function findUser(string $provider, string $providerId): ?User
    {
        $idUser = (new Query())->select('id_user')->from(self::table())
            ->where(['provider' => $provider, 'provider_id' => $providerId])->scalar();

        return $idUser === false ? null : User::findOne((int) $idUser);
    }

    /**
     * The account's linked identities, provider => row (provider_id, email, date_create).
     *
     * @return array<string, array>
     */
    public static function identities(int $idUser): array
    {
        $rows = (new Query())->from(self::table())->where(['id_user' => $idUser])->all();

        return array_column($rows, null, 'provider');
    }

    /**
     * Links the identity to the account.
     *
     * @return string|null null on success (also when this exact link already exists), or an ERROR_* constant
     */
    public static function link(int $idUser, string $provider, string $providerId, ?string $email): ?string
    {
        $owner = (new Query())->select('id_user')->from(self::table())
            ->where(['provider' => $provider, 'provider_id' => $providerId])->scalar();
        if ($owner !== false) {
            return (int) $owner === $idUser ? null : self::ERROR_TAKEN;
        }
        if ((new Query())->from(self::table())->where(['id_user' => $idUser, 'provider' => $provider])->exists()) {
            return self::ERROR_PROVIDER_LINKED;
        }
        try {
            Yii::$app->db->createCommand()->insert(self::table(), [
                'id_user' => $idUser,
                'provider' => $provider,
                'provider_id' => $providerId,
                'email' => $email,
                'date_create' => new Expression('NOW()'),
            ])->execute();
        } catch (IntegrityException $e) {
            return self::ERROR_TAKEN;
        }

        return null;
    }

    public static function unlink(int $idUser, string $provider): bool
    {
        return Yii::$app->db->createCommand()->delete(self::table(), ['id_user' => $idUser, 'provider' => $provider])->execute() > 0;
    }

    /**
     * Starts linking from the profile: returns the OAuth "state" to send to the provider. The state is kept in the
     * cache, not the session, because Apple returns with a cross-site POST that carries no session cookie.
     */
    public static function beginConnect(int $idUser, string $provider): string
    {
        $state = Yii::$app->security->generateRandomString(40);
        Yii::$app->cache->set(self::stateKey($state), ['id_user' => $idUser, 'provider' => $provider], self::CONNECT_TTL);

        return $state;
    }

    /**
     * The connect request for the state, removed so it is used once; null if missing or expired.
     *
     * @return array{id_user:int, provider:string}|null
     */
    public static function takeConnect(string $state): ?array
    {
        if ($state === '') {
            return null;
        }
        $key = self::stateKey($state);
        $request = Yii::$app->cache->get($key);
        Yii::$app->cache->delete($key);

        return is_array($request) && isset($request['id_user'], $request['provider']) ? $request : null;
    }

    /**
     * Links the identity in the provider data to the account that started the connect request.
     *
     * @return string|null null on success, or an ERROR_* constant
     */
    public static function finishConnect(array $request, array $userData): ?string
    {
        $identity = self::identityOf($userData);
        if ($identity === null || $identity[0] !== $request['provider']) {
            return self::ERROR_EXPIRED;
        }

        return self::link((int) $request['id_user'], $identity[0], $identity[1], $userData['email'] ?? null);
    }

    public static function label(string $provider): string
    {
        return $provider === self::APPLE ? 'Apple' : 'Google';
    }

    public static function enabled(string $provider): bool
    {
        try {
            return (bool) Yii::$app->setting->getValue('auth::' . $provider . 'Enabled');
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function accountExistsMessage(string $provider): string
    {
        return Module::t(
            'An account with this email already exists. Sign in with your password, then link {provider} from your profile.',
            ['provider' => self::label($provider)]
        );
    }

    public static function errorMessage(string $error, string $provider): string
    {
        $params = ['provider' => self::label($provider)];
        switch ($error) {
            case self::ERROR_TAKEN:
                return Module::t('This {provider} account is already linked to another account.', $params);
            case self::ERROR_PROVIDER_LINKED:
                return Module::t('Another {provider} account is already linked. Remove it first.', $params);
            default:
                return Module::t('Linking {provider} failed or took too long. Please try again.', $params);
        }
    }

    private static function stateKey(string $state): string
    {
        return 'auth.oauth_connect.' . hash('sha256', $state);
    }

    private static function table(): string
    {
        return Module::$tablePrefix . 'oauth_identity';
    }
}
