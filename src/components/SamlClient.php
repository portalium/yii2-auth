<?php

namespace portalium\auth\components;

use portalium\auth\models\AuthProvider;
use Throwable;
use Yii;
use yii\helpers\Url;

/**
 * SAML 2.0 sign-in with a workspace's provider (ADFS, Entra ID, Okta, …), through onelogin/php-saml: the signature
 * is never checked by hand (XML signature wrapping has broken many hand-written checks).
 *
 * Strict settings: the assertion must be signed with the provider's certificate (SHA-256), the response must answer
 * a request this browser started (InResponseTo; unsolicited, IdP-initiated responses are refused), and its
 * destination and audience must be this Suite provider. The person's email is the first email attribute, else the
 * NameID when it is an address.
 *
 * The provider posts the response from its own site, so the browser leaves out the session cookie (SameSite=Lax):
 * the requests this browser started are also kept in their own short-lived signed cookie (SameSite=None, only over
 * https), which keeps the response tied to this browser.
 *
 * The library is not installed with composer on the servers: its folders are copied into vendor/ (VendorLibraries).
 */
class SamlClient
{
    public const SESSION_KEY = '__ssoSaml';

    public const STATE_TTL = 600;

    public const COOKIE = '_ssoSaml';

    private const EMAIL_ATTRIBUTES = [
        'email', 'mail', 'emailaddress', 'Email',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        'urn:oid:0.9.2342.19200300.100.1.3',
    ];

