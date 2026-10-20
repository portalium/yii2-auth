<?php

use yii\helpers\Html;
use portalium\theme\widgets\ActiveForm;
use portalium\auth\Module;
use portalium\auth\models\SignupForm;
use portalium\auth\bundles\AuthAsset;
use portalium\theme\bundles\IconAsset;

$this->title = Module::t('Signup');
$lockedEmail = $model->signedInvitationEmail();
$emailLock = $lockedEmail === null ? [] : ['readonly' => true, 'value' => $lockedEmail];

$authBundle = AuthAsset::register($this);
$iconBundle = IconAsset::register($this);

$_appTitle = Html::encode(Yii::$app->setting->getValue('app::title') ?? 'Portalium');

try {
    $_layout = Yii::$app->setting->getValue('auth::layout_style') ?? 'split';
} catch (\Exception $e) {
    $_layout = 'split';
}

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
            <h3 class="card-title text-center mb-1 fw-bold text-dark"><?= Module::t('Create Account') ?></h3>
            <p class="auth-sl-card-subtitle mb-2">
                <?= Module::t('Already have an account?') ?>
                <?= Html::a(Module::t('Log in'), ['/auth/default/login'], ['class' => 'auth-sl-link']) ?>
            </p>
            <?php if (!Yii::$app->user->isGuest): ?>
            <div class="auth-sl-notice"><?= Module::t('You are already logged in. You cannot register.') ?></div>
            <?php else: ?>
            <!-- Social Providers -->
            <?php
            $providers = [
                'google' => 'Google',
                'apple' => 'Apple',
                'github' => 'GitHub',
                'linkedin' => 'LinkedIn',
                'twitter' => 'Twitter',
            ];
            $enabledProviders = [];
            foreach ($providers as $key => $label) {
                try {
                    $isEnabled = Yii::$app->setting->getValue('auth::' . $key . 'Enabled');
                } catch (\Exception $e) {
                    $isEnabled = false;
                }
                if ($isEnabled) {
                    $enabledProviders[$key] = $label;
                }
            }
            $hasSocial = $enabledProviders !== [];
            echo $this->render('_invitation_hint', ['hint' => $model->invitationHint(), 'hasSocial' => $hasSocial, 'signed' => $lockedEmail !== null]);
            foreach ($enabledProviders as $key => $label) {
                echo Html::a(
                    Html::img($authBundle->baseUrl . '/icons/' . $key . '.svg', ['alt' => $label . ' icon', 'class' => 'auth-icon-xs']) . ' ' . Module::t('Continue with {provider}', ['provider' => $label]),
                    ['/auth/default/login-' . $key],
                    ['class' => 'auth-sl-btn-social mb-2']
                );
            }
            ?>
            <?php if ($hasSocial): ?>
            <div class="auth-sl-divider mb-2">
                <div class="auth-sl-divider-line"></div>
                <span class="auth-sl-divider-text"><?= Module::t('OR') ?></span>
                <div class="auth-sl-divider-line"></div>
            </div>
            <?php endif; ?>
            <!-- Signup Form -->
            <?= $this->render('_notice') ?>
            <?php $form = ActiveForm::begin([
                'id' => 'form-signup',
                'fieldConfig' => [
                    'template' => "<div class=\"auth-sl-field\">{label}{input}\n{hint}\n{error}</div>",
                    'labelOptions' => ['class' => 'auth-sl-label'],
                ],
            ]); ?>
            <?php if ($model->invitationToken() !== '') : ?>
                <?= Html::activeHiddenInput($model, 'invitation', ['value' => $model->invitationToken()]) ?>
            <?php endif; ?>
            <?php if ($model->invitationSignature() !== '') : ?>
                <?= Html::activeHiddenInput($model, 'sig', ['value' => $model->invitationSignature()]) ?>
            <?php endif; ?>
            <div class="d-flex gap-2 mb-1">
                <div class="flex-grow-1">
                    <?= $form->field($model, 'first_name', ['options' => ['class' => 'mb-1']])->textInput([
                        'autofocus' => true,
                        'class' => 'auth-sl-input',
                        'placeholder' => Module::t('John'),
                    ])->label(Module::t('First Name')) ?>
                </div>
                <div class="flex-grow-1">
                    <?= $form->field($model, 'last_name', ['options' => ['class' => 'mb-1']])->textInput([
                        'class' => 'auth-sl-input',
                        'placeholder' => Module::t('Doe'),
                    ])->label(Module::t('Last Name')) ?>
                </div>
            </div>
            <?= $form->field($model, 'username', ['options' => ['class' => 'mb-1']])->textInput([
                'class' => 'auth-sl-input',
                'placeholder' => Module::t('username'),
            ]) ?>
            <?= $form->field($model, 'email', ['options' => ['class' => 'mb-1']])->textInput([
                'class' => 'auth-sl-input',
                'placeholder' => Module::t('name@company.com'),
            ] + $emailLock)->hint($lockedEmail === null ? null : Module::t('This is the address the invitation was sent to; it cannot be changed.')) ?>
            <?= $form->field($model, 'password', ['options' => ['class' => 'mb-1']])->passwordInput([
                'class' => 'auth-sl-input',
                'placeholder' => '••••••••',
            ]) ?>
            <?= $form->field($model, 'password_confirm', ['options' => ['class' => 'mb-1']])->passwordInput([
                'class' => 'auth-sl-input',
                'placeholder' => '••••••••',
            ]) ?>
            <?= $form->field($model, 'verifyCode', ['template' => '{input}'])->widget(
                \himiklab\yii2\recaptcha\ReCaptcha3::className(),
                [
                    'siteKey' => '6LdtOVspAAAAAGGnMu_yPK2hlyyNAjmiQJz0v7Ws',
                    'action' => 'signup',
                ]
            ) ?>
            <?= Html::submitButton(Module::t('Sign up'), [
                'class' => 'btn bg-white border border-2 border-success text-dark fw-bold w-100 mb-2',
                'name' => 'signup-button',
                'disabled' => SignupForm::signupBlocked(),
                'style' => 'border-radius: 0.375rem; padding: 0.7rem 1rem; font-size: 1rem;',
            ]) ?>
            <?php ActiveForm::end(); ?>
            <?php endif; ?>
            <!-- Dynamic Social Footer Icons -->
            <?php if (!empty($_visibleIntegrations)): ?>
            <div class="auth-sl-social-footer mb-1">
                <?php foreach ($_visibleIntegrations as $_iName => $_iUrl): ?>
                <?= Html::a(
                    Html::img($authBundle->baseUrl . '/icons/' . $_iName . '.svg', [
                        'alt' => ucfirst($_iName),
                        'class' => 'auth-sl-social-footer-icon',
                    ]),
                    Html::encode($_iUrl),
                    ['target' => '_blank', 'rel' => 'noopener noreferrer', 'class' => 'auth-sl-social-footer-link', 'title' => ucfirst($_iName)]
                ) ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p class="auth-sl-recaptcha-text mb-0">
                <?= Module::t('This page is protected by Portalium to ensure you\'re not a bot.') ?>
            </p>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ======================== SPLIT (Two Columns, Flipped) ======================== -->
