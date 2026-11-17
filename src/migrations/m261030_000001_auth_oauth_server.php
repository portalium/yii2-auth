<?php

use portalium\auth\Module;
use yii\db\Migration;

class m261030_000001_auth_oauth_server extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE=InnoDB'
            : null;

        $this->createTable(Module::$tablePrefix . 'oauth_client', [
            'id_client' => $this->primaryKey(),
            'client_id' => $this->string(64)->notNull()->unique(),
            'client_secret_hash' => $this->string(64)->null(),
            'client_name' => $this->string(255)->null(),
            'redirect_uris' => $this->text()->notNull(),
            'auth_method' => $this->string(32)->notNull()->defaultValue('none'),
            'date_create' => $this->dateTime()->notNull(),
        ], $options);

        $this->createTable(Module::$tablePrefix . 'oauth_code', [
            'id_code' => $this->primaryKey(),
            'code_hash' => $this->string(64)->notNull()->unique(),
            'client_id' => $this->string(64)->notNull(),
            'id_user' => $this->integer()->notNull(),
            'redirect_uri' => $this->text()->notNull(),
            'code_challenge' => $this->string(128)->notNull(),
            'resource' => $this->text()->null(),
            'scope' => $this->string(255)->null(),
            'expires_at' => $this->integer()->notNull(),
            'used' => $this->tinyInteger(1)->notNull()->defaultValue(0),
            'date_create' => $this->dateTime()->notNull(),
        ], $options);

        $this->createIndex('idx-' . Module::$tablePrefix . 'oauth_code-expires_at', Module::$tablePrefix . 'oauth_code', 'expires_at');
    }

    public function safeDown()
    {
        $this->dropTable(Module::$tablePrefix . 'oauth_code');
        $this->dropTable(Module::$tablePrefix . 'oauth_client');
    }
}
