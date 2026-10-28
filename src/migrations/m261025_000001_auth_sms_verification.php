<?php

use yii\db\Migration;
use portalium\user\Module as UserModule;

class m261025_000001_auth_sms_verification extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%auth_sms_verification}}', [
            'id_user' => $this->integer()->notNull(),
            'active_phone' => $this->string(16)->null(),
            'active_code_hash' => $this->string(255)->null(),
            'active_expires_at' => $this->integer()->null(),
            'pending_phone' => $this->string(16)->null(),
            'pending_code_hash' => $this->string(255)->null(),
            'pending_expires_at' => $this->integer()->null(),
            'last_sent_at' => $this->integer()->notNull(),
            'attempts' => $this->smallInteger()->notNull()->defaultValue(0),
            'locked_until' => $this->integer()->null(),
            'window_started_at' => $this->integer()->null(),
            'sent_count' => $this->smallInteger()->notNull()->defaultValue(0),
        ]);
        $this->addPrimaryKey('pk-auth_sms_verification-id_user', '{{%auth_sms_verification}}', 'id_user');
        $this->addForeignKey(
            'fk-auth_sms_verification-user_user-id_user',
            '{{%auth_sms_verification}}',
            'id_user',
            '{{%' . UserModule::$tablePrefix . 'user}}',
            'id_user',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-auth_sms_verification-user_user-id_user', '{{%auth_sms_verification}}');
        $this->dropTable('{{%auth_sms_verification}}');
    }
}
