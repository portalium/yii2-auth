<?php

namespace portalium\auth\controllers\web;

use portalium\auth\models\AuthLogSearch;
use portalium\auth\Module;
use portalium\web\Controller;
use Yii;
use yii\web\ForbiddenHttpException;

/**
 * The login log of the whole system (top menu, site admins). IP addresses show only here.
 */
class LogController extends Controller
{
    public function actionManage()
    {
        if (!Yii::$app->user->can('authWebLogManage')) {
            throw new ForbiddenHttpException(Module::t('You do not have permission to access this page.'));
        }

        $searchModel = new AuthLogSearch();

        return $this->render('manage', [
            'searchModel' => $searchModel,
            'dataProvider' => $searchModel->search(Yii::$app->request->queryParams),
        ]);
    }
}
