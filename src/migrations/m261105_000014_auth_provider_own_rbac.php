<?php

use portalium\db\Migration;
use portalium\user\rbac\OwnRule;

class m261105_000014_auth_provider_own_rbac extends Migration
{
    private const LINKED = [
        'authWebProviderUpdateOwn',
        'authWebProviderDeleteOwn',
    ];

    private const UNLINKED = [
        'authWebProviderIndexOwn',
        'authWebProviderCreateOwn',
    ];

    public function safeUp()
    {
        $auth = Yii::$app->authManager;
        $rule = $auth->getRule((new OwnRule())->name);
        if ($rule === null) {
            $rule = new OwnRule();
            $auth->add($rule);
        }
        $role = Yii::$app->setting->getValue('site::admin_role');
        $admin = (isset($role) && $role != '') ? $auth->getRole($role) : $auth->getRole('admin');
        foreach (self::LINKED as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            if ($permission === null) {
                $permission = $auth->createPermission($permissionName);
                $permission->description = $permissionName;
                $permission->ruleName = $rule->name;
                $auth->add($permission);
            }
            if ($admin !== null && !$auth->hasChild($admin, $permission)) {
                $auth->addChild($admin, $permission);
            }
            $childPermission = $auth->getPermission(str_replace('Own', '', $permissionName));
            if ($childPermission !== null && !$auth->hasChild($permission, $childPermission)) {
                $auth->addChild($permission, $childPermission);
            }
        }

        foreach (self::UNLINKED as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            if ($permission === null) {
                $permission = $auth->createPermission($permissionName);
                $permission->description = $permissionName;
                $auth->add($permission);
            }
            if ($admin !== null && !$auth->hasChild($admin, $permission)) {
                $auth->addChild($admin, $permission);
            }
        }

        $workspaceAdmin = $auth->getRole((string) Yii::$app->setting->getValue('app::workspace::admin_role'));
        $createOwn = $auth->getPermission('authWebProviderCreateOwn');
        if ($workspaceAdmin !== null && !$auth->hasChild($workspaceAdmin, $createOwn)) {
            $auth->addChild($workspaceAdmin, $createOwn);
        }
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;
        foreach (array_merge(self::LINKED, self::UNLINKED) as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            if ($permission !== null) {
                $auth->remove($permission);
            }
        }
    }
}
