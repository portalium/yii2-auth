<?php

namespace portalium\auth\models;

use yii\db\ActiveRecord;

/**
 * @property int $id_user
 * @property string|null $active_phone
 * @property string|null $active_code_hash
 * @property int|null $active_expires_at
 * @property string|null $pending_phone
 * @property string|null $pending_code_hash
 * @property int|null $pending_expires_at
 * @property int $last_sent_at
 * @property int $attempts
 * @property int|null $locked_until
 * @property int|null $window_started_at
 * @property int $sent_count
 *
 * @property \portalium\user\models\User $user
 */
class SmsVerification extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%auth_sms_verification}}';
    }

    public function rules()
    {
        return [
            [['id_user', 'last_sent_at', 'attempts', 'sent_count'], 'required'],
            [['id_user', 'active_expires_at', 'pending_expires_at', 'last_sent_at', 'locked_until', 'window_started_at'], 'integer'],
            [['attempts', 'sent_count'], 'integer', 'min' => 0],
            [['active_phone', 'pending_phone'], 'string', 'max' => 16],
            [['active_code_hash', 'pending_code_hash'], 'string', 'max' => 255],
        ];
    }

    public function getUser()
    {
        return $this->hasOne(\portalium\user\models\User::class, ['id_user' => 'id_user']);
    }
}
