<?php

namespace portalium\auth;

use portalium\base\Event;
use portalium\auth\components\AuthLogListener;
use portalium\auth\components\TriggerActions;

class Module extends \portalium\base\Module
{
    public static $tablePrefix = 'auth_';
    
    public static $name = 'Auth';

    public static $description = 'Auth Module';

    // Auth events
    const EVENT_ON_LOGIN = 'onLogin';
    const EVENT_ON_LOGOUT = 'onLogout';
    const EVENT_ON_SIGNUP = 'onSignup';

    /**
     * OAuth server for MCP clients (OauthController); the discovery documents live where the standards put them.
     * Written as pattern/route arrays: portalium\web\Bootstrap appends the rules of all modules to one list, which
     * drops "pattern => route" keys.
     */
    public $urlRules = [
        ['pattern' => '.well-known/oauth-protected-resource/<path:.+>', 'route' => 'auth/oauth/protected-resource'],
        ['pattern' => '.well-known/oauth-protected-resource', 'route' => 'auth/oauth/protected-resource'],
        ['pattern' => '.well-known/oauth-authorization-server', 'route' => 'auth/oauth/metadata'],
        ['pattern' => 'oauth/register', 'route' => 'auth/oauth/register'],
        ['pattern' => 'oauth/authorize', 'route' => 'auth/oauth/authorize'],
        ['pattern' => 'oauth/token', 'route' => 'auth/oauth/token'],
    ];

    public $apiRules = [
        [
            'class' => 'yii\rest\UrlRule',
            'controller' => [
                'auth/default',
            ]
        ],
    ];
    
    public static function moduleInit()
    {
        self::registerTranslation('auth','@portalium/auth/messages',[
            'auth' => 'auth.php',
        ]);
    }

    public static function t($message, array $params = [])
    {
        return parent::coreT('auth', $message, $params);
    }

    /* 
        public function registerEvents()
        {
            Event::on($this::className(), UserModule::EVENT_USER_DELETE_BEFORE, [new TriggerActions(), 'onUserDeleteBefore']);
        } 
    */

    public function registerEvents()
    {
        AuthLogListener::register();
    }
}
