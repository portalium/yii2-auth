<?php

namespace portalium\auth\components;

use Yii;
use yii\filters\auth\HttpBearerAuth;

/**
 * Bearer authentication for a resource protected by OAuthServer: a request without a valid token gets the
 * WWW-Authenticate header that points the client to this resource's metadata (RFC 9728, MCP authorization), where
 * an OAuth client finds out how to sign the user in.
 */
class OAuthBearerAuth extends HttpBearerAuth
{
    public function challenge($response)
    {
        $path = trim(Yii::$app->request->pathInfo, '/');
        $metadata = OAuthServer::issuer() . '/.well-known/oauth-protected-resource' . ($path === '' ? '' : '/' . $path);
        $response->getHeaders()->set('WWW-Authenticate', 'Bearer resource_metadata="' . $metadata . '"');
    }
}
