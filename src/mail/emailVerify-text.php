<?php

use portalium\site\helpers\Route;

/** @var yii\web\View $this */
/** @var common\models\User $user */

// See emailVerify-html.php: route id is "default" (DefaultController), not "auth".
$verifyLink = Route::createUrlWeb('auth/default/verify-email', ['token' => $user->verification_token]);
?>
    Hello <?= $user->username ?>,

    Follow the link below to verify your email:
       
<?= $verifyLink ?>