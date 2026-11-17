<?php

use yii\db\Migration;
use yii\db\Query;
use portalium\site\models\Form;
use portalium\site\Module as SiteModule;

class m261105_000018_auth_provider_network_setting extends Migration
{
    public function safeUp()
    {
        $table = SiteModule::$tablePrefix . 'setting';
        $settings = [
            ['auth::provider_allow_private_network', 'Identity Providers On Private Networks', '0', Form::TYPE_CHECKBOX,
                json_encode(['help' => 'Only for on-premises installations whose directory is on the same network'])],
            ['auth::provider_blocked_domains', 'Domains Never Used For Corporate Login', '', Form::TYPE_INPUTTEXT, ''],
        ];
        foreach ($settings as [$name, $label, $value, $type, $config]) {
            if ((new Query())->from($table)->where(['name' => $name])->exists($this->db)) {
                continue;
            }
            $this->insert($table, [
                'module' => 'auth',
                'name' => $name,
                'label' => $label,
                'value' => $value,
                'type' => $type,
                'config' => $config,
                'is_preference' => 0,
            ]);
        }
    }

    public function safeDown()
    {
        $this->delete(SiteModule::$tablePrefix . 'setting', ['name' => ['auth::provider_allow_private_network', 'auth::provider_blocked_domains']]);
    }
}
