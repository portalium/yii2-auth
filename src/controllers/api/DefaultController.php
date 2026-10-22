<?php

namespace portalium\auth\controllers\api;

use Exception;
use Yii;
use yii\filters\ContentNegotiator;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\Cors;
use yii\filters\RateLimiter;
use yii\web\Response;
use portalium\auth\models\LoginForm;
use portalium\auth\models\SignupForm;
use portalium\user\models\User;
use portalium\auth\components\OAuthClient;
use portalium\auth\components\AppleJWTHelper;
use portalium\auth\components\AccountStatus;
use portalium\auth\components\ApiAccess;
use portalium\auth\components\OAuthAccount;
use portalium\auth\models\PasswordResetRequestForm;
use portalium\auth\models\ResetPasswordForm;
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

        // profile: Bearer API key authentication; the `novavision login` CLI uses this
        $behaviors['authenticator'] = [
            'class' => HttpBearerAuth::class,
            'only' => ['profile'],
        ];
        // The CORS preflight (OPTIONS) must be answered before authentication runs
        $behaviors = ['corsFilter' => $behaviors['corsFilter']] + $behaviors;

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
        if (!ApiAccess::loginAllowed()) {
            return $this->disabled(ApiAccess::LOGIN_DISABLED, ApiAccess::loginDisabledMessage());
        }
        $model = new LoginForm();

        if ($model->load(Yii::$app->request->getBodyParams(), '') && $model->login()) {
            return [
                'success' => true,
                'status' => AccountStatus::ACTIVE,
                'message' => 'Login successful',
                'user' => $this->userFields(Yii::$app->user->identity, true),
            ];
        }

        if ($model->getAccountStatus() !== null) {
            Yii::$app->response->statusCode = 403;
            $message = (string) $model->getAccountMessage();
            return ['success' => false] + $this->statusFields($model->getAccountStatus(), $message)
                + ['errors' => ['password' => [$message]]];
        }

        Yii::$app->response->statusCode = 401;
        return [
            'success' => false,
            'message' => 'Invalid credentials',
            'errors' => $model->getErrors()
        ];
    }

    /**
     * Register new user.
     * The "invitation" body param is the invitation link's token (decision 28); signing up with the invited
     * address activates the account immediately.
     *
     * @return array
     */
    public function actionSignup()
    {
        if (!ApiAccess::signupAllowed()) {
            return $this->disabled(ApiAccess::SIGNUP_DISABLED, ApiAccess::signupDisabledMessage());
        }
        $model = new SignupForm();

        if ($model->load(Yii::$app->request->getBodyParams(), '') && ($user = $model->signup(false))) {
            if ((int) $user->status !== User::STATUS_ACTIVE) {
                return ['success' => true] + $this->inactiveFields($user) + ['user' => $this->userFields($user, false)];
            }

            Yii::$app->user->login($user, 3600 * 24 * 30);

            return [
                'success' => true,
                'status' => AccountStatus::ACTIVE,
                'message' => 'Registration successful',
                'user' => $this->userFields($user, true),
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
        if (!ApiAccess::loginAllowed()) {
            return $this->disabled(ApiAccess::LOGIN_DISABLED, ApiAccess::loginDisabledMessage());
        }
        $idToken = Yii::$app->request->getBodyParam('id_token');
        if (empty($idToken)) {
            Yii::$app->response->statusCode = 400;
            return [
                'success' => false,
                'message' => 'ID token is required'
            ];
        }

        try {
            $client = Yii::createObject([
                'class' => OAuthClient::class,
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
        if (!ApiAccess::loginAllowed()) {
            return $this->disabled(ApiAccess::LOGIN_DISABLED, ApiAccess::loginDisabledMessage());
        }
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
                'created_at' => $user->date_create,
                'updated_at' => $user->date_update,
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
                if ($model->request()) {
                    return [
                        'message' => PasswordResetRequestForm::sentMessage(),
                        'success' => true
                    ];
                } else {
                    return $this->error(['PasswordReset' => 'Sorry, we are unable to reset password for the provided email address.']);
                }
            } else {
                return $this->modelError($model);
            }
        } else {
            return $this->error(['PasswordResetRequest' => 'Email (email) is required.']);
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
        $token = $bodyParams['token'] ?? null;
        $password = $bodyParams['password'] ?? null;

        if (!$token) {
            return $this->error(['ResetPassword' => 'Token (token) is required.']);
        }

        if (!$password) {
            return $this->error(['ResetPassword' => 'Password (password) is required.']);
        }

        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidParamException $e) {
            return $this->error(['ResetPassword' => $e->getMessage()]);
        }

        $model->password = $password;
        if ($model->validate() && $model->resetPassword()) {
            return [
                'message' => 'New password saved successfully.',
                'success' => true
            ];
        } else {
            return $this->modelError($model);
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
        $appleHelper = Yii::createObject([
            'class' => AppleJWTHelper::class,
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
            'email_verified' => $tokenData['email_verified'] ?? false,
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
     * Sign in or sign up via Google/Apple (OAuthAccount). A new account follows the auth::user_status setting;
     * an inactive account is never signed in and gets no access_token. A newly created account returns 200 +
     * "pending_approval"; an existing inactive account returns 403.
     *
     * @param array $userData
     * @param string $provider
     * @return array
     */
    private function processOAuthAuthentication($userData, $provider)
    {
        $account = OAuthAccount::resolve($userData, ApiAccess::signupAllowed());
        $user = $account->user;
        $refusals = [
            OAuthAccount::REASON_EMAIL_NOT_VERIFIED => OAuthAccount::REASON_EMAIL_NOT_VERIFIED,
            OAuthAccount::REASON_SIGNUP_DISABLED => ApiAccess::SIGNUP_DISABLED,
        ];
        if ($user === null && isset($refusals[$account->reason])) {
            Yii::$app->response->statusCode = 403;
            return [
                'success' => false,
                'status' => $refusals[$account->reason],
                'message' => (string) $account->message,
                'errors' => $account->errors,
                'is_new_user' => false,
            ];
        }
        if ($user === null) {
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'message' => 'Failed to create user account',
                'errors' => $account->errors,
            ];
        }

        if ((int) $user->status !== User::STATUS_ACTIVE) {
            if (!$account->isNew) {
                Yii::$app->response->statusCode = 403;
                return ['success' => false] + $this->inactiveFields($user) + ['is_new_user' => false];
            }
            return ['success' => true] + $this->inactiveFields($user)
                + ['is_new_user' => true, 'user' => $this->userFields($user, false)];
        }

        Yii::$app->user->login($user, 3600 * 24 * 30);

        return [
            'success' => true,
            'status' => AccountStatus::ACTIVE,
            'message' => $account->isNew ? 'Account created and logged in with ' . $provider : 'Login successful with ' . $provider,
            'is_new_user' => $account->isNew,
            'user' => $this->userFields($user, true),
        ];
    }

    /**
     * API signup or login disabled via setting (decision 18): 403 + machine-readable status + message.
     */
    private function disabled(string $status, string $message): array
    {
        Yii::$app->response->statusCode = 403;

        return ['success' => false, 'status' => $status, 'message' => $message];
    }

    /**
     * Response fields for an inactive account (see statusFields).
     */
    private function inactiveFields(User $user): array
    {
        return $this->statusFields(AccountStatus::of($user), AccountStatus::inactiveMessage($user));
    }

    /**
     * Machine-readable status (AccountStatus constants) and the user-facing message. A pending account also
     * returns auth::approval_contact (null if empty); the message already includes that address.
     */
    private function statusFields(string $status, string $message): array
    {
        $fields = ['status' => $status, 'message' => $message];
        if ($status === AccountStatus::PENDING_APPROVAL) {
            $fields['approval_contact'] = AccountStatus::approvalContact();
        }

        return $fields;
    }

    /**
     * User fields in the response; access_token is only included for an active account that is signed in.
     */
    private function userFields(User $user, bool $withToken): array
    {
        $fields = [
            'id' => $user->id,
            'email' => $user->email,
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ];
        if ($withToken) {
            $fields['access_token'] = $user->access_token;
        }

        return $fields;
    }
}
