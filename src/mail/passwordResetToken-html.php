<?php

use yii\helpers\Html;
use portalium\auth\Module;

$resetLink = Yii::$app->urlManager->createAbsoluteUrl(['auth/default/reset-password', 'token' => $user->password_reset_token]);
?>
<div class="password-reset">
    <p><?= Module::t('Hello {username},',['username' => Html::encode($user->username)]) ?></p>
    <p><?= Module::t('Follow the link below to reset your password:') ?></p>
    <p><?= Html::a(Html::encode($resetLink), $resetLink) ?></p>
</div>