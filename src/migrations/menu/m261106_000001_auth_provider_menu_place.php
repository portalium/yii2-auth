<?php

use portalium\db\Migration;
use yii\db\Query;

class m261106_000001_auth_provider_menu_place extends Migration
{
    public function safeUp()
    {
        $item = (new Query())->from('menu_item')->where(['slug' => 'auth-provider-side'])->one($this->db);
        if (!$item) {
            return true;
        }
        $style = str_replace(['fa-sign-in', 'fa-id-card'], 'fa-building', (string) $item['style']);
        $this->update('menu_item', ['label' => 'Corporate Login Settings', 'style' => $style], ['id_item' => $item['id_item']]);

        $audit = (new Query())->from('menu_item')->where(['slug' => 'audit-log-side'])->one($this->db);
        $parent = $audit
            ? (new Query())->select('id_item')->from('menu_item_child')->where(['id_child' => $audit['id_item']])->scalar($this->db)
            : false;
        $placed = (new Query())->from('menu_item_child')->where(['id_child' => $item['id_item']])->exists($this->db);
        if ($parent && !$placed) {
            $this->insert('menu_item_child', ['id_item' => $parent, 'id_child' => $item['id_item']]);
            $this->update('menu_item', ['sort' => (int) $audit['sort']], ['id_item' => $item['id_item']]);
        }

        return true;
    }

    public function safeDown()
    {
        return true;
    }
}
