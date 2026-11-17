<?php

namespace portalium\auth\models;

use portalium\auth\components\AccountStatus;
use portalium\auth\Module;
use portalium\user\models\User;
use yii\db\ActiveRecord;

/**
 * One authentication event: a login, a failed or refused login, a logout, a password change or reset request, an
 * app (MCP client) given access to the account. For a failed or refused login "reason" says why; for an app's access
 * it is the app's name.
 * Written by AuthLogger; the password itself is never stored.
 *
 * @property int $id_log
 * @property string $event
 * @property string $result
 * @property string|null $method
 * @property string|null $reason
 * @property int|null $id_user
 * @property string|null $username
 * @property string|null $app
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $date_create
 * @property User|null $user
 */
class AuthLog extends ActiveRecord
{
    public const EVENT_LOGIN = 'login';
    public const EVENT_LOGIN_FAILED = 'login_failed';
    public const EVENT_LOGIN_BLOCKED = 'login_blocked';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_PASSWORD_CHANGE = 'password_change';
    public const EVENT_PASSWORD_RESET_REQUEST = 'password_reset_request';
    public const EVENT_OAUTH_GRANT = 'oauth_grant';
    public const EVENT_SESSION_END = 'session_end';

    public const RESULT_OK = 'ok';
    public const RESULT_ERROR = 'error';

    public const METHOD_PASSWORD = 'password';
    public const METHOD_COOKIE = 'cookie';
    public const METHOD_GOOGLE = 'google';
    public const METHOD_APPLE = 'apple';
    public const METHOD_SIGNUP = 'signup';
    public const METHOD_EMAIL_VERIFY = 'email_verify';
    public const METHOD_RESET = 'reset';
    public const METHOD_OAUTH = 'oauth';
    public const METHOD_LDAP = 'ldap';
    public const METHOD_OIDC = 'oidc';
    public const METHOD_SAML = 'saml';

    public const REASON_WRONG_PASSWORD = 'wrong_password';
    public const REASON_UNKNOWN_USER = 'unknown_user';
    public const REASON_ACCOUNT_EXISTS = 'account_exists';
    public const REASON_LOCKED = 'locked';

    public static function tableName()
    {
        return '{{%' . Module::$tablePrefix . 'log}}';
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id_user' => 'id_user']);
    }

    public static function eventLabels(): array
    {
        return [
            self::EVENT_LOGIN => 'Logged in',
            self::EVENT_LOGIN_FAILED => 'Failed login',
            self::EVENT_LOGIN_BLOCKED => 'Login refused',
            self::EVENT_LOGOUT => 'Logged out',
            self::EVENT_PASSWORD_CHANGE => 'Password changed',
            self::EVENT_PASSWORD_RESET_REQUEST => 'Password reset requested',
            self::EVENT_OAUTH_GRANT => 'App access granted',
            self::EVENT_SESSION_END => 'Session ended',
        ];
    }

    public static function methodLabels(): array
    {
        return [
            self::METHOD_PASSWORD => 'Password',
            self::METHOD_COOKIE => 'Remember me',
            self::METHOD_GOOGLE => 'Google',
            self::METHOD_APPLE => 'Apple',
            self::METHOD_SIGNUP => 'Sign up',
            self::METHOD_EMAIL_VERIFY => 'Email verification',
            self::METHOD_RESET => 'Password reset',
            self::METHOD_OAUTH => 'Connected app',
            self::METHOD_LDAP => 'Company directory (LDAP)',
            self::METHOD_OIDC => 'Company sign-in (OIDC)',
            self::METHOD_SAML => 'Company sign-in (SAML)',
        ];
    }

    /** Reasons of failed and refused logins; a refused login's reason is the account status (AccountStatus). */
    public static function reasonLabels(): array
    {
        return [
            self::REASON_WRONG_PASSWORD => 'Wrong password',
            self::REASON_UNKNOWN_USER => 'No such account',
            self::REASON_ACCOUNT_EXISTS => 'Account exists, not linked',
            self::REASON_LOCKED => 'Locked after failed attempts',
            AuthSession::END_REPLACED => 'Signed in on another device',
            AuthSession::END_BY_USER => 'Ended by the user',
            AuthSession::END_BY_ADMIN => 'Ended by an administrator',
            AccountStatus::PENDING_APPROVAL => 'Awaiting approval',
            AccountStatus::EMAIL_NOT_VERIFIED => 'Email not verified',
            AccountStatus::INACTIVE => 'Account not active',
        ];
    }

    public static function resultLabels(): array
    {
        return [
            self::RESULT_OK => 'Succeeded',
            self::RESULT_ERROR => 'Failed',
        ];
    }

    /**
     * The browser and system read from the user agent, e.g. "Chrome · macOS"; a client that is not a browser
     * (curl, a script) shows its own name without the version.
     */
    public static function browser(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }
        $browsers = [
            'Edge' => '/Edg(e|A|iOS)?\//', 'Opera' => '/OPR\//', 'Firefox' => '/Firefox\/|FxiOS\//',
            'Chrome' => '/Chrome\/|CriOS\//', 'Safari' => '/Safari\//',
        ];
        $systems = [
            'Windows' => '/Windows/', 'Android' => '/Android/', 'iOS' => '/iPhone|iPad|iPod/',
            'macOS' => '/Mac OS X|Macintosh/', 'Linux' => '/Linux/',
        ];
        $pick = static function (array $patterns) use ($userAgent): ?string {
            foreach ($patterns as $name => $pattern) {
                if (preg_match($pattern, $userAgent)) {
                    return $name;
                }
            }
            return null;
        };
        $browser = $pick($browsers);
        if ($browser === null) {
            return mb_substr(strtok($userAgent, '/ '), 0, 40);
        }
        $system = $pick($systems);

        return $system === null ? $browser : $browser . ' · ' . $system;
    }
}
