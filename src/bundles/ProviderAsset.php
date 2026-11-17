<?php

namespace portalium\auth\bundles;

use portalium\theme\bundles\AppAsset;
use yii\web\AssetBundle;

class ProviderAsset extends AssetBundle
{
    public $sourcePath = '@vendor/portalium/yii2-auth/src/assets/provider';

    public $js = [
        'js/provider-form.js',
    ];

    public $depends = [
        AppAsset::class,
    ];

    public $publishOptions = [
        'forceCopy' => YII_DEBUG,
    ];
}
