<?php

use portalium\auth\models\AuthLog;
use portalium\auth\models\AuthSession;
use portalium\auth\Module;
use portalium\theme\helpers\Html;
use portalium\theme\widgets\GridView;
use portalium\theme\widgets\Panel;

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var string $username */
/** @var yii\data\ArrayDataProvider $limitProvider rows: id_user, limit */
/** @var array<int, portalium\user\models\User> $limitUsers */
/** @var int $defaultLimit */
/** @var int|null $currentId the administrator's own session */

$this->title = Module::t('Active Sessions');
$this->params['breadcrumbs'][] = $this->title;

$methods = array_map(fn ($label) => Module::t($label), AuthLog::methodLabels());
$wide = ['class' => 'd-none d-md-table-cell'];
$limitText = fn (int $n) => $n > 0 ? (string) $n : Module::t('Unlimited');
?>
<div class="auth-session-manage">

    <?php Panel::begin(['title' => $this->title]) ?>

    <?= Html::beginForm(['manage'], 'get', ['class' => 'row g-2 mb-3']) ?>
    <div class="col-sm-6 col-lg-4">
        <?= Html::textInput('username', $username, ['class' => 'form-control', 'placeholder' => Module::t('Username or email')]) ?>
    </div>
    <div class="col-auto">
        <?= Html::submitButton(Module::t('Search'), ['class' => 'btn btn-info']) ?>
    </div>
    <?= Html::endForm() ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'emptyText' => $username !== '' ? Module::t('No records match the filters.') : Module::t('No active sessions.'),
        'columns' => [
            [
                'label' => Module::t('User'),
                'value' => fn (AuthSession $m) => $m->user ? $m->user->username : '#' . $m->id_user,
            ],
            [
                'label' => Module::t('Browser'),
                'format' => 'raw',
                'value' => fn (AuthSession $m) => Html::tag('span', Html::encode(AuthLog::browser($m->user_agent) ?? '—'), ['title' => $m->user_agent]),
            ],
            [
                'attribute' => 'ip',
                'label' => Module::t('IP Address'),
                'value' => fn (AuthSession $m) => $m->ip ?? '—',
                'contentOptions' => ['class' => 'text-nowrap'],
            ],
            [
                'label' => Module::t('Method'),
                'value' => fn (AuthSession $m) => $m->method ? ($methods[$m->method] ?? $m->method) : '—',
                'headerOptions' => $wide, 'contentOptions' => $wide,
            ],
            [
                'attribute' => 'date_create',
                'label' => Module::t('Signed in'),
                'headerOptions' => $wide, 'contentOptions' => ['class' => 'd-none d-md-table-cell text-nowrap'],
            ],
            [
                'attribute' => 'date_active',
                'label' => Module::t('Last active'),
                'contentOptions' => ['class' => 'text-nowrap'],
            ],
            [
                'label' => '',
                'format' => 'raw',
                'value' => fn (AuthSession $m) => (int) $m->id_session === $currentId
                    ? Html::tag('span', Module::t('This device'), ['class' => 'badge text-bg-success'])
                    : Html::a(Module::t('End session'), ['revoke', 'id' => $m->id_session], [
                        'class' => 'btn btn-danger btn-sm',
                        'data' => ['method' => 'post', 'confirm' => Module::t('End this session?')],
                    ]),
            ],
        ],
    ]) ?>

    <?php Panel::end() ?>

    <?php Panel::begin(['title' => Module::t('Session limits per user')]) ?>

    <p class="small text-body-secondary mb-2">
        <?= Module::t('Default limit: {n}', ['n' => Html::encode($limitText($defaultLimit))]) ?>
    </p>

    <?= Html::beginForm(['limit'], 'post', ['class' => 'row g-2 mb-3']) ?>
    <div class="col-sm-6 col-lg-4">
        <?= Html::label(Module::t('User'), 'session-limit-user', ['class' => 'form-label']) ?>
        <?= Html::textInput('username', '', [
            'id' => 'session-limit-user', 'class' => 'form-control', 'placeholder' => Module::t('Username or email'), 'required' => true,
        ]) ?>
    </div>
    <div class="col-sm-3 col-lg-2">
        <?= Html::label(Module::t('Session limit'), 'session-limit-value', ['class' => 'form-label']) ?>
        <?= Html::input('number', 'limit', '', [
            'id' => 'session-limit-value', 'class' => 'form-control', 'min' => 0, 'max' => 1000, 'required' => true,
            'placeholder' => Module::t('0 = unlimited'),
        ]) ?>
    </div>
    <div class="col-auto align-self-end">
        <?= Html::submitButton(Module::t('Save'), ['class' => 'btn btn-success']) ?>
    </div>
    <?= Html::endForm() ?>

    <?= GridView::widget([
        'dataProvider' => $limitProvider,
        'emptyText' => Module::t('No account has its own limit.'),
        'columns' => [
            [
                'label' => Module::t('User'),
                'value' => fn (array $r) => isset($limitUsers[$r['id_user']]) ? $limitUsers[$r['id_user']]->username : '#' . $r['id_user'],
            ],
            [
                'label' => Module::t('Session limit'),
                'value' => fn (array $r) => $limitText($r['limit']),
            ],
            [
                'label' => '',
                'format' => 'raw',
                'value' => fn (array $r) => isset($limitUsers[$r['id_user']]) ? Html::a(Module::t('Remove'), ['limit'], [
                    'class' => 'btn btn-danger btn-sm',
                    'data' => [
                        'method' => 'post',
                        'params' => ['username' => $limitUsers[$r['id_user']]->username, 'limit' => ''],
                        'confirm' => Module::t('Remove the own limit of this account?'),
                    ],
                ]) : '',
            ],
        ],
    ]) ?>

    <?php Panel::end() ?>

</div>
