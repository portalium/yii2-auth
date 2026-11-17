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
?>
<div class="auth-provider-index">

    <?php Panel::begin([
        'title' => $this->title,
        'actions' => [
            'header' => [
                Html::a('', ['create'], ['class' => 'fa fa-plus btn btn-success', 'title' => Module::t('New Identity Provider')]),
            ],
        ],
    ]) ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'emptyText' => Module::t('No identity provider yet.'),
        'columns' => [
            'name',
            [
                'attribute' => 'type',
                'value' => fn (AuthProvider $m) => $types[$m->type] ?? $m->type,
                'headerOptions' => ['class' => 'd-none d-md-table-cell'],
                'contentOptions' => ['class' => 'd-none d-md-table-cell'],
            ],
            'domain',
            [
                'label' => Module::t('Status'),
                'format' => 'raw',
                'value' => function (AuthProvider $m) {
                    if (!$m->isVerified()) {
                        return Html::tag('span', Module::t('Waiting for verification'), ['class' => 'badge text-bg-warning']);
                    }
                    return $m->isActive()
                        ? Html::tag('span', Module::t('Active'), ['class' => 'badge text-bg-success'])
                        : Html::tag('span', Module::t('Passive'), ['class' => 'badge text-bg-secondary']);
                },
            ],
            [
                'label' => '',
                'format' => 'raw',
                'contentOptions' => ['class' => 'text-nowrap text-end'],
                'value' => fn (AuthProvider $m) => Html::a(Module::t('Edit'), ['update', 'id' => $m->id_provider], ['class' => 'btn btn-warning btn-sm'])
                    . ' ' . Html::a(Module::t('Delete'), ['delete', 'id' => $m->id_provider], [
                        'class' => 'btn btn-danger btn-sm',
                        'data' => ['method' => 'post', 'confirm' => Module::t('Delete this identity provider? People who signed in through it keep their accounts.')],
                    ]),
            ],
        ],
    ]) ?>

    <?php Panel::end() ?>

</div>
