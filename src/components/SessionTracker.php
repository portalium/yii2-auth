<?php

namespace portalium\auth\components;

use portalium\auth\models\AuthLog;
use portalium\auth\models\AuthSession;
use portalium\auth\Module;
use portalium\user\models\User;
use Throwable;
use Yii;

/**
 * Keeps one row per signed-in browser session (auth_session) and holds each account to its session limit.
 *
 * A web login (password, Google/Apple, …) gets a random token in its PHP session and a row with the token's hash;
 * with "remember me" the token also goes into a signed cookie. When the account then has more active sessions than
 * its limit, the oldest ones end. Every web request checks its row: an ended session is logged out (its PHP session
 * emptied) and the login page says why. A remember-me cookie login is let in only with the cookie of an active row,
 * and continues that row; so an ended session cannot come back with its cookie. Sessions that were open before this
 * existed (no token at all) get a row on their next request, so nobody is logged out by the update. Token logins
 * (API, devices, MCP) have no session and are not counted.
 *
 * The limit is auth::max_sessions (0 = unlimited) unless the account has its own (auth_session_limit).
 */
class SessionTracker
{
    public const SESSION_KEY = '__authSession';

    /** The browser's copy of its session token, for "remember me": a cookie login must name an active row. */
    public const COOKIE = '_authSession';

    public const LIMIT_SETTING = 'auth::max_sessions';

    public const DEFAULT_LIMIT = 1;

    /** date_active is written at most this often (seconds), not on every request. */
    public const TOUCH_INTERVAL = 60;

    /** Ended and expired rows are deleted after this many days. */
    public const KEEP_DAYS = 30;

    /** True while an ended session is being logged out (check), so it is not recorded as a logout. */
    public static $signingOut = false;

    /** About one login in this many also deletes old rows; 0 never does (tests). */
    public static $cleanupChance = 100;

    /**
     * A person's login in the web app: a new row, then the limit. A remember-me cookie login continues the row its
     * cookie names (allowsCookieLogin let it in).
     */
    public static function start(User $user, int $duration, ?string $method, bool $cookieBased = false): void
    {
        try {
            if ($cookieBased) {
                $token = self::cookieToken();
                $row = $token !== null ? self::activeRow($token, (int) $user->id_user) : null;
                if ($row !== null) {
                    Yii::$app->session->set(self::SESSION_KEY, $token);
                    self::touch($row);
                    return;
                }
            }
            $token = Yii::$app->security->generateRandomString(40);
            $session = self::insert($user, $token, $duration, $method);
            Yii::$app->session->set(self::SESSION_KEY, $token);
            if ($duration > 0) {
                self::sendCookie($token, $duration);
            }
            self::enforce($user, (int) $session->id_session);
            if (self::$cleanupChance > 0 && mt_rand(1, self::$cleanupChance) === 1) {
                self::cleanup();
            }
        } catch (Throwable $e) {
            Yii::warning('Session tracking failed: ' . get_class($e), __METHOD__);
        }
    }

    /**
     * Whether a remember-me cookie may sign this user in: only with the session cookie of an active row of theirs.
     * A refused one loses its identity cookie, so it is not tried again on every request.
     */
    public static function allowsCookieLogin(User $user): bool
    {
        $token = self::cookieToken();
        if ($token !== null && self::activeRow($token, (int) $user->id_user) !== null) {
            return true;
        }
        $response = Yii::$app->response;
        $response->cookies->remove(Yii::$app->user->identityCookie['name'] ?? '_identity');
        $response->cookies->remove(self::COOKIE);
        AuthLogger::record(AuthLog::EVENT_LOGIN_BLOCKED, [
            'result' => AuthLog::RESULT_ERROR,
            'method' => AuthLog::METHOD_COOKIE,
            'reason' => 'session_ended',
            'id_user' => (int) $user->id_user,
            'username' => $user->username,
        ]);

        return false;
    }

