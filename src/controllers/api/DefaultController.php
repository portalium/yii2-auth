<?php

namespace portalium\auth\controllers\api;

use Exception;
use Yii;
use yii\filters\ContentNegotiator;
use yii\filters\Cors;
use yii\filters\RateLimiter;
use yii\web\Response;
use portalium\auth\models\LoginForm;
use portalium\auth\models\SignupForm;
use portalium\user\models\User;
use portalium\auth\components\OAuthClient;
use portalium\auth\components\AppleJWTHelper;
use portalium\auth\models\PasswordResetRequestForm;
use portalium\auth\models\ResetPasswordForm;
use portalium\helpers\RestHelper;
use yii\base\InvalidParamException;
use yii\rest\Controller;

class DefaultController extends Controller
{
    /**
     * API behaviors
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        
        // CORS support for mobile apps
        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => true,
                'Access-Control-Max-Age' => 86400,
            ],
        ];

        // Content negotiation - always JSON
        $behaviors['contentNegotiator'] = [
            'class' => ContentNegotiator::class,
            'formats' => [
                'application/json' => Response::FORMAT_JSON,
            ],
        ];

        // Rate limiting for API endpoints
        $behaviors['rateLimiter'] = [
            'class' => RateLimiter::class,
            'only' => ['login', 'signup', 'google-signin', 'apple-signin', 'request-password-reset', 'reset-password'],
        ];

        return $behaviors;
    }

    /**
     * Login with email and password
     * 
     * @return array
     */
    public function actionLogin()
    {
        $model = new LoginForm();
        
        if ($model->load(Yii::$app->request->getBodyParams(), '') && $model->login()) {
            $user = Yii::$app->user->identity;
            
            return [
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'access_token' => $user->access_token,
                ]
            ];
        }

