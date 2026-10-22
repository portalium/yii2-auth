<?php

namespace portalium\auth\models;

use Yii;
use yii\base\Model;
use portalium\auth\Module;
use portalium\user\models\User;

/**
 * Password reset request. The response never reveals whether an account exists or is active: if an active
 * account exists, the link is sent; otherwise nothing is sent. The user sees the same message either way.
 */
class PasswordResetRequestForm extends Model
{
    public $email;

    public function rules()
    {
        return [
            ['email', 'trim'],
            ['email', 'required'],
            ['email', 'email'],
        ];
    }

    /**
     * The neutral result message shown in every case.
     */
    public static function sentMessage(): string
    {
        return Module::t(
            'If there is an active account for this address, we sent a password reset link to it.'
            . ' If your account is awaiting approval, it must be approved first.'
        );
    }

    /**
     * Processes the request without revealing whether the account exists: if no active account exists, sends
     * nothing and returns true; returns false only if sending to an existing active account's email fails.
     */
    public function request(): bool
    {
        if (!User::find()->where(['status' => User::STATUS_ACTIVE, 'email' => $this->email])->exists()) {
            return true;
        }

        return (bool) $this->sendEmail();
    }

    public function attributeLabels()
    {
        return [
            'email' => Module::t('Email'),
        ];
    }

    public function sendEmail()
    {
        $user = User::findOne([
            'status' => User::STATUS_ACTIVE,
            'email' => $this->email,
        ]);

        if (!$user) {
            return false;
        }
        
        if (!User::isPasswordResetTokenValid($user->password_reset_token)) {
            $user->generatePasswordResetToken();
            if (!$user->save()) {
                return false;
            }
        }

        Yii::$app->site->mailer->setViewPath(Yii::getAlias('@portalium/auth/mail'));
        
        return Yii::$app
            ->site
            ->mailer
            ->compose(
                ['html' => 'passwordResetToken-html', 'text' => 'passwordResetToken-text'],
                ['user' => $user]
            )
            ->setFrom(Yii::$app->setting->getValue('email::address'))
            ->setTo($this->email)
            ->setSubject(Module::t('Password reset for {email_displayname}!',['email_displayname' => Yii::$app->setting->getValue('email::displayname')]))
            ->send();
    }
}