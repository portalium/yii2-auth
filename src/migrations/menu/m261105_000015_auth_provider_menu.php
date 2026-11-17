<?php

use portalium\db\Migration;
use yii\db\Query;

class m261105_000015_auth_provider_menu extends Migration
{
    public function safeUp()
    {
        $menu = (new Query())->from('menu_menu')->where(['slug' => 'web-side-menu'])->one($this->db);
        if (!$menu || (new Query())->from('menu_item')->where(['slug' => 'auth-provider-side'])->exists($this->db)) {
            return true;
        }

        $sort = (new Query())->select('sort')->from('menu_item')->where(['slug' => 'audit-log-side'])->scalar($this->db);
        $this->insert('menu_item', [
            'label' => 'Corporate Login Settings',
            'slug' => 'auth-provider-side',
            'type' => 2,
            'style' => '{"icon":"fa-building","color":"","iconSize":"","display":"3","childDisplay":"","placement":"1"}',
            'data' => '{"data":{"module":"app","routeType":"action","route":"\\/auth\\/provider\\/index","model":"","menuRoute":null,"menuType":null}}',
            'sort' => $sort !== false && $sort !== null ? (int) $sort : 16,
            'id_menu' => $menu['id_menu'],
            'name_auth' => 'authWebProviderIndex',
            'id_user' => $menu['id_user'] ?? 1,
            'date_create' => date('Y-m-d H:i:s'),
            'date_update' => date('Y-m-d H:i:s'),
        ]);
        $id = $this->db->getLastInsertID();
        $audit = (new Query())->select('id_item')->from('menu_item')->where(['slug' => 'audit-log-side'])->scalar($this->db);
        $parent = $audit ? (new Query())->select('id_item')->from('menu_item_child')->where(['id_child' => $audit])->scalar($this->db) : false;
        if ($parent) {
            $this->insert('menu_item_child', ['id_item' => $parent, 'id_child' => $id]);
        }

        return true;
    }

    public function safeDown()
    {
        $this->delete('menu_item', ['slug' => 'auth-provider-side']);

        return true;
    }
}
