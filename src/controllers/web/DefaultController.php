<?php

namespace portalium\auth\controllers\web;

use Exception;
use yii\base\InvalidArgumentException;
use portalium\auth\models\ResendVerificationEmailForm;
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
use portalium\auth\components\AuthNotice;
use portalium\auth\components\AccountStatus;
use portalium\auth\components\AuthLogger;
use portalium\auth\models\AuthLog;
use portalium\auth\components\OAuthAccount;
use portalium\auth\components\OAuthClient;
use portalium\auth\components\OAuthLink;
use portalium\auth\components\OAuthLoginState;
use portalium\auth\components\AppleJWTHelper;

/**
 * Authentication controller for web interface
 */
class DefaultController extends WebController
{
    public $layout = '@portalium/theme/layouts/main';

    /** The profile page, where Google/Apple are linked and removed. */
    private const PROFILE_ROUTE = '/site/profile/edit';

    /**
     * Configure access control and verb filters
     * @return array
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'denyCallback' => function ($rule, $action) {
                    if (!Yii::$app->user->isGuest) {
                        return $this->goHome();
                    }
                    return $this->redirect(['/auth/default/login']);
                },
                'rules' => [
                    [
                        'actions' => ['logout', 'connect-google', 'connect-apple', 'disconnect'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                    [
                        'actions' => ['index', 'login', 'login-google', 'login-apple', 'signup',
                        'request-password-reset', 'reset-password', 'resend-verification-email', 'verify-email'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                    [
                        'actions' => ['verify-email', 'resend-verification-email', 'callback-google', 'callback-apple'],
                        'allow' => true,
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['post'],
                    'disconnect' => ['post'],
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

        // sign-in pages are not dark-ready (docs/koyu-tema-plani.md, decision 7.4)
        $this->view->params['colorMode'] = 'light';

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
        }
        if ($model->getAccountStatus() !== null) {
            $type = $model->getAccountStatus() === AccountStatus::INACTIVE ? 'warning' : 'info';
            AuthNotice::set($type, (string) $model->getAccountMessage());
            $model->password = '';
        }

        return $this->render('login', [
            'model' => $model,
        ]);
    }

    /**
     * Initiate Google OAuth authentication
     * @return \yii\web\Response
     * @throws \yii\web\NotFoundHttpException
     */
    public function actionLoginGoogle()
    {
        if (!OAuthLink::enabled(OAuthLink::GOOGLE)) {
            throw new \yii\web\NotFoundHttpException('Google login is not available.');
        }

        return $this->redirect($this->googleAuthUrl(null, OAuthLoginState::begin(OAuthLink::GOOGLE)));
    }

    /**
     * Link Google to the signed-in account (profile, decision 2026-11-04). The OAuth "state" identifies the request.
     * @return \yii\web\Response
     * @throws \yii\web\NotFoundHttpException
     */
    public function actionConnectGoogle()
    {
        if (!OAuthLink::enabled(OAuthLink::GOOGLE)) {
            throw new \yii\web\NotFoundHttpException('Google login is not available.');
        }

        return $this->redirect($this->googleAuthUrl(OAuthLink::beginConnect((int) Yii::$app->user->id, OAuthLink::GOOGLE)));
    }

