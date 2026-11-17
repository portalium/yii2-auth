<?php

use yii\db\Migration;
use yii\db\Query;
use portalium\site\models\Form;
use portalium\site\Module as SiteModule;

class m261105_000008_auth_session_setting extends Migration
{
    public function safeUp()
    {
        $table = SiteModule::$tablePrefix . 'setting';
        if ((new Query())->from($table)->where(['name' => 'auth::max_sessions'])->exists($this->db)) {
            return;
        }

        $this->insert($table, [
            'module' => 'auth',
            'name' => 'auth::max_sessions',
            'label' => 'Sessions Per Account (0 = unlimited)',
            'value' => '1',
            'type' => Form::TYPE_INPUT,
            'config' => 'number',
            'is_preference' => 0,
        ]);
    }

    public function safeDown()
    {
        $this->delete(SiteModule::$tablePrefix . 'setting', ['name' => 'auth::max_sessions']);
    }
}
