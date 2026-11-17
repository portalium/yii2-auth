<?php

use portalium\auth\bundles\AuthAsset;
use portalium\auth\Module;
use portalium\theme\bundles\IconAsset;
use portalium\theme\helpers\Html;
use portalium\theme\widgets\ActiveForm;

/** @var yii\web\View $this */
/** @var portalium\auth\models\SsoLdapForm $model */

$provider = $model->getProvider();
$this->title = Module::t('Sign in with {name}', ['name' => $provider->name]);

AuthAsset::register($this);
$iconBundle = IconAsset::register($this);

$setting = static function (string $key, string $default): string {
    try {
        $value = trim((string) (Yii::$app->setting->getValue($key) ?? ''));
    } catch (\Exception $e) {
        $value = '';
    }
    return $value !== '' ? $value : $default;
};
$appTitle = $setting('app::title', 'Portalium');
$single = $setting('auth::layout_style', 'split') === 'single';
$logo = Html::img($iconBundle->baseUrl . '/favicon.ico', ['alt' => $appTitle, 'class' => 'auth-logo-icon']);
?>
<div class="auth-fullscreen-wrapper<?= $single ? ' auth-fullscreen-wrapper--single' : '' ?>">
    <?php if (!$single): ?>
        <div class="auth-left-panel mesh-gradient">
            <div class="auth-brand">
                <?= Html::img($iconBundle->baseUrl . '/favicon.ico', ['alt' => $appTitle, 'class' => 'auth-logo-icon auth-brand-logo']) ?>
                <span class="auth-brand-name"><?= Html::encode($appTitle) ?></span>
            </div>
            <div class="auth-hero">
                <h2 class="auth-hero-title"><?= Html::encode($setting('auth::login_hero_title', Module::t('One Command Center'))) ?></h2>
                <p class="auth-hero-subtitle"><?= Html::encode($setting('auth::login_hero_subtitle', Module::t('Manage your entire organization with a single login.'))) ?></p>
            </div>
        </div>
    <?php endif; ?>
    <div class="<?= $single ? 'auth-single-card' : 'auth-right-panel' ?>">
        <div class="auth-form-inner">
            <div class="auth-mobile-logo">
                <?= $logo ?>
                <span class="auth-mobile-logo-name"><?= Html::encode($appTitle) ?></span>
            </div>
            <div class="auth-form-header">
                <h2 class="auth-form-title"><?= Html::encode($this->title) ?></h2>
                <p class="auth-form-subtitle"><?= Html::encode($provider->domain) ?></p>
            </div>
            <?= $this->render('@vendor/portalium/yii2-auth/src/views/web/default/_notice') ?>
            <?php $form = ActiveForm::begin([
                'id' => 'sso-ldap-form',
                'fieldConfig' => [
                    'template' => "<div class=\"auth-field\">{label}{input}\n{hint}\n{error}</div>",
                    'labelOptions' => ['class' => 'auth-label'],
                ],
            ]); ?>
            <?= $form->field($model, 'username')->textInput([
                'autofocus' => true,
                'autocomplete' => 'username',
                'class' => 'auth-input',
                'placeholder' => Module::t('Email or username'),
            ]) ?>
            <?= $form->field($model, 'password')->passwordInput([
                'autocomplete' => 'current-password',
                'class' => 'auth-input',
                'placeholder' => '••••••••',
            ]) ?>
            <div>
                <?= Html::submitButton(Module::t('Sign in'), ['class' => 'btn btn-outline-success auth-btn', 'name' => 'login-button']) ?>
            </div>
            <?php ActiveForm::end(); ?>
            <div class="auth-footer">
                <?= Html::a(Module::t('Sign in another way'), ['/auth/default/login']) ?>
            </div>
        </div>
    </div>
</div>
