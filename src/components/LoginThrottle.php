<?php

namespace portalium\auth\components;

use portalium\auth\Module;
use portalium\user\models\User;
use Throwable;
use Yii;

/**
 * Locks password login after repeated failures (auth_attempt).
 *
 * Every attempt is counted before its password is checked (reserve), with one atomic upsert that also returns the
 * new count, so requests sent at the same moment cannot all slip past the limit. Two counters:
 * - the account from this client ("u:<id>|ip", or "n:<typed name>|ip" when no account has that name): locked
 *   after auth::lock_attempts attempts. Another client is not locked, so nobody can lock someone else out.
 * - the client alone: locked after auth::lock_ip_attempts, against one address trying many names. An account that
 *   signed in from this client before ("known", 30 days) is spared, so one person's failures behind a shared
 *   address (office NAT, mobile carrier) do not lock everyone else out.
 * A client is its IPv4 address or its IPv6 /64 block. A counter starts over when auth::lock_minutes have passed
 * since its first attempt; a lock lasts as long. While locked even the right password is refused, so a locked
 * attacker learns nothing. A right password takes its attempt back. Keys are stored hashed.
 */
class LoginThrottle
{
    public const ATTEMPTS_SETTING = 'auth::lock_attempts';

    public const IP_ATTEMPTS_SETTING = 'auth::lock_ip_attempts';

    public const MINUTES_SETTING = 'auth::lock_minutes';

    public const DEFAULT_ATTEMPTS = 5;

    public const DEFAULT_IP_ATTEMPTS = 20;

    public const DEFAULT_MINUTES = 15;

    public const KNOWN_DAYS = 30;

    /** About one attempt in this many also deletes the counters that ran out; 0 never does (tests). */
    public static $cleanupChance = 100;

    /** Seconds left of the lock on this account from this client (or on the client), 0 when not locked or off. */
    public static function lockedFor(string $typedName, ?User $user, ?string $ip): int
    {
        if (self::maxAttempts() <= 0) {
            return 0;
        }
        try {
            $keys = [self::accountKey($typedName, $user, $ip)];
            if (!self::isKnown($user, $ip)) {
                $keys[] = self::ipKey($ip);
            }
            $seconds = Yii::$app->db->createCommand(
                'SELECT MAX(TIMESTAMPDIFF(SECOND, NOW(), [[date_lock]])) FROM ' . self::table()
                . ' WHERE [[key_hash]] IN (' . implode(', ', array_map(fn ($i) => ':k' . $i, array_keys($keys))) . ')'
                . ' AND [[date_lock]] > NOW()',
                array_combine(array_map(fn ($i) => ':k' . $i, array_keys($keys)), $keys)
            )->queryScalar();
        } catch (Throwable $e) {
            Yii::warning('Login lock check failed: ' . get_class($e), __METHOD__);
            return 0;
        }

        return $seconds ? max(1, (int) $seconds) : 0;
    }

    /**
     * Counts this attempt before the password is checked; false when it is over a limit, and then the password must
     * not be checked at all.
     */
    public static function reserve(string $typedName, ?User $user, ?string $ip): bool
    {
        $max = self::maxAttempts();
        if ($max <= 0) {
            return true;
        }
        try {
            $allowed = self::count(self::accountKey($typedName, $user, $ip), $max) <= $max;
            if (!self::isKnown($user, $ip)) {
                $ipMax = self::maxIpAttempts();
                $allowed = (self::count(self::ipKey($ip), $ipMax) <= $ipMax) && $allowed;
            }
            if (self::$cleanupChance > 0 && mt_rand(1, self::$cleanupChance) === 1) {
                self::cleanup();
            }
        } catch (Throwable $e) {
            Yii::warning('Login lock count failed: ' . get_class($e), __METHOD__);
            return true;
        }

        return $allowed;
    }

    /**
     * A name's attempts at one corporate directory from any address (LDAP), counted before the directory is asked:
     * false over auth::lock_attempts. Stops many addresses from spraying one company's accounts through Suite and
     * locking them out in the company's own directory. Cleared by a right password (resetName).
     */
    public static function reserveName(string $scope, string $typedName): bool
    {
        $max = self::maxAttempts();
        if ($max <= 0) {
            return true;
        }
        try {
            return self::count(self::nameKey($scope, $typedName), $max) <= $max;
        } catch (Throwable $e) {
            Yii::warning('Login lock count failed: ' . get_class($e), __METHOD__);
            return true;
        }
    }

    public static function resetName(string $scope, string $typedName): void
    {
        try {
            Yii::$app->db->createCommand()->delete(self::table(), ['key_hash' => self::nameKey($scope, $typedName)])->execute();
        } catch (Throwable $e) {
            Yii::warning('Login lock reset failed: ' . get_class($e), __METHOD__);
        }
    }

    /**
     * The right password: the account's counter from this client goes, the attempt is taken back from the client's
     * counter (with the lock it may have set), and the account becomes known on this client.
     */
    public static function succeeded(string $typedName, User $user, ?string $ip): void
    {
        try {
            $db = Yii::$app->db;
            self::reset($typedName, $user, $ip);
            if (!self::isKnown($user, $ip)) {
                $db->createCommand(
                    'UPDATE ' . self::table() . ' SET [[attempts]] = GREATEST([[attempts]] - 1, 0),'
                    . ' [[date_lock]] = IF([[attempts]] < :max, NULL, [[date_lock]]) WHERE [[key_hash]] = :key',
                    [':max' => self::maxIpAttempts(), ':key' => self::ipKey($ip)]
                )->execute();
            }
            $db->createCommand(
                'INSERT INTO ' . self::table() . ' ([[key_hash]], [[attempts]], [[date_start]], [[date_lock]])'
                . ' VALUES (:key, 0, NOW(), NOW() + INTERVAL :d1 DAY)'
                . ' ON DUPLICATE KEY UPDATE [[date_lock]] = NOW() + INTERVAL :d2 DAY',
                [':key' => self::knownKey($user, $ip), ':d1' => self::KNOWN_DAYS, ':d2' => self::KNOWN_DAYS]
            )->execute();
        } catch (Throwable $e) {
            Yii::warning('Login lock release failed: ' . get_class($e), __METHOD__);
        }
    }

