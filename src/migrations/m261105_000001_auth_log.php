<?php

use portalium\auth\Module;
use portalium\db\Migration;

class m261105_000001_auth_log extends Migration
{
    public function safeUp()
    {
        $table = '{{%' . Module::$tablePrefix . 'log}}';
        if ($this->db->getTableSchema($table, true) !== null) {
            return true;
        }

        $this->createTable($table, [
            'id_log' => $this->bigPrimaryKey(),
            'event' => $this->string(32)->notNull(),
            'result' => $this->string(16)->notNull()->defaultValue('ok'),
            'method' => $this->string(32)->null(),
            'reason' => $this->string(64)->null(),
            'id_user' => $this->integer(11)->null(),
            'username' => $this->string(128)->null(),
            'app' => $this->string(16)->null(),
            'ip' => $this->string(45)->null(),
            'user_agent' => $this->string(255)->null(),
            'date_create' => $this->datetime()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

        $prefix = Module::$tablePrefix . 'log';
        $this->createIndex('idx-' . $prefix . '-user-date', $table, ['id_user', 'date_create']);
        $this->createIndex('idx-' . $prefix . '-event-date', $table, ['event', 'date_create']);
        $this->createIndex('idx-' . $prefix . '-ip-date', $table, ['ip', 'date_create']);
        $this->createIndex('idx-' . $prefix . '-date', $table, 'date_create');

        return true;
    }

    public function safeDown()
    {
        $table = '{{%' . Module::$tablePrefix . 'log}}';
        if ($this->db->getTableSchema($table, true) !== null) {
            $this->dropTable($table);
        }

        return true;
    }
}
