<?php

namespace portalium\auth\controllers\web;

use portalium\auth\components\SessionTracker;
use portalium\auth\models\AuthSession;
use portalium\auth\Module;
use portalium\data\ActiveDataProvider;
use portalium\user\models\User;
use portalium\web\Controller;
use Yii;
use yii\data\ArrayDataProvider;
use yii\filters\VerbFilter;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Signed-in sessions.
 * - end / end-others: the user's own sessions, from the profile ("Active sessions" panel).
 * - manage (top menu, authWebSessionManage): every active session with its IP, ending any of them, and the accounts
 *   that have their own session limit.
 */
class SessionController extends Controller
{
    private const PROFILE_ROUTE = '/site/profile/edit';

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['end' => ['post'], 'end-others' => ['post'], 'revoke' => ['post'], 'limit' => ['post']],
            ],
        ]);
    }

    public function actionEnd($id)
    {
        $row = AuthSession::findActive()->andWhere(['id_session' => (int) $id, 'id_user' => (int) Yii::$app->user->id])->one();
        if ($row === null) {
            throw new NotFoundHttpException(Module::t('The session was not found.'));
        }
        SessionTracker::end($row, AuthSession::END_BY_USER);
        Yii::$app->session->addFlash('success', Module::t('The session was ended.'));

        return $this->redirect([self::PROFILE_ROUTE]);
    }

    public function actionEndOthers()
    {
        $count = SessionTracker::endOthers((int) Yii::$app->user->id, AuthSession::END_BY_USER);
        Yii::$app->session->addFlash('success', Module::t('{n} other sessions were ended.', ['n' => $count]));

        return $this->redirect([self::PROFILE_ROUTE]);
    }

    public function actionManage()
    {
        $this->requireManage();
        $username = trim((string) Yii::$app->request->get('username', ''));
        $query = AuthSession::findActive()->alias('s')->with('user');
        if ($username !== '') {
            $query->joinWith('user u', false)->andWhere(['or', ['like', 'u.username', $username], ['like', 'u.email', $username]]);
        }
        $limits = SessionTracker::ownLimits();
        $current = SessionTracker::current();

        return $this->render('manage', [
            'dataProvider' => new ActiveDataProvider([
                'query' => $query,
                'sort' => [
                    'defaultOrder' => ['date_active' => SORT_DESC],
                    'attributes' => [
                        'date_active' => ['asc' => ['s.date_active' => SORT_ASC], 'desc' => ['s.date_active' => SORT_DESC]],
                        'date_create' => ['asc' => ['s.date_create' => SORT_ASC], 'desc' => ['s.date_create' => SORT_DESC]],
                    ],
                ],
            ]),
            'username' => $username,
            'limitProvider' => new ArrayDataProvider([
                'allModels' => array_map(fn ($id, $n) => ['id_user' => (int) $id, 'limit' => (int) $n], array_keys($limits), $limits),
                'pagination' => ['pageSize' => 20],
            ]),
            'limitUsers' => User::find()->where(['id_user' => array_keys($limits)])->indexBy('id_user')->all(),
            'defaultLimit' => SessionTracker::limitFor(0),
            'currentId' => $current !== null ? (int) $current->id_session : null,
        ]);
    }

    public function actionRevoke($id)
    {
        $this->requireManage();
        $row = AuthSession::findActive()->andWhere(['id_session' => (int) $id])->one();
        if ($row === null) {
            throw new NotFoundHttpException(Module::t('The session was not found.'));
        }
        SessionTracker::end($row, AuthSession::END_BY_ADMIN);
        Yii::$app->session->addFlash('success', Module::t('The session was ended.'));

        return $this->redirect(['manage']);
    }

    /** Sets an account's own limit (a number, 0 = unlimited) or, with an empty limit, removes it. */
    public function actionLimit()
    {
        $this->requireManage();
        $request = Yii::$app->request;
        $name = trim((string) $request->post('username', ''));
        $user = $name === '' ? null : User::find()->where(['or', ['username' => $name], ['email' => $name]])->one();
        $limit = trim((string) $request->post('limit', ''));
        if ($user === null) {
            Yii::$app->session->addFlash('error', Module::t('No user with this username or email.'));
        } elseif ($limit !== '' && (!ctype_digit($limit) || (int) $limit > 1000)) {
            Yii::$app->session->addFlash('error', Module::t('The limit must be a number from 0 to 1000.'));
        } else {
            SessionTracker::setLimit((int) $user->id_user, $limit === '' ? null : (int) $limit);
            Yii::$app->session->addFlash('success', Module::t('The session limit of {user} was saved.', ['user' => $user->username]));
        }

        return $this->redirect(['manage']);
    }

    private function requireManage(): void
    {
        if (!Yii::$app->user->can('authWebSessionManage')) {
            throw new ForbiddenHttpException(Module::t('You do not have permission to access this page.'));
        }
    }
}
