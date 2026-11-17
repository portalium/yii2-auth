<?php

use portalium\auth\models\AuthLog;
use portalium\auth\Module;
use portalium\theme\widgets\Panel;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var portalium\auth\models\AuthSession[] $sessions */
/** @var int|null $currentId */
/** @var int $limit */

$others = count(array_filter($sessions, fn ($s) => (int) $s->id_session !== $currentId));
?>
<?php Panel::begin([
    'title' => Html::encode(Module::t('Active sessions')),
    'actions' => [
        'header' => [],
        'footer' => $others > 0 ? [
            Html::a(Module::t('End other sessions'), ['/auth/session/end-others'], [
                'class' => 'btn btn-danger',
                'data' => ['method' => 'post', 'confirm' => Module::t('End your sessions on the other devices?')],
            ]),
        ] : [],
    ],
]) ?>
<p class="small text-body-secondary mb-2">
    <?= $limit > 0 ? Module::t('Limit: {n} device(s)', ['n' => $limit]) : Module::t('Limit: unlimited') ?>
</p>
<ul class="list-group list-group-flush" id="active-sessions">
    <?php foreach ($sessions as $session): ?>
        <?php $isCurrent = (int) $session->id_session === $currentId; ?>
        <li class="list-group-item d-flex align-items-center justify-content-between gap-3 px-0">
            <div>
                <div class="fw-semibold">
                    <?= Html::encode(AuthLog::browser($session->user_agent) ?? Module::t('Unknown browser')) ?>
                    <?php if ($isCurrent): ?>
                        <span class="badge text-bg-success ms-1"><?= Module::t('This device') ?></span>
                    <?php endif; ?>
                </div>
                <div class="small text-body-secondary">
                    <?= Module::t('Signed in {date}, last active {active}', [
                        'date' => Html::encode($session->date_create),
                        'active' => Html::encode($session->date_active),
                    ]) ?>
                </div>
            </div>
            <?php if (!$isCurrent): ?>
                <?= Html::a(Module::t('End session'), ['/auth/session/end', 'id' => $session->id_session], [
                    'class' => 'btn btn-danger btn-sm',
                    'data' => ['method' => 'post', 'confirm' => Module::t('End this session?')],
                ]) ?>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
<?php Panel::end() ?>
