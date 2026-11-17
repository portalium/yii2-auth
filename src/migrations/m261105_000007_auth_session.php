<?php

use portalium\auth\Module;
use portalium\db\Migration;

class m261105_000007_auth_session extends Migration
{
    public function safeUp()
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        $session = '{{%' . Module::$tablePrefix . 'session}}';
        if ($this->db->getTableSchema($session, true) === null) {
            $this->createTable($session, [
                'id_session' => $this->bigPrimaryKey(),
                'token_hash' => $this->char(64)->notNull()->unique(),
                'id_user' => $this->integer(11)->notNull(),
                'method' => $this->string(32)->null(),
                'ip' => $this->string(45)->null(),
                'user_agent' => $this->string(255)->null(),
                'date_create' => $this->datetime()->notNull(),
                'date_active' => $this->datetime()->notNull(),
                'date_expire' => $this->datetime()->notNull(),
                'date_end' => $this->datetime()->null(),
                'end_reason' => $this->string(32)->null(),
            ], $options);
            $this->createIndex('idx-' . Module::$tablePrefix . 'session-user', $session, ['id_user', 'date_end', 'date_expire']);
            $this->createIndex('idx-' . Module::$tablePrefix . 'session-expire', $session, 'date_expire');
        }

        $limit = '{{%' . Module::$tablePrefix . 'session_limit}}';
        if ($this->db->getTableSchema($limit, true) === null) {
            $this->createTable($limit, [
                'id_user' => $this->integer(11)->notNull(),
                'max_sessions' => $this->integer(11)->notNull(),
                'PRIMARY KEY ([[id_user]])',
            ], $options);
        }

        return true;
    }

    public function safeDown()
    {
        foreach (['session_limit', 'session'] as $name) {
            $table = '{{%' . Module::$tablePrefix . $name . '}}';
            if ($this->db->getTableSchema($table, true) !== null) {
                $this->dropTable($table);
            }
        }

        return true;
    }
}
