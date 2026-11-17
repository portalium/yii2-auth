<?php

use portalium\auth\Module;

/** @var yii\web\View $this */
/** @var portalium\auth\models\AuthProvider $model */
/** @var array<int, string> $workspaces */

$this->title = Module::t('New Identity Provider');
$this->params['breadcrumbs'][] = ['label' => Module::t('SSO'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="auth-provider-create">
    <?= $this->render('_form', ['model' => $model, 'workspaces' => $workspaces]) ?>
</div>