    private const FIRST_NAME_ATTRIBUTES = ['givenName', 'firstName', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname', 'urn:oid:2.5.4.42'];

    private const LAST_NAME_ATTRIBUTES = ['sn', 'surname', 'lastName', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname', 'urn:oid:2.5.4.4'];

    public static function available(): bool
    {
        self::boot();

        return class_exists(\OneLogin\Saml2\Auth::class);
    }

    /** Makes the copied library folders loadable (composer installs need nothing). */
    public static function boot(): void
    {
        VendorLibraries::boot();
    }

    /**
     * The address of the provider's sign-in page with our request; its id stays in this browser's session.
     *
     * @throws SamlError
     */
    public static function start(AuthProvider $provider): string
    {
        $auth = self::auth($provider);
        try {
            $url = $auth->login(null, [], false, false, true);
        } catch (Throwable $e) {
            throw new SamlError('The sign-in request could not be made: ' . $e->getMessage());
        }
        $pending = self::pending();
        $pending[(string) $auth->getLastRequestID()] = ['provider' => (int) $provider->id_provider, 'time' => time()];
        self::remember(array_slice($pending, -5, null, true));

        return $url;
    }

    /**
     * The person in the provider's signed response (POSTed to the ACS address): id (NameID), email, username,
     * first_name, last_name.
     *
     * @throws SamlError
     */
    public static function finish(AuthProvider $provider): array
    {
        $auth = self::auth($provider);
        $requests = [];
        foreach (self::pending() as $id => $pending) {
            if ((int) ($pending['provider'] ?? 0) === (int) $provider->id_provider) {
                $requests[] = (string) $id;
            }
        }
        if ($requests === []) {
            throw new SamlError('No sign-in was started here for this provider.');
        }
        $inResponseTo = self::inResponseTo();
        if ($inResponseTo === null || !in_array($inResponseTo, $requests, true)) {
            throw new SamlError('The response does not answer a request made here.');
        }
        try {
            $auth->processResponse($inResponseTo);
        } catch (Throwable $e) {
            throw new SamlError('The response could not be read: ' . $e->getMessage());
        }
        $pending = self::pending();
        unset($pending[$inResponseTo]);
        self::remember($pending);
        if ($auth->getErrors() !== [] || !$auth->isAuthenticated()) {
            throw new SamlError('The response was refused: ' . implode(', ', $auth->getErrors()) . ' ' . (string) $auth->getLastErrorReason());
        }
        $nameId = trim((string) $auth->getNameId());
        if ($nameId === '') {
            throw new SamlError('The response has no NameID.');
        }
        $attributes = $auth->getAttributes();
        $pick = static function (array $names) use ($attributes): string {
            foreach ($names as $name) {
                $value = trim((string) ($attributes[$name][0] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
            return '';
        };
        $email = $pick(self::EMAIL_ATTRIBUTES);
        if ($email === '' && filter_var($nameId, FILTER_VALIDATE_EMAIL)) {
            $email = $nameId;
        }

        return [
            'id' => $nameId,
            'email' => $email,
            'username' => explode('@', $email ?: $nameId)[0],
            'first_name' => $pick(self::FIRST_NAME_ATTRIBUTES),
            'last_name' => $pick(self::LAST_NAME_ATTRIBUTES),
        ];
    }

    /** This Suite provider's SP metadata (for the company's SAML set-up). */
    public static function metadata(AuthProvider $provider): string
    {
        return self::auth($provider)->getSettings()->getSPMetadata();
    }

    public static function entityId(AuthProvider $provider): string
    {
        return Url::to(['/auth/sso/metadata', 'provider' => $provider->id_provider], true);
    }

    public static function acsUrl(AuthProvider $provider): string
    {
        return Url::to(['/auth/sso/acs', 'provider' => $provider->id_provider], true);
    }

    public static function settings(AuthProvider $provider): array
    {
        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => self::entityId($provider),
                'assertionConsumerService' => [
                    'url' => self::acsUrl($provider),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
            ],
            'idp' => [
                'entityId' => (string) $provider->idp_entity_id,
                'singleSignOnService' => [
                    'url' => (string) $provider->idp_sso_url,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => AuthProvider::pem((string) $provider->idp_x509cert),
            ],
            'security' => [
                'requestedAuthnContext' => false,
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => false,
                'wantNameId' => true,
                // Keycloak sends each role as its own Attribute named "Role"; the values are merged, the assertion stays signed
                'allowRepeatAttributeName' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
                'rejectDeprecatedAlgorithm' => true,
                'relaxDestinationValidation' => false,
                'lowercaseUrlencoding' => false,
            ],
        ];
    }

    /** @throws SamlError */
    private static function auth(AuthProvider $provider): \OneLogin\Saml2\Auth
    {
        if (!self::available()) {
            throw new SamlError('The SAML library (onelogin/php-saml) is not installed on this server.');
        }
        try {
            return new \OneLogin\Saml2\Auth(self::settings($provider));
        } catch (Throwable $e) {
            throw new SamlError('The SAML settings are not valid: ' . $e->getMessage());
        }
    }

    /** The requests this browser started and that have not expired: from the session, else from the cookie. */
    private static function pending(): array
    {
        $pending = (array) Yii::$app->session->get(self::SESSION_KEY, []);
        $cookie = json_decode((string) Yii::$app->request->cookies->getValue(self::COOKIE, ''), true);
        if (is_array($cookie)) {
            $pending += $cookie;
        }

        return array_filter($pending, static fn ($p) => is_array($p) && ($p['time'] ?? 0) > time() - self::STATE_TTL);
    }

    private static function remember(array $pending): void
    {
        Yii::$app->session->set(self::SESSION_KEY, $pending);
        $secure = Yii::$app->request->getIsSecureConnection();
        Yii::$app->response->cookies->add(new \yii\web\Cookie([
            'name' => self::COOKIE,
            'value' => json_encode($pending),
            'expire' => $pending === [] ? 1 : time() + self::STATE_TTL,
            'httpOnly' => true,
            'secure' => $secure,
            'sameSite' => $secure ? \yii\web\Cookie::SAME_SITE_NONE : \yii\web\Cookie::SAME_SITE_LAX,
        ]));
    }

    /** The InResponseTo of the posted response, read only to pick our request (the library checks it again). */
    private static function inResponseTo(): ?string
    {
        $raw = (string) Yii::$app->request->post('SAMLResponse', '');
        $xml = $raw === '' ? false : base64_decode($raw, true);
        if ($xml === false || !preg_match('/<(?:\w+:)?Response\b[^>]*\bInResponseTo="([^"]+)"/', $xml, $m)) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
    }
}
