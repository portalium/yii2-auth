<?php

use yii\db\Migration;
use portalium\site\models\Form;
use portalium\site\Module as SiteModule;

class m260318_145931_auth_setting extends Migration
{
    public function safeUp()
    {
        $this->insertSetting([
            'module' => 'site',
            'is_preference' => 0,
            'name' => 'auth::web_signup',
            'label' => 'Signup Form',
            'value' => '1',
            'type' => Form::TYPE_RADIOLIST,
            'config' => json_encode([1 => 'Show', 0 => 'Hide'])
        ]);

        $this->insertSetting([
            'module' => 'site',
            'is_preference' => 0,
            'name' => 'auth::web_login',
            'label' => 'Login Form',
            'value' => '1',
            'type' => Form::TYPE_RADIOLIST,
            'config' => json_encode([1 => 'Show', 0 => 'Hide'])
        ]);

        $this->insertSetting([
            'module' => 'site',
            'is_preference' => 0,
            'name' => 'auth::api_signup',
            'label' => 'API Signup',
            'value' => '1',
            'type' => Form::TYPE_RADIOLIST,
            'config' => json_encode([1 => 'Allow', 0 => 'Deny'])
        ]);

        $this->insertSetting([
            'module' => 'site',
            'is_preference' => 0,
            'name' => 'auth::api_login',
            'label' => 'API Login',
            'value' => '1',
            'type' => Form::TYPE_RADIOLIST,
            'config' => json_encode([1 => 'Allow', 0 => 'Deny'])
        ]);

        $this->insertSetting([
            'module' => 'site',
            'is_preference' => 0,
            'name' => 'auth::user_status',
            'label' => 'User Registration Status',
            'value' => '10',
            'type' => Form::TYPE_RADIOLIST,
            'config' => json_encode([10 => 'Active', 20 => 'Passive'])
        ]);

        $this->insertSetting([
            'module' => 'site',
            'is_preference' => 0,
            'name' => 'auth::layout',
            'label' => 'Auth Layout',
            'type' => Form::TYPE_DROPDOWNLIST,
            'value' => 'login',
            'config' => json_encode([
                'method' => [
                    'class' => 'portalium\theme\Module',
                    'name' => 'getLayouts',
                    'map' => [
                        'key' => 'layout',
                        'value' => 'name'
                    ]
                ]
            ])
        ]);

        $this->insertSetting([
            'module' => 'site',
            'is_preference' => 0,
            'name' => 'auth::login_layout',
            'label' => 'Login and Signup Page Layout',
            'value' => 'single-column',
            'type' => Form::TYPE_DROPDOWNLIST,
            'config' => json_encode([
                'single-column' => 'Single Column',
                'two-column' => 'Two Column'
            ])
        ]);

        $this->insertSetting([
            'module' => 'site',
            'is_preference' => 0,
            'name' => 'auth::login_image',
            'label' => 'Application Login Image',
            'value' => '0',
            'type' => Form::TYPE_WIDGET,
            'config' => json_encode([
                'widget' => '\portalium\storage\widgets\FilePicker',
                'options' => [
                    'multiple' => 0,
                    'attributes' => ['name', 'id_storage'],
                    'name' => 'auth::login_image',
                    'isPicker' => true
                ]
            ])
        ]);
    }

    /**
     * portalium/yii2-site already seeds auth::layout; a fresh install would get the row twice.
     */
    private function insertSetting(array $columns)
    {
        $exists = (new \yii\db\Query())->from(SiteModule::$tablePrefix . 'setting')
            ->where(['name' => $columns['name']])->exists($this->db);
        if (!$exists) {
            $this->insert(SiteModule::$tablePrefix . 'setting', $columns);
        }
    }

    public function safeDown()
    {
        $this->delete(SiteModule::$tablePrefix . 'setting', [
            'name' => 'auth::user_status',
            'module' => 'auth',
        ]);
        $this->delete(SiteModule::$tablePrefix . 'setting', [
            'name' => 'auth::api_login',
            'module' => 'auth',
        ]);
        $this->delete(SiteModule::$tablePrefix . 'setting', [
            'name' => 'auth::api_signup',
            'module' => 'auth',
        ]);
        $this->delete(SiteModule::$tablePrefix . 'setting', [
            'name' => 'auth::web_login',
            'module' => 'auth',
        ]);
        $this->delete(SiteModule::$tablePrefix . 'setting', [
            'name' => 'auth::web_signup',
            'module' => 'auth',
        ]);
        $this->delete(SiteModule::$tablePrefix . 'setting', [
            'name' => 'auth::user_status',
            'module' => 'auth',
        ]);

    }
}
