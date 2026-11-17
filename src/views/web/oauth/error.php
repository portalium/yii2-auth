<?php

use portalium\auth\Module;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $message string why the authorization request cannot go on */

$this->title = Module::t('Access request failed');
?>
<div class="container">
    <div class="row min-vh-100 align-items-center justify-content-center">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5 py-5">
            <div class="bg-white rounded-4 shadow-lg px-4 py-4 text-center">
                <h3 class="fw-bold text-dark mb-3"><?= Html::encode($this->title) ?></h3>
                <p class="text-muted mb-0"><?= Html::encode($message) ?></p>
            </div>
        </div>
    </div>
</div>
