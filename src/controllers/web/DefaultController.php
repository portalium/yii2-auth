<?php

namespace portalium\auth\controllers\web;

use Exception;
use InvalidArgumentException;
use portalium\site\models\ResendVerificationEmailForm;
use Yii;
use portalium\auth\Module;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use portalium\auth\models\LoginForm;
use yii\web\BadRequestHttpException;
use portalium\auth\models\SignupForm;
use portalium\user\models\User;
use portalium\auth\models\ResetPasswordForm;
use portalium\web\Controller as WebController;
use portalium\auth\models\PasswordResetRequestForm;
use portalium\auth\models\VerifyEmailForm;
use portalium\auth\components\OAuthClient;
use portalium\auth\components\AppleJWTHelper;

/**
 * Authentication controller for web interface
 */
class DefaultController extends WebController
{
    public $layout = '@portalium/theme/layouts/main';

    /**
     * Configure access control and verb filters
     * @return array
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => ['logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                    [
                        'actions' => ['index', 'login', 'login-google', 'callback-google', 'login-apple', 'callback-apple','signup', 
                        'request-password-reset', 'reset-password', 'resend-verification-email', 'verify-email'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Configure captcha action
     * @return array
     */
    public function actions()
    {
        return [
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    /**
     * Setup layout and CSRF validation before action execution
     * @param \yii\base\Action $action
     * @return bool
     */
    public function beforeAction($action)
    {
        if (Yii::$app->setting->getValue('auth::layout') != '')
            $this->layout = '@portalium/theme/layouts/' . Yii::$app->setting->getValue('auth::layout');

        if ($action->id === 'popup-callback-apple' || $action->id === 'callback-apple') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Redirect to login page
     * @return \yii\web\Response
     */
    public function actionIndex()
    {  
        return $this->redirect('login');
    }

    /**
     * Display login form and handle authentication
     * @return string|\yii\web\Response
     */
    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            if (Yii::$app->setting->getValue('app::language')) {
                Yii::$app->session->set('lang', Yii::$app->setting->getValue('app::language'));
            }
            return $this->goBack();
        } else {
            return $this->render('login', [
                'model' => $model,
            ]);
        }
    }

    /**
     * Initiate Google OAuth authentication
     * @return \yii\web\Response
     * @throws \yii\web\NotFoundHttpException
     */
    public function actionLoginGoogle()
    {
        if (!Yii::$app->setting->getValue('auth::googleEnabled')) {
            throw new \yii\web\NotFoundHttpException('Google login is not available.');
        }

        $params = [
            'client_id' => Yii::$app->setting->getValue('auth::googleClientId'),
            'redirect_uri' => Yii::$app->urlManager->createAbsoluteUrl(['/auth/default/callback-google']),
            'response_type' => 'code',
            'scope' => 'email profile',
            'access_type' => 'offline',
            'prompt' => 'select_account',
        ];

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        return $this->redirect($authUrl);
    }

    /**
     * Handle Google OAuth callback and process authentication
     * @param string|null $code Authorization code from Google
     * @return \yii\web\Response
     */
    public function actionCallbackGoogle($code = null)
    {
        if (!$code) {
            Yii::$app->session->setFlash('error', 'Google authorization failed.');
            return $this->redirect(['/auth/default/login']);
        }

        try {
            $tokenData = $this->exchangeGoogleCode($code);

            if (!$tokenData || empty($tokenData['id_token'])) {
                Yii::$app->session->setFlash('error', 'Failed to get Google tokens.');
                return $this->redirect(['/auth/default/login']);
            }

            $client = new OAuthClient([
                'clientId' => Yii::$app->setting->getValue('auth::googleClientId'),
                'provider' => 'google',
            ]);

            $userData = $client->verifyIdToken($tokenData['id_token']);

            if (!$userData) {
                Yii::$app->session->setFlash('error', 'Google authentication failed.');
                return $this->redirect(['/auth/default/login']);
            }

            return $this->processUserAuthentication($userData, 'Google');
        } catch (\Exception $e) {
            Yii::error('Google OAuth error: ' . $e->getMessage(), 'oauth');
            Yii::$app->session->setFlash('error', 'Google authentication error.');
            return $this->redirect(['/auth/default/login']);
        }
    }

    /**
     * Initiate Apple OAuth authentication
     * @return \yii\web\Response
     * @throws \yii\web\NotFoundHttpException
     */
    public function actionLoginApple()
    {
        if (!Yii::$app->setting->getValue('auth::appleEnabled')) {
            throw new \yii\web\NotFoundHttpException('Apple login is not available.');
        }

        $params = [
            'client_id' => Yii::$app->setting->getValue('auth::appleClientId'),
            'redirect_uri' => Yii::$app->urlManager->createAbsoluteUrl(['/auth/default/callback-apple']),
            'response_type' => 'code',
            'scope' => 'name email',
            'response_mode' => 'form_post',
        ];

        $authUrl = 'https://appleid.apple.com/auth/authorize?' . http_build_query($params);
        return $this->redirect($authUrl);
    }

    /**
     * Handle Apple OAuth callback and process authentication
     * @return \yii\web\Response
     */
    public function actionCallbackApple()
    {
        Yii::$app->response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        Yii::$app->response->headers->set('Cross-Origin-Embedder-Policy', 'unsafe-none');

        $code = Yii::$app->request->post('code');
        $idToken = Yii::$app->request->post('id_token');
        $user = Yii::$app->request->post('user');
        $error = Yii::$app->request->post('error');

        if ($error) {
            Yii::$app->session->setFlash('error', 'Apple OAuth error: ' . $error);
            return $this->redirect(['/auth/default/login']);
        }

        if (!$code && !$idToken) {
            Yii::$app->session->setFlash('error', 'Apple authorization failed.');
            return $this->redirect(['/auth/default/login']);
        }

        try {
            if ($idToken) {
                $userData = $this->processAppleIdToken($idToken, $user);
            } else {
                $tokenData = $this->exchangeAppleCode($code);
                if ($tokenData && isset($tokenData['id_token'])) {
                    $userData = $this->processAppleIdToken($tokenData['id_token'], $user);
                } else {
                    throw new \Exception('Failed to get Apple ID token');
                }
            }

            if (!$userData) {
                Yii::$app->session->setFlash('error', 'Apple authentication failed.');
                return $this->redirect(['/auth/default/login']);
            }

            // Process user login/signup
            return $this->processUserAuthentication($userData, 'Apple');
        } catch (\Exception $e) {
            Yii::error('Apple OAuth error: ' . $e->getMessage(), 'oauth');
            Yii::$app->session->setFlash('error', 'Apple authentication error.');
            return $this->redirect(['/auth/default/login']);
        }
    }

    /**
     * Verify user email address with token
     * @param string $token Verification token
     * @return \yii\web\Response
     * @throws BadRequestHttpException
     */
    public function actionVerifyEmail($token)
    {
        try {
            $model = new VerifyEmailForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if (($user = $model->verifyEmail()) && Yii::$app->user->login($user)) {
            $userId = Yii::$app->user->id;
            $userModel = User::findOne($userId);
            $userModel->email_verify = User::EMAIL_VERIFY;
            $userModel->status = Yii::$app->setting->getValue('auth::user_status');
            $userModel->save();

            return $this->goHome();
        }
        Yii::$app->session->setFlash('error', 'Sorry, we are unable to verify your account with provided token.');
        return $this->goHome();
    }

    /**
     * Resend email verification link
     * @return string|\yii\web\Response
     */
    public function actionResendVerificationEmail()
    {
        $model = new ResendVerificationEmailForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->session->setFlash('success', 'Check your email for further instructions.');
                return $this->goHome();
            }
            Yii::$app->session->setFlash('error', 'Sorry, we are unable to resend verification email for the provided email address.');
        }

        return $this->render('resendVerificationEmail', [
            'model' => $model
        ]);
    }

