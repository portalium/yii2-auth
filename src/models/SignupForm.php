<?php

namespace portalium\auth\models;

use Exception;
use portalium\auth\components\AccountStatus;
use portalium\base\Event;
use portalium\site\components\Site;
use portalium\workspace\components\InvitationSignup;
use yii\base\Model;
use portalium\auth\Module;
use Yii;
use portalium\user\models\User;
use yii\web\NotFoundHttpException;

class SignupForm extends Model
{
    public $username;
    public $email;
    public $password;
    public $password_confirm;
    public $verifyCode;
    public $first_name;
    public $last_name;
    public $isApiRequest;
    /** @var mixed the invitation link's token (web: hidden field, API: "invitation"); see invitationMatched() */
    public $invitation;
    /** @var mixed the signature of the invitation link in the email (web: hidden field, API: "sig"); decision 35/49 */
    public $sig;
    const RECAPCHA_ACTIVE = 1;
    const RECAPCHA_PASIVE = 0;

    /** Web signup form; the reCAPTCHA secret key is only required in this scenario. */
    public const SCENARIO_WEB = 'web';

    /** The Yii::$app->params key for the reCAPTCHA secret key (in the install's local configuration). */
    public const RECAPTCHA_SECRET_PARAM = 'recaptchaSecretKey';

    private bool $invitationMatched = false;


    public function rules()
    {
        $rules = [
            ['username', 'trim'],
            ['username', 'required'],
            ['username', 'unique', 'targetClass' => '\portalium\user\models\User', 'message' => Module::t('This username has already been taken.')],
            ['username', 'string', 'min' => 2, 'max' => 255],
            ['username', 'match', 'pattern' => '/^[a-z0-9_.]+$/', 'message' => Module::t('Username can only contain alphanumeric characters, underscores, and dots.')],
            ['email', 'trim'],
            ['email', 'required'],
            ['email', 'email'],
            ['email', 'string', 'max' => 255],
            ['email', 'unique', 'targetClass' => '\portalium\user\models\User', 'message' => Module::t('This email address has already been taken.')],
            ['password', 'required'],
            ['password', 'string', 'min' => 6],
            ['password_confirm', 'required'],
            ['password_confirm', 'compare', 'compareAttribute' => 'password', 'message' => Module::t('Passwords do not match.')],
            ['first_name', 'safe'],
            ['last_name', 'safe'],
            ['invitation', 'safe'],
            ['sig', 'safe'],
            ['email', 'validateSignedInvitationEmail', 'on' => self::SCENARIO_WEB],
            ['verifyCode', 'validateRecaptchaSecret', 'skipOnEmpty' => false, 'on' => self::SCENARIO_WEB],
        ];

        $secret = self::recaptchaSecret();
        if ($secret === null) {
            return $rules;
        }
        $rules[] = [
            ['verifyCode'], \himiklab\yii2\recaptcha\ReCaptchaValidator3::className(),
            'secret' => $secret,
            'threshold' => '0.5',
            'action' => 'signup',
            'when' => function () {
                if (Yii::$app instanceof \portalium\web\Controller) {
                    if (Yii::$app->setting->getValue('site::recaptcha') == Yii::$app->site->RECAPTCHA_ACTIVE) {
                        return Yii::$app->setting->getValue('site::recaptcha');
                    } else {
                        return false;
                    }
                } else {
                    return false;
                }
            }
        ];

        return $rules;
    }

    /**
     * The reCAPTCHA secret key: Yii::$app->params['recaptchaSecretKey'] (the install's uncommitted local
     * configuration, common/config/main-local.php). Null if empty or missing.
     */
    public static function recaptchaSecret(): ?string
    {
        $secret = Yii::$app->params[self::RECAPTCHA_SECRET_PARAM] ?? null;
        $secret = is_string($secret) ? trim($secret) : '';

        return $secret === '' ? null : $secret;
    }

