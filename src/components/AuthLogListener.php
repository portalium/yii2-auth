<?php

namespace portalium\auth\components;

use portalium\auth\models\AuthLog;
use portalium\auth\models\AuthSession;
use portalium\user\models\User;
use Throwable;
use Yii;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;
use yii\web\User as WebUser;
use yii\web\UserEvent;

/**
 * Records logins, logouts and password changes in the login log, and keeps the session rows (SessionTracker) of web
 * logins. Failed and refused logins are recorded where they are decided (LoginForm, the Google/Apple sign-in), since
 * no event carries them.
 */
class AuthLogListener
{
    public static function register(): void
    {
        Event::on(WebUser::class, WebUser::EVENT_BEFORE_LOGIN, self::guard([self::class, 'onBeforeLogin']));
        Event::on(WebUser::class, WebUser::EVENT_AFTER_LOGIN, self::guard([self::class, 'onLogin']));
        Event::on(WebUser::class, WebUser::EVENT_BEFORE_LOGOUT, self::guard([self::class, 'onLogout']));
        Event::on(User::class, ActiveRecord::EVENT_AFTER_UPDATE, self::guard([self::class, 'onUserUpdate']));
        Event::on(User::class, ActiveRecord::EVENT_AFTER_INSERT, self::guard([SignupSource::class, 'onUserInsert']));
        if (Yii::$app->id === 'web') {
            Event::on(
                \yii\web\Application::class,
                \yii\base\Application::EVENT_BEFORE_ACTION,
                self::guard([self::class, 'onBeforeAction'])
            );
        }
    }

    /** Every web request: a session ended elsewhere (limit, profile, admin) is logged out here. */
    public static function onBeforeAction($event): void
    {
        if (Yii::$app->user->enableSession) {
            SessionTracker::check();
        }
    }

    /** A remember-me cookie login in the web app must belong to an active session (SessionTracker). */
    public static function onBeforeLogin(UserEvent $event): void
    {
        if ($event->cookieBased && Yii::$app->id === 'web' && $event->identity instanceof User) {
            $event->isValid = SessionTracker::allowsCookieLogin($event->identity);
        }
    }

    /**
     * A login on a sign-in route is always a person. Elsewhere, a login with a duration of 0 outside the web app
     * or without a session is a client identifying itself for one request (an API token, a device, every MCP
     * request), not a person logging in.
     */
    public static function onLogin(UserEvent $event): void
    {
        $method = $event->cookieBased ? AuthLog::METHOD_COOKIE : AuthLogger::takeMethod();
        $sessionless = $event->sender instanceof WebUser && !$event->sender->enableSession;
        if ($method === null && $event->duration === 0 && (Yii::$app->id !== 'web' || $sessionless)) {
            return;
        }
        AuthLogger::record(AuthLog::EVENT_LOGIN, [
            'method' => $method,
            'id_user' => (int) $event->identity->getId(),
            'username' => $event->identity->username ?? null,
        ]);
        if (Yii::$app->id === 'web' && !$sessionless && $event->identity instanceof User) {
            SessionTracker::start($event->identity, (int) $event->duration, $method, (bool) $event->cookieBased);
        }
    }

    public static function onLogout(UserEvent $event): void
    {
        if ($event->identity === null) {
            return;
        }
        if (SessionTracker::$signingOut) {
            return;
        }
        SessionTracker::endCurrent(AuthSession::END_LOGOUT);
        AuthLogger::record(AuthLog::EVENT_LOGOUT, [
            'method' => null,
            'id_user' => (int) $event->identity->getId(),
            'username' => $event->identity->username ?? null,
        ]);
    }

    /**
     * A new password hash is a password change; when the reset token goes in the same save, it came through a
     * password reset. A new reset token is a reset request.
     */
    public static function onUserUpdate(AfterSaveEvent $event): void
    {
        /** @var User $user */
        $user = $event->sender;
        $changed = $event->changedAttributes;
        $base = ['id_user' => (int) $user->id_user, 'username' => $user->username];
        $tokenChanged = array_key_exists('password_reset_token', $changed)
            && (string) $changed['password_reset_token'] !== (string) $user->password_reset_token;

        if (array_key_exists('password_hash', $changed) && (string) $changed['password_hash'] !== (string) $user->password_hash) {
            $reset = $tokenChanged && empty($user->password_reset_token);
            AuthLogger::record(AuthLog::EVENT_PASSWORD_CHANGE, $base + ['method' => $reset ? AuthLog::METHOD_RESET : null]);
        }
        if ($tokenChanged && !empty($user->password_reset_token)) {
            AuthLogger::record(AuthLog::EVENT_PASSWORD_RESET_REQUEST, $base + ['method' => null]);
        }
    }

    /** A listener that never lets a failure reach the login or the save being recorded. */
    private static function guard(callable $handler): \Closure
    {
        return static function ($event) use ($handler) {
            try {
                $handler($event);
            } catch (Throwable $e) {
                Yii::warning('Login log listener failed: ' . $e->getMessage(), __METHOD__);
            }
        };
    }
}
