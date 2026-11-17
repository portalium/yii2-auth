<?php

namespace portalium\auth\widgets;

use Yii;
use yii\base\Widget;
use portalium\auth\components\OAuthLink;

/**
 * Profile panel listing the Google/Apple identities linked to the signed-in account, with [Link] and [Remove]
 * (decision 2026-11-04: an existing account links Google/Apple only from here). Only providers enabled in the auth
 * settings are listed; with none enabled, nothing is rendered.
 */
class ConnectedAccounts extends Widget
{
    public function run()
    {
        if (Yii::$app->user->isGuest) {
            return '';
        }
        $providers = array_values(array_filter([OAuthLink::GOOGLE, OAuthLink::APPLE], [OAuthLink::class, 'enabled']));
        if ($providers === []) {
            return '';
        }

        return $this->render('connected-accounts', [
            'providers' => $providers,
            'identities' => OAuthLink::identities((int) Yii::$app->user->id),
        ]);
    }
}
