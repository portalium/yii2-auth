<?php

use portalium\auth\Module;
use portalium\db\Migration;

class m261105_000005_auth_attempt extends Migration
{
    public function safeUp()
    {
        $table = '{{%' . Module::$tablePrefix . 'attempt}}';
        if ($this->db->getTableSchema($table, true) !== null) {
            return true;
        }

        $this->createTable($table, [
            'key_hash' => $this->char(64)->notNull(),
            'attempts' => $this->integer(11)->notNull()->defaultValue(0),
            'date_start' => $this->datetime()->notNull(),
            'date_lock' => $this->datetime()->null(),
            'PRIMARY KEY ([[key_hash]])',
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

        $this->createIndex('idx-' . Module::$tablePrefix . 'attempt-start', $table, 'date_start');

        return true;
    }

    public function safeDown()
    {
        $table = '{{%' . Module::$tablePrefix . 'attempt}}';
        if ($this->db->getTableSchema($table, true) !== null) {
            $this->dropTable($table);
        }

        return true;
    }
}
