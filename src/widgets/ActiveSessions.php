<?php

namespace portalium\auth\widgets;

use portalium\auth\components\SessionTracker;
use portalium\auth\models\AuthSession;
use Yii;
use yii\base\Widget;

/**
 * Profile panel listing the signed-in account's active sessions (browser, last activity), with [End] per session
 * and [End other sessions]. The IP address is not shown here (only on the administrator's page).
 */
class ActiveSessions extends Widget
{
    public function run()
    {
        if (Yii::$app->user->isGuest) {
            return '';
        }
        $current = SessionTracker::current();

        return $this->render('active-sessions', [
            'sessions' => AuthSession::findActive()
                ->andWhere(['id_user' => (int) Yii::$app->user->id])
                ->orderBy(['date_active' => SORT_DESC])
                ->all(),
            'currentId' => $current !== null ? (int) $current->id_session : null,
            'limit' => SessionTracker::limitFor((int) Yii::$app->user->id),
        ]);
    }
}
