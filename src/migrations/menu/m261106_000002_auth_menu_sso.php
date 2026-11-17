<?php

use portalium\db\Migration;
use yii\db\Query;

class m261106_000002_auth_menu_sso extends Migration
{
    public function safeUp()
    {
        $this->update('menu_item', ['label' => 'SSO'], ['slug' => ['auth-provider-side', 'auth-provider-manage']]);

        $id = (new Query())->select('id_item')->from('menu_item')->where(['slug' => 'auth-log-manage'])->scalar($this->db);
        if ($id) {
            $this->delete('menu_item_child', ['id_child' => $id]);
            $this->delete('menu_item', ['id_item' => $id]);
        }

        return true;
    }

    public function safeDown()
    {
        $this->update('menu_item', ['label' => 'Corporate Login Settings'], ['slug' => 'auth-provider-side']);
        $this->update('menu_item', ['label' => 'Corporate Logins'], ['slug' => 'auth-provider-manage']);

        return true;
    }
}
