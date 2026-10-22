<?php

namespace portalium\auth\models;

use Yii;
use yii\base\Model;
use yii\base\InvalidArgumentException;
use portalium\user\models\User;

/**
 * Processes the email verification link.
 *
 * The link is only valid for a user whose email is not yet verified and who is passive or active, and it is
 * single-use. This way, a user the administrator deleted, or one awaiting approval (whose email is already
 * verified), cannot activate their own account with an old link. The verified user's status is only ever
 * upgraded: an active user stays active, a passive user's status follows the auth::user_status setting.
 */
class VerifyEmailForm extends Model
{
    public $token;

    private $user;

    public function __construct($token, array $config = [])
    {
        if (empty($token) || !is_string($token)) {
            throw new InvalidArgumentException('Verify email token cannot be blank.');
        }
        $this->user = User::findOne([
            'verification_token' => $token,
            'email_verify' => User::EMAIL_NOT_VERIFY,
            'status' => [User::STATUS_PASSIVE, User::STATUS_ACTIVE],
        ]);
        if (!$this->user) {
            throw new InvalidArgumentException('Wrong verify email token.');
        }
        $this->token = $token;
        parent::__construct($config);
    }

    /**
     * Marks the email as verified and clears the token.
     *
     * @return User|null the saved user; null if saving fails
     */
    public function verifyEmail(): ?User
    {
        $user = $this->user;
        $user->email_verify = User::EMAIL_VERIFY;
        $user->verification_token = null;
        if ((int) $user->status !== User::STATUS_ACTIVE) {
            $user->status = static::registrationStatus();
        }

        return $user->save(false) ? $user : null;
    }

    /**
     * The verified user's status per the signup setting (auth::user_status).
     * If the setting is not explicitly Active, this always returns passive (administrator approval).
     */
    public static function registrationStatus(): int
    {
        return (int) Yii::$app->setting->getValue('auth::user_status') === User::STATUS_ACTIVE
            ? User::STATUS_ACTIVE
            : User::STATUS_PASSIVE;
    }
}
