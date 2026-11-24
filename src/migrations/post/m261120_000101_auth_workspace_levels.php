<?php

use yii\db\Migration;
use yii\db\Query;

class m261120_000101_auth_workspace_levels extends Migration
{
    private const LEVELS = [
        'ws-manager' => [
            'authWebProviderCreate',
            'authWebProviderDelete',
            'authWebProviderIndex',
            'authWebProviderUpdate',
        ],
    ];

    private const RENAMED = [];

    public function safeUp()
    {
        $auth = Yii::$app->authManager;
        if ($auth->getRole('ws-manager') === null) {
            return true;
        }
        $role = Yii::$app->setting->getValue('site::admin_role');
        $admin = (isset($role) && $role != '') ? $auth->getRole($role) : $auth->getRole('admin');
        $userRoleName = (new Query())->select('value')->from('{{%site_setting}}')->where(['name' => 'site::user_role'])->scalar($this->db);
        $user = $auth->getRole($userRoleName ? (string) $userRoleName : 'user');

        foreach (self::RENAMED as $old => $new) {
            $item = $auth->getPermission($old);
            if ($item !== null && $item->name !== $new) {
                $item->name = $new;
                $auth->update($old, $item);
            }
        }

        foreach (self::LEVELS as $levelName => $permissionNames) {
            $level = $auth->getRole($levelName);
            if ($level === null) {
                continue;
            }
            foreach ($permissionNames as $permissionName) {
                $permission = $auth->getPermission($permissionName);
                if ($permission === null) {
                    $permission = $auth->createPermission($permissionName);
                    $permission->description = $permissionName . ' (workspace levels)';
                    $auth->add($permission);
                    if ($admin !== null) {
                        $auth->addChild($admin, $permission);
                    }
                }
                if (!$auth->hasChild($level, $permission)) {
                    $auth->addChild($level, $permission);
                }
                if ($user !== null && $permission->ruleName === null && $auth->hasChild($user, $permission)) {
                    $auth->removeChild($user, $permission);
                }
            }
        }

        return true;
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;
        foreach (self::LEVELS as $levelName => $permissionNames) {
            $level = $auth->getRole($levelName);
            if ($level === null) {
                continue;
            }
            foreach ($permissionNames as $permissionName) {
                $permission = $auth->getPermission($permissionName);
                if ($permission !== null && $auth->hasChild($level, $permission)) {
                    $auth->removeChild($level, $permission);
                }
            }
        }

        return true;
    }
}
