<?php

use portalium\auth\Module;
use portalium\db\Migration;
use portalium\site\models\Form;
use portalium\site\Module as SiteModule;
use yii\db\Query;

class m261105_000012_auth_provider extends Migration
{
    public function safeUp()
    {
        $table = '{{%' . Module::$tablePrefix . 'provider}}';
        if ($this->db->getTableSchema($table, true) === null) {
            $this->createTable($table, [
                'id_provider' => $this->primaryKey(),
                'id_workspace' => $this->integer(11)->notNull(),
                'id_user' => $this->integer(11)->null(),
                'type' => $this->string(16)->notNull(),
                'name' => $this->string(128)->notNull(),
                'domain' => $this->string(255)->notNull(),
                'config' => $this->text()->null(),
                'new_user_status' => $this->string(16)->notNull()->defaultValue('active'),
                'workspaces' => $this->text()->null(),
                'status' => $this->tinyInteger(1)->notNull()->defaultValue(1),
                'verify_token' => $this->string(64)->notNull(),
                'date_verified' => $this->datetime()->null(),
                'verified_by' => $this->string(16)->null(),
                'date_create' => $this->datetime()->notNull(),
                'date_update' => $this->datetime()->notNull(),
            ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
            $this->createIndex('idx-' . Module::$tablePrefix . 'provider-workspace', $table, 'id_workspace');
            $this->createIndex('idx-' . Module::$tablePrefix . 'provider-domain', $table, 'domain');
        }

        $settings = SiteModule::$tablePrefix . 'setting';
        if (!(new Query())->from($settings)->where(['name' => 'auth::provider_key'])->exists($this->db)) {
            $this->insert($settings, [
                'module' => 'auth',
                'name' => 'auth::provider_key',
                'label' => 'Identity Provider Encryption Key',
                'value' => Yii::$app->security->generateRandomString(32),
                'type' => Form::TYPE_INPUTHIDDEN,
                'is_preference' => 0,
                'config' => json_encode(['hint' => 'Generated automatically. Changing this makes stored provider secrets unreadable.']),
            ]);
        }

        return true;
    }

    public function safeDown()
    {
        $table = '{{%' . Module::$tablePrefix . 'provider}}';
        if ($this->db->getTableSchema($table, true) !== null) {
            $this->dropTable($table);
        }
        $this->delete(SiteModule::$tablePrefix . 'setting', ['name' => 'auth::provider_key']);

        return true;
    }
}
