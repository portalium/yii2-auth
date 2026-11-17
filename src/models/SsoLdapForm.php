<?php

namespace portalium\auth\models;

use portalium\auth\components\LdapDirectory;
use portalium\auth\components\LdapUnavailable;
use portalium\auth\components\LoginThrottle;
use portalium\auth\components\ProviderLogin;
use portalium\auth\Module;
use Yii;
use yii\base\Model;

/**
 * Sign-in with the company directory of an LDAP provider (?domain=…): the person types their company username and
 * password into Suite, Suite asks the directory.
 *
 * Behind two locks before the directory is asked: the usual account + address lock (LoginThrottle::reserve) and a
 * per-name lock for this directory from any address (reserveName), so Suite cannot be used to spray the company's
 * accounts or to lock them out in its own directory.
 */
class SsoLdapForm extends Model
{
    public $username;
    public $password;

    /** @var AuthProvider */
    private $provider;

    /** @var string|null the message when the sign-in did not get through after the password check */
    private $refusal;

    public function __construct(AuthProvider $provider, array $config = [])
    {
        $this->provider = $provider;
        parent::__construct($config);
    }

    public function rules()
    {
        return [
            [['username', 'password'], 'required'],
            [['username', 'password'], 'string', 'max' => 255],
        ];
    }

    public function attributeLabels()
    {
        return [
            'username' => Module::t('Company username or email'),
            'password' => Module::t('Password'),
        ];
    }

    public function getProvider(): AuthProvider
    {
        return $this->provider;
    }

    /** Asks the directory and signs the person in; false with an error on the form otherwise. */
    public function login(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $name = trim((string) $this->username);
        $ip = LoginThrottle::requestIp();
        $scope = 'ldap:' . $this->provider->id_provider;
        if (
            LoginThrottle::lockedFor($name, null, $ip) > 0
            || !LoginThrottle::reserve($name, null, $ip)
            || !LoginThrottle::reserveName($scope, $name)
        ) {
            ProviderLogin::recordFailure($this->provider, $name, AuthLog::REASON_LOCKED);
            $this->addError('password', Module::t('Too many failed attempts. Try again in {minutes} minutes.', [
                'minutes' => LoginThrottle::minutes(),
            ]));
            return false;
        }
        try {
            $entry = Yii::createObject(LdapDirectory::class, [$this->provider->connection()])->authenticate($name, (string) $this->password);
        } catch (LdapUnavailable $e) {
            Yii::warning('LDAP provider ' . $this->provider->id_provider . ' not reachable: ' . $e->getMessage(), __METHOD__);
            ProviderLogin::recordFailure($this->provider, $name, 'ldap_unavailable');
            $this->addError('password', ProviderLogin::unavailableMessage());
            return false;
        }
        if ($entry === null) {
            ProviderLogin::recordFailure($this->provider, $name, AuthLog::REASON_WRONG_PASSWORD);
            $this->addError('password', Module::t('Incorrect username or password.'));
            return false;
        }
        LoginThrottle::resetName($scope, $name);
        $this->refusal = ProviderLogin::finish($this->provider, $entry);
        if ($this->refusal !== null) {
            $this->addError('username', $this->refusal);
            return false;
        }

        return true;
    }
}
