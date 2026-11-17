<?php

namespace portalium\auth\components;

use portalium\auth\models\AuthProvider;
use portalium\auth\Module;
use Throwable;
use Yii;

/**
 * The provider form's connection test: whether Suite can reach the identity provider with these settings.
 * It proves the server works and the settings are right, not that the domain belongs to this workspace (that is the
 * DNS check, DomainVerifier).
 * - LDAP: connect (StartTLS / LDAPS) and bind with the service account.
 * - OIDC: read the discovery document (issuer/.well-known/openid-configuration); its issuer must match and it must
 *   name the authorization, token and key endpoints.
 * - SAML: the signing certificate is readable and the sign-in URL is https (a SAML IdP has nothing to call ahead).
 */
class ProviderTester
{
    /** Seconds the OIDC discovery request may take. */
    public const TIMEOUT = 8;

    /** @var callable|null fetch(string $url): ?string, replaced in tests */
    public static $fetch;

    /** @return array{0: bool, 1: string} ok, message for the user */
    public static function test(AuthProvider $provider): array
    {
        try {
            switch ($provider->type) {
                case AuthProvider::TYPE_LDAP:
                    Yii::createObject(LdapDirectory::class, [$provider->connection()])->testConnection();
                    return [true, Module::t('Connected to the LDAP server and signed in with the service account.')];
                case AuthProvider::TYPE_OIDC:
                    return self::oidc($provider);
                case AuthProvider::TYPE_SAML:
                    return self::saml($provider);
            }
        } catch (LdapUnavailable $e) {
            return [false, Module::t('LDAP connection failed: {error}', ['error' => $e->getMessage()])];
        } catch (Throwable $e) {
            Yii::warning('Provider test failed: ' . get_class($e) . ' ' . $e->getMessage(), __METHOD__);
            return [false, Module::t('The test could not be run.')];
        }

        return [false, Module::t('Unknown provider type.')];
    }

    /** The OIDC discovery document of an issuer, or null. */
    public static function discovery(string $issuer): ?array
    {
        $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';
        $body = self::fetch($url);
        $data = $body === null ? null : json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    public static function fetch(string $url): ?string
    {
        if (self::$fetch !== null) {
            return (self::$fetch)($url);
        }
        if (!NetworkGuard::allowedUrl($url)) {
            return null;
        }
        $context = stream_context_create([
            'http' => ['timeout' => self::TIMEOUT, 'ignore_errors' => false, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context, 0, 1024 * 1024);

        return $body === false ? null : $body;
    }

    private static function oidc(AuthProvider $provider): array
    {
        $issuer = rtrim((string) $provider->issuer, '/');
        $doc = self::discovery($issuer);
        if ($doc === null) {
            return [false, Module::t('The discovery document could not be read from {url}.', [
                'url' => $issuer . '/.well-known/openid-configuration',
            ])];
        }
        if (rtrim((string) ($doc['issuer'] ?? ''), '/') !== $issuer) {
            return [false, Module::t('The discovery document belongs to another issuer: {issuer}', ['issuer' => (string) ($doc['issuer'] ?? '')])];
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (empty($doc[$key])) {
                return [false, Module::t('The discovery document has no {key}.', ['key' => $key])];
            }
        }

        return [true, Module::t('The identity provider answered; its endpoints were found.')];
    }

    private static function saml(AuthProvider $provider): array
    {
        if (!function_exists('openssl_x509_read') || @openssl_x509_read(AuthProvider::pem((string) $provider->idp_x509cert)) === false) {
            return [false, Module::t('This is not a valid certificate.')];
        }
        if (stripos((string) $provider->idp_sso_url, 'https://') !== 0) {
            return [false, Module::t('The sign-in URL must start with https://.')];
        }

        return [true, Module::t('The certificate and the sign-in URL look right; try a sign-in to finish the test.')];
    }
}
