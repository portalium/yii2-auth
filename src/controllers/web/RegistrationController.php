<?php

namespace portalium\auth\controllers\web;

use portalium\auth\components\RegistrationRequirements;
use portalium\auth\Module;
use portalium\web\Controller;
use Yii;

/**
 * "Complete registration" page: the signed-in user accepts the documents other modules require
 * (RegistrationRequirements) before reaching the app.
 */
class RegistrationController extends Controller
{
    public $layout = '@portalium/theme/layouts/main';

    public function beforeAction($action)
    {
        if (Yii::$app->setting->getValue('auth::layout') != '') {
            $this->layout = '@portalium/theme/layouts/' . Yii::$app->setting->getValue('auth::layout');
        }
        $this->view->params['colorMode'] = 'light';

        return parent::beforeAction($action);
    }

    public function actionComplete()
    {
        $user = Yii::$app->user->identity;
        $items = RegistrationRequirements::items($user);
        if (RegistrationRequirements::missingRequired($items) === []) {
            RegistrationRequirements::clear();
            return $this->redirect(RegistrationRequirements::returnUrl());
        }

        $checked = [];
        $error = null;
        if (Yii::$app->request->isPost) {
            $checked = array_map('strval', array_filter((array) Yii::$app->request->post('accept', []), 'is_scalar'));
            $missing = array_diff(array_column(RegistrationRequirements::missingRequired($items), 'key'), $checked);
            if ($missing !== []) {
                $error = Module::t('Please accept the required documents to continue.');
            } elseif (RegistrationRequirements::accept($user, $checked) !== []) {
                $error = Module::t('Your approval could not be saved. Please try again.');
            } elseif (RegistrationRequirements::isRequired($user)) {
                $error = Module::t('Please accept the required documents to continue.');
                $items = RegistrationRequirements::items($user);
            } else {
                RegistrationRequirements::clear();
                return $this->redirect(RegistrationRequirements::returnUrl());
            }
        }

        return $this->render('complete', [
            'items' => RegistrationRequirements::pending($items),
            'checked' => $checked,
            'error' => $error,
        ]);
    }
}
