<?php

use yii\helpers\Html;
use portalium\auth\Module;

/** @var string $layout 'single' or 'split' */
/** @var yii\web\AssetBundle $authBundle */
/** @var string $boxGradient */

$_featureDefaults = [
    1 => ['icon' => 'feature-portalium.svg', 'title' => Module::t('Vision To Insight'), 'desc' => Module::t('Transform your business.')],
    2 => ['icon' => 'feature-ai.svg', 'title' => Module::t('Real-Time AI'), 'desc' => Module::t('Start operational intelligence.')],
    3 => ['icon' => 'feature-data.svg', 'title' => Module::t('Data To Action'), 'desc' => Module::t('Fast, reliable, and at scale.')],
    4 => ['icon' => 'feature-productivity.svg', 'title' => Module::t('Increase Productivity'), 'desc' => Module::t('One platform for real world.')],
];
$_features = [];
foreach ($_featureDefaults as $_i => $_def) {
    try {
        $_fTitle = trim(Yii::$app->setting->getValue('auth::feature' . $_i . '_title') ?? '');
    } catch (\Exception $e) {
        $_fTitle = '';
    }
    try {
        $_fDesc = trim(Yii::$app->setting->getValue('auth::feature' . $_i . '_desc') ?? '');
    } catch (\Exception $e) {
        $_fDesc = '';
    }
    try {
        $_fIcon = trim(Yii::$app->setting->getValue('auth::feature' . $_i . '_icon') ?? '');
    } catch (\Exception $e) {
        $_fIcon = '';
    }
    $_features[$_i] = [
        'title' => !empty($_fTitle) ? $_fTitle : $_def['title'],
        'desc' => !empty($_fDesc) ? $_fDesc : $_def['desc'],
        'icon' => !empty($_fIcon) ? $_fIcon : $_def['icon'],
    ];
}
?>
<?php if ($layout === 'single'): ?>
    <div class="row w-100 justify-content-center m-0 pb-4" style="max-width:1200px; align-self:center;">
        <?php foreach ($_features as $_f): ?>
        <div class="col-12 col-sm-6 col-md-3 mb-2 px-2">
            <div class="rounded-3 h-100 p-3 text-wrap text-break d-flex flex-column align-items-start border-0 shadow-sm" style="background: <?= $boxGradient ?>;">
                <div class="d-flex align-items-center mb-2">
                    <?= Html::img($authBundle->baseUrl . '/icons/' . Html::encode($_f['icon']), [
                        'alt' => '',
                        'class' => 'me-2',
                        'style' => 'width:24px;height:24px;filter:brightness(0) invert(1);'
                    ]) ?>
                    <span class="fw-bold text-white">
                        <?= Html::encode($_f['title']) ?>
                    </span>
                </div>
                <div class="small text-white opacity-75">
                    <?= Html::encode($_f['desc']) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
        <div class="auth-features">
            <div class="auth-feature-grid">
                <?php foreach ($_features as $_f): ?>
                <div class="auth-feature-card text-wrap text-break">
                    <span class="auth-feature-title"><?= Html::encode($_f['title']) ?></span>
                    <span class="auth-feature-text"><?= Html::encode($_f['desc']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
<?php endif; ?>
