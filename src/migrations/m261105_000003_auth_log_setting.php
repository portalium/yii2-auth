<?php

use yii\db\Migration;
use yii\db\Query;
use portalium\site\models\Form;
use portalium\site\Module as SiteModule;

class m261105_000003_auth_log_setting extends Migration
{
    public function safeUp()
    {
        $table = SiteModule::$tablePrefix . 'setting';
        if ((new Query())->from($table)->where(['name' => 'auth::log_retention_days'])->exists($this->db)) {
            return;
        }

        $this->insert($table, [
            'module' => 'auth',
            'name' => 'auth::log_retention_days',
            'label' => 'Login Log Retention (days, 0 = keep forever)',
            'value' => '365',
            'type' => Form::TYPE_INPUT,
            'config' => 'number',
            'is_preference' => 0,
        ]);
    }

    public function safeDown()
    {
        $this->delete(SiteModule::$tablePrefix . 'setting', ['name' => 'auth::log_retention_days']);
    }
}
