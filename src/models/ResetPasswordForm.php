<?php

namespace portalium\auth\models;

use yii\base\Model;
use yii\base\InvalidArgumentException;
use portalium\auth\Module;
use portalium\auth\components\LoginThrottle;
use portalium\user\models\User;

class ResetPasswordForm extends Model
{
    const SCENARIO_WEB = 'web';

    public $password;
    public $password_confirm;

    private $_user;

    public function __construct($token, $config = [])
    {
        if (empty($token) || !is_string($token)) {
            throw new InvalidArgumentException(Module::t('Password reset token cannot be blank.'));
        }
        $this->_user = User::findByPasswordResetToken($token);
        if (!$this->_user) {
            throw new InvalidArgumentException(Module::t('Wrong password reset token.'));
        }
        parent::__construct($config);
    }

    public function rules()
    {
        return [
            ['password', 'required'],
            ['password', 'string', 'min' => \portalium\user\components\PasswordPolicy::minLength(), 'max' => \portalium\user\components\PasswordPolicy::MAX_LENGTH],
            ['password', \portalium\user\components\PasswordPolicy::class, 'userAttributes' => []],
            ['password_confirm', 'required', 'on' => self::SCENARIO_WEB],
            ['password_confirm', 'compare', 'compareAttribute' => 'password', 'on' => self::SCENARIO_WEB, 'message' => Module::t('Passwords do not match.')],
        ];
    }

    public function attributeLabels()
    {
        return [
            'password' => Module::t('New Password'),
            'password_confirm' => Module::t('Confirm New Password'),
        ];
    }

    public function resetPassword()
    {
        $user = $this->_user;
        $user->setPassword($this->password);
        $user->removePasswordResetToken();
        if (!$user->save(false)) {
            return false;
        }
        LoginThrottle::reset('', $user, LoginThrottle::requestIp());

        return true;
    }
}