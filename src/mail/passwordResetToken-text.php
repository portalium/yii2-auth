<?php

use portalium\auth\Module;

$resetLink = Yii::$app->urlManager->createAbsoluteUrl(['auth/default/reset-password', 'token' => $user->password_reset_token]);
?>
<?= Module::t('Hello {username},',['username' => $user->username]) ?>
<?= Module::t('Follow the link below to reset your password:') ?>
<?= $resetLink ?>