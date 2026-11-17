<?php

use portalium\auth\models\AuthProvider;
use portalium\auth\Module;
use portalium\theme\helpers\Html;
use portalium\theme\widgets\GridView;
use portalium\theme\widgets\Panel;

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Module::t('SSO');
$this->params['breadcrumbs'][] = $this->title;
$types = array_map(fn ($label) => Module::t($label), AuthProvider::typeLabels());
$by = [AuthProvider::VERIFIED_DNS => Module::t('DNS record'), AuthProvider::VERIFIED_ADMIN => Module::t('Administrator')];
?>
<div class="auth-provider-manage">

    <?php Panel::begin(['title' => $this->title]) ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'emptyText' => Module::t('No identity provider yet.'),
        'columns' => [
            [
                'label' => Module::t('Workspace'),
                'value' => fn (AuthProvider $m) => $m->workspace ? $m->workspace->title : '#' . $m->id_workspace,
            ],
            'name',
            'domain',
            [
                'attribute' => 'type',
                'value' => fn (AuthProvider $m) => $types[$m->type] ?? $m->type,
                'headerOptions' => ['class' => 'd-none d-md-table-cell'],
                'contentOptions' => ['class' => 'd-none d-md-table-cell'],
            ],
            [
                'label' => Module::t('Domain'),
                'format' => 'raw',
                'value' => fn (AuthProvider $m) => $m->isVerified()
                    ? Html::tag('span', Module::t('Verified'), ['class' => 'badge text-bg-success']) . ' '
                        . Html::tag('span', Html::encode($by[$m->verifiedBy()] ?? (string) $m->verifiedBy()), ['class' => 'small'])
                    : Html::tag('span', Module::t('Waiting for verification'), ['class' => 'badge text-bg-warning']),
            ],
            [
                'label' => '',
                'format' => 'raw',
                'contentOptions' => ['class' => 'text-nowrap text-end'],
                'value' => fn (AuthProvider $m) => $m->isVerified()
                    ? Html::a(Module::t('Revoke'), ['revoke', 'id' => $m->id_provider], [
                        'class' => 'btn btn-danger btn-sm',
                        'data' => ['method' => 'post', 'confirm' => Module::t('Revoke the verification of {domain}? Nobody can sign in through it until it is verified again.', ['domain' => $m->domain])],
                    ])
                    : Html::a(Module::t('Approve'), ['approve', 'id' => $m->id_provider], [
                        'class' => 'btn btn-success btn-sm',
                        'data' => ['method' => 'post', 'confirm' => Module::t('Approve {domain} for {workspace} without a DNS record? Only do this when you know the domain belongs to them.', [
                            'domain' => $m->domain,
                            'workspace' => $m->workspace ? $m->workspace->title : '#' . $m->id_workspace,
                        ])],
                    ]),
            ],
        ],
    ]) ?>

    <?php Panel::end() ?>

</div>
