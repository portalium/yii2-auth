<?php

use yii\helpers\Html;
use portalium\auth\Module;
use portalium\auth\bundles\AuthAsset;
use portalium\theme\bundles\IconAsset;

$this->title = Module::t('Verify Phone Number');

$authBundle = AuthAsset::register($this);
$iconBundle = IconAsset::register($this);

$_appTitle = Html::encode(Yii::$app->setting->getValue('app::title') ?? 'Portalium');

try {
    $_layout = Yii::$app->setting->getValue('auth::layout_style') ?? 'split';
} catch (\Exception $e) { $_layout = 'split'; }

try {
    $_heroTitle = trim(Yii::$app->setting->getValue('auth::login_hero_title') ?? '');
} catch (\Exception $e) { $_heroTitle = ''; }
if (empty($_heroTitle)) { $_heroTitle = Module::t('One Command Center'); }

try {
    $_heroSubtitle = trim(Yii::$app->setting->getValue('auth::login_hero_subtitle') ?? '');
} catch (\Exception $e) { $_heroSubtitle = ''; }
if (empty($_heroSubtitle)) { $_heroSubtitle = Module::t('Manage your entire organization with a single login.'); }

$_integrationNames = ['slack', 'github', 'jira', 'notion', 'linkedin', 'google'];
$_visibleIntegrations = [];
foreach ($_integrationNames as $_name) {
    try { $_url = trim(Yii::$app->setting->getValue('auth::' . $_name . '_url') ?? ''); }
    catch (\Exception $e) { $_url = ''; }
    if (!empty($_url)) { $_visibleIntegrations[$_name] = $_url; }
}

$emailVerified = (int) $user->email_verify === \portalium\user\models\User::EMAIL_VERIFY;
$alreadyVerified = (int) $user->sms_verified === \portalium\user\models\User::SMS_VERIFY;
$flashError = Yii::$app->session->getFlash('error');
$flashSuccess = Yii::$app->session->getFlash('success');
?>

<?php if ($_layout === 'single'): ?>
<div class="position-fixed top-0 start-0 vw-100 vh-100 d-flex flex-column overflow-hidden bg-white" style="z-index:9999;margin:0;padding:0;">
    <div class="d-flex flex-column flex-grow-1 align-items-center justify-content-center w-100 pt-5">
        <div class="d-flex flex-column align-items-center mb-4">
            <?= Html::img($iconBundle->baseUrl . '/favicon.ico', ['alt' => $_appTitle, 'style' => 'width:48px;height:48px;']) ?>
            <span class="fw-bold fs-4 text-dark mt-2"><?= $_appTitle ?></span>
        </div>

        <div class="bg-white rounded-4 shadow-lg px-4 py-4" style="min-width:320px;max-width:440px;width:100%;">
            <h3 class="card-title text-center mb-1 fw-bold text-dark"><?= Module::t('Verify Your Phone Number') ?></h3>
            <p class="text-center text-muted mb-3">
                <?= Module::t('Enter the phone number you want to verify. We will send a 6-digit code by SMS.') ?>
            </p>

            <?php if ($flashSuccess): ?>
                <div class="alert alert-success py-2 mb-2 small"><?= Html::encode($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert alert-danger py-2 mb-2 small"><?= Html::encode($flashError) ?></div>
            <?php endif; ?>

            <?php if (!$emailVerified): ?>
                <div class="alert alert-warning py-2 mb-2 small">
                    <?= Module::t('Email must be verified before phone verification.') ?>
                </div>
            <?php endif; ?>

            <?php if ($alreadyVerified): ?>
                <div class="alert alert-info py-2 mb-2 small">
                    <?= Module::t('Your phone number is already verified.') ?>
                    <?php if ($user->tel_no): ?>
                        <br><strong><?= Html::encode($user->tel_no) ?></strong>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?= Html::beginForm(['/auth/default/request-sms-verification'], 'post', ['id' => 'request-sms-form']) ?>

            <div class="auth-sl-field mb-2">
                <label class="auth-sl-label" for="tel_no"><?= Module::t('Phone Number') ?></label>
                <input type="tel" name="tel_no" id="tel_no"
                       class="auth-sl-input"
                       value="<?= Html::encode($prefillPhone) ?>"
                       placeholder="+905551234567"
                       autocomplete="tel" required>
                <div class="form-text small text-muted mt-1">
                    <?= Module::t('Use the international format, e.g. +905551234567') ?>
                </div>
            </div>

            <?= Html::submitButton(Module::t('Send Verification Code'), [
                'class' => 'btn bg-white border border-2 border-success text-dark fw-bold w-100 mb-2',
                'style' => 'border-radius:0.375rem;padding:0.7rem 1rem;font-size:1rem;',
                'disabled' => !$emailVerified,
            ]) ?>
            
            <?= Html::endForm() ?>
            
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
                <h2 class="auth-form-title"><?= Module::t('Verify Your Phone Number') ?></h2>
                <p class="auth-form-subtitle"><?= Module::t('Enter the number you want to verify. We will send a 6-digit code by SMS.') ?></p>
            </div>

            <?php if ($flashSuccess): ?>
                <div class="alert alert-success py-2 mb-2 small"><?= Html::encode($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <div class="alert alert-danger py-2 mb-2 small"><?= Html::encode($flashError) ?></div>
            <?php endif; ?>

            <?php if (!$emailVerified): ?>
                <div class="alert alert-warning py-2 mb-2 small">
                    <?= Module::t('Email must be verified before phone verification.') ?>
                </div>
            <?php endif; ?>

            <?php if ($alreadyVerified): ?>
                <div class="alert alert-info py-2 mb-2 small">
                    <?= Module::t('Your phone number is already verified.') ?>
                    <?php if ($user->tel_no): ?>
                        <br><strong><?= Html::encode($user->tel_no) ?></strong>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?= Html::beginForm(['/auth/default/request-sms-verification'], 'post', ['id' => 'request-sms-form']) ?>

            <div class="auth-field">
                <label class="auth-label" for="tel_no"><?= Module::t('Phone Number') ?></label>
                <input type="tel" name="tel_no" id="tel_no"
                       class="auth-input"
                       value="<?= Html::encode($prefillPhone) ?>"
                       placeholder="+905551234567"
                       autocomplete="tel" required>
                <div class="form-text small text-muted mt-1">
                    <?= Module::t('Use the international format, e.g. +905551234567') ?>
                </div>
            </div>

            <div>
                <?= Html::submitButton(Module::t('Send Verification Code'), [
                    'class' => 'btn btn-outline-success auth-btn',
                    'disabled' => !$emailVerified,
                ]) ?>
            </div>

            <?= Html::endForm() ?>

            <div class="auth-footer">
                <?= Html::a(Module::t('I already have a code'), ['/auth/default/verify-sms-verification']) ?>
            </div>
            <p class="auth-sl-footer-text mb-0 text-center">
                <?= Html::a(Module::t('Back to Profile'), ['/site/profile/edit'], ['class' => 'auth-sl-link']) ?>
            </p>

        </div>
    </div>
</div>
<?php endif; ?>