    /**
     * Logout user and redirect to home
     * @return \yii\web\Response
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }

    /**
     * Display signup form and handle registration
     * @return string|\yii\web\Response
     */
    public function actionSignup()
    {
        if (Yii::$app->setting->getValue('auth::web_signup')) {
            $model = new SignupForm();
            if ($model->load(Yii::$app->request->post())) {
                if ($user = $model->signup()) {
                    if (Yii::$app->getUser()->login($user)) {
                        return $this->goHome();
                    }
                }
            }
            return $this->render('signup', [
                'model' => $model,
            ]);
        }
        return $this->goHome();
    }

    /**
     * Request password reset email
     * @return string|\yii\web\Response
     */
    public function actionRequestPasswordReset()
    {
        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->session->addFlash('success', Module::t('Check your email for further instructions.'));
                return $this->goHome();
            } else {
                Yii::$app->session->addFlash('error', Module::t('Sorry, we are unable to reset password for the provided email address.'));
            }
        }

        return $this->render('requestPasswordResetToken', [
            'model' => $model,
        ]);
    }

    /**
     * Reset password with token
     * @param string $token Password reset token
     * @return string|\yii\web\Response
     * @throws BadRequestHttpException
     */
    public function actionResetPassword($token)
    {
        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->resetPassword()) {
            Yii::$app->session->addFlash('success', Module::t('New password saved.'));
            return $this->goHome();
        }

        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

    /**
     * Exchange Google authorization code for access tokens
     * @param string $code Authorization code from Google
     * @return array|null Token data or null on failure
     */
    private function exchangeGoogleCode($code)
    {
        $client = new \yii\httpclient\Client();

        $data = [
            'code' => $code,
            'client_id' => Yii::$app->setting->getValue('auth::googleClientId'),
            'client_secret' => Yii::$app->setting->getValue('auth::googleClientSecret'),
            'redirect_uri' => Yii::$app->urlManager->createAbsoluteUrl(['/auth/default/callback-google']),
            'grant_type' => 'authorization_code',
        ];

        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl('https://oauth2.googleapis.com/token')
            ->setData($data)
            ->send();

        return $response->isOk ? $response->data : null;
    }

    /**
     * Exchange Apple authorization code for access tokens
     * @param string $code Authorization code from Apple
     * @return array|null Token data or null on failure
     */
    private function exchangeAppleCode($code)
    {
        $appleHelper = new AppleJWTHelper([
            'teamId' => Yii::$app->setting->getValue('auth::appleTeamId'),
            'keyId' => Yii::$app->setting->getValue('auth::appleKeyId'),
            'clientId' => Yii::$app->setting->getValue('auth::appleClientId'),
        ]);

        $client = new \yii\httpclient\Client();

        $data = [
            'code' => $code,
            'client_id' => Yii::$app->setting->getValue('auth::appleClientId'),
            'client_secret' => $appleHelper->createClientSecret(),
            'redirect_uri' => Yii::$app->urlManager->createAbsoluteUrl(['/auth/default/callback-apple']),
            'grant_type' => 'authorization_code',
        ];

        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl('https://appleid.apple.com/auth/token')
            ->setData($data)
            ->send();

        return $response->isOk ? $response->data : null;
    }

    /**
     * Process Apple ID Token
     */
    /**
     * Process Apple ID token and extract user data
     * @param string $idToken Apple ID token
     * @param string|null $userParam Additional user data from Apple
     * @return array|false User data array or false on failure
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
     * Process OAuth user authentication - login existing or create new user
     * @param array $userData User data from OAuth provider
     * @param string $provider OAuth provider name
     * @return \yii\web\Response
     */
    private function processUserAuthentication($userData, $provider)
    {
        $email = $userData['email'];
        $user = User::findOne(['email' => $email, 'status' => User::STATUS_ACTIVE]);

        if ($user) {
            Yii::$app->user->login($user, 3600 * 24 * 30);
            Yii::$app->session->setFlash('success', $provider . ' ile giriş yapıldı: ' . $email);
            return $this->goHome();
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

                Yii::$app->session->setFlash('success', $provider . ' ile hesap oluşturuldu ve giriş yapıldı: ' . $email);
                return $this->goHome();
            } else {
                Yii::$app->session->setFlash('error', 'Kullanıcı oluşturulamadı: ' . print_r($model->getErrors(), true));
                return $this->redirect(['/auth/default/login']);
            }
        }
    }
}