        Yii::$app->response->statusCode = 401;
        return [
            'success' => false,
            'message' => 'Invalid credentials',
            'errors' => $model->getErrors()
        ];
    }

    /**
     * Register new user
     * 
     * @return array
     */
    public function actionSignup()
    {
        $model = new SignupForm();
        
        if ($model->load(Yii::$app->request->getBodyParams(), '') && ($user = $model->signup())) {
            // Auto-activate for mobile registrations
            $user->status = User::STATUS_ACTIVE;
            $user->email_verify = User::EMAIL_VERIFY;
            $user->save(false);
            
            // Auto-login after signup
            Yii::$app->user->login($user, 3600 * 24 * 30); // 30 days
            
            return [
                'success' => true,
                'message' => 'Registration successful',
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'access_token' => $user->access_token,
                ]
            ];
        }

        Yii::$app->response->statusCode = 422;
        return [
            'success' => false,
            'message' => 'Registration failed',
            'errors' => $model->getErrors()
        ];
    }

    /**
     * Google Sign-In with ID Token
     * Supports Web, Android and iOS client IDs
     * 
     * @return array
     */
    public function actionGoogleSignin()
    {
        $idToken = Yii::$app->request->getBodyParam('id_token');
        if (empty($idToken)) {
            Yii::$app->response->statusCode = 400;
            return [
                'success' => false,
                'message' => 'ID token is required'
            ];
        }

        try {
            $client = new OAuthClient([
                'clientId' => Yii::$app->setting->getValue('auth::googleClientId'),
                'clientIds' => [
                    Yii::$app->setting->getValue('auth::googleClientId'),
                    'xxxxxxxxxxxx-xxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com'
                ],
                'provider' => 'google',
            ]);

            $userData = $client->verifyIdToken($idToken);

            if (!$userData) {
                Yii::$app->response->statusCode = 401;
                return [
                    'success' => false,
                    'message' => 'Invalid Google ID token'
                ];
            }

            return $this->processOAuthAuthentication($userData, 'Google');

        } catch (Exception $e) {
            Yii::error('Google ID token API error: ' . $e->getMessage(), 'oauth');
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'message' => 'Authentication failed'
            ];
        }
    }

    /**
     * Apple Sign-In with ID Token
     * 
     * @return array
     */
    public function actionAppleSignin()
    {
        $idToken = Yii::$app->request->getBodyParam('id_token');
        $userParam = Yii::$app->request->getBodyParam('user');
        
        if (empty($idToken)) {
            Yii::$app->response->statusCode = 400;
            return [
                'success' => false,
                'message' => 'ID token is required'
            ];
        }

        try {
            $userData = $this->processAppleIdToken($idToken, $userParam);
            
            if (!$userData) {
                Yii::$app->response->statusCode = 401;
                return [
                    'success' => false,
                    'message' => 'Invalid Apple ID token'
                ];
            }

            return $this->processOAuthAuthentication($userData, 'Apple');

        } catch (Exception $e) {
            Yii::error('Apple ID token API error: ' . $e->getMessage(), 'oauth');
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'message' => 'Authentication failed'
            ];
        }
    }

    /**
     * Get current user profile
     * 
     * @return array
     */
    public function actionProfile()
    {
        if (Yii::$app->user->isGuest) {
            Yii::$app->response->statusCode = 401;
            return [
                'success' => false,
                'message' => 'Authentication required'
            ];
        }

        $user = Yii::$app->user->identity;
        
        return [
            'success' => true,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]
        ];
    }

    /**
     * Request password reset - sends email with reset token
     * 
     * @return array
     */
    public function actionRequestPasswordReset()
    {
        $model = new PasswordResetRequestForm();
        
        if ($model->load(Yii::$app->request->getBodyParams(), '')) {
            if ($model->validate()) {
                if ($model->sendEmail()) {
                    return [
                        'message' => 'Check your email for further instructions.',
                        'success' => true
                    ];
                } else {
                    return RestHelper::error(['PasswordReset' => 'Sorry, we are unable to reset password for the provided email address.']);
                }
            } else {
                return RestHelper::modelError($model);
            }
        } else {
            return RestHelper::error(['PasswordResetRequest' => 'Email (email) is required.']);
        }
    }

    /**
     * Reset password with token and new password
     * 
     * @return array
     */
    public function actionResetPassword()
    {
        $bodyParams = Yii::$app->request->getBodyParams();
        $token = $bodyParams['token'] ?? Yii::$app->request->get('token');
        $password = $bodyParams['password'] ?? Yii::$app->request->get('password');

        if (!$token) {
            return RestHelper::error(['ResetPassword' => 'Token (token) is required.']);
        }

        if (!$password) {
            return RestHelper::error(['ResetPassword' => 'Password (password) is required.']);
        }

        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidParamException $e) {
            return RestHelper::error(['ResetPassword' => $e->getMessage()]);
        }

        $model->password = $password;
        if ($model->validate() && $model->resetPassword()) {
            return [
                'message' => 'New password saved successfully.',
                'success' => true
            ];
        } else {
            return RestHelper::modelError($model);
        }
    }

    /**
     * Process Apple ID Token
     * 
     * @param string $idToken
     * @param mixed $userParam
     * @return array|false
     */
    private function processAppleIdToken($idToken, $userParam = null)
    {
        $appleHelper = new AppleJWTHelper([
            'teamId' => Yii::$app->setting->getValue('auth::appleTeamId'),
            'keyId' => Yii::$app->setting->getValue('auth::appleKeyId'),
            'clientId' => Yii::$app->setting->getValue('auth::appleClientId'),
        ]);

        $tokenData = $appleHelper->decodeIdToken($idToken);
        if (!$tokenData || empty($tokenData['email'])) {
            return false;
        }

        $userData = [
            'apple_id' => $tokenData['sub'] ?? null,
            'email' => $tokenData['email'],
            'email_verified' => true,
            'name' => '',
            'given_name' => '',
            'family_name' => '',
            'picture' => '',
        ];

        if ($userParam) {
            $user = is_string($userParam) ? json_decode($userParam, true) : $userParam;
            if (isset($user['name'])) {
                $userData['given_name'] = $user['name']['firstName'] ?? '';
                $userData['family_name'] = $user['name']['lastName'] ?? '';
                $userData['name'] = trim($userData['given_name'] . ' ' . $userData['family_name']);
            }
        }

        return $userData;
    }

    /**
     * Process OAuth authentication (login/signup)
     * 
     * @param array $userData
     * @param string $provider
     * @return array
     */
    private function processOAuthAuthentication($userData, $provider)
    {
        $email = $userData['email'];
        
        $user = User::findOne(['email' => $email, 'status' => User::STATUS_ACTIVE]);
        
        if ($user) {
            Yii::$app->user->login($user, 3600 * 24 * 30); // 30 days

            return [
                'success' => true,
                'message' => 'Login successful with ' . $provider,
                'is_new_user' => false,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'access_token' => Yii::$app->user->identity->access_token,
                ]
            ];
        } else {
            $model = new SignupForm();
            $model->username = explode('@', $email)[0];
            $model->email = $email;
            $model->password = Yii::$app->security->generateRandomString(12);
            $model->password_confirm = $model->password;
            $model->first_name = $userData['given_name'] ?? '';
            $model->last_name = $userData['family_name'] ?? '';
            
            if ($newUser = $model->signup()) {
                $newUser->status = User::STATUS_ACTIVE;
                $newUser->email_verify = User::EMAIL_VERIFY;
                $newUser->save(false);
                
                Yii::$app->user->login($newUser, 3600 * 24 * 30);
                
                return [
                    'success' => true,
                    'message' => 'Account created and logged in with ' . $provider,
                    'is_new_user' => true,
                    'user' => [
                        'id' => $newUser->id,
                        'email' => $newUser->email,
                        'username' => $newUser->username,
                        'first_name' => $newUser->first_name,
                        'last_name' => $newUser->last_name,
                        'access_token' => Yii::$app->user->identity->access_token,
                    ]
                ];
            } else {
                Yii::$app->response->statusCode = 500;
                return [
                    'success' => false,
                    'message' => 'Failed to create user account',
                    'errors' => $model->getErrors()
                ];
            }
        }
    }
}
