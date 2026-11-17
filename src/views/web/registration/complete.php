<?php

use portalium\auth\Module;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $items array[] */
/* @var $checked string[] */
/* @var $error string|null */

$this->title = Module::t('Complete registration');
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6 col-xl-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h3 class="card-title fw-bold mb-1"><?= Html::encode($this->title) ?></h3>
                    <p class="text-muted mb-4"><?= Module::t('Accept the documents below to continue.') ?></p>

                    <?php if ($error !== null): ?>
                        <div class="alert alert-danger" role="alert"><?= Html::encode($error) ?></div>
                    <?php endif; ?>

                    <?= Html::beginForm(['/auth/registration/complete'], 'post', ['id' => 'complete-registration-form']) ?>
                    <?php foreach ($items as $item): ?>
                        <?php $inputId = 'registration-' . preg_replace('/[^a-z0-9_-]/i', '-', $item['key']); ?>
                        <div class="form-check mb-3">
                            <?= Html::checkbox('accept[]', in_array($item['key'], $checked, true), [
                                'value' => $item['key'],
                                'id' => $inputId,
                                'class' => 'form-check-input',
                            ]) ?>
                            <label class="form-check-label" for="<?= $inputId ?>">
                                <?= Html::encode($item['label']) ?>
                                <span class="text-muted">
                                    (<?= $item['required'] ? Module::t('Required') : Module::t('Optional') ?>)
                                </span>
                            </label>
                            <?php if (!empty($item['url'])): ?>
                                <div>
                                    <?= Html::a(Module::t('Read'), $item['url'], [
                                        'target' => '_blank',
                                        'rel' => 'noopener',
                                    ]) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?= Html::submitButton(Module::t('Accept and continue'), ['class' => 'btn btn-success w-100']) ?>
                    <?= Html::endForm() ?>

                    <?= Html::beginForm(['/auth/default/logout'], 'post', ['class' => 'mt-2']) ?>
                    <?= Html::submitButton(Module::t('Sign out'), ['class' => 'btn btn-warning w-100']) ?>
                    <?= Html::endForm() ?>
                </div>
            </div>
        </div>
    </div>
</div>
