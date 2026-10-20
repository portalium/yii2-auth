<?php
namespace portalium\auth\components;

use Yii;
use yii\base\Component;
use Google_Client;

/**
 * OAuth client for ID token verification and user authentication
 * Supports multiple client IDs for Web, Android, and iOS applications
 */
class OAuthClient extends Component
{
    public $clientId;
    public $clientIds = []; // Array of allowed client IDs for multi-platform support
    public $provider = 'google';

    /**
     * Verify Google ID token and extract user data
     * Supports multiple client IDs for Web, Android and iOS platforms
     * @param string $idToken ID token from Google OAuth
     * @return array|false User data array or false on failure
     */
    public function verifyIdToken($idToken)
    {
        try {
            // Prepare allowed client IDs
            $allowedClientIds = $this->getAllowedClientIds();
            
            if (empty($allowedClientIds)) {
                Yii::error('No client IDs configured for OAuth verification', 'oauth');
                return false;
            }

            // Parse token to get audience
            $parts = explode('.', $idToken);
            if (count($parts) != 3) {
                Yii::error('Invalid ID token format', 'oauth');
                return false;
            }
            
            $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);
            
            // Check if token audience is in our allowed list
            $tokenAudience = $payload['aud'] ?? '';
            if (!in_array($tokenAudience, $allowedClientIds)) {
                Yii::error("Token audience '{$tokenAudience}' not in allowed client IDs: " . implode(', ', $allowedClientIds), 'oauth');
                return false;
            }

            // Basic token validation
            $currentTime = time();
            $tokenExp = $payload['exp'] ?? 0;
            
            if ($tokenExp < ($currentTime - 300)) {
                Yii::error('Token expired', 'oauth');
                return false;
            }

            // Try Google_Client verification with each allowed client ID
            $verifiedPayload = $this->verifyWithGoogleClient($idToken, $allowedClientIds);
            
            // A token whose signature Google cannot verify is not accepted; an unsigned "manual
            // verification" fallback used to let a forged token take over an account.

            if (!$verifiedPayload) {
                Yii::error('Token verification failed with all methods', 'oauth');
                return false;
            }

            return $this->extractUserData($verifiedPayload);
            
        } catch (\Exception $e) {
            Yii::error('Google ID token verification error: ' . $e->getMessage(), 'oauth');
            return false;
        }
    }

    /**
     * Get array of allowed client IDs
     * @return array
     */
    private function getAllowedClientIds()
    {
        $clientIds = [];
        
        // Add single client ID if set
        if (!empty($this->clientId)) {
            $clientIds[] = $this->clientId;
        }
        
        // Add multiple client IDs if set
        if (!empty($this->clientIds) && is_array($this->clientIds)) {
            $clientIds = array_merge($clientIds, $this->clientIds);
        }
        
        return array_unique($clientIds);
    }

    /**
     * Verify token using Google_Client with multiple client IDs
     * @param string $idToken
     * @param array $clientIds
     * @return array|false
     */
    private function verifyWithGoogleClient($idToken, $clientIds)
    {
        foreach ($clientIds as $clientId) {
            try {
                $client = $this->createGoogleClient($clientId);
                
                $originalErrorReporting = error_reporting();
                error_reporting($originalErrorReporting & ~E_DEPRECATED);
                
                $verifiedPayload = $client->verifyIdToken($idToken);
                error_reporting($originalErrorReporting);
                
                if ($verifiedPayload) {                    
                    // Handle different return types from Google_Client
                    if (is_object($verifiedPayload)) {
                        if (method_exists($verifiedPayload, 'getPayload')) {
                            return call_user_func([$verifiedPayload, 'getPayload']);
                        } elseif (method_exists($verifiedPayload, 'getAttributes')) {
                            return call_user_func([$verifiedPayload, 'getAttributes']);
                        } else {
                            return (array) $verifiedPayload;
                        }
                    }
                    
                    return $verifiedPayload;
                }
                
            } catch (\Exception $e) {
                Yii::warning("Google_Client verification failed for client ID {$clientId}: " . $e->getMessage(), 'oauth');
                continue;
            }
        }
        
        return false;
    }

    /**
     * Creates a Google_Client; overridden in a subclass in tests to avoid hitting the network.
     * @param string $clientId
     * @return Google_Client
     */
    protected function createGoogleClient($clientId)
    {
        $client = new Google_Client();
        $client->setClientId($clientId);
        return $client;
    }

    /**
     * Extract user data from verified payload
     * @param array $payload
     * @return array|false
     */
    private function extractUserData($payload)
    {
        // Handle nested payload structure
        $actualPayload = $payload;
        if (isset($payload['payload']) && is_array($payload['payload'])) {
            $actualPayload = $payload['payload'];
        }

        $userData = [
            'google_id' => $actualPayload['sub'] ?? null,
            'email' => $actualPayload['email'] ?? null,
            'email_verified' => $actualPayload['email_verified'] ?? false,
            'name' => $actualPayload['name'] ?? '',
            'given_name' => $actualPayload['given_name'] ?? '',
            'family_name' => $actualPayload['family_name'] ?? '',
            'picture' => $actualPayload['picture'] ?? '',
            'locale' => $actualPayload['locale'] ?? '',
            'client_id' => $actualPayload['aud'] ?? null, // Which client ID was used
        ];

        if (empty($userData['email']) || empty($userData['google_id'])) {
            Yii::error('Missing required fields in verified token payload', 'oauth');
            return false;
        }

        return $userData;
    }
}
