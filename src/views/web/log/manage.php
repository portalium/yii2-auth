<?php

use portalium\auth\models\AuthLog;
use portalium\auth\Module;
use portalium\theme\helpers\Html;
use portalium\theme\widgets\GridView;
use portalium\theme\widgets\Nav;
use portalium\theme\widgets\Panel;

/** @var yii\web\View $this */
/** @var portalium\auth\models\AuthLogSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var bool $showIp false on a workspace's own page (yii2-ide audit/login) */
/** @var array|null $tabs nav items; null = the system-wide tabs */
/** @var string|null $panelTitle */

$showIp = $showIp ?? true;
$canAudit = Yii::$app->hasModule('ide') && Yii::$app->user->can('ideWebAuditManage');
$tabs = $tabs ?? ($canAudit ? [
    ['label' => 'Audit', 'url' => ['/ide/audit/manage']],
    ['label' => 'System', 'url' => ['/auth/log/manage'], 'active' => true],
] : []);

$this->title = Module::t('Login Log');
$this->params['breadcrumbs'][] = $this->title;

$t = fn (array $labels) => array_map(fn ($label) => Module::t($label), $labels);
$events = $t(AuthLog::eventLabels());
$methods = $t(AuthLog::methodLabels());
$reasons = $t(AuthLog::reasonLabels());
$results = $t(AuthLog::resultLabels());
$badges = [AuthLog::RESULT_OK => 'text-bg-success', AuthLog::RESULT_ERROR => 'text-bg-danger'];

$filtered = array_filter([$searchModel->event, $searchModel->method, $searchModel->result, $searchModel->username, $searchModel->ip, $searchModel->date], fn ($v) => $v !== null && $v !== '') !== [];
$wide = ['class' => 'd-none d-md-table-cell'];
?>
<div class="auth-log-manage">

    <?php Panel::begin(['title' => Html::encode($panelTitle ?? $this->title)]) ?>

    <?php if ($tabs !== []) : ?>
        <?= Nav::widget(['options' => ['class' => 'nav-tabs mb-3'], 'items' => $tabs]) ?>
    <?php endif ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'emptyText' => $filtered ? Module::t('No records match the filters.') : Module::t('No records yet.'),
        'columns' => [
            [
                'attribute' => 'date_create',
                'label' => Module::t('Date'),
                'filter' => Html::activeInput('date', $searchModel, 'date', ['class' => 'form-control']),
                'format' => 'raw',
                'value' => fn (AuthLog $m) => Html::tag('span', Html::encode($m->date_create), ['class' => 'text-nowrap']),
            ],
            [
                'attribute' => 'username',
                'label' => Module::t('User'),
                'value' => fn (AuthLog $m) => $m->user ? $m->user->username : ($m->username ?? ($m->id_user ? '#' . $m->id_user : '—')),
            ],
            [
                'attribute' => 'event',
                'label' => Module::t('Event'),
                'filter' => $events,
                'value' => fn (AuthLog $m) => $events[$m->event] ?? $m->event,
            ],
            [
                'attribute' => 'method',
                'label' => Module::t('Method'),
                'filter' => $methods,
                'value' => fn (AuthLog $m) => $m->method ? ($methods[$m->method] ?? $m->method) : '—',
                'headerOptions' => $wide, 'filterOptions' => $wide, 'contentOptions' => $wide,
            ],
            [
                'attribute' => 'result',
                'label' => Module::t('Result'),
                'filter' => $results,
                'format' => 'raw',
                'value' => function (AuthLog $m) use ($results, $reasons, $badges) {
                    $badge = Html::tag('span', Html::encode($results[$m->result] ?? $m->result), [
                        'class' => 'badge ' . ($badges[$m->result] ?? 'text-bg-secondary'),
                    ]);
                    return $m->reason ? $badge . ' ' . Html::tag('span', Html::encode($reasons[$m->reason] ?? $m->reason), ['class' => 'small']) : $badge;
                },
            ],
            [
                'attribute' => 'ip',
                'label' => Module::t('IP Address'),
                'value' => fn (AuthLog $m) => $m->ip ?? '—',
                'contentOptions' => ['class' => 'text-nowrap'],
                'visible' => $showIp,
            ],
            [
                'label' => Module::t('Browser'),
                'format' => 'raw',
                'value' => fn (AuthLog $m) => Html::tag('span', Html::encode(AuthLog::browser($m->user_agent) ?? '—'), ['title' => $m->user_agent]),
                'headerOptions' => $wide, 'filterOptions' => $wide, 'contentOptions' => $wide,
            ],
            [
                'attribute' => 'app',
                'label' => Module::t('Source'),
                'filter' => false,
                'value' => fn (AuthLog $m) => $m->app === 'api' ? Module::t('API') : Module::t('Web'),
                'headerOptions' => $wide, 'filterOptions' => $wide, 'contentOptions' => $wide,
            ],
        ],
    ]) ?>

    <?php Panel::end() ?>

</div>
