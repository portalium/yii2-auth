<?php

namespace portalium\auth\components;

use portalium\auth\models\AuthProvider;
use portalium\auth\Module;
use portalium\user\models\User;
use Throwable;
use Yii;
use yii\base\Event;
use yii\db\Query;

/**
 * Where each account came from (auth_signup_source): a sign-up with email and password, Google, Apple, a workspace's
 * corporate identity provider (which one), or an administrator. Kept apart from the sign-in methods linked later.
 *
 * The code that creates an account says how (expect()) just before; an account made without that is a sign-up when
 * nobody is signed in, else made by the signed-in person (an administrator). Accounts from before this have no row.
 */
class SignupSource
{
    public const PASSWORD = 'password';
    public const GOOGLE = 'google';
    public const APPLE = 'apple';
    public const CORPORATE = 'corporate';
    public const ADMIN = 'admin';

    /** @var array|null [source, id_provider] for the next account */
    private static $next;

    /** @var array<int, array|null> rows read for a list page (prefetch) */
    private static $rows = [];

    public static function expect(string $source, ?int $idProvider = null): void
    {
        self::$next = [$source, $idProvider];
    }

    public static function onUserInsert(Event $event): void
    {
        [$source, $idProvider] = self::$next ?? [null, null];
        self::$next = null;
        unset(self::$rows[(int) $event->sender->id_user]);
        $creator = null;
        if ($source === null) {
            $webUser = Yii::$app->has('user') ? Yii::$app->user : null;
            $signedIn = $webUser instanceof \yii\web\User && $webUser->getIdentity(false) !== null;
            $source = $signedIn ? self::ADMIN : self::PASSWORD;
            $creator = $signedIn ? (int) $webUser->getId() : null;
        }
        try {
            Yii::$app->db->createCommand()->insert(self::table(), [
                'id_user' => (int) $event->sender->id_user,
                'source' => $source,
                'id_provider' => $idProvider,
                'id_creator' => $creator,
                'date_create' => date('Y-m-d H:i:s'),
            ])->execute();
        } catch (Throwable $e) {
            Yii::warning('Signup source not recorded: ' . get_class($e), __METHOD__);
        }
    }

    /** Reads the rows of these accounts at once, for a list page. */
    public static function prefetch(array $idUsers): void
    {
        $idUsers = array_values(array_diff(array_map('intval', $idUsers), array_keys(self::$rows)));
        if ($idUsers === []) {
            return;
        }
        $rows = (new Query())->from(self::table())->where(['id_user' => $idUsers])->indexBy('id_user')->all();
        foreach ($idUsers as $id) {
            self::$rows[$id] = $rows[$id] ?? null;
        }
    }

    /** "Email and password", "Google", "Corporate: ABC Entra (ABC)", … or "Unknown" for older accounts. */
    public static function label(int $idUser): string
    {
        self::prefetch([$idUser]);
        $row = self::$rows[$idUser] ?? null;
        if ($row === null) {
            return Module::t('Unknown');
        }
        switch ($row['source']) {
            case self::PASSWORD:
                return Module::t('Email and password');
            case self::GOOGLE:
                return 'Google';
            case self::APPLE:
                return 'Apple';
            case self::ADMIN:
                return Module::t('Created by an administrator');
            case self::CORPORATE:
                $provider = AuthProvider::findOne((int) $row['id_provider']);
                return $provider === null
                    ? Module::t('Corporate sign-in (removed)')
                    : Module::t('Corporate: {name} ({workspace})', [
                        'name' => $provider->name,
                        'workspace' => $provider->workspace->title ?? '#' . $provider->id_workspace,
                    ]);
        }

        return (string) $row['source'];
    }

    public static function table(): string
    {
        return '{{%' . Module::$tablePrefix . 'signup_source}}';
    }
}