    /**
     * Every web request of a signed-in user. Returns false when the session had been ended: the user is then logged
     * out (the PHP session kept, so the login page can say why).
     */
    public static function check(): bool
    {
        $webUser = Yii::$app->user;
        if ($webUser->getIsGuest()) {
            return true;
        }
        try {
            $token = Yii::$app->session->get(self::SESSION_KEY);
            if (!is_string($token)) {
                self::adopt($webUser->getIdentity());
                return true;
            }
            $row = AuthSession::findOne(['token_hash' => self::hash($token)]);
            if ($row === null || (int) $row->id_user !== (int) $webUser->getId() || $row->date_end !== null) {
                self::signOut($row !== null ? (string) $row->end_reason : '');
                return false;
            }
            self::touch($row);
        } catch (Throwable $e) {
            Yii::warning('Session check failed: ' . get_class($e), __METHOD__);
        }

        return true;
    }

    /** The current browser's own session row, if any. */
    public static function current(): ?AuthSession
    {
        $token = Yii::$app->has('session') ? Yii::$app->session->get(self::SESSION_KEY) : null;

        return is_string($token) ? AuthSession::findOne(['token_hash' => self::hash($token)]) : null;
    }

    /** Ends the current browser's session (logout). */
    public static function endCurrent(string $reason): void
    {
        try {
            $row = self::current();
            if ($row !== null && $row->date_end === null) {
                self::end($row, $reason, false);
            }
            Yii::$app->session->remove(self::SESSION_KEY);
            Yii::$app->response->cookies->remove(self::COOKIE);
        } catch (Throwable $e) {
            Yii::warning('Session end failed: ' . get_class($e), __METHOD__);
        }
    }

    /** Ends one session; the other browser is logged out on its next request. */
    public static function end(AuthSession $row, string $reason, bool $record = true): void
    {
        $row->date_end = date('Y-m-d H:i:s');
        $row->end_reason = $reason;
        $row->save(false, ['date_end', 'end_reason']);
        if ($record) {
            AuthLogger::record(AuthLog::EVENT_SESSION_END, [
                'method' => null,
                'reason' => $reason,
                'id_user' => (int) $row->id_user,
                'username' => $row->user->username ?? null,
            ]);
        }
    }

    /** Ends the account's other active sessions, keeping the current browser's; returns how many ended. */
    public static function endOthers(int $idUser, string $reason): int
    {
        $current = self::current();
        $query = AuthSession::findActive()->andWhere(['id_user' => $idUser]);
        if ($current !== null) {
            $query->andWhere(['!=', 'id_session', $current->id_session]);
        }
        $ended = 0;
        foreach ($query->all() as $row) {
            self::end($row, $reason);
            $ended++;
        }

        return $ended;
    }

    /** The account's session limit; 0 = unlimited. */
    public static function limitFor(int $idUser): int
    {
        $own = (new \yii\db\Query())->select('max_sessions')->from(self::limitTable())->where(['id_user' => $idUser])->scalar();
        if ($own !== false && $own !== null) {
            return max(0, (int) $own);
        }
        try {
            $value = Yii::$app->has('setting') ? Yii::$app->setting->getValue(self::LIMIT_SETTING) : null;
        } catch (Throwable $e) {
            $value = null;
        }

        return is_numeric($value) ? max(0, (int) $value) : self::DEFAULT_LIMIT;
    }