    /** Clears the account's counter from this client (a right password, a password reset). */
    public static function reset(string $typedName, ?User $user, ?string $ip): void
    {
        try {
            Yii::$app->db->createCommand()
                ->delete(self::table(), ['key_hash' => self::accountKey($typedName, $user, $ip)])
                ->execute();
        } catch (Throwable $e) {
            Yii::warning('Login lock reset failed: ' . get_class($e), __METHOD__);
        }
    }

    /** Deletes the counters whose window and lock (or known period) have both passed. */
    public static function cleanup(): int
    {
        return Yii::$app->db->createCommand(
            'DELETE FROM ' . self::table() . ' WHERE [[date_start]] < NOW() - INTERVAL :minutes MINUTE'
            . ' AND ([[date_lock]] IS NULL OR [[date_lock]] < NOW())',
            [':minutes' => self::minutes()]
        )->execute();
    }

    public static function maxAttempts(): int
    {
        return self::setting(self::ATTEMPTS_SETTING, self::DEFAULT_ATTEMPTS);
    }

    public static function maxIpAttempts(): int
    {
        return max(1, self::setting(self::IP_ATTEMPTS_SETTING, self::DEFAULT_IP_ATTEMPTS));
    }

    public static function minutes(): int
    {
        return max(1, self::setting(self::MINUTES_SETTING, self::DEFAULT_MINUTES));
    }

    /** The client's address: IPv4 as it is (also when written as ::ffff:a.b.c.d), IPv6 as its /64 block. */
    public static function client(?string $ip): string
    {
        $ip = trim((string) $ip);
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return inet_ntop(substr(inet_pton($ip), 0, 8) . str_repeat("\0", 8)) . '/64';
        }

        return $ip;
    }

    /** The current request's IP address; null outside a web request. */
    public static function requestIp(): ?string
    {
        $request = Yii::$app->has('request') ? Yii::$app->request : null;

        return $request instanceof \yii\web\Request ? $request->getUserIP() : null;
    }

    /**
     * One attempt on a counter; returns the new count. MySQL assigns left to right, so the lock sees the new count;
     * a counter whose window has passed starts over at 1. LAST_INSERT_ID(expr) hands the count back on this
     * connection without a second read that another request could get in front of.
     */
    private static function count(string $key, int $max): int
    {
        $db = Yii::$app->db;
        $db->createCommand(
            'INSERT INTO ' . self::table() . ' ([[key_hash]], [[attempts]], [[date_start]], [[date_lock]])'
            . ' VALUES (:key, LAST_INSERT_ID(1), NOW(), IF(1 >= :max1, NOW() + INTERVAL :m1 MINUTE, NULL))'
            . ' ON DUPLICATE KEY UPDATE'
            . ' [[attempts]] = LAST_INSERT_ID(IF([[date_start]] < NOW() - INTERVAL :m2 MINUTE, 1, [[attempts]] + 1)),'
            . ' [[date_start]] = IF([[attempts]] = 1, NOW(), [[date_start]]),'
            . ' [[date_lock]] = IF([[attempts]] >= :max2, NOW() + INTERVAL :m3 MINUTE, [[date_lock]])',
            [
                ':key' => $key, ':max1' => $max, ':max2' => $max,
                ':m1' => self::minutes(), ':m2' => self::minutes(), ':m3' => self::minutes(),
            ]
        )->execute();

        return (int) $db->createCommand('SELECT LAST_INSERT_ID()')->queryScalar();
    }

    private static function isKnown(?User $user, ?string $ip): bool
    {
        if ($user === null) {
            return false;
        }

        return (bool) Yii::$app->db->createCommand(
            'SELECT 1 FROM ' . self::table() . ' WHERE [[key_hash]] = :key AND [[date_lock]] > NOW()',
            [':key' => self::knownKey($user, $ip)]
        )->queryScalar();
    }

    private static function accountKey(string $typedName, ?User $user, ?string $ip): string
    {
        $who = $user !== null ? 'u:' . $user->id_user : 'n:' . mb_strtolower(trim($typedName));

        return self::hash($who . '|' . self::client($ip));
    }

    private static function nameKey(string $scope, string $typedName): string
    {
        return self::hash('d:' . $scope . '|' . mb_strtolower(trim($typedName)));
    }

    private static function ipKey(?string $ip): string
    {
        return self::hash('ip:' . self::client($ip));
    }

    private static function knownKey(User $user, ?string $ip): string
    {
        return self::hash('k:' . $user->id_user . '|' . self::client($ip));
    }

    private static function hash(string $key): string
    {
        return hash('sha256', 'portalium-login|' . $key);
    }

    private static function table(): string
    {
        return '{{%' . Module::$tablePrefix . 'attempt}}';
    }

    private static function setting(string $name, int $default): int
    {
        try {
            $value = Yii::$app->has('setting') ? Yii::$app->setting->getValue($name) : null;
        } catch (Throwable $e) {
            $value = null;
        }

        return is_numeric($value) ? (int) $value : $default;
    }
}