    /**
     * On the web signup form, if the reCAPTCHA setting (site::recaptcha) is on but the secret key is not
     * configured, signup is refused (decision 8): the user sees a generic message, and the missing
     * configuration is logged. The reCAPTCHA validator cannot be constructed without a key
     * (InvalidConfigException); this rule stops the signup instead of silently skipping validation.
     */
    public function validateRecaptchaSecret($attribute)
    {
        if (self::recaptchaSecret() !== null || !self::recaptchaEnabled()) {
            return;
        }
        Yii::error(
            'reCAPTCHA secret key is not configured (params[' . self::RECAPTCHA_SECRET_PARAM . '] in common/config/main-local.php); web signup is refused.',
            __METHOD__
        );
        $this->addError($attribute, Module::t('Signing up is not possible at the moment. Please try again later.'));
    }

    /**
     * Whether web signup is currently impossible: the reCAPTCHA setting is on but the secret key is not
     * configured (decision 8). Announced when the page loads, disabling [Sign up] (final review U11); even if
     * submitted anyway, validateRecaptchaSecret refuses it.
     */
    public static function signupBlocked(): bool
    {
        return self::recaptchaSecret() === null && self::recaptchaEnabled();
    }

    /**
     * Message shown while signup is unavailable; also includes the approval contact address if one is set.
     */
    public static function blockedMessage(): string
    {
        $text = Module::t('Signing up is not possible at the moment. Please try again later.');
        $contact = AccountStatus::approvalContact();
        if ($contact !== null) {
            $text .= ' ' . Module::t('If it is urgent, you can write to {contact}.', ['contact' => $contact]);
        }

        return $text;
    }

    private static function recaptchaEnabled(): bool
    {
        try {
            return (int) Yii::$app->setting->getValue('site::recaptcha') === self::RECAPCHA_ACTIVE;
        } catch (NotFoundHttpException $e) {
            return true;
        }
    }

    /**
     * Whether the last signup was done via the signed invitation link in the email, with the invited address
     * (decision 28, 35/49); if so, the account was created active with its email already verified.
     */
    public function invitationMatched(): bool
    {
        return $this->invitationMatched;
    }

    /**
     * The invitation token (decision 28). Ignored if not a string.
     */
    public function invitationToken(): string
    {
        return is_string($this->invitation) ? trim($this->invitation) : '';
    }

    /**
     * The signature from the email (decision 35/49). Ignored if not a string.
     */
    public function invitationSignature(): string
    {
        return is_string($this->sig) ? trim($this->sig) : '';
    }

    /**
     * Whether the form was opened via the signed invitation link in the email: if the signature belongs to this
     * token's pending invitation, returns the invitation's address (trimmed + lowercased), otherwise null. On a
     * signed link, the email field is pre-filled with this address and read-only (decision 45), and signup is
     * possible even while auth::web_signup is off (decision 40). Null if the workspace module is not installed.
     */
    public function signedInvitationEmail(): ?string
    {
        $token = $this->invitationToken();
        $sig = $this->invitationSignature();
        if ($token === '' || $sig === '' || !class_exists(InvitationSignup::class)) {
            return null;
        }

        return InvitationSignup::signedAddress($token, $sig);
    }

    public function invitationSigned(): bool
    {
        return $this->signedInvitationEmail() !== null;
    }

    /**
     * If the web form was opened via the signed invitation link, the email must be the invitation's address
     * (decision 45). The field is read-only; this rule rejects a tampered request. The API has no such rule:
     * signing up with a different address there just falls through to the normal flow.
     */
    public function validateSignedInvitationEmail($attribute)
    {
        $signed = $this->signedInvitationEmail();
        if ($signed === null || mb_strtolower(trim((string) $this->$attribute)) === $signed) {
            return;
        }
        $this->addError($attribute, Module::t('With this invitation link you can only sign up with the address the invitation was sent to.'));
    }

    /**
     * Invitation info to show on the signup form (final review U4/U5): if the token belongs to a valid pending
     * invitation, the workspace's title and a masked version of the address the invitation was sent to;
     * otherwise null. Null if the workspace module is not installed.
     *
     * @return array{workspace:string, masked_email:string}|null
     */
    public function invitationHint(): ?array
    {
        $token = $this->invitationToken();
        if ($token === '' || !class_exists(InvitationSignup::class)) {
            return null;
        }

        return InvitationSignup::describe($token);
    }

