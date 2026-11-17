<?php

use portalium\db\Migration;

class m261105_000013_auth_provider_rbac extends Migration
{
    private const PERMISSIONS = [
        'authWebProviderIndex',
        'authWebProviderCreate',
        'authWebProviderUpdate',
        'authWebProviderDelete',
    ];

    private const WORKSPACE_ADMIN_PERMISSIONS = [
        'authWebProviderIndex',
        'authWebProviderUpdate',
        'authWebProviderDelete',
    ];

    public function safeUp()
    {
        $auth = Yii::$app->authManager;
        $role = Yii::$app->setting->getValue('site::admin_role');
        $admin = (isset($role) && $role != '') ? $auth->getRole($role) : $auth->getRole('admin');
        foreach (self::PERMISSIONS as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            if ($permission === null) {
                $permission = $auth->createPermission($permissionName);
                $permission->description = ucfirst(str_replace('auth', '', $permissionName));
                $auth->add($permission);
            }
            if ($admin !== null && !$auth->hasChild($admin, $permission)) {
                $auth->addChild($admin, $permission);
            }
        }

        try {
            $workspaceAdminRole = (string) Yii::$app->setting->getValue('app::workspace::admin_role');
        } catch (\Exception $e) {
            $workspaceAdminRole = '';
        }
        $workspaceAdmin = $workspaceAdminRole === '' ? null : $auth->getRole($workspaceAdminRole);
        if ($workspaceAdmin !== null) {
            foreach (self::WORKSPACE_ADMIN_PERMISSIONS as $permissionName) {
                $permission = $auth->getPermission($permissionName);
                if (!$auth->hasChild($workspaceAdmin, $permission)) {
                    $auth->addChild($workspaceAdmin, $permission);
                }
            }
        }
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;
        foreach (self::PERMISSIONS as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            if ($permission !== null) {
                $auth->remove($permission);
            }
        }
    }
}
