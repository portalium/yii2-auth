<?php

use portalium\db\Migration;

class m261105_000016_auth_provider_manage_rbac extends Migration
{
    private const PERMISSIONS = [
        'authWebProviderManage',
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