    /**
     * Handle Google OAuth callback: sign in, or finish linking when the state belongs to a profile connect request
     * @param string|null $code Authorization code from Google
     * @param string $state OAuth state; only sent when linking from the profile
     * @return \yii\web\Response
     */
    public function actionCallbackGoogle($code = null, $state = '')
    {
        $login = Yii::$app->user->isGuest ? OAuthLoginState::take((string) $state, OAuthLink::GOOGLE) : null;
        $connect = $login === null ? OAuthLink::takeConnect((string) $state) : null;
        if ($login === null && $connect === null) {
            return $this->connectFailed(OAuthLink::GOOGLE);
        }
        if (!$code) {
            return $this->oauthFailed($connect, 'Google authorization failed.');
        }

        try {
            $tokenData = $this->exchangeGoogleCode($code, $login['verifier'] ?? null);

            if (!$tokenData || empty($tokenData['id_token'])) {
                return $this->oauthFailed($connect, 'Failed to get Google tokens.');
            }

            $client = Yii::createObject([
                'class' => OAuthClient::class,
                'clientId' => Yii::$app->setting->getValue('auth::googleClientId'),
                'provider' => 'google',
            ]);

            $userData = $client->verifyIdToken($tokenData['id_token']);

            if (!$userData || ($login !== null && !OAuthLoginState::nonceMatches($login, $userData))) {
                return $this->oauthFailed($connect, 'Google authentication failed.');
            }

            return $connect !== null
                ? $this->finishConnect($connect, $userData)
                : $this->processUserAuthentication($userData, 'Google');
        } catch (\Exception $e) {
            Yii::error('Google OAuth error: ' . $e->getMessage(), 'oauth');
            return $this->oauthFailed($connect, 'Google authentication error.');
        }
    }

    /**
     * Initiate Apple OAuth authentication
     * @return \yii\web\Response
     * @throws \yii\web\NotFoundHttpException
     */
    public function actionLoginApple()
    {
        if (!OAuthLink::enabled(OAuthLink::APPLE)) {
            throw new \yii\web\NotFoundHttpException('Apple login is not available.');
        }

        return $this->redirect($this->appleAuthUrl(null, OAuthLoginState::begin(OAuthLink::APPLE)));
    }

    /**
     * Link Apple to the signed-in account (profile). No scope is asked, so Apple returns with a GET ("query") instead
     * of a cross-site POST: the browser then sends the session cookie and the user stays signed in.
     * @return \yii\web\Response
     * @throws \yii\web\NotFoundHttpException
     */
    public function actionConnectApple()
    {
        if (!OAuthLink::enabled(OAuthLink::APPLE)) {
            throw new \yii\web\NotFoundHttpException('Apple login is not available.');
        }

        return $this->redirect($this->appleAuthUrl(OAuthLink::beginConnect((int) Yii::$app->user->id, OAuthLink::APPLE)));
    }

    /**
     * Handle Apple OAuth callback: sign in (form_post), or finish linking from the profile (query, with state)
     * @return \yii\web\Response
     */
    public function actionCallbackApple()
    {
        Yii::$app->response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        Yii::$app->response->headers->set('Cross-Origin-Embedder-Policy', 'unsafe-none');

        $request = Yii::$app->request;
        $code = $request->post('code', $request->get('code'));
        $idToken = $request->post('id_token');
        $user = $request->post('user');
        $error = $request->post('error', $request->get('error'));
        $state = (string) $request->post('state', $request->get('state', ''));
        $login = Yii::$app->user->isGuest ? OAuthLoginState::take($state, OAuthLink::APPLE) : null;
        $connect = $login === null ? OAuthLink::takeConnect($state) : null;
        if ($login === null && $connect === null) {
            return $this->connectFailed(OAuthLink::APPLE);
        }

        if ($error) {
            return $this->oauthFailed($connect, 'Apple OAuth error: ' . $error);
        }

        if (!$code && !$idToken) {
            return $this->oauthFailed($connect, 'Apple authorization failed.');
        }

        try {
            if ($idToken) {
                $userData = $this->processAppleIdToken($idToken, $user, $connect === null);
            } else {
                $tokenData = $this->exchangeAppleCode($code);
                if ($tokenData && isset($tokenData['id_token'])) {
                    $userData = $this->processAppleIdToken($tokenData['id_token'], $user, $connect === null);
                } else {
                    throw new \Exception('Failed to get Apple ID token');
                }
            }

            if (!$userData || ($login !== null && !OAuthLoginState::nonceMatches($login, $userData))) {
                return $this->oauthFailed($connect, 'Apple authentication failed.');
            }

            return $connect !== null
                ? $this->finishConnect($connect, $userData)
                : $this->processUserAuthentication($userData, 'Apple');
        } catch (\Exception $e) {
            Yii::error('Apple OAuth error: ' . $e->getMessage(), 'oauth');
            return $this->oauthFailed($connect, 'Apple authentication error.');
        }
    }

