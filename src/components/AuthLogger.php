<?php

namespace portalium\auth\components;

use portalium\auth\models\AuthLog;
use portalium\user\models\User;
use Throwable;
use Yii;

/**
 * Writes the login log (auth_log).
 *
 * Recording must never break the login being recorded: every failure is swallowed and only logged as a warning.
 * Rows older than the auth::log_retention_days setting are deleted now and then while writing (the project has no
 * cron), and by the "auth/log/purge" console command.
 */
class AuthLogger
{
    public const RETENTION_SETTING = 'auth::log_retention_days';

    public const DEFAULT_RETENTION_DAYS = 365;

    /** The method of the next login, set by whoever knows it better than the route (LDAP); used once. */
    public static $nextMethod;

    /** About one write in this many also deletes expired rows; 0 never does (tests). */
    public static $purgeChance = 100;

    /** Rows deleted by one purge query; a purge repeats it until nothing is left. */
    public const PURGE_BATCH = 5000;

    public const PURGE_LOCK = 'auth_log_purge';

    /**
     * Where a person signs in, and how. Logins elsewhere get no method; logins restored from the remember-me
     * cookie are METHOD_COOKIE.
     */
    public const ROUTE_METHODS = [
        'auth/default/login' => AuthLog::METHOD_PASSWORD,
        'auth/default/callback-google' => AuthLog::METHOD_GOOGLE,
        'auth/default/google-signin' => AuthLog::METHOD_GOOGLE,
        'auth/default/callback-apple' => AuthLog::METHOD_APPLE,
        'auth/default/apple-signin' => AuthLog::METHOD_APPLE,
        'auth/default/signup' => AuthLog::METHOD_SIGNUP,
        'auth/default/verify-email' => AuthLog::METHOD_EMAIL_VERIFY,
    ];

    /**
     * Records one event.
     *
     * $data keys (all optional): result, method, reason, id_user, username. ip, user_agent and app come from the
     * current request; the method defaults to the one of the current route (ROUTE_METHODS).
     */
    public static function record(string $event, array $data = []): bool
    {
        try {
            $row = [
                'event' => $event,
                'result' => $data['result'] ?? AuthLog::RESULT_OK,
                'method' => array_key_exists('method', $data) ? $data['method'] : self::routeMethod(),
                'reason' => self::text($data['reason'] ?? null, 64),
                'id_user' => $data['id_user'] ?? null,
                'username' => self::text($data['username'] ?? null, 128),
                'app' => Yii::$app->id,
                'ip' => null,
                'user_agent' => null,
                'date_create' => date('Y-m-d H:i:s'),
            ];
            $request = Yii::$app->has('request') ? Yii::$app->request : null;
            if ($request instanceof \yii\web\Request) {
                $row['ip'] = $request->getUserIP();
                $row['user_agent'] = self::text($request->getUserAgent(), 255);
            }
        } catch (Throwable $e) {
            Yii::warning('Login log record failed (' . $event . '): ' . get_class($e), __METHOD__);
            return false;
        }

        if (!self::insert($row)) {
            // the typed name and the user agent come from the client: the event must stay even if they cannot
            $row['username'] = $row['user_agent'] = null;
            if (!self::insert($row)) {
                return false;
            }
        }

        if (self::$purgeChance > 0 && mt_rand(1, self::$purgeChance) === 1) {
            self::purge(null, false);
        }

        return true;
    }

    /**
     * A login that did not get through: a wrong password or name (REASON_*), or a correct one on an account that
     * is not active (the account status). The typed name is kept so attempts on a name that does not exist show up
     * too; when no account has that name only its start is kept (it may be a password typed into the wrong box).
     */
    public static function failed(string $typedName, ?User $user, string $reason, ?string $method = null): bool
    {
        $refused = !in_array($reason, [AuthLog::REASON_WRONG_PASSWORD, AuthLog::REASON_UNKNOWN_USER], true);
        $data = [
            'result' => AuthLog::RESULT_ERROR,
            'reason' => $reason,
            'id_user' => $user ? (int) $user->id_user : null,
            'username' => $user ? $typedName : mb_substr($typedName, 0, 2) . '***',
        ];
        if ($method !== null) {
            $data['method'] = $method;
        }

        return self::record($refused ? AuthLog::EVENT_LOGIN_BLOCKED : AuthLog::EVENT_LOGIN_FAILED, $data);
    }