<div class="auth-fullscreen-wrapper auth-fullscreen-wrapper--flipped">
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
        <div class="auth-form-inner--wide">
            <div class="auth-mobile-logo">
                <?= Html::img($iconBundle->baseUrl . '/favicon.ico', [
                    'alt' => $_appTitle,
                    'class' => 'auth-logo-icon',
                ]) ?>
                <span class="auth-mobile-logo-name"><?= $_appTitle ?></span>
            </div>
            <div class="auth-form-header">
                <h2 class="auth-form-title"><?= Module::t('Create your Account') ?></h2>
                <p class="auth-form-subtitle">
                    <?= Module::t('Already have an account?') ?>
                    <?= Html::a(Module::t('Log in'), ['/auth/default/login'], ['class' => 'auth-link']) ?>
                </p>
            </div>
            <?php if (!Yii::$app->user->isGuest): ?>
            <div class="auth-notice">
                <?= Module::t('You are already logged in. You cannot register.') ?>
            </div>
            <?php else: ?>
            <?php
            $providers = [
                'google' => 'Google',
                'apple' => 'Apple',
                'github' => 'GitHub',
                'linkedin' => 'LinkedIn',
                'twitter' => 'Twitter',
            ];
            $enabledProviders = [];
            foreach ($providers as $key => $label) {
                try {
                    $isEnabled = Yii::$app->setting->getValue('auth::' . $key . 'Enabled');
                } catch (\Exception $e) {
                    $isEnabled = false;
                }
                if ($isEnabled) {
                    $enabledProviders[$key] = $label;
                }
            }
            $hasSocial = $enabledProviders !== [];
            echo $this->render('_invitation_hint', ['hint' => $model->invitationHint(), 'hasSocial' => $hasSocial, 'signed' => $lockedEmail !== null]);
            foreach ($enabledProviders as $key => $label) {
                echo Html::a(
                    Html::img($authBundle->baseUrl . '/icons/' . $key . '.svg', ['alt' => $label . ' icon', 'class' => 'auth-icon-xs']) . ' ' . Module::t('Continue with {provider}', ['provider' => $label]),
                    ['/auth/default/login-' . $key],
                    ['class' => 'auth-btn-social mb-3']
                );
            }
            ?>
            <?php if ($hasSocial): ?>
            <div class="auth-divider">
                <div class="auth-divider-line"></div>
                <span class="auth-divider-text"><?= Module::t('or register with email') ?></span>
                <div class="auth-divider-line"></div>
            </div>
            <?php endif; ?>
            <?= $this->render('_notice') ?>
            <?php $form = ActiveForm::begin([
                'id' => 'form-signup',
                'fieldConfig' => [
                    'template' => "<div class=\"auth-field\">{label}{input}\n{hint}\n{error}</div>",
                    'labelOptions' => ['class' => 'auth-label'],
                ],
            ]); ?>
            <?php if ($model->invitationToken() !== '') : ?>
                <?= Html::activeHiddenInput($model, 'invitation', ['value' => $model->invitationToken()]) ?>
            <?php endif; ?>
            <?php if ($model->invitationSignature() !== '') : ?>
                <?= Html::activeHiddenInput($model, 'sig', ['value' => $model->invitationSignature()]) ?>
            <?php endif; ?>
            <div class="d-flex gap-3">
                <div class="flex-grow-1">
                    <?= $form->field($model, 'first_name')->textInput([
                        'autofocus' => true,
                        'class' => 'auth-input auth-input-sm',
                        'placeholder' => Module::t('John'),
                    ])->label(Module::t('First Name')) ?>
                </div>
                <div class="flex-grow-1">
                    <?= $form->field($model, 'last_name')->textInput([
                        'class' => 'auth-input auth-input-sm',
                        'placeholder' => Module::t('Doe'),
                    ])->label(Module::t('Last Name')) ?>
                </div>
            </div>
            <?= $form->field($model, 'username')->textInput([
                'class' => 'auth-input auth-input-sm',
                'placeholder' => Module::t('username'),
            ]) ?>
            <?= $form->field($model, 'email')->textInput([
                'class' => 'auth-input auth-input-sm',
                'placeholder' => Module::t('name@company.com'),
            ] + $emailLock)->hint($lockedEmail === null ? null : Module::t('This is the address the invitation was sent to; it cannot be changed.')) ?>
            <?= $form->field($model, 'password')->passwordInput([
                'class' => 'auth-input auth-input-sm',
                'placeholder' => '••••••••',
            ]) ?>
            <?= $form->field($model, 'password_confirm')->passwordInput([
                'class' => 'auth-input auth-input-sm',
                'placeholder' => '••••••••',
            ]) ?>
            <?= $form->field($model, 'verifyCode', ['template' => '{input}'])->widget(
                \himiklab\yii2\recaptcha\ReCaptcha3::className(),
                [
                    'siteKey' => '6LdtOVspAAAAAGGnMu_yPK2hlyyNAjmiQJz0v7Ws',
                    'action' => 'signup',
                ]
            ) ?>
            <div>
                <?= Html::submitButton(Module::t('Create Account'), [
                    'class' => 'btn btn-outline-success auth-btn auth-btn-sm',
                    'name' => 'signup-button',
                    'disabled' => SignupForm::signupBlocked(),
                ]) ?>
            </div>
            <?php ActiveForm::end(); ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