    /**
     * Remove a linked Google/Apple identity from the signed-in account (profile).
     * @return \yii\web\Response
     */
    public function actionDisconnect()
    {
        $provider = (string) Yii::$app->request->post('provider', '');
        $known = in_array($provider, [OAuthLink::GOOGLE, OAuthLink::APPLE], true);
        if ($known && OAuthLink::unlink((int) Yii::$app->user->id, $provider)) {
            Yii::$app->session->addFlash('success', Module::t('{provider} is no longer linked to your account.', [
                'provider' => OAuthLink::label($provider),
            ]));
        }

        return $this->redirect([self::PROFILE_ROUTE]);
    }

    /**
     * Verify user email address with token.
     * Open to guests. The user's status follows auth::user_status (decision 25): Active logs a guest in,
     * Passive waits for administrator approval. An invalid or used link never yields a server error.
     * @param string $token Verification token
     * @return \yii\web\Response
     */
    public function actionVerifyEmail($token)
    {
        try {
            $user = (new VerifyEmailForm($token))->verifyEmail();
        } catch (InvalidArgumentException $e) {
            $user = null;
        }

        if ($user === null) {
            AuthNotice::set('warning', Module::t('This verification link is invalid or has already been used.') . ' '
                . Module::t('Your account may already be verified: try signing in. If it is not, signing in will send you a new link.'));
            return $this->redirect(['/auth/default/login']);
        }

        if ((int) $user->status !== User::STATUS_ACTIVE) {
            AuthNotice::set('info', AccountStatus::approvalMessage(true));
            return $this->redirect(['/auth/default/login']);
        }

        Yii::$app->session->addFlash('success', Module::t('Your email has been verified.'));
        if (Yii::$app->user->isGuest) {
            Yii::$app->user->login($user);
        }
        return $this->goHome();
    }

