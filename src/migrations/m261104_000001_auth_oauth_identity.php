<?php

use portalium\auth\Module;
use portalium\user\Module as UserModule;
use yii\db\Migration;

class m261104_000001_auth_oauth_identity extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE=InnoDB'
            : null;
        $identity = Module::$tablePrefix . 'oauth_identity';

        $this->createTable($identity, [
            'id_identity' => $this->primaryKey(),
            'id_user' => $this->integer()->notNull(),
            'provider' => $this->string(16)->notNull(),
            'provider_id' => $this->string(255)->notNull(),
            'email' => $this->string(255)->null(),
            'date_create' => $this->dateTime()->notNull(),
        ], $options);
        $this->createIndex('uq-' . $identity . '-provider', $identity, ['provider', 'provider_id'], true);
        $this->createIndex('uq-' . $identity . '-id_user', $identity, ['id_user', 'provider'], true);
        $this->addForeignKey('fk-' . $identity . '-id_user', $identity, 'id_user', UserModule::$tablePrefix . 'user', 'id_user', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable(Module::$tablePrefix . 'oauth_identity');
    }
}
