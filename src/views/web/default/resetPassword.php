<?php

use yii\helpers\Html;
use portalium\theme\widgets\ActiveForm;
use portalium\auth\Module;
use portalium\auth\bundles\AuthAsset;
use portalium\theme\bundles\IconAsset;

$this->title = Module::t('Reset password');

$authBundle = AuthAsset::register($this);
$iconBundle = IconAsset::register($this);

$_appTitle = Html::encode(Yii::$app->setting->getValue('app::title') ?? 'Portalium');

try {
    $_heroTitle = trim(Yii::$app->setting->getValue('auth::login_hero_title') ?? '');
} catch (\Exception $e) {
    $_heroTitle = '';
}
if (empty($_heroTitle)) {
    $_heroTitle = Module::t('One Command Center');
}

try {
    $_heroSubtitle = trim(Yii::$app->setting->getValue('auth::login_hero_subtitle') ?? '');
} catch (\Exception $e) {
    $_heroSubtitle = '';
}
if (empty($_heroSubtitle)) {
    $_heroSubtitle = Module::t('Manage your entire organization with a single login.');
}

try {
    $_layout = Yii::$app->setting->getValue('auth::layout_style') ?? 'split';
} catch (\Exception $e) {
    $_layout = 'split';
}

$_integrationNames = ['slack', 'github', 'jira', 'notion', 'linkedin', 'google'];
$_visibleIntegrations = [];
foreach ($_integrationNames as $_name) {
    try {
        $_url = trim(Yii::$app->setting->getValue('auth::' . $_name . '_url') ?? '');
    } catch (\Exception $e) {
        $_url = '';
    }
    if (!empty($_url)) {
        $_visibleIntegrations[$_name] = $_url;
    }
}
try {
    $colorSetting = Yii::$app->setting->getValue('auth::box_gradient_colors');
    $colors = explode(',', $colorSetting);
    $color1 = trim($colors[0] ?? '#011f1b');
    $color2 = trim($colors[1] ?? '#2ecc71');
} catch (\Exception $e) {
    $color1 = '#011f1b'; $color2 = '#2ecc71';
}
$boxGradient = "linear-gradient(135deg, {$color1} 0%, {$color2} 100%)";
?>
<?php if ($_layout === 'single'): ?>
<!-- ======================== SINGLE — White BG + Centered Card ======================== -->
<div class="position-fixed top-0 start-0 vw-100 vh-100 d-flex flex-column overflow-hidden bg-white" style="z-index: 9999; margin: 0; padding: 0;">
    <!-- Centered Card Area -->
    <div class="d-flex flex-column flex-grow-1 align-items-center justify-content-center w-100 pt-5">
        <!-- Centered Logo -->
        <div class="d-flex flex-column align-items-center mb-4">
            <?= Html::img($iconBundle->baseUrl . '/favicon.ico', [
                'alt' => $_appTitle,
                'class' => 'auth-sl-header-icon',
                'style' => 'width:48px;height:48px;'
            ]) ?>
            <span class="fw-bold fs-4 text-dark mt-2"><?= $_appTitle ?></span>
        </div>
        <!-- Centered Card -->
        <div class="bg-white rounded-4 shadow-lg px-4 py-4" style="min-width:320px;max-width:400px;width:100%;">
            <h3 class="card-title text-center mb-1 fw-bold text-dark"><?= Module::t('Reset Your Password') ?></h3>
            <p class="text-center text-muted mb-3"><?= Module::t('Choose a new password for your account.') ?></p>
            <!-- Form -->
            <?php $form = ActiveForm::begin([
                'id' => 'reset-password-form',
                'fieldConfig' => [
                    'template' => "<div class=\"auth-sl-field\">{label}{input}\n{hint}\n{error}</div>",
                    'labelOptions' => ['class' => 'auth-sl-label'],
                ],
            ]); ?>
            <?= $form->field($model, 'password', ['options' => ['class' => 'mb-2']])->passwordInput([
                'autofocus' => true,
                'class' => 'auth-sl-input',
                'placeholder' => '••••••••',
            ]) ?>
            <?= $form->field($model, 'password_confirm', ['options' => ['class' => 'mb-2']])->passwordInput([
                'class' => 'auth-sl-input',
                'placeholder' => '••••••••',
            ]) ?>
            <?= Html::submitButton(Module::t('Reset Password'), [
                'class' => 'btn bg-white border border-2 border-success text-dark fw-bold w-100 mb-2',
                'name' => 'reset-button',
                'style' => 'border-radius: 0.375rem; padding: 0.7rem 1rem; font-size: 1rem;',
            ]) ?>
            <?php ActiveForm::end(); ?>
            <p class="auth-sl-footer-text mb-0">
                <?= Html::a(Module::t('Back to Log in'), ['/auth/default/login'], ['class' => 'auth-sl-link']) ?>
            </p>
        </div>
    </div>
    <?= $this->render('_feature_boxes', ['layout' => 'single', 'authBundle' => $authBundle, 'boxGradient' => $boxGradient]) ?>
