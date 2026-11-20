<?php

use portalium\auth\Module;
use portalium\site\helpers\Route;

// See passwordResetToken-html.php: createAbsoluteUrl adds '/api' in the API
// context, and the route id is "default" (DefaultController), not "auth".
$resetLink = Route::createUrlWeb('auth/default/reset-password', ['token' => $user->password_reset_token]);
?>
<?= Module::t('Hello {username},',['username' => $user->username]) ?>
<?= Module::t('Follow the link below to reset your password:') ?>
<?= $resetLink ?>