    /**
     * Whether this came via the signed invitation link in the email, and the invitation's address matches the
     * email being signed up with (decision 35/49). The token alone is not enough (the inviter can see it), and
     * an address match alone is not enough either (see InvitationSignup::matches). The invitation is ignored if
     * the workspace module is not installed.
     */
    private function matchesInvitation(): bool
    {
        $token = $this->invitationToken();
        if ($token === '' || !class_exists(InvitationSignup::class)) {
            return false;
        }

        return InvitationSignup::matches($token, (string) $this->email, $this->invitationSignature());
    }

    public function attributeLabels()
    {
        return [
            'username' => Module::t('Username'),
            'email' => Module::t('Email'),
            'password' => Module::t('Password'),
            'password_confirm' => Module::t('Confirm Password'),
            'verifyCode' => Module::t('Verify Code'),
            'rememberMe' => Module::t('Remember Me'),
        ];
    }

    /**
     * @param bool|null $emailVerification null: follows the site::verifyEmail setting (web signup). false: email
     *        verification is not required, no link is generated or sent; used by the API and Google/Apple signup
     *        (Card 2, decision A). When verification is not required, the status comes from the auth::user_status
     *        setting (VerifyEmailForm::registrationStatus). A signup via the signed invitation link, with the
     *        invited address (decision 28, 35/49), is always created active with its email verified regardless
     *        of these settings; no verification email is sent in that case.
     */
    public function signup(?bool $emailVerification = null)
    {
        $this->invitationMatched = false;
        if (!$this->validate()) {
            Yii::warning($this->errors);
            return null;
        }

        $this->invitationMatched = $this->matchesInvitation();
        $verify = !$this->invitationMatched
            && ($emailVerification ?? (bool) Yii::$app->setting->getValue('site::verifyEmail'));

        $user = new User();
        $user->username = $this->username;
        $user->email = $this->email;
        $user->first_name = $this->first_name;
        $user->last_name = $this->last_name;
        $user->setPassword($this->password);
        $user->access_token = \Yii::$app->security->generateRandomString();
        $user->generateAuthKey();
        if ($verify) {
            $user->generateEmailVerificationToken();
        }
        $user->status = $verify ? User::STATUS_PASSIVE : VerifyEmailForm::registrationStatus();
        if ($this->invitationMatched) {
            $user->status = User::STATUS_ACTIVE;
            $user->email_verify = User::EMAIL_VERIFY;
        }


        if ($user->save()) {
            if ($verify) {
                try {
                    if ($this->sendEmail($user)) {
                        Yii::$app->trigger(Module::EVENT_ON_SIGNUP, new Event(['payload' => $user]));
                        Yii::$app->session->setFlash('success', 'Your registration has been successfully completed. Confirm the confirmation e-mail sent to your e-mail.');
                        return $user;
                    }
                } catch (Exception $e) {
                    Yii::warning($e->getMessage());
                    Yii::$app->session->addFlash('error', Module::t('SMTP error: Unable to send verification email. Please try again later.'));
                    $user->delete();
                }
            } else {
                Yii::warning('No email verification required');
                Yii::$app->session->setFlash('success', 'Your registration has been successfully completed.');
                Yii::$app->trigger(Module::EVENT_ON_SIGNUP, new Event(['payload' => $user]));
                return $user;
            }
        } else {
            Yii::warning($user->errors);
        }
        Yii::warning('Signup failed');
        return null;
    }

    protected function sendEmail($user)
    {
        Yii::$app->site->mailer->setViewPath(Yii::getAlias('@portalium/auth/mail'));
        return Yii::$app
            ->site
            ->mailer
            ->compose(
                ['html' => 'emailVerify-html', 'text' => 'emailVerify-text'],
                ['user' => $user]
            )
            ->setFrom([Yii::$app->setting->getValue('email::address') => Yii::$app->setting->getValue('email::displayname')])
            ->setTo($this->email)
            ->setSubject('Account registration at ' .  Yii::$app->setting->getValue('app::title'))
            ->send();
             
    }
}