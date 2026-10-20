<?php

use portalium\auth\components\AccountStatus;
use portalium\auth\components\AuthNotice;
use portalium\theme\widgets\Alert;
use yii\helpers\Html;

$notice = AuthNotice::take();
if ($notice !== null) {
    $body = Html::encode($notice['text']);
    $contact = AccountStatus::approvalContact();
    if ($contact !== null && filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        $body = str_replace(Html::encode($contact), Html::mailto(Html::encode($contact), $contact), $body);
    }
    echo Alert::widget([
        'body' => $body,
        'closeButton' => false,
        'options' => ['class' => 'alert-' . $notice['type'] . ' auth-notice', 'role' => 'alert'],
    ]);
}
