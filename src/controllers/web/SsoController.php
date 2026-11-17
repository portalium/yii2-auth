<?php

namespace portalium\auth\controllers\web;

use portalium\auth\components\AuthNotice;
use portalium\auth\components\OidcClient;
use portalium\auth\components\OidcError;
use portalium\auth\components\ProviderLogin;
use portalium\auth\components\SamlClient;
use portalium\auth\components\SamlError;
use portalium\auth\models\AuthProvider;
use portalium\auth\models\SsoLdapForm;
use portalium\auth\Module;
use portalium\web\Controller;
use Yii;
use yii\filters\AccessControl;

/**
 * Corporate sign-in: /auth/sso/login?domain=company.com sends the person to the identity provider whose domain this
 * is (verified and active). LDAP: a username and password form here; OIDC: a redirect to the company's sign-in page
 * and back to callback; SAML: a redirect and the signed response posted to acs (metadata gives the SP details).
 */
class SsoController extends Controller
{
    public $layout = '@portalium/theme/layouts/main';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'actions' => ['login', 'callback', 'acs', 'metadata'], 'roles' => ['?', '@']],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (Yii::$app->setting->getValue('auth::layout') != '') {
            $this->layout = '@portalium/theme/layouts/' . Yii::$app->setting->getValue('auth::layout');
        }
        $this->view->params['colorMode'] = 'light';
        if ($action->id === 'acs') {
            // the identity provider posts the response from its own page; the signed response is the check
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionLogin($domain = '')
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }
        $domain = mb_substr(trim((string) $domain), 0, 253);
        $provider = ProviderLogin::forDomain($domain);
        if ($provider === null) {
            $shown = preg_match('/^[a-z0-9.-]+$/i', $domain) ? $domain : '';
            AuthNotice::set('warning', $shown !== ''
                ? Module::t('There is no corporate sign-in for {domain}.', ['domain' => $shown])
                : Module::t('There is no corporate sign-in for this address.'));
            return $this->redirect(['/auth/default/login']);
        }
        if ($provider->type === AuthProvider::TYPE_OIDC) {
            try {
                return $this->redirect(OidcClient::start($provider));
            } catch (OidcError $e) {
                return $this->failed($provider, $e);
            }
        }
        if ($provider->type === AuthProvider::TYPE_SAML) {
            try {
                return $this->redirect(SamlClient::start($provider));
            } catch (SamlError $e) {
                return $this->failed($provider, $e);
            }
        }
        if ($provider->type !== AuthProvider::TYPE_LDAP) {
            AuthNotice::set('warning', Module::t('This sign-in type is not available yet.'));
            return $this->redirect(['/auth/default/login']);
        }

        $model = new SsoLdapForm($provider);
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack();
        }
        $model->password = '';

        return $this->render('ldap', ['model' => $model]);
    }

    /** The OIDC provider sends the person back here with a code (or an error). */
    public function actionCallback($state = '', $code = '', $error = '')
    {
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }
        try {
            [$provider, $pending] = OidcClient::takeState((string) $state);
        } catch (OidcError $e) {
            Yii::warning('OIDC callback refused: ' . $e->getMessage(), __METHOD__);
            AuthNotice::set('warning', Module::t('The sign-in expired. Start it again.'));
            return $this->redirect(['/auth/default/login']);
        }
        if (ProviderLogin::forDomain($provider->domain)?->id_provider !== $provider->id_provider) {
            AuthNotice::set('warning', Module::t('There is no corporate sign-in for {domain}.', ['domain' => $provider->domain]));
            return $this->redirect(['/auth/default/login']);
        }
        if ((string) $error !== '' || (string) $code === '') {
            ProviderLogin::recordFailure($provider, '', 'oidc_' . mb_substr((string) ($error ?: 'no_code'), 0, 40));
            AuthNotice::set('warning', Module::t('The company sign-in was cancelled or refused.'));
            return $this->redirect(['/auth/default/login']);
        }
        try {
            $person = OidcClient::finish($provider, $pending, (string) $code);
        } catch (OidcError $e) {
            return $this->failed($provider, $e);
        }
        $refusal = ProviderLogin::finish($provider, $person);
        if ($refusal !== null) {
            AuthNotice::set('warning', $refusal);
            return $this->redirect(['/auth/default/login']);
        }

        return $this->goBack();
    }

    /** The SAML provider posts its signed response here (Assertion Consumer Service). */
    public function actionAcs($provider = 0)
    {
        $model = AuthProvider::findOne((int) $provider);
        if ($model === null || $model->type !== AuthProvider::TYPE_SAML || ProviderLogin::forDomain($model->domain)?->id_provider !== $model->id_provider) {
            AuthNotice::set('warning', Module::t('There is no corporate sign-in for this address.'));
            return $this->redirect(['/auth/default/login']);
        }
        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }
        try {
            $person = SamlClient::finish($model);
        } catch (SamlError $e) {
            return $this->failed($model, $e);
        }
        $refusal = ProviderLogin::finish($model, $person);
        if ($refusal !== null) {
            AuthNotice::set('warning', $refusal);
            return $this->redirect(['/auth/default/login']);
        }

        return $this->goBack();
    }

    /** This Suite provider's SAML metadata (SP entity ID and ACS address), for the company's set-up. */
    public function actionMetadata($provider = 0)
    {
        $model = AuthProvider::findOne((int) $provider);
        if ($model === null || $model->type !== AuthProvider::TYPE_SAML) {
            throw new \yii\web\NotFoundHttpException(Module::t('The identity provider was not found.'));
        }
        try {
            $xml = SamlClient::metadata($model);
        } catch (SamlError $e) {
            throw new \yii\web\NotFoundHttpException(Module::t('The identity provider was not found.'));
        }
        $response = Yii::$app->response;
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/samlmetadata+xml');
        $response->content = $xml;

        return $response;
    }

    private function failed(AuthProvider $provider, \Throwable $e)
    {
        Yii::warning('Corporate sign-in with provider ' . $provider->id_provider . ' failed: ' . $e->getMessage(), __METHOD__);
        ProviderLogin::recordFailure($provider, '', $provider->type . '_error');
        AuthNotice::set('warning', ProviderLogin::unavailableMessage());

        return $this->redirect(['/auth/default/login']);
    }
}
