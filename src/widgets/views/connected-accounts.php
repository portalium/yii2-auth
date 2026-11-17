<?php

use yii\helpers\Html;
use portalium\auth\Module;
use portalium\auth\components\OAuthLink;
use portalium\theme\widgets\Panel;

/** @var yii\web\View $this */
/** @var string[] $providers */
/** @var array<string, array> $identities */

$icons = [OAuthLink::GOOGLE => 'fa fa-google', OAuthLink::APPLE => 'fa fa-apple'];
?>
<?php Panel::begin([
    'title' => Html::encode(Module::t('Linked accounts')),
]) ?>
<ul class="list-group list-group-flush" id="connected-accounts">
    <?php foreach ($providers as $provider): ?>
        <?php $label = OAuthLink::label($provider); ?>
        <?php $identity = $identities[$provider] ?? null; ?>
        <li class="list-group-item d-flex align-items-center justify-content-between gap-3 px-0" data-provider="<?= Html::encode($provider) ?>">
            <div class="d-flex align-items-center gap-2">
                <i class="<?= $icons[$provider] ?> fa-lg" aria-hidden="true"></i>
                <div>
                    <div class="fw-semibold"><?= Html::encode($label) ?></div>
                    <div class="small text-body-secondary">
                        <?= $identity !== null
                            ? Html::encode($identity['email'] ?: Module::t('Linked'))
                            : Module::t('Not linked') ?>
                    </div>
                </div>
            </div>
            <?php if ($identity !== null): ?>
                <?= Html::a(Module::t('Remove'), ['/auth/default/disconnect'], [
                    'class' => 'btn btn-danger btn-sm',
                    'data' => [
                        'method' => 'post',
                        'params' => ['provider' => $provider],
                        'confirm' => Module::t('Remove {provider} from your account? You will not be able to sign in with it until you link it again.', [
                            'provider' => $label,
                        ]),
                    ],
                ]) ?>
            <?php else: ?>
                <?= Html::a(Module::t('Link'), ['/auth/default/connect-' . $provider], ['class' => 'btn btn-success btn-sm']) ?>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
<?php Panel::end() ?>
