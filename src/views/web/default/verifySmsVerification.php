<?php

use yii\helpers\Html;
use portalium\auth\Module;
use portalium\auth\bundles\AuthAsset;
use portalium\theme\bundles\IconAsset;

$this->title = Module::t('Enter Verification Code');

$authBundle = AuthAsset::register($this);
$iconBundle = IconAsset::register($this);

$_appTitle = Html::encode(Yii::$app->setting->getValue('app::title') ?? 'Portalium');

try { $_layout = Yii::$app->setting->getValue('auth::layout_style') ?? 'split'; }
catch (\Exception $e) { $_layout = 'split'; }

try { $_heroTitle = trim(Yii::$app->setting->getValue('auth::login_hero_title') ?? ''); }
catch (\Exception $e) { $_heroTitle = ''; }
if (empty($_heroTitle)) { $_heroTitle = Module::t('One Command Center'); }

try { $_heroSubtitle = trim(Yii::$app->setting->getValue('auth::login_hero_subtitle') ?? ''); }
catch (\Exception $e) { $_heroSubtitle = ''; }
if (empty($_heroSubtitle)) { $_heroSubtitle = Module::t('Manage your entire organization with a single login.'); }

$_integrationNames = ['slack', 'github', 'jira', 'notion', 'linkedin', 'google'];
$_visibleIntegrations = [];
foreach ($_integrationNames as $_name) {
    try { $_url = trim(Yii::$app->setting->getValue('auth::' . $_name . '_url') ?? ''); }
    catch (\Exception $e) { $_url = ''; }
    if (!empty($_url)) { $_visibleIntegrations[$_name] = $_url; }
}

$flashError = Yii::$app->session->getFlash('error');
$flashSuccess = Yii::$app->session->getFlash('success');
$isAlreadyVerified = (int) $user->sms_verified === \portalium\user\models\User::SMS_VERIFY;
?>

<?php if ($_layout === 'single'): ?>
<div class="position-fixed top-0 start-0 vw-100 vh-100 d-flex flex-column overflow-hidden bg-white" style="z-index:9999;margin:0;padding:0;">
    <div class="d-flex flex-column flex-grow-1 align-items-center justify-content-center w-100 pt-5">
        <div class="d-flex flex-column align-items-center mb-4">
            <?= Html::img($iconBundle->baseUrl . '/favicon.ico', ['alt' => $_appTitle, 'style' => 'width:48px;height:48px;']) ?>
            <span class="fw-bold fs-4 text-dark mt-2"><?= $_appTitle ?></span>
        </div>

        <div class="bg-white rounded-4 shadow-lg px-4 py-4" style="min-width:320px;max-width:440px;width:100%;">
            <h3 class="card-title text-center mb-1 fw-bold text-dark"><?= Module::t('Enter Verification Code') ?></h3>
            <p class="text-center text-muted mb-3">
                <?php if ($pendingPhone): ?>
                    <?= Module::t('Enter the 6-digit code we sent to {phone}.', ['phone' => Html::encode($pendingPhone)]) ?>
                <?php else: ?>
                    <?= Module::t('Enter the 6-digit code we sent to your phone.') ?>
                <?php endif; ?>
            </p>

            <?php if ($flashSuccess): ?>
                <div class="alert alert-success py-2 mb-2 small"><?= Html::encode($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert alert-danger py-2 mb-2 small"><?= Html::encode($flashError) ?></div>
            <?php endif; ?>

            <?php if ($isAlreadyVerified): ?>
                <div class="alert alert-info py-2 mb-2 small">
                    <?= Module::t('Your phone number is already verified.') ?>
                </div>
            <?php endif; ?>

            <?= Html::beginForm(['/auth/default/verify-sms-verification'], 'post', ['id' => 'verify-sms-form']) ?>

            <div class="auth-sl-field mb-2">
                <label class="auth-sl-label" for="code"><?= Module::t('Verification Code') ?></label>
                <input type="text" name="code" id="code"
                       class="auth-sl-input"
                       placeholder="000000"
                       inputmode="numeric"
                       pattern="\d{6}"
                       maxlength="6"
                       autocomplete="one-time-code"
                       autofocus required>
            </div>

            <?= Html::submitButton(Module::t('Verify'), [
                'class' => 'btn bg-white border border-2 border-success text-dark fw-bold w-100 mb-2',
                'style' => 'border-radius:0.375rem;padding:0.7rem 1rem;font-size:1rem;',
            ]) ?>

            <?= Html::endForm() ?>

            <p class="auth-sl-footer-text mb-0 text-center">
                <?= Module::t('Need a new code?') ?>
                <?= Html::a(Module::t('Request again'), ['/auth/default/request-sms-verification'], ['class' => 'auth-sl-link']) ?>
            </p>
        </div>
    </div>
</div>

<?php else: ?>
<div class="auth-fullscreen-wrapper">
    <div class="auth-left-panel mesh-gradient">
        <div class="auth-brand">
            <?= Html::img($iconBundle->baseUrl . '/favicon.ico', ['alt' => $_appTitle, 'class' => 'auth-logo-icon auth-brand-logo']) ?>
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
                        Html::img($authBundle->baseUrl . '/icons/' . $_name . '.svg', ['alt' => ucfirst($_name), 'class' => 'auth-badge-icon']),
                        Html::encode($_url),
                        ['class' => 'auth-badge', 'title' => ucfirst($_name), 'target' => '_blank', 'rel' => 'noopener noreferrer']
                    ) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="auth-right-panel">
        <div class="auth-form-inner">
            <div class="auth-mobile-logo">
                <?= Html::img($iconBundle->baseUrl . '/favicon.ico', ['alt' => $_appTitle, 'class' => 'auth-logo-icon']) ?>
                <span class="auth-mobile-logo-name"><?= $_appTitle ?></span>
            </div>
            <div class="auth-form-header">
                <h2 class="auth-form-title"><?= Module::t('Enter Verification Code') ?></h2>
                <p class="auth-form-subtitle">
                    <?php if ($pendingPhone): ?>
                        <?= Module::t('Enter the 6-digit code we sent to {phone}.', ['phone' => Html::encode($pendingPhone)]) ?>
                    <?php else: ?>
                        <?= Module::t('Enter the 6-digit code we sent to your phone.') ?>
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($flashSuccess): ?>
                <div class="alert alert-success py-2 mb-2 small"><?= Html::encode($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert alert-danger py-2 mb-2 small"><?= Html::encode($flashError) ?></div>
            <?php endif; ?>

            <?php if ($isAlreadyVerified): ?>
                <div class="alert alert-info py-2 mb-2 small">
                    <?= Module::t('Your phone number is already verified.') ?>
                </div>
            <?php endif; ?>

            <?= Html::beginForm(['/auth/default/verify-sms-verification'], 'post', ['id' => 'verify-sms-form']) ?>

            <div class="auth-field">
                <label class="auth-label" for="code"><?= Module::t('Verification Code') ?></label>
                <input type="text" name="code" id="code"
                       class="auth-input"
                       placeholder="000000"
                       inputmode="numeric"
                       pattern="\d{6}"
                       maxlength="6"
                       autocomplete="one-time-code"
                       autofocus required>
            </div>

            <div>
                <?= Html::submitButton(Module::t('Verify'), [
                    'class' => 'btn btn-outline-success auth-btn',
                ]) ?>
            </div>

            <?= Html::endForm() ?>

            <div class="auth-footer">
                <?= Module::t('Need a new code?') ?>
                <?= Html::a(Module::t('Request again'), ['/auth/default/request-sms-verification']) ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>