    /**
     * A Google/Apple sign-in that did not get through: the address belongs to an account the identity is not
     * linked to (REASON_ACCOUNT_EXISTS), or the account is not active (its status).
     */
    public static function providerFailed(string $provider, string $email, ?User $user, string $reason): bool
    {
        if ($user === null && $reason === AuthLog::REASON_ACCOUNT_EXISTS && $email !== '') {
            $user = User::findOne(['email' => $email]);
        }

        return self::failed($email, $user, $reason, strtolower($provider));
    }

    /**
     * Deletes the rows older than the retention setting and returns how many went; 0 or less keeps everything.
     * Only one purge runs at a time. A web request ($all false) deletes one batch, so a login never waits for a
     * large backlog; the console command deletes everything.
     */
    public static function purge(?int $days = null, bool $all = true): int
    {
        $days = $days ?? self::retentionDays();
        if ($days <= 0) {
            return 0;
        }
        $deleted = 0;
        $db = Yii::$app->db;
        try {
            if (!$db->createCommand('SELECT GET_LOCK(:name, 0)', [':name' => self::PURGE_LOCK])->queryScalar()) {
                return 0;
            }
            $before = date('Y-m-d H:i:s', time() - $days * 86400);
            $sql = 'DELETE FROM ' . AuthLog::tableName() . ' WHERE [[date_create]] < :before LIMIT ' . self::PURGE_BATCH;
            do {
                $count = $db->createCommand($sql, [':before' => $before])->execute();
                $deleted += $count;
            } while ($all && $count === self::PURGE_BATCH);
        } catch (Throwable $e) {
            Yii::warning('Login log purge failed: ' . get_class($e), __METHOD__);
        } finally {
            try {
                $db->createCommand('SELECT RELEASE_LOCK(:name)', [':name' => self::PURGE_LOCK])->queryScalar();
            } catch (Throwable $e) {
                // the lock goes with the connection anyway
            }
        }

        return $deleted;
    }

    public static function retentionDays(): int
    {
        try {
            $value = Yii::$app->has('setting') ? Yii::$app->setting->getValue(self::RETENTION_SETTING) : null;
        } catch (Throwable $e) {
            $value = null;
        }

        return ($value === null || $value === '') ? self::DEFAULT_RETENTION_DAYS : (int) $value;
    }

    /** The method of this login: the one set for it (nextMethod, then cleared), else the route's. */
    public static function takeMethod(): ?string
    {
        $method = self::$nextMethod;
        self::$nextMethod = null;

        return $method ?? self::routeMethod();
    }

    public static function routeMethod(): ?string
    {
        $controller = Yii::$app->controller;

        return $controller !== null ? (self::ROUTE_METHODS[$controller->getRoute()] ?? null) : null;
    }

    /**
     * Client-supplied text fit for the column: broken bytes dropped, and characters outside the Basic Multilingual
     * Plane (emoji) replaced, since the connection may be 3-byte utf8.
     */
    private static function text($value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = mb_scrub((string) $value, 'UTF-8');
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $value) ?? '';

        return mb_substr($value, 0, $length);
    }

    /** One insert; a failure is logged without the SQL (it would carry the IP and the typed name). */
    private static function insert(array $row): bool
    {
        try {
            Yii::$app->db->createCommand()->insert(AuthLog::tableName(), $row)->execute();
            return true;
        } catch (Throwable $e) {
            $code = $e instanceof \yii\db\Exception && isset($e->errorInfo[1]) ? ' ' . $e->errorInfo[1] : '';
            Yii::warning('Login log record failed (' . $row['event'] . '):' . $code . ' ' . get_class($e), __METHOD__);
            return false;
        }
    }
}
