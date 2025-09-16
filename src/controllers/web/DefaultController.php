<?php

namespace {{vendor}}\{{package}}\controllers\web;

use portalium\web\Controller as WebController;

class DefaultController extends WebController
{
    public function actionIndex()
    {
        return $this->render('index');
    }
}