    /**
     * Resend email verification link.
     * Open to guests; the response is the same whether or not an account exists for the address.
     * @return string|\yii\web\Response
     */
    public function actionResendVerificationEmail()
    {
        $model = new ResendVerificationEmailForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->sendEmail();
            AuthNotice::set('info', Module::t('If an unverified account exists for this address, a new verification link has been sent.'));
            return $this->redirect(['/auth/default/login']);
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
     * Display signup form and handle registration.
     * A signup that arrives from an invitation link (?invitation=<token>, decision 28) carries the token in a
     * hidden form field; a user who signs up with the invited address is activated immediately, signed in, and
     * sent to the invitation page so they can click [Join]. A signup that is activated immediately but does not
     * match an invitation returns to the page it came from (e.g. the invitation page) instead (final review U2).
     * If reCAPTCHA is enabled but no secret key is configured, this is announced when the page loads and [Sign
     * up] is disabled (U11). Immediate activation only happens via the signed link in the email
     * (?invitation=<token>&sig=<signature>, decision 35/49); the signature is also carried in a hidden field.
     * While auth::web_signup is off (Hide), signup is only possible through a valid signed invitation link; an
     * unsigned request is redirected to the home page (decision 40).
     * @return string|\yii\web\Response
     */
    public function actionSignup()
    {
        $model = new SignupForm(['scenario' => SignupForm::SCENARIO_WEB]);
        foreach (['invitation', 'sig'] as $param) {
            $value = Yii::$app->request->get($param);
            if (is_string($value)) {
                $model->$param = $value;
            }
        }
        $posted = $model->load(Yii::$app->request->post());
        if (Yii::$app->setting->getValue('auth::web_signup') || $model->invitationSigned()) {
            if ($posted) {
                if ($user = $model->signup()) {
                    if ((int) $user->status === User::STATUS_ACTIVE && Yii::$app->getUser()->login($user)) {
                        if ($model->invitationMatched()) {
                            return $this->redirect(['/workspace/invitation/accept', 'token' => $model->invitationToken()]);
                        }
                        return $this->goBack();
                    }
                    if (!Yii::$app->setting->getValue('site::verifyEmail')) {
                        AuthNotice::set('info', AccountStatus::approvalMessage());
                    }
                    return $this->redirect(['/auth/default/login']);
                }
                if ($model->hasErrors('verifyCode')) {
                    AuthNotice::set('danger', (string) $model->getFirstError('verifyCode'));
                }
            }
            if (SignupForm::signupBlocked()) {
                AuthNotice::set('danger', SignupForm::blockedMessage());
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
            if ($model->request()) {
                Yii::$app->session->addFlash('success', PasswordResetRequestForm::sentMessage());
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
        $model->scenario = ResetPasswordForm::SCENARIO_WEB;

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
    private function exchangeGoogleCode($code, ?string $codeVerifier = null)
    {
        $client = new \yii\httpclient\Client();

        $data = [
            'code' => $code,
            'client_id' => Yii::$app->setting->getValue('auth::googleClientId'),
            'client_secret' => Yii::$app->setting->getValue('auth::googleClientSecret'),
            'redirect_uri' => Yii::$app->urlManager->createAbsoluteUrl(['/auth/default/callback-google']),
            'grant_type' => 'authorization_code',
        ];
        if ($codeVerifier !== null) {
            $data['code_verifier'] = $codeVerifier;
        }

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
        $appleHelper = Yii::createObject([
            'class' => AppleJWTHelper::class,
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
    private function processAppleIdToken($idToken, $userParam = null, bool $requireEmail = true)
    {
        $appleHelper = Yii::createObject([
            'class' => AppleJWTHelper::class,
            'teamId' => Yii::$app->setting->getValue('auth::appleTeamId'),
            'keyId' => Yii::$app->setting->getValue('auth::appleKeyId'),
            'clientId' => Yii::$app->setting->getValue('auth::appleClientId'),
        ]);

        $tokenData = $appleHelper->decodeIdToken($idToken);

        if (!$tokenData || empty($tokenData['sub']) || ($requireEmail && empty($tokenData['email']))) {
            return false;
        }

        $userData = [
            'apple_id' => $tokenData['sub'],
            'email' => $tokenData['email'] ?? '',
            'email_verified' => $tokenData['email_verified'] ?? false,
            'nonce' => $tokenData['nonce'] ?? null,
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
     * an inactive account is never signed in, and the login card shows the reason (awaiting approval, not
     * active). After signing in, the user returns to the page they came from (e.g. the invitation page) (final
     * review U3).
     * @param array $userData User data from OAuth provider
     * @param string $provider OAuth provider name
     * @return \yii\web\Response
     */
    private function processUserAuthentication($userData, $provider)
    {
        $account = OAuthAccount::resolve($userData);
        $user = $account->user;
        if ($user === null && $account->reason === OAuthAccount::REASON_CORPORATE_ONLY) {
            AuthLogger::providerFailed($provider, (string) ($userData['email'] ?? ''), null, OAuthAccount::REASON_CORPORATE_ONLY);
            AuthNotice::set('warning', (string) $account->message);
            return $this->redirect(['/auth/default/login']);
        }
        if ($user === null && $account->reason === OAuthAccount::REASON_ACCOUNT_EXISTS) {
            $email = (string) ($userData['email'] ?? '');
            AuthLogger::providerFailed($provider, $email, null, AuthLog::REASON_ACCOUNT_EXISTS);
            AuthNotice::set('warning', (string) $account->message);
            return $this->redirect(['/auth/default/login']);
        }
        if ($user === null && $account->reason === OAuthAccount::REASON_EMAIL_NOT_VERIFIED) {
            $message = ($account->signupRefused && Yii::$app->setting->getValue('auth::web_signup'))
                ? OAuthAccount::unverifiedSignupWebMessage()
                : (string) $account->message;
            AuthNotice::set('warning', $message);
            return $this->redirect(['/auth/default/login']);
        }
        if ($user === null) {
            Yii::warning('OAuth signup failed: ' . json_encode($account->errors), 'oauth');
            $errors = array_map(static fn ($messages) => (string) reset($messages), $account->errors);
            Yii::$app->session->setFlash('error', Module::t('The account could not be created: {errors}', ['errors' => implode(' ', $errors)]));
            return $this->redirect(['/auth/default/login']);
        }

        if ((int) $user->status !== User::STATUS_ACTIVE) {
            AuthLogger::providerFailed($provider, (string) $user->email, $user, AccountStatus::of($user));
            $type = AccountStatus::of($user) === AccountStatus::INACTIVE ? 'warning' : 'info';
            AuthNotice::set($type, AccountStatus::inactiveMessage($user));
            return $this->redirect(['/auth/default/login']);
        }

        Yii::$app->user->login($user, 3600 * 24 * 30);
        $params = ['provider' => $provider, 'email' => $user->email];
        Yii::$app->session->setFlash('success', $account->isNew
            ? Module::t('Account created and signed in with {provider}: {email}', $params)
            : Module::t('Signed in with {provider}: {email}', $params));
        return $this->goBack();
    }

    /**
     * @param string|null $state profile connect request (OAuthLink::beginConnect)
     * @param array|null $login sign-in request (OAuthLoginState::begin): state, nonce and PKCE challenge
     */
    private function googleAuthUrl(?string $state = null, ?array $login = null): string
    {
        $params = [
            'client_id' => Yii::$app->setting->getValue('auth::googleClientId'),
            'redirect_uri' => Yii::$app->urlManager->createAbsoluteUrl(['/auth/default/callback-google']),
            'response_type' => 'code',
            'scope' => 'email profile',
            'access_type' => 'offline',
            'prompt' => 'select_account',
        ];
        if ($state !== null) {
            $params['state'] = $state;
        }
        if ($login !== null) {
            $params += [
                'state' => $login['state'], 'nonce' => $login['nonce'],
                'code_challenge' => $login['challenge'], 'code_challenge_method' => 'S256',
            ];
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    private function appleAuthUrl(?string $state = null, ?array $login = null): string
    {
        $params = [
            'client_id' => Yii::$app->setting->getValue('auth::appleClientId'),
            'redirect_uri' => Yii::$app->urlManager->createAbsoluteUrl(['/auth/default/callback-apple']),
            'response_type' => 'code',
        ];
        $params += $state === null
            ? ['scope' => 'name email', 'response_mode' => 'form_post', 'state' => $login['state'] ?? '', 'nonce' => $login['nonce'] ?? '']
            : ['response_mode' => 'query', 'state' => $state];

        return 'https://appleid.apple.com/auth/authorize?' . http_build_query($params);
    }

    /**
     * Links the identity to the account that started the profile connect request, and returns to the profile.
     */
    private function finishConnect(array $connect, array $userData)
    {
        $error = OAuthLink::finishConnect($connect, $userData);
        if ($error !== null) {
            Yii::$app->session->addFlash('error', OAuthLink::errorMessage($error, $connect['provider']));
        } else {
            Yii::$app->session->addFlash('success', Module::t('{provider} is now linked to your account.', [
                'provider' => OAuthLink::label($connect['provider']),
            ]));
        }

        return $this->redirect([self::PROFILE_ROUTE]);
    }

    /**
     * A callback whose connect request is missing or expired, or a signed-in user landing on the sign-in callback.
     */
    private function connectFailed(string $provider)
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['/auth/default/login']);
        }
        Yii::$app->session->addFlash('error', OAuthLink::errorMessage(OAuthLink::ERROR_EXPIRED, $provider));

        return $this->redirect([self::PROFILE_ROUTE]);
    }

    private function oauthFailed(?array $connect, string $message)
    {
        if ($connect !== null) {
            Yii::$app->session->addFlash('error', OAuthLink::errorMessage(OAuthLink::ERROR_EXPIRED, $connect['provider']));
            return $this->redirect([self::PROFILE_ROUTE]);
        }
        Yii::$app->session->setFlash('error', $message);

        return $this->redirect(['/auth/default/login']);
    }
}
