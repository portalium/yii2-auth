<?php

namespace portalium\auth\controllers\web;

use portalium\auth\components\AuthLogger;
use portalium\auth\models\AuthLog;
use portalium\auth\components\OAuthError;
use portalium\auth\components\OAuthServer;
use portalium\web\Controller;
use Yii;
use yii\filters\AccessControl;
use yii\web\Response;

/**
 * OAuth 2.1 authorization server endpoints (OAuthServer) for MCP clients:
 * /.well-known/oauth-protected-resource[/<path>], /.well-known/oauth-authorization-server, /oauth/register,
 * /oauth/authorize (the signed-in user allows the client) and /oauth/token.
 */
class OauthController extends Controller
{
    /** endpoints a client calls from its server: no session, no CSRF token, open to any origin */
    private const MACHINE = ['protected-resource', 'metadata', 'register', 'token'];

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['actions' => self::MACHINE, 'allow' => true],
                    // a guest is sent to the login page and comes back here (user->loginRequired)
                    ['actions' => ['authorize'], 'allow' => true, 'roles' => ['@']],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (in_array($action->id, self::MACHINE, true)) {
            $this->enableCsrfValidation = false;
            Yii::$app->response->headers->set('Access-Control-Allow-Origin', '*');
            Yii::$app->response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        } else {
            // a page of its own, without the app's menus, whatever layout the login page is set to
            $this->layout = '@portalium/theme/layouts/auth';
            $this->view->params['colorMode'] = 'light';
        }
        return parent::beforeAction($action);
    }

    /**
     * RFC 9728 metadata of the resource at <path> (e.g. app/mcp), or of the site.
     */
    public function actionProtectedResource($path = '')
    {
        return $this->json(OAuthServer::protectedResourceMetadata((string) $path));
    }

    /**
     * RFC 8414 metadata of this authorization server.
     */
    public function actionMetadata()
    {
        return $this->json(OAuthServer::authorizationServerMetadata());
    }

    /**
     * RFC 7591 dynamic client registration (JSON body).
     */
    public function actionRegister()
    {
        if (!Yii::$app->request->isPost) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'POST a JSON body.'], 405);
        }
        try {
            return $this->json(OAuthServer::register((array) json_decode(Yii::$app->request->getRawBody(), true)), 201);
        } catch (OAuthError $e) {
            return $this->json($e->body(), 400);
        }
    }

    /**
     * GET: the consent page for the signed-in user. POST (allow / deny): back to the client with a code or an error.
     */
    public function actionAuthorize()
    {
        $request = Yii::$app->request;
        $params = $request->isPost ? (array) $request->post('oauth', []) : $request->get();
        try {
            $authorization = OAuthServer::authorizationRequest($params);
        } catch (OAuthError $e) {
            $redirect = $e->redirect();
            if ($redirect !== null) {
                return $this->redirect($redirect);
            }
            return $this->render('error', ['message' => $e->getMessage()]);
        }
        if (!$request->isPost) {
            return $this->render('authorize', ['authorization' => $authorization, 'params' => $params]);
        }
        if ($request->post('decision') !== 'allow') {
            return $this->redirect((new OAuthError(
                'access_denied',
                'The user denied the request.',
                $authorization['redirect_uri'],
                $authorization['state']
            ))->redirect());
        }
        $code = OAuthServer::issueCode($authorization, (int) Yii::$app->user->id);
        AuthLogger::record(AuthLog::EVENT_OAUTH_GRANT, [
            'method' => AuthLog::METHOD_OAUTH,
            'reason' => $authorization['client_name'] ?: $authorization['client_id'],
            'id_user' => (int) Yii::$app->user->id,
            'username' => Yii::$app->user->identity->username ?? null,
        ]);
        $query = array_filter(
            ['code' => $code, 'state' => $authorization['state'], 'iss' => OAuthServer::issuer()],
            static fn($v) => $v !== null
        );
        $uri = $authorization['redirect_uri'];
        return $this->redirect($uri . (strpos($uri, '?') === false ? '?' : '&') . http_build_query($query));
    }

    /**
     * The token request (form-encoded): the code for the user's access token.
     */
    public function actionToken()
    {
        $request = Yii::$app->request;
        if (!$request->isPost) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'POST the token request.'], 405);
        }
        $basic = null;
        [$user, $password] = $request->getAuthCredentials();
        if ($user !== null) {
            $basic = [rawurldecode($user), rawurldecode((string) $password)];
        }
        try {
            $token = OAuthServer::token($request->post(), $basic);
        } catch (OAuthError $e) {
            return $this->json($e->body(), $e->error === 'invalid_client' ? 401 : 400);
        }
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        return $this->json($token);
    }

    private function json(array $body, int $status = 200): Response
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->statusCode = $status;
        $response->data = $body;
        return $response;
    }
}