    /** Gives the account its own limit (0 = unlimited), or null to follow the setting again. */
    public static function setLimit(int $idUser, ?int $limit): void
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete(self::limitTable(), ['id_user' => $idUser])->execute();
        if ($limit !== null) {
            $db->createCommand()->insert(self::limitTable(), ['id_user' => $idUser, 'max_sessions' => max(0, $limit)])->execute();
        }
    }

    /** The accounts with their own limit, as id_user => limit. */
    public static function ownLimits(): array
    {
        return (new \yii\db\Query())->select(['max_sessions', 'id_user'])->from(self::limitTable())->indexBy('id_user')->column();
    }

    public static function cleanup(): int
    {
        $before = date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400);

        return Yii::$app->db->createCommand()->delete(
            AuthSession::tableName(),
            ['or', ['<', 'date_end', $before], ['<', 'date_expire', $before]]
        )->execute();
    }

    public static function endedMessage(string $reason): string
    {
        return $reason === AuthSession::END_REPLACED
            ? Module::t('You were signed out because your account signed in on another device.')
            : Module::t('Your session was ended. Please sign in again.');
    }

    /**
     * Keeps the newest active sessions up to the limit and ends the rest. Newest by id, so two logins at the same
     * moment agree on which one stays.
     */
    private static function enforce(User $user, int $keep): void
    {
        $limit = self::limitFor((int) $user->id_user);
        if ($limit <= 0) {
            return;
        }
        $active = AuthSession::findActive()
            ->andWhere(['id_user' => $user->id_user])
            ->orderBy(['id_session' => SORT_DESC])
            ->all();
        foreach (array_slice($active, $limit) as $row) {
            if ((int) $row->id_session !== $keep) {
                self::end($row, AuthSession::END_REPLACED);
            }
        }
    }

    /** Logs out a session that was ended elsewhere; the PHP session is emptied, only the reason stays for the login page. */
    private static function signOut(string $reason): void
    {
        $session = Yii::$app->session;
        self::$signingOut = true;
        try {
            Yii::$app->user->logout(false);
        } finally {
            self::$signingOut = false;
        }
        $session->removeAll();
        $session->regenerateID(true);
        Yii::$app->response->cookies->remove(self::COOKIE);
        AuthNotice::set('warning', self::endedMessage($reason));
    }

    private static function activeRow(string $token, int $idUser): ?AuthSession
    {
        return AuthSession::findActive()->andWhere(['token_hash' => self::hash($token), 'id_user' => $idUser])->one();
    }

    private static function cookieToken(): ?string
    {
        $value = Yii::$app->request instanceof \yii\web\Request ? Yii::$app->request->getCookies()->getValue(self::COOKIE) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The session cookie goes with the identity cookie: same lifetime and the same flags. */
    private static function sendCookie(string $token, int $duration): void
    {
        $identity = Yii::$app->user->identityCookie;
        unset($identity['name']);
        Yii::$app->response->cookies->add(new \yii\web\Cookie(array_merge(['httpOnly' => true], $identity, [
            'name' => self::COOKIE,
            'value' => $token,
            'expire' => time() + $duration,
        ])));
    }

    /** A session that was open before tracking (or whose row is gone): a row, but no limit, so nobody is logged out. */
    private static function adopt(User $user): void
    {
        $token = Yii::$app->security->generateRandomString(40);
        self::insert($user, $token, 0, null);
        Yii::$app->session->set(self::SESSION_KEY, $token);
    }

    private static function insert(User $user, string $token, int $duration, ?string $method): AuthSession
    {
        $now = time();
        $request = Yii::$app->request;
        $row = new AuthSession([
            'token_hash' => self::hash($token),
            'id_user' => (int) $user->id_user,
            'method' => $method,
            'ip' => $request instanceof \yii\web\Request ? $request->getUserIP() : null,
            'user_agent' => $request instanceof \yii\web\Request ? self::text($request->getUserAgent(), 255) : null,
            'date_create' => date('Y-m-d H:i:s', $now),
            'date_active' => date('Y-m-d H:i:s', $now),
            'date_expire' => date('Y-m-d H:i:s', $now + max($duration, self::timeout())),
        ]);
        $row->save(false);

        return $row;
    }

    private static function touch(AuthSession $row): void
    {
        $now = time();
        if (strtotime($row->date_active) > $now - self::TOUCH_INTERVAL) {
            return;
        }
        $row->date_active = date('Y-m-d H:i:s', $now);
        $row->date_expire = date('Y-m-d H:i:s', max(strtotime($row->date_expire), $now + self::timeout()));
        $row->save(false, ['date_active', 'date_expire']);
    }

    private static function timeout(): int
    {
        return Yii::$app->has('session') ? max(1440, (int) Yii::$app->session->getTimeout()) : 86400;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function limitTable(): string
    {
        return '{{%' . Module::$tablePrefix . 'session_limit}}';
    }

    private static function text(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', mb_scrub($value, 'UTF-8')) ?? '';

        return mb_substr($value, 0, $length);
    }
}
