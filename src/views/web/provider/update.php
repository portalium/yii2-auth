<?php

use portalium\auth\models\AuthProvider;
use portalium\auth\Module;
use portalium\theme\helpers\Html;
use portalium\theme\widgets\Panel;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var portalium\auth\models\AuthProvider $model */
/** @var array<int, string> $workspaces */

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => Module::t('SSO'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;

$suite = [
    AuthProvider::TYPE_OIDC => [
        Module::t('Redirect URI') => Url::to(['/auth/sso/callback'], true),
    ],
    AuthProvider::TYPE_SAML => [
        Module::t('SP entity ID') => Url::to(['/auth/sso/metadata', 'provider' => $model->id_provider], true),
        Module::t('Reply (ACS) URL') => Url::to(['/auth/sso/acs', 'provider' => $model->id_provider], true),
    ],
    AuthProvider::TYPE_LDAP => [],
][$model->type] ?? [];
$login = Url::to(['/auth/sso/login', 'domain' => $model->domain], true);
?>
<div class="auth-provider-update">

    <?= $this->render('_form', ['model' => $model, 'workspaces' => $workspaces]) ?>

    <?php Panel::begin([
        'title' => Module::t('Connection'),
        'actions' => [
            'footer' => [
                Html::a(Module::t('Test connection'), ['test', 'id' => $model->id_provider], [
                    'class' => 'btn btn-info',
                    'data' => ['method' => 'post'],
                ]),
            ],
        ],
    ]) ?>
    <dl class="row mb-0">
        <dt class="col-sm-4"><?= Module::t('Sign-in link') ?></dt>
        <dd class="col-sm-8 text-break"><code><?= Html::encode($login) ?></code></dd>
        <?php foreach ($suite as $label => $value): ?>
            <dt class="col-sm-4"><?= Html::encode($label) ?></dt>
            <dd class="col-sm-8 text-break"><code><?= Html::encode($value) ?></code></dd>
        <?php endforeach; ?>
        <dt class="col-sm-4"><?= Module::t('Domain') ?></dt>
        <dd class="col-sm-8">
            <?= $model->isVerified()
                ? Html::tag('span', Module::t('Verified'), ['class' => 'badge text-bg-success'])
                : Html::tag('span', Module::t('Waiting for verification'), ['class' => 'badge text-bg-warning']) ?>
        </dd>
    </dl>
    <?php Panel::end() ?>

    <?php if (!$model->isVerified()): ?>
        <?php Panel::begin([
            'title' => Module::t('Verify the domain'),
            'actions' => [
                'footer' => [
                    Html::a(Module::t('Verify domain'), ['verify', 'id' => $model->id_provider], [
                        'class' => 'btn btn-success',
                        'data' => ['method' => 'post'],
                    ]),
                ],
            ],
        ]) ?>
        <p class="mb-2"><?= Module::t('Add this TXT record to the DNS of {domain}, then press Verify domain.', [
            'domain' => Html::tag('strong', Html::encode($model->domain)),
        ]) ?></p>
        <dl class="row mb-0">
            <dt class="col-sm-4"><?= Module::t('Name') ?></dt>
            <dd class="col-sm-8"><code><?= Html::encode($model->domain) ?></code> <?= Module::t('(or @)') ?></dd>
            <dt class="col-sm-4"><?= Module::t('Type') ?></dt>
            <dd class="col-sm-8"><code>TXT</code></dd>
            <dt class="col-sm-4"><?= Module::t('Value') ?></dt>
            <dd class="col-sm-8 text-break"><code><?= Html::encode($model->dnsRecord()) ?></code></dd>
        </dl>
        <?php Panel::end() ?>
    <?php endif; ?>

</div>
