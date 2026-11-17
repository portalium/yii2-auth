<?php

namespace portalium\auth\models;

use Yii;
use portalium\base\Event;
use yii\base\Model;
use portalium\auth\Module;
use portalium\auth\components\AccountStatus;
use portalium\auth\components\AuthLogger;
use portalium\auth\components\ProviderAccount;
use portalium\auth\components\LoginThrottle;
use portalium\auth\models\AuthLog;
use yii\validators\EmailValidator;
use portalium\user\models\User;

class LoginForm extends Model
{
    public $username;
    public $password;
    public $rememberMe = true;
    private $_user;
    private $accountStatus;
    private $accountMessage;
    private $locked = false;

    public function rules()
    {
        return [
            [['username', 'password'], 'required'],
            [['username', 'password'], 'string'],
            ['rememberMe', 'boolean'],
            ['password', 'validatePassword'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'username' => Module::t('Email / Username'),
            'password' => Module::t('Password'),
            'rememberMe' => Module::t('Remember Me'),
        ];
    }

    /**
     * The password check, behind the lock (LoginThrottle): a locked account or client is refused, and so is an
     * attempt over the limit, before the password is checked; a right password takes its attempt back.
     */
    public function validatePassword($attribute, $params)
    {
        if (!$this->hasErrors()) {
            $user = $this->getUser();
            $name = (string) $this->username;
            $ip = LoginThrottle::requestIp();
            $seconds = LoginThrottle::lockedFor($name, $user, $ip);
            if ($seconds > 0 || !LoginThrottle::reserve($name, $user, $ip)) {
                $this->locked = true;
                $minutes = $seconds > 0 ? (int) ceil($seconds / 60) : LoginThrottle::minutes();
                $this->addError($attribute, Module::t('Too many failed attempts. Try again in {minutes} minutes.', [
                    'minutes' => $minutes,
                ]));
                AuthLogger::failed($name, $user, AuthLog::REASON_LOCKED, AuthLog::METHOD_PASSWORD);
                return;
            }
            $valid = $user !== null && !ProviderAccount::corporateOnly($user) && $user->validatePassword($this->password);
            if (!$valid) {
                $this->addError($attribute, Module::t('Incorrect email or password.'));
                AuthLogger::failed(
                    $name,
                    $user,
                    $user ? AuthLog::REASON_WRONG_PASSWORD : AuthLog::REASON_UNKNOWN_USER,
                    AuthLog::METHOD_PASSWORD
                );
                return;
            }
            LoginThrottle::succeeded($name, $user, $ip);
        }
    }

    /** Whether the last check was refused by the lock (the API answers 429). */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function login()
    {
        if (!$this->validate()) {
            return false;
        }

        $user = $this->getUser();
        if ((int) $user->status === User::STATUS_ACTIVE) {
            Yii::$app->session->set("login_status", true);
            Event::trigger(Yii::$app->getModules(), Module::EVENT_ON_LOGIN, new Event(['payload' => $user]));
            Yii::$app->trigger(Module::EVENT_ON_LOGIN, new Event(['payload' => $user]));
            return Yii::$app->user->login($user, $this->rememberMe ? 3600 * 24 * 30 : 0);
        }

        Yii::$app->session->set("login_status", false);
        $this->accountStatus = AccountStatus::of($user);
        AuthLogger::failed((string) $this->username, $user, $this->accountStatus, AuthLog::METHOD_PASSWORD);
        $this->accountMessage = AccountStatus::inactiveMessage($user);
        return false;
    }

    /**
     * The account's status (an AccountStatus constant) when the password was correct but the account is not
     * active; null otherwise. The status is not changed here (see AccountStatus).
     */
    public function getAccountStatus(): ?string
    {
        return $this->accountStatus;
    }

    /**
     * The reason shown to the user when the password was correct but the account is not active; null
     * otherwise. Since the password was correct, this is not added as a password field error: it is shown as
     * an info box (AuthNotice) above the web login card, and as "message" in the API response.
     */
    public function getAccountMessage(): ?string
    {
        return $this->accountMessage;
    }

    protected function getUser()
    {
        if ($this->_user === null) {
            $this->_user = User::findOne(
                (new EmailValidator())->validate($this->username) ?
                    ['email' => $this->username] : ['username' => $this->username]
            );
        }

        return $this->_user;
    }
}
