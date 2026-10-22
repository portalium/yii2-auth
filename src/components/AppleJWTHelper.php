<?php
namespace portalium\auth\components;

use Yii;
use yii\base\Component;
use yii\httpclient\Client;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AppleJWTHelper extends Component
{
    public $teamId;
    public $keyId;
    public $clientId;
    public $privateKey;

    /**
     * Accepted "aud" values. If empty, only $clientId is accepted.
     * @var string[]
     */
    public $allowedAudiences = [];

    /**
     * Apple's signing keys (JWKS). If empty, fetched from https://appleid.apple.com/auth/keys and cached for
     * one hour. Supplied directly in tests.
     * @var array|null
     */
    public $jwks;

    const ISSUER = 'https://appleid.apple.com';

    /**
     * Creates a client secret JWT for Apple OAuth
     */
    public function createClientSecret()
    {
        // For debugging, log the values
        $privateKeyContent = Yii::$app->setting->getValue('auth::applePrivateKey');

        if (empty($privateKeyContent)) {
            throw new \Exception('Apple private key not configured');
        }

        // Clean up the private key content
        $privateKeyContent = trim($privateKeyContent);

        // Ensure proper PEM format
        if (strpos($privateKeyContent, '-----BEGIN') === false) {
            $privateKeyContent = "-----BEGIN PRIVATE KEY-----\n" .
                chunk_split($privateKeyContent, 64, "\n") .
                "-----END PRIVATE KEY-----";
        }

        $now = time();
        $payload = [
            'iss' => $this->teamId,
            'iat' => $now,
            'exp' => $now + 3600 * 6, // 6 hours
            'aud' => 'https://appleid.apple.com',
            'sub' => $this->clientId
        ];

        $header = [
            'kid' => $this->keyId,
            'alg' => 'ES256'
        ];

        try {

            // Use Firebase JWT to create the token
            $jwt = JWT::encode($payload, $privateKeyContent, 'ES256', $this->keyId);

            return $jwt;

        }
        catch (\Exception $e) {
            Yii::error('Firebase JWT creation error: ' . $e->getMessage(), 'oauth');
            throw new \Exception('Failed to create Apple JWT: ' . $e->getMessage());
        }
    }

    /**
     * Verifies the Apple identity token, including its signature, and returns its payload.
     * Returns null if the signature, "iss", "aud", or expiry is invalid.
     */
    public function decodeIdToken($idToken)
    {
        try {
            $jwks = $this->jwks ?? $this->getApplePublicKeys();
            if (empty($jwks['keys'])) {
                throw new \Exception('Apple public keys are not available');
            }

            $payload = (array) JWT::decode((string) $idToken, JWK::parseKeySet($jwks, 'RS256'));

            if (($payload['iss'] ?? null) !== self::ISSUER) {
                throw new \Exception('Invalid issuer in ID token');
            }

            $audiences = array_filter(array_merge([$this->clientId], (array) $this->allowedAudiences));
            if (empty($audiences) || !in_array($payload['aud'] ?? null, $audiences, true)) {
                throw new \Exception('Invalid audience in ID token');
            }

            return $payload;
        } catch (\Throwable $e) {
            Yii::error('Apple ID token verification error: ' . $e->getMessage(), 'oauth');
            return null;
        }
    }

    /**
     * Fetches Apple's public keys for token verification
     */
    public function getApplePublicKeys()
    {
        $cache = Yii::$app->has('cache') ? Yii::$app->cache : null;
        $cached = $cache ? $cache->get('auth.apple.jwks') : false;
        if (is_array($cached) && !empty($cached['keys'])) {
            return $cached;
        }

        $client = new Client();
        $response = $client->createRequest()
            ->setMethod('GET')
            ->setUrl('https://appleid.apple.com/auth/keys')
            ->send();

        if ($response->isOk && !empty($response->data['keys'])) {
            if ($cache) {
                $cache->set('auth.apple.jwks', $response->data, 3600);
            }
            return $response->data;
        }

        return null;
    }
}