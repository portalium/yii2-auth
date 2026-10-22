<?php

namespace portalium\auth\models;

use Throwable;
use Yii;
use yii\base\Model;
use portalium\auth\Module;
use portalium\user\models\User;

/**
 * Resends the verification email.
 *
 * The email only goes to an account that came from signup, is passive, and has an unverified email (see
 * canReceive); it never goes to a pending-approval or deleted account, an account created from the admin
 * panel, or when email verification is disabled. The same account is not sent to more than once per
 * RESEND_INTERVAL seconds. Each send regenerates the token, so only the latest link stays valid. The form
 * never reveals whether an account exists for the given address.
 */
class ResendVerificationEmailForm extends Model
{
    public const RESEND_INTERVAL = 60;

    public $email;

    public function rules()
    {
        return [
            ['email', 'trim'],
            ['email', 'required'],
            ['email', 'email'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'email' => Module::t('Email'),
        ];
    }

    /**
     * Sends a verification email to the form's address.
     *
     * @return bool true if the email was sent; false if no account exists, it is not eligible, or the rate limit has not elapsed
     */
    public function sendEmail(): bool
    {
        $user = User::findOne(['email' => $this->email]);

        return $user !== null && static::sendTo($user);
    }

    /**
     * Sends a new verification link to the user.
     */
    public static function sendTo(User $user): bool
    {
        if (!static::canReceive($user) || static::sentRecently($user)) {
            return false;
        }

        $user->generateEmailVerificationToken();
        if (!$user->save(false)) {
            return false;
        }

        $displayName = Yii::$app->setting->getValue('email::displayname');
        try {
            Yii::$app->site->mailer->setViewPath(Yii::getAlias('@portalium/auth/mail'));

            return (bool) Yii::$app->site->mailer
                ->compose(['html' => 'emailVerify-html', 'text' => 'emailVerify-text'], ['user' => $user])
                ->setFrom([Yii::$app->setting->getValue('email::address') => $displayName])
                ->setTo($user->email)
                ->setSubject(Module::t('Account activation for {email_displayname}!', ['email_displayname' => $displayName]))
                ->send();
        } catch (Throwable $e) {
            Yii::warning('Verification email could not be sent: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * The verification email only goes to an account that came from signup and is awaiting verification: email
     * verification enabled, account passive, email unverified, and the token issued at signup is still present.
     * Accounts created from the admin panel have no token, so if an admin sets one of those to passive, the
     * user cannot get a link and activate it themselves.
     */
    public static function canReceive(User $user): bool
    {
        return (bool) Yii::$app->setting->getValue('site::verifyEmail')
            && (int) $user->status === User::STATUS_PASSIVE
            && (int) $user->email_verify === User::EMAIL_NOT_VERIFY
            && (string) $user->verification_token !== '';
    }

    /**
     * True if the token's "_<unix timestamp>" suffix is more recent than RESEND_INTERVAL seconds.
     */
    public static function sentRecently(User $user): bool
    {
        $token = (string) $user->verification_token;
        $pos = strrpos($token, '_');
        if ($pos === false) {
            return false;
        }

        return (int) substr($token, $pos + 1) > time() - self::RESEND_INTERVAL;
    }
}
