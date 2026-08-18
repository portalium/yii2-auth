<?php

use portalium\site\helpers\Route;
use yii\helpers\Html;
use Yii;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var common\models\User $user */

$verifyLink = Route::createUrlWeb('auth/default/verify-email', ['token' => $user->verification_token]);
?>
<div class="verify-email">
    <p>Hello <?= Html::encode($user->username) ?>,</p>
    <p>Follow the link below to verify your email:</p>

    <p><?= Html::a(Html::encode($verifyLink), $verifyLink) ?></p>
</div>