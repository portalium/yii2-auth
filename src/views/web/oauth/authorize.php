<?php

use portalium\auth\Module;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $authorization array checked by OAuthServer::authorizationRequest */
/* @var $params array the request as the client sent it, posted back with the decision */

$this->title = Module::t('Allow access');
$client = $authorization['client_name'] ?: Module::t('An application');
$appTitle = Yii::$app->setting->getValue('app::title') ?? 'Portalium';
$fields = ['response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'resource', 'scope'];
?>
<div class="container">
    <div class="row min-vh-100 align-items-center justify-content-center">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5 py-5">
            <p class="text-center fw-bold fs-4 text-dark mb-4"><?= Html::encode($appTitle) ?></p>
            <div class="bg-white rounded-4 shadow-lg px-4 py-4">
                <h3 class="text-center fw-bold text-dark mb-3"><?= Html::encode($this->title) ?></h3>
                <p class="text-center mb-2">
                    <?= Module::t('{client} wants to use {app} as {user}.', [
                        'client' => Html::tag('strong', Html::encode($client)),
                        'app' => Html::encode($appTitle),
                        'user' => Html::tag('strong', Html::encode(Yii::$app->user->identity->username ?? '')),
                    ]) ?>
                </p>
                <p class="text-center text-muted small mb-4">
                    <?= Module::t('It can list, create and change your apps, flows, collections and dashboards with your permissions.') ?>
                </p>
                <?= Html::beginForm(['/auth/oauth/authorize'], 'post') ?>
                <?php foreach ($fields as $field) : ?>
                    <?php if (isset($params[$field])) : ?>
                        <?= Html::hiddenInput('oauth[' . $field . ']', (string) $params[$field]) ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="d-flex gap-2 justify-content-center">
                    <?= Html::submitButton(Module::t('Allow'), ['name' => 'decision', 'value' => 'allow', 'class' => 'btn btn-success']) ?>
                    <?= Html::submitButton(Module::t('Deny'), ['name' => 'decision', 'value' => 'deny', 'class' => 'btn btn-danger']) ?>
                </div>
                <?= Html::endForm() ?>
                <p class="text-center text-muted small mt-3 mb-0">
                    <?= Module::t('You will be sent back to {uri}.', ['uri' => Html::encode(parse_url($authorization['redirect_uri'], PHP_URL_HOST))]) ?>
                </p>
            </div>
        </div>
    </div>
</div>
