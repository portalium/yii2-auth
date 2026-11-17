<?php

namespace portalium\auth\models;

use yii\base\Model;
use yii\base\InvalidArgumentException;
use portalium\auth\Module;
use portalium\auth\components\LoginThrottle;
use portalium\user\models\User;

class ResetPasswordForm extends Model
{
    public $password;

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
        ];
    }

    public function attributeLabels()
    {
        return [
            'password' => Module::t('Password'),
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