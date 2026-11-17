<?php

namespace portalium\auth\models;

use portalium\auth\Module;
use portalium\user\models\User;
use yii\db\ActiveRecord;

/**
 * One signed-in browser session of a user (SessionTracker). Only the hash of the session's token is stored; the
 * token itself lives in that browser's PHP session. A row is active while it has no date_end and has not expired.
 *
 * @property int $id_session
 * @property string $token_hash
 * @property int $id_user
 * @property string|null $method
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $date_create
 * @property string $date_active
 * @property string $date_expire
 * @property string|null $date_end
 * @property string|null $end_reason
 * @property User|null $user
 */
class AuthSession extends ActiveRecord
{
    public const END_LOGOUT = 'logout';
    public const END_REPLACED = 'replaced';
    public const END_BY_USER = 'ended_by_user';
    public const END_BY_ADMIN = 'ended_by_admin';

    public static function tableName()
    {
        return '{{%' . Module::$tablePrefix . 'session}}';
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id_user' => 'id_user']);
    }

    /** Active sessions: not ended and not expired. */
    public static function findActive()
    {
        return static::find()->where(['date_end' => null])->andWhere(['>', 'date_expire', date('Y-m-d H:i:s')]);
    }
}
