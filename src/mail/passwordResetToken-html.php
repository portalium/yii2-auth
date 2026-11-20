<?php

use yii\helpers\Html;
use portalium\auth\Module;
use portalium\site\helpers\Route;

// Route::createUrlWeb is used (same as the emailVerify templates): when the
// mail is sent from the API app, createAbsoluteUrl adds '/api' to the URL
// and the link lands on the API endpoint instead of the web page.
//
// Route is auth/default/reset-password, not auth/auth/...: the module's
// web controller is DefaultController (id "default"), there is no
// controller with id "auth". The wrong route 404'd on the live server.
$resetLink = Route::createUrlWeb('auth/default/reset-password', ['token' => $user->password_reset_token]);
?>
<div class="password-reset">
    <p><?= Module::t('Hello {username},',['username' => Html::encode($user->username)]) ?></p>
    <p><?= Module::t('Follow the link below to reset your password:') ?></p>
    <p><?= Html::a(Html::encode($resetLink), $resetLink) ?></p>
</div>