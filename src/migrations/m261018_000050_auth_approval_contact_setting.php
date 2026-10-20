<?php

use yii\db\Migration;
use yii\db\Query;
use portalium\site\models\Form;
use portalium\site\Module as SiteModule;

class m261018_000050_auth_approval_contact_setting extends Migration
{
    public function safeUp()
    {
        $table = SiteModule::$tablePrefix . 'setting';
        if ((new Query())->from($table)->where(['name' => 'auth::approval_contact'])->exists($this->db)) {
            return;
        }

        $this->insert($table, [
            'module' => 'auth',
            'name' => 'auth::approval_contact',
            'label' => 'Approval Contact Email (Leave empty to hide)',
            'value' => '',
            'type' => Form::TYPE_INPUT,
            'config' => 'email',
            'is_preference' => 0,
        ]);
    }

    public function safeDown()
    {
        $this->delete(SiteModule::$tablePrefix . 'setting', ['name' => 'auth::approval_contact']);
    }
}
