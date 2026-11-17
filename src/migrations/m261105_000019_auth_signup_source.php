<?php

use portalium\auth\Module;
use portalium\db\Migration;

class m261105_000019_auth_signup_source extends Migration
{
    public function safeUp()
    {
        $table = '{{%' . Module::$tablePrefix . 'signup_source}}';
        if ($this->db->getTableSchema($table, true) !== null) {
            return true;
        }

        $this->createTable($table, [
            'id_user' => $this->integer(11)->notNull(),
            'source' => $this->string(32)->notNull(),
            'id_provider' => $this->integer(11)->null(),
            'id_creator' => $this->integer(11)->null(),
            'date_create' => $this->datetime()->notNull(),
            'PRIMARY KEY ([[id_user]])',
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $this->createIndex('idx-' . Module::$tablePrefix . 'signup_source-provider', $table, 'id_provider');

        return true;
    }

    public function safeDown()
    {
        $table = '{{%' . Module::$tablePrefix . 'signup_source}}';
        if ($this->db->getTableSchema($table, true) !== null) {
            $this->dropTable($table);
        }

        return true;
    }
}
