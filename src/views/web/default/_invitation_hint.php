<?php

use portalium\auth\Module;
use portalium\theme\widgets\Alert;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var array{workspace:string, masked_email:string}|null $hint SignupForm::invitationHint() */
/** @var bool $hasSocial whether Google/Apple etc. signup buttons are shown */
/** @var bool $signed whether the form was opened from the signed invitation link in the email (decision 35/49, 45) */

if ($hint === null) {
    return;
}
$signed = $signed ?? false;

$lines = [Module::t('You have been invited to the «{workspace}» workspace.', ['workspace' => $hint['workspace']])];
if ($signed) {
    $lines[] = Module::t('You are signing up with the address the invitation was sent to; your account will be active right away.');
    if ($hasSocial) {
        $lines[] = Module::t('For an account that is active right away through the invitation, fill in the form below with the address the invitation was sent to.');
    }
} else {
    $lines[] = Module::t(
        'If you sign up with the address the invitation was sent to ({email}) using the link in the invitation email, your account will be active right away.',
        ['email' => $hint['masked_email']]
    );
}

echo Alert::widget([
    'body' => Html::encode(implode(' ', $lines)),
    'closeButton' => false,
    'options' => ['id' => 'auth-invitation-hint', 'class' => 'alert-info', 'role' => 'status'],
]);
