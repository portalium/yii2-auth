<?php

namespace portalium\auth\components;

use Yii;
use portalium\auth\Module;
use yii\web\NotFoundHttpException;

/**
 * Whether API signup and login are enabled: the auth::api_signup and auth::api_login settings (decision 18,
 * 2026-10-18).
 *
 * Both settings are added by m260318_145931_auth_setting: a radiolist, {1: Allow, 0: Deny}, default 1. Only
 * Deny (0) turns it off; if the setting row does not exist, the API stays open as before. A disabled endpoint
 * returns 403 with a machine-readable status (SIGNUP_DISABLED, LOGIN_DISABLED) and a user-facing message.
 *
 * Google/Apple API sign-in also hands out an access_token, so it follows api_login; and since those endpoints
 * open a new account when none exists, they also follow api_signup.
 */
class ApiAccess
{
    public const SIGNUP_SETTING = 'auth::api_signup';
    public const LOGIN_SETTING = 'auth::api_login';

    public const SIGNUP_DISABLED = 'api_signup_disabled';
    public const LOGIN_DISABLED = 'api_login_disabled';

    public static function signupAllowed(): bool
    {
        return self::allowed(self::SIGNUP_SETTING);
    }

    public static function loginAllowed(): bool
    {
        return self::allowed(self::LOGIN_SETTING);
    }

    /**
     * Also tells the user what they can do instead (final review U9).
     */
    public static function signupDisabledMessage(): string
    {
        return Module::t('Signing up from the mobile app is currently disabled. You can try signing up on the website or contact your administrator.');
    }

    public static function loginDisabledMessage(): string
    {
        return Module::t('Signing in from the mobile app is currently disabled. You can sign in on the website or contact your administrator.');
    }

    /**
     * The setting component decodes the "0" value as JSON (integer 0); the comparison is done as a string.
     * Only 0 disables it: empty, null, or any other unexpected value is treated as enabled (final review D3;
     * documented in the mobile notes).
     */
    private static function allowed(string $name): bool
    {
        try {
            $value = Yii::$app->setting->getValue($name);
        } catch (NotFoundHttpException $e) {
            return true;
        }

        return !(is_scalar($value) && (string) $value === '0');
    }
}
