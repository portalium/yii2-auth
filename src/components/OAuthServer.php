<?php

namespace portalium\auth\components;

use portalium\auth\Module;
use portalium\user\models\User;
use Yii;
use yii\db\Query;

/**
 * Suite as an OAuth 2.1 authorization server, for MCP clients (Claude, ChatGPT, …) that sign a user in: discovery
 * metadata (RFC 8414, RFC 9728), dynamic client registration (RFC 7591) and the authorization-code grant with PKCE
 * S256. What a client gets is the user's own access token, the one the REST API and the MCP endpoint already take.
 *
 * Separate from OAuthClient / OAuthAccount, where Suite is the client of Google and Apple.
 */
class OAuthServer
{
    public const CODE_TTL = 300;
    public const SCOPE = 'mcp';
    public const AUTH_METHODS = ['none', 'client_secret_post', 'client_secret_basic'];

    public static function issuer(): string
    {
        return rtrim(Yii::$app->request->hostInfo, '/');
    }

    /**
     * RFC 8414: where to sign in, get a token and register.
     */
    public static function authorizationServerMetadata(): array
    {
        $base = self::issuer();
        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => self::AUTH_METHODS,
            'scopes_supported' => [self::SCOPE],
        ];
    }

    /**
     * RFC 9728: the resource at $path (e.g. "app/mcp") is protected by this server.
     */
    public static function protectedResourceMetadata(string $path): array
    {
        $base = self::issuer();
        $path = trim($path, '/');
        return [
            'resource' => $path === '' ? $base : $base . '/' . $path,
            'authorization_servers' => [$base],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => [self::SCOPE],
        ];
    }

    /**
     * RFC 7591: a client registers its redirect URIs (https, or http on the loopback address for a desktop client).
     *
     * @throws OAuthError invalid_redirect_uri / invalid_client_metadata
     */
    public static function register(array $metadata): array
    {
        $uris = $metadata['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 10) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must list 1-10 URIs.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::allowedRedirect($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'Redirect URIs must be https (http only on localhost).');
            }
        }
        $method = (string) ($metadata['token_endpoint_auth_method'] ?? 'none');
        if (!in_array($method, self::AUTH_METHODS, true)) {
            throw new OAuthError('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
        }
        $clientId = 'nv-' . Yii::$app->security->generateRandomString(32);
        $secret = $method === 'none' ? null : Yii::$app->security->generateRandomString(48);
        $name = mb_substr(trim((string) ($metadata['client_name'] ?? '')), 0, 255) ?: null;
        Yii::$app->db->createCommand()->insert(self::table('oauth_client'), [
            'client_id' => $clientId,
            'client_secret_hash' => $secret === null ? null : hash('sha256', $secret),
            'client_name' => $name,
            'redirect_uris' => json_encode(array_values($uris), JSON_UNESCAPED_SLASHES),
            'auth_method' => $method,
            'date_create' => date('Y-m-d H:i:s'),
        ])->execute();
        return array_filter([
            'client_id' => $clientId,
            'client_secret' => $secret,
            'client_id_issued_at' => time(),
            'client_secret_expires_at' => $secret === null ? null : 0,
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'token_endpoint_auth_method' => $method,
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
        ], static fn($v) => $v !== null);
    }

    /**
     * The authorization request, checked. A wrong client or redirect URI is never redirected to (it may not be the
     * client's); anything else is an error the client gets on its redirect URI.
     *
     * @return array{client_id: string, client_name: ?string, redirect_uri: string, state: ?string,
     *               code_challenge: string, resource: ?string, scope: ?string}
     * @throws OAuthError
     */
    public static function authorizationRequest(array $query): array
    {
        $client = self::client((string) ($query['client_id'] ?? ''));
        if ($client === null) {
            throw new OAuthError('invalid_client', 'Unknown client.');
        }
        $redirect = (string) ($query['redirect_uri'] ?? '');
        if (!in_array($redirect, $client['redirect_uris'], true)) {
            throw new OAuthError('invalid_request', 'The redirect URI is not registered for this client.');
        }
        $state = isset($query['state']) ? (string) $query['state'] : null;
        if (($query['response_type'] ?? '') !== 'code') {
            throw new OAuthError('unsupported_response_type', 'Only response_type=code.', $redirect, $state);
        }
        $challenge = (string) ($query['code_challenge'] ?? '');
        if (($query['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
            throw new OAuthError('invalid_request', 'PKCE with code_challenge_method=S256 is required.', $redirect, $state);
        }
        return [
            'client_id' => $client['client_id'],
            'client_name' => $client['client_name'],
            'redirect_uri' => $redirect,
            'state' => $state,
            'code_challenge' => $challenge,
            'resource' => isset($query['resource']) ? mb_substr((string) $query['resource'], 0, 1000) : null,
            'scope' => isset($query['scope']) ? mb_substr((string) $query['scope'], 0, 255) : null,
        ];
    }

    /**
     * A one-time code for the signed-in user who allowed the request; the client trades it for the token.
     */
    public static function issueCode(array $request, int $idUser): string
    {
        $code = Yii::$app->security->generateRandomString(48);
        Yii::$app->db->createCommand()->insert(self::table('oauth_code'), [
            'code_hash' => hash('sha256', $code),
            'client_id' => $request['client_id'],
            'id_user' => $idUser,
            'redirect_uri' => $request['redirect_uri'],
            'code_challenge' => $request['code_challenge'],
            'resource' => $request['resource'],
            'scope' => $request['scope'],
            'expires_at' => time() + self::CODE_TTL,
            'date_create' => date('Y-m-d H:i:s'),
        ])->execute();
        return $code;
    }

    /**
     * The token request (authorization_code grant): the code, once, by the client it was issued to, with the same
     * redirect URI and the PKCE verifier. $basic is the client's HTTP Basic credentials, if it sent them.
     *
     * @throws OAuthError
     */
    public static function token(array $body, ?array $basic = null): array
    {
        if (($body['grant_type'] ?? '') !== 'authorization_code') {
            throw new OAuthError('unsupported_grant_type', 'Only grant_type=authorization_code.');
        }
        $clientId = (string) ($basic[0] ?? $body['client_id'] ?? '');
        $client = self::client($clientId);
        if ($client === null || !self::clientAuthenticated($client, $basic[1] ?? ($body['client_secret'] ?? null))) {
            throw new OAuthError('invalid_client', 'Client authentication failed.');
        }
        $row = (new Query())->from(self::table('oauth_code'))
            ->where(['code_hash' => hash('sha256', (string) ($body['code'] ?? ''))])->one();
        if ($row === null || $row['client_id'] !== $clientId) {
            throw new OAuthError('invalid_grant', 'Unknown code.');
        }
        // a code works once: marked used before anything else, and only by the request that still sees it unused
        $taken = Yii::$app->db->createCommand()->update(
            self::table('oauth_code'),
            ['used' => 1],
            ['id_code' => $row['id_code'], 'used' => 0]
        )->execute();
        if ($taken !== 1 || (int) $row['expires_at'] < time()) {
            throw new OAuthError('invalid_grant', 'The code has expired or was already used.');
        }
        if ((string) ($body['redirect_uri'] ?? '') !== $row['redirect_uri']) {
            throw new OAuthError('invalid_grant', 'The redirect URI does not match.');
        }
        $verifier = (string) ($body['code_verifier'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals($row['code_challenge'], self::challenge($verifier))) {
            throw new OAuthError('invalid_grant', 'PKCE verification failed.');
        }
        $user = User::findOne(['id_user' => $row['id_user'], 'status' => User::STATUS_ACTIVE]);
        if ($user === null) {
            throw new OAuthError('invalid_grant', 'The account is not active.');
        }
        if ((string) $user->access_token === '') {
            $user->access_token = Yii::$app->security->generateRandomString(40);
            $user->updateAttributes(['access_token']);
        }
        return ['access_token' => $user->access_token, 'token_type' => 'Bearer', 'scope' => $row['scope'] ?: self::SCOPE];
    }

    /**
     * BASE64URL(SHA256(verifier)), no padding (RFC 7636).
     */
    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function client(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }
        $row = (new Query())->from(self::table('oauth_client'))->where(['client_id' => $clientId])->one();
        if ($row === false || $row === null) {
            return null;
        }
        $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        return $row;
    }

    private static function clientAuthenticated(array $client, $secret): bool
    {
        if ($client['auth_method'] === 'none') {
            return true;
        }
        return is_string($secret) && $client['client_secret_hash'] !== null
            && hash_equals($client['client_secret_hash'], hash('sha256', $secret));
    }

    private static function allowedRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $loopback = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        return $scheme === 'https' || ($scheme === 'http' && $loopback);
    }

    private static function table(string $name): string
    {
        return Module::$tablePrefix . $name;
    }
}
