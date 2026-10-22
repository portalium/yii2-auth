<?php

namespace portalium\auth\models;

use Yii;
use portalium\base\Event;
use yii\base\Model;
use portalium\auth\Module;
use portalium\auth\components\AccountStatus;
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

    public function rules()
    {
        return [
            [['username', 'password'], 'required'],
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

    public function validatePassword($attribute, $params)
    {
        if (!$this->hasErrors()) {
            $user = $this->getUser();
            if (!$user || !$user->validatePassword($this->password)) {
                $this->addError($attribute, Module::t('Incorrect email or password.'));
            }
        }
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