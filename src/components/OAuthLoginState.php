<?php

namespace portalium\auth\components;

use Yii;
use yii\web\Cookie;

/**
 * State, nonce and PKCE for "Sign in with Google/Apple" (pentest 2026-11-12 finding 05).
 *
 * Without a state bound to the browser, an attacker could make a victim's browser finish the attacker's own sign-in
 * (login CSRF) and the victim would work inside the attacker's account. begin() keeps the state in the server cache
 * and in a short-lived cookie of this browser; take() accepts a callback only when both match, once.
 *
 * Apple returns a sign-in with a cross-site POST (form_post), so on HTTPS the cookie is SameSite=None; Secure.
 */
class OAuthLoginState
{
    public const COOKIE = 'oauth_login_state';
    public const TTL = 600;

    /**
     * @return array{state:string, nonce:string, verifier:string, challenge:string}
     */
    public static function begin(string $provider): array
    {
        $security = Yii::$app->security;
        $state = $security->generateRandomString(40);
        $nonce = $security->generateRandomString(32);
        $verifier = $security->generateRandomString(64);
        Yii::$app->cache->set(self::key($state), ['provider' => $provider, 'nonce' => $nonce, 'verifier' => $verifier], self::TTL);

        $secure = Yii::$app->request->getIsSecureConnection();
        Yii::$app->response->cookies->add(new Cookie([
            'name' => self::COOKIE,
            'value' => $state,
            'expire' => time() + self::TTL,
            'httpOnly' => true,
            'secure' => $secure,
            'sameSite' => $secure ? Cookie::SAME_SITE_NONE : Cookie::SAME_SITE_LAX,
        ]));

        return ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'challenge' => self::challenge($verifier)];
    }

    /**
     * The sign-in started by this browser for this state, removed so it is used once; null otherwise.
     *
     * @return array{provider:string, nonce:string, verifier:string}|null
     */
    public static function take(string $state, string $provider): ?array
    {
        if ($state === '') {
            return null;
        }
        $cookie = (string) Yii::$app->request->cookies->getValue(self::COOKIE, '');
        $key = self::key($state);
        $data = Yii::$app->cache->get($key);
        Yii::$app->cache->delete($key);
        Yii::$app->response->cookies->remove(self::COOKIE);

        if ($cookie === '' || !hash_equals($cookie, $state) || !is_array($data) || ($data['provider'] ?? null) !== $provider) {
            return null;
        }

        return $data;
    }

    /** Whether the ID token carries the nonce sent with this sign-in. */
    public static function nonceMatches(array $login, array $userData): bool
    {
        return isset($userData['nonce']) && is_string($userData['nonce']) && hash_equals($login['nonce'], $userData['nonce']);
    }

    /** PKCE S256 code challenge. */
    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function key(string $state): string
    {
        return 'auth.oauth.login.' . hash('sha256', $state);
    }
}