</div>

<?php else: ?>
<!-- ======================== SPLIT (Two Columns) ======================== -->
<div class="auth-fullscreen-wrapper">
    <div class="auth-left-panel mesh-gradient">
        <div class="auth-brand">
            <?= Html::img($iconBundle->baseUrl . '/favicon.ico', [
                'alt' => $_appTitle,
                'class' => 'auth-logo-icon auth-brand-logo',
            ]) ?>
            <span class="auth-brand-name"><?= $_appTitle ?></span>
        </div>
        <div class="auth-hero">
            <h2 class="auth-hero-title"><?= Html::encode($_heroTitle) ?></h2>
            <p class="auth-hero-subtitle"><?= Html::encode($_heroSubtitle) ?></p>
        </div>
        <?= $this->render('_feature_boxes', ['layout' => 'split', 'authBundle' => $authBundle, 'boxGradient' => $boxGradient]) ?>
        <?php if (!empty($_visibleIntegrations)): ?>
        <div class="auth-badges">
            <div class="auth-badges-list">
                <?php foreach ($_visibleIntegrations as $_name => $_url): ?>
                <?= Html::a(
                    Html::img($authBundle->baseUrl . '/icons/' . $_name . '.svg', [
                        'alt' => ucfirst($_name) . ' icon',
                        'class' => 'auth-badge-icon',
                    ]),
                    Html::encode($_url),
                    [
                        'class' => 'auth-badge',
                        'title' => ucfirst($_name),
                        'target' => '_blank',
                        'rel' => 'noopener noreferrer',
                    ]
                ) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="auth-right-panel">
        <div class="auth-form-inner">
            <div class="auth-mobile-logo">
                <?= Html::img($iconBundle->baseUrl . '/favicon.ico', [
                    'alt' => $_appTitle,
                    'class' => 'auth-logo-icon',
                ]) ?>
                <span class="auth-mobile-logo-name"><?= $_appTitle ?></span>
            </div>
            <div class="auth-form-header">
                <h2 class="auth-form-title"><?= Module::t('Reset Your Password') ?></h2>
                <p class="auth-form-subtitle"><?= Module::t('Choose a new password for your account') ?></p>
            </div>
            <?php $form = ActiveForm::begin([
                'id' => 'reset-password-form',
                'fieldConfig' => [
                    'template' => "<div class=\"auth-field\">{label}{input}\n{hint}\n{error}</div>",
                    'labelOptions' => ['class' => 'auth-label'],
                ],
            ]); ?>
            <?= $form->field($model, 'password')->passwordInput([
                'autofocus' => true,
                'class' => 'auth-input',
                'placeholder' => '••••••••',
            ]) ?>
            <?= $form->field($model, 'password_confirm')->passwordInput([
                'class' => 'auth-input',
                'placeholder' => '••••••••',
            ]) ?>
            <div>
                <?= Html::submitButton(Module::t('Reset Password'), [
                    'class' => 'btn btn-outline-success auth-btn',
                    'name' => 'reset-button',
                ]) ?>
            </div>
            <?php ActiveForm::end(); ?>
            <div class="auth-footer">
                <?= Html::a(Module::t('Back to Login'), ['/auth/default/login']) ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
