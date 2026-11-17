<?php

namespace portalium\auth\controllers\web;

use portalium\auth\components\DomainVerifier;
use portalium\auth\components\ProviderTester;
use portalium\auth\models\AuthProvider;
use portalium\auth\Module;
use portalium\data\ActiveDataProvider;
use portalium\web\Controller;
use Yii;
use yii\filters\VerbFilter;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Corporate identity providers.
 * - index (side menu "Corporate Login"): the current workspace's providers; workspace admins (the app module's
 *   workspace admin role) manage their own workspace's, site admins any. verify checks the domain's DNS TXT record.
 * - manage (top menu, authWebProviderManage): every workspace's providers; approve / revoke a domain by hand.
 */
class ProviderController extends Controller
{
    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST'], 'test' => ['POST'], 'verify' => ['POST'], 'approve' => ['POST'], 'revoke' => ['POST'],
                ],
            ],
        ]);
    }

    public function actionIndex()
    {
        if (
            !Yii::$app->user->can('authWebProviderManage') &&
            !Yii::$app->workspace->can('app', 'authWebProviderIndex')
        ) {
            throw new ForbiddenHttpException(Module::t('You do not have permission to access this page.'));
        }

        return $this->render('index', [
            'dataProvider' => new ActiveDataProvider([
                'query' => AuthProvider::find()->andWhere(['id_workspace' => Yii::$app->workspace->id])->orderBy(['name' => SORT_ASC]),
            ]),
        ]);
    }

    public function actionCreate()
    {
        if (
            !Yii::$app->user->can('authWebProviderManage') &&
            !Yii::$app->workspace->can('app', 'authWebProviderCreateOwn')
        ) {
            throw new ForbiddenHttpException(Module::t('You do not have permission to access this page.'));
        }

        $model = new AuthProvider([
            'type' => AuthProvider::TYPE_OIDC,
            'status' => AuthProvider::STATUS_ACTIVE,
            'new_user_status' => AuthProvider::NEW_USER_ACTIVE,
            'id_user' => Yii::$app->user->id,
            'id_workspace' => Yii::$app->workspace->id,
            'workspaceIds' => [(int) Yii::$app->workspace->id],
        ]);
        if ($this->load($model) && $model->save()) {
            Yii::$app->session->setFlash('success', Module::t('Identity provider saved. Verify the domain to start using it.'));
            return $this->redirect(['update', 'id' => $model->id_provider]);
        }

        return $this->render('create', ['model' => $model, 'workspaces' => $this->workspaceChoices($model)]);
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $this->requireModelAccess($model, 'Update');

        if ($this->load($model) && $model->save()) {
            Yii::$app->session->setFlash('success', Module::t('Identity provider saved.'));
            return $this->redirect(['update', 'id' => $model->id_provider]);
        }

        return $this->render('update', ['model' => $model, 'workspaces' => $this->workspaceChoices($model)]);
    }

    public function actionTest($id)
    {
        $model = $this->findModel($id);
        $this->requireModelAccess($model, 'Update');
        [$ok, $message] = ProviderTester::test($model);
        Yii::$app->session->setFlash($ok ? 'success' : 'error', $message);

        return $this->redirect(['update', 'id' => $model->id_provider]);
    }

    /** Checks the domain's DNS TXT record (DomainVerifier). */
    public function actionVerify($id)
    {
        $model = $this->findModel($id);
        $this->requireModelAccess($model, 'Update');
        [$ok, $message] = DomainVerifier::dns($model);
        Yii::$app->session->setFlash($ok ? 'success' : 'error', $message);

        return $this->redirect(['update', 'id' => $model->id_provider]);
    }

    public function actionManage()
    {
        $this->requireManage();

        return $this->render('manage', [
            'dataProvider' => new ActiveDataProvider([
                'query' => AuthProvider::find()->with('workspace')->orderBy(['date_verified' => SORT_ASC, 'domain' => SORT_ASC]),
            ]),
        ]);
    }

    /** A site administrator's approval of a domain, for a customer who cannot add the DNS record. */
    public function actionApprove($id)
    {
        $this->requireManage();
        [$ok, $message] = DomainVerifier::approve($this->findModel($id));
        Yii::$app->session->setFlash($ok ? 'success' : 'error', $message);

        return $this->redirect(['manage']);
    }

    public function actionRevoke($id)
    {
        $this->requireManage();
        DomainVerifier::revoke($this->findModel($id));
        Yii::$app->session->setFlash('success', Module::t('The domain is no longer verified; nobody can sign in through it.'));

        return $this->redirect(['manage']);
    }

    public function actionDelete($id)
    {
        $model = $this->findModel($id);
        $this->requireModelAccess($model, 'Delete');
        $model->delete();
        Yii::$app->session->setFlash('success', Module::t('Identity provider deleted.'));

        return $this->redirect(['index']);
    }

    /** The provider form posts its fields, including the workspace list (none checked = empty list). */
    private function load(AuthProvider $model): bool
    {
        $post = Yii::$app->request->post();
        if (!$model->load($post)) {
            return false;
        }
        $model->workspaceIds = (array) ($post['AuthProvider']['workspaceIds'] ?? []);

        return true;
    }

    /** The workspaces the form offers: the ones this person manages, plus the provider's own and its current ones. */
    private function workspaceChoices(AuthProvider $model): array
    {
        $choices = AuthProvider::manageableWorkspaces((int) Yii::$app->user->id);
        $ids = array_unique(array_merge([(int) $model->id_workspace], (array) $model->workspaceIds));
        $missing = array_diff($ids, array_keys($choices));
        if ($missing !== []) {
            $choices += \portalium\workspace\models\Workspace::find()->select(['title', 'id_workspace'])
                ->where(['id_workspace' => $missing])->indexBy('id_workspace')->column();
        }

        return $choices;
    }

    private function requireManage(): void
    {
        if (!Yii::$app->user->can('authWebProviderManage')) {
            throw new ForbiddenHttpException(Module::t('You do not have permission to access this page.'));
        }
    }

    /**
     * The workspace admin role ("manager") can also be assigned globally, so a global check of the base permission
     * would open every workspace's providers to such a person: the global path is the site admin's manage permission,
     * the workspace path is checked against the provider's own workspace.
     */
    private function requireModelAccess(AuthProvider $model, string $action): void
    {
        if (
            !Yii::$app->user->can('authWebProviderManage') &&
            !Yii::$app->workspace->can('app', 'authWebProvider' . $action, ['model' => $model])
        ) {
            throw new ForbiddenHttpException(Module::t('You do not have permission to access this page.'));
        }
    }

    private function findModel($id): AuthProvider
    {
        $model = AuthProvider::findOne((int) $id);
        if ($model === null) {
            throw new NotFoundHttpException(Module::t('The identity provider was not found.'));
        }

        return $model;
    }
}
