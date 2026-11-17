<?php

namespace portalium\auth\components;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use portalium\auth\models\AuthProvider;
use Throwable;
use Yii;

/**
 * OpenID Connect sign-in with a workspace's provider (Entra ID, Okta, Google Workspace, …): the authorization code
 * flow with PKCE, state and nonce.
 *
 * start(): reads the provider's discovery document and returns the address of its sign-in page; the state, nonce and
 * PKCE verifier stay in this browser's session, one per sign-in in progress (kept for STATE_TTL seconds).
 * finish(): takes the state back (once), exchanges the code at the token endpoint (client_secret_post) and checks the
 * ID token: signed with one of the provider's published keys (RS/ES/PS algorithms only), issuer = the discovery
 * issuer, audience = the client id, nonce = ours, not expired. The person's email is "email", else
 * "preferred_username" or "upn" when it looks like an address (Entra ID sends no email claim for some accounts).
 */
class OidcClient
{
    public const SESSION_KEY = '__ssoOidc';

    public const STATE_TTL = 600;

    /** Signature algorithms accepted for the ID token; never HS* (the client secret) or none. */
    public const ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'PS256', 'PS384', 'PS512'];

    /** @var callable|null post(string $url, array $form): ?string, replaced in tests */
    public static $post;

    /** @throws OidcError */
    public static function start(AuthProvider $provider): string
    {
        $doc = self::discovery($provider);
        $state = Yii::$app->security->generateRandomString(32);
        $nonce = Yii::$app->security->generateRandomString(32);
        $verifier = Yii::$app->security->generateRandomString(64);
        $pending = array_filter(
            (array) Yii::$app->session->get(self::SESSION_KEY, []),
            static fn ($p) => is_array($p) && ($p['time'] ?? 0) > time() - self::STATE_TTL
        );
        $pending[$state] = ['provider' => (int) $provider->id_provider, 'nonce' => $nonce, 'verifier' => $verifier, 'time' => time()];
        Yii::$app->session->set(self::SESSION_KEY, array_slice($pending, -5, null, true));

        return $doc['authorization_endpoint'] . (strpos($doc['authorization_endpoint'], '?') === false ? '?' : '&') . http_build_query([
            'response_type' => 'code',
            'client_id' => (string) $provider->client_id,
            'redirect_uri' => self::redirectUri(),
            'scope' => trim((string) $provider->scopes) ?: 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * The provider of a pending sign-in, taken from the session (each state works once).
     *
     * @return array{0: AuthProvider, 1: array} provider, pending (nonce, verifier)
     * @throws OidcError
     */
    public static function takeState(string $state): array
    {
        $pending = (array) Yii::$app->session->get(self::SESSION_KEY, []);
        $entry = $pending[$state] ?? null;
        unset($pending[$state]);
        Yii::$app->session->set(self::SESSION_KEY, $pending);
        if (!is_array($entry) || ($entry['time'] ?? 0) <= time() - self::STATE_TTL) {
            throw new OidcError('The sign-in expired or was not started here.');
        }
        $provider = AuthProvider::findOne((int) $entry['provider']);
        if ($provider === null || $provider->type !== AuthProvider::TYPE_OIDC) {
            throw new OidcError('The identity provider is gone.');
        }

        return [$provider, $entry];
    }

    /**
     * The person the provider vouches for: id (sub), email, username, first_name, last_name.
     *
     * @throws OidcError
     */
    public static function finish(AuthProvider $provider, array $pending, string $code): array
    {
        $doc = self::discovery($provider);
        $connection = $provider->connection();
        $body = self::post($doc['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'client_id' => (string) ($connection['client_id'] ?? ''),
            'client_secret' => (string) ($connection['client_secret'] ?? ''),
            'code_verifier' => (string) $pending['verifier'],
        ]);
        $tokens = $body === null ? null : json_decode($body, true);
        if (!is_array($tokens) || empty($tokens['id_token']) || !is_string($tokens['id_token'])) {
            throw new OidcError('The identity provider gave no ID token.');
        }
        $claims = self::verify($provider, $doc, $tokens['id_token'], (string) $pending['nonce']);

        return [
            'id' => (string) $claims['sub'],
            'email' => self::email($claims),
            'username' => (string) ($claims['preferred_username'] ?? ''),
            'first_name' => (string) ($claims['given_name'] ?? ''),
            'last_name' => (string) ($claims['family_name'] ?? ''),
        ];
    }

    public static function redirectUri(): string
    {
        return \yii\helpers\Url::to(['/auth/sso/callback'], true);
    }

    /** @throws OidcError */
    private static function verify(AuthProvider $provider, array $doc, string $idToken, string $nonce): array
    {
        $jwks = ProviderTester::fetch((string) $doc['jwks_uri']);
        $set = $jwks === null ? null : json_decode($jwks, true);
        if (!is_array($set) || empty($set['keys'])) {
            throw new OidcError('The identity provider\'s signing keys could not be read.');
        }
        $header = json_decode((string) JWT::urlsafeB64Decode(explode('.', $idToken)[0] ?? ''), true);
        if (!is_array($header) || !in_array($header['alg'] ?? '', self::ALGORITHMS, true)) {
            throw new OidcError('The ID token is not signed with an accepted algorithm.');
        }
        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($set, (string) $header['alg']));
        } catch (Throwable $e) {
            throw new OidcError('The ID token is not valid: ' . $e->getMessage());
        }
        $audience = (array) ($claims['aud'] ?? []);
        $checks = [
            'issuer' => rtrim((string) ($claims['iss'] ?? ''), '/') === rtrim((string) $doc['issuer'], '/'),
            'audience' => in_array((string) $provider->client_id, $audience, true),
            'nonce' => hash_equals($nonce, (string) ($claims['nonce'] ?? '')),
            'subject' => (string) ($claims['sub'] ?? '') !== '',
            'expiry' => isset($claims['exp']),
        ];
        foreach ($checks as $name => $ok) {
            if (!$ok) {
                throw new OidcError('The ID token failed the ' . $name . ' check.');
            }
        }

        return $claims;
    }

    private static function email(array $claims): string
    {
        foreach (['email', 'preferred_username', 'upn'] as $claim) {
            $value = trim((string) ($claims[$claim] ?? ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return '';
    }

    /** @throws OidcError */
    private static function discovery(AuthProvider $provider): array
    {
        $doc = ProviderTester::discovery((string) $provider->issuer);
        if ($doc === null || rtrim((string) ($doc['issuer'] ?? ''), '/') !== rtrim((string) $provider->issuer, '/')) {
            throw new OidcError('The identity provider\'s discovery document could not be read.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (empty($doc[$key]) || stripos((string) $doc[$key], 'https://') !== 0) {
                throw new OidcError('The discovery document has no https ' . $key . '.');
            }
        }

        return $doc;
    }

    private static function post(string $url, array $form): ?string
    {
        if (self::$post !== null) {
            return (self::$post)($url, $form);
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => http_build_query($form),
                'timeout' => ProviderTester::TIMEOUT,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context, 0, 1024 * 1024);

        return $body === false ? null : $body;
    }
}
