<?php

use portalium\db\Migration;
use yii\db\Query;

class m261105_000017_auth_provider_manage_menu extends Migration
{
    public function safeUp()
    {
        $menu = (new Query())->from('menu_menu')->where(['slug' => 'web-main-menu'])->one($this->db);
        $site = $menu
            ? (new Query())->from('menu_item')->where(['slug' => 'site', 'id_menu' => $menu['id_menu']])->one($this->db)
            : null;
        if (!$site || (new Query())->from('menu_item')->where(['slug' => 'auth-provider-manage'])->exists($this->db)) {
            return true;
        }

        $sort = (new Query())->select('sort')->from('menu_item')->where(['slug' => 'audit-log-manage'])->scalar($this->db);
        $this->insert('menu_item', [
            'label' => 'Corporate Logins',
            'slug' => 'auth-provider-manage',
            'type' => 2,
            'style' => '{"icon":"","color":"","iconSize":"","display":"","childDisplay":"","placement":"1"}',
            'data' => '{"data":{"module":"auth","routeType":"action","route":"\\/auth\\/provider\\/manage","model":"","menuRoute":null,"menuType":"web"}}',
            'sort' => $sort !== false && $sort !== null ? (int) $sort : 12,
            'id_menu' => $menu['id_menu'],
            'name_auth' => 'authWebProviderManage',
            'id_user' => $menu['id_user'] ?? 1,
            'date_create' => date('Y-m-d H:i:s'),
            'date_update' => date('Y-m-d H:i:s'),
        ]);
        $this->insert('menu_item_child', [
            'id_item' => $site['id_item'],
            'id_child' => $this->db->getLastInsertID(),
        ]);

        return true;
    }

    public function safeDown()
    {
        $id = (new Query())->select('id_item')->from('menu_item')->where(['slug' => 'auth-provider-manage'])->scalar($this->db);
        if ($id) {
            $this->delete('menu_item_child', ['id_child' => $id]);
            $this->delete('menu_item', ['id_item' => $id]);
        }

        return true;
    }
}
