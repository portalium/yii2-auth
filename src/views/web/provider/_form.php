<?php

use portalium\auth\bundles\ProviderAsset;
use portalium\auth\models\AuthProvider;
use portalium\auth\Module;
use portalium\theme\helpers\Html;
use portalium\theme\widgets\ActiveForm;
use portalium\theme\widgets\Panel;

/** @var yii\web\View $this */
/** @var portalium\auth\models\AuthProvider $model */
/** @var array<int, string> $workspaces */

ProviderAsset::register($this);
$t = fn (array $labels) => array_map(fn ($label) => Module::t($label), $labels);
$keep = fn (string $field) => $model->hasSecret($field) ? Module::t('Leave blank to keep the saved value') : '';
?>

<?php $form = ActiveForm::begin(['id' => 'auth-provider-form']); ?>

<?php Panel::begin([
    'title' => $model->isNewRecord ? Module::t('New Identity Provider') : Html::encode($model->name),
    'actions' => [
        'footer' => [
            Html::submitButton(Module::t('Save'), ['class' => 'btn btn-success']),
        ],
    ],
]) ?>

<?= $form->field($model, 'name')->textInput(['maxlength' => true]) ?>

<?= $form->field($model, 'type')->dropDownList($t(AuthProvider::typeLabels())) ?>

<?= $form->field($model, 'domain')->textInput(['placeholder' => 'company.com'])
    ->hint(Module::t('People with this email domain sign in through this provider, after the domain is verified.')) ?>

<?= $form->field($model, 'new_user_status')->dropDownList($t(AuthProvider::newUserStatusLabels())) ?>

<?= $form->field($model, 'workspaceIds')->checkboxList($workspaces) ?>

<?= $form->field($model, 'status')->dropDownList([
    AuthProvider::STATUS_ACTIVE => Module::t('Active'),
    AuthProvider::STATUS_PASSIVE => Module::t('Passive'),
]) ?>

<div class="provider-type-fields" data-type="<?= AuthProvider::TYPE_LDAP ?>">
    <?= $form->field($model, 'host')->textInput(['placeholder' => 'ldap.company.com']) ?>
    <?= $form->field($model, 'directory_type')->dropDownList($t(AuthProvider::directoryTypeLabels())) ?>
    <?= $form->field($model, 'encryption')->dropDownList(AuthProvider::encryptionLabels()) ?>
    <?= $form->field($model, 'port')->textInput(['placeholder' => '636 / 389']) ?>
    <?= $form->field($model, 'bind_dn')->textInput(['placeholder' => 'CN=suite-reader,OU=Service,DC=company,DC=com']) ?>
    <?= $form->field($model, 'bind_password')->passwordInput(['value' => '', 'autocomplete' => 'new-password', 'placeholder' => $keep('bind_password')]) ?>
    <?= $form->field($model, 'base_dn')->textInput(['placeholder' => 'DC=company,DC=com']) ?>
    <?= $form->field($model, 'group_dn')->textInput(['placeholder' => 'CN=Suite Users,OU=Groups,DC=company,DC=com'])
        ->hint(Module::t('Only members of this group can sign in.')) ?>
    <?= $form->field($model, 'user_filter')->textInput(['placeholder' => \portalium\auth\components\LdapDirectory::DEFAULT_FILTER]) ?>
    <?= $form->field($model, 'id_attribute')->textInput(['placeholder' => 'objectGUID / entryUUID']) ?>
    <?= $form->field($model, 'ca_cert')->textarea(['rows' => 4, 'placeholder' => '-----BEGIN CERTIFICATE-----']) ?>
</div>

<div class="provider-type-fields" data-type="<?= AuthProvider::TYPE_OIDC ?>">
    <?= $form->field($model, 'issuer')->textInput(['placeholder' => 'https://login.microsoftonline.com/<tenant>/v2.0']) ?>
    <?= $form->field($model, 'client_id')->textInput() ?>
    <?= $form->field($model, 'client_secret')->passwordInput(['value' => '', 'autocomplete' => 'new-password', 'placeholder' => $keep('client_secret')]) ?>
    <?= $form->field($model, 'scopes')->textInput() ?>
</div>

<div class="provider-type-fields" data-type="<?= AuthProvider::TYPE_SAML ?>">
    <?= $form->field($model, 'idp_entity_id')->textInput() ?>
    <?= $form->field($model, 'idp_sso_url')->textInput(['placeholder' => 'https://']) ?>
    <?= $form->field($model, 'idp_x509cert')->textarea(['rows' => 6, 'placeholder' => '-----BEGIN CERTIFICATE-----']) ?>
</div>

<?php Panel::end() ?>

<?php ActiveForm::end(); ?>
