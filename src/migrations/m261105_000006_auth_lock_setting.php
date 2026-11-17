<?php

use yii\db\Migration;
use yii\db\Query;
use portalium\site\models\Form;
use portalium\site\Module as SiteModule;

class m261105_000006_auth_lock_setting extends Migration
{
    private const SETTINGS = [
        ['auth::lock_attempts', 'Failed Logins Before Lock (0 = never lock)', '5'],
        ['auth::lock_ip_attempts', 'Failed Logins From One Address Before Lock', '20'],
        ['auth::lock_minutes', 'Login Lock Duration (minutes)', '15'],
    ];

    public function safeUp()
    {
        $table = SiteModule::$tablePrefix . 'setting';
        foreach (self::SETTINGS as [$name, $label, $value]) {
            if ((new Query())->from($table)->where(['name' => $name])->exists($this->db)) {
                continue;
            }
            $this->insert($table, [
                'module' => 'auth',
                'name' => $name,
                'label' => $label,
                'value' => $value,
                'type' => Form::TYPE_INPUT,
                'config' => 'number',
                'is_preference' => 0,
            ]);
        }
    }

    public function safeDown()
    {
        $this->delete(SiteModule::$tablePrefix . 'setting', ['name' => array_column(self::SETTINGS, 0)]);
    }
}
