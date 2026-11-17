<?php

namespace portalium\auth\models;

use portalium\auth\Module;
use portalium\workspace\models\Workspace;
use portalium\workspace\models\WorkspaceUser;
use Throwable;
use Yii;
use yii\db\ActiveRecord;

/**
 * A workspace's corporate identity provider: its own LDAP / Active Directory server, or an OIDC or SAML identity
 * provider (Entra ID, Okta, ADFS, …). People of the provider's email domain sign in through it (?domain=…), once the
 * domain is verified (DNS TXT record, or a site administrator's approval).
 *
 * The connection settings are one encrypted JSON (config), read and written through the attributes below; secrets
 * (LDAP service account password, OIDC client secret) are never shown again, and a blank one keeps the stored value.
 * A new person is added to the workspaces listed in "workspaces" (only ones the provider's administrator manages),
 * with each module's default workspace role, and gets the status new_user_status.
 *
 * @property int $id_provider
 * @property int $id_workspace
 * @property int|null $id_user
 * @property string $type
 * @property string $name
 * @property string $domain
 * @property string|null $config
 * @property string $new_user_status
 * @property string|null $workspaces
 * @property int $status
 * @property string $verify_token
 * @property string|null $date_verified
 * @property string|null $verified_by
 * @property string $date_create
 * @property string $date_update
 * @property Workspace|null $workspace
 */
class AuthProvider extends ActiveRecord
{
    public const TYPE_LDAP = 'ldap';
    public const TYPE_OIDC = 'oidc';
    public const TYPE_SAML = 'saml';

    public const STATUS_ACTIVE = 1;
    public const STATUS_PASSIVE = 0;

    public const NEW_USER_ACTIVE = 'active';
    public const NEW_USER_VERIFY_EMAIL = 'verify_email';
    public const NEW_USER_APPROVAL = 'approval';

    public const VERIFIED_DNS = 'dns';
    public const VERIFIED_ADMIN = 'admin';

    /** The DNS TXT record that proves the domain: "novavision-verify=<token>". */
    public const DNS_PREFIX = 'novavision-verify=';

    public const ENCRYPTION_KEY_SETTING = 'auth::provider_key';

    /** More domains that can never be verified, comma separated (e.g. the platform's own staff and device domain). */
    public const BLOCKED_DOMAINS_SETTING = 'auth::provider_blocked_domains';

    public const PUBLIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'ymail.com',
        'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.net', 'mail.com',
        'yandex.com', 'yandex.ru', 'zoho.com', 'hotmail.com.tr', 'windowslive.com', 'mail.ru', 'qq.com', '163.com',
    ];

    /** Settings kept in config, by type; the secret ones are write-only. */
    public const FIELDS = [
        self::TYPE_LDAP => [
            'host', 'port', 'encryption', 'directory_type', 'bind_dn', 'bind_password', 'base_dn', 'user_filter', 'group_dn', 'id_attribute', 'ca_cert',
        ],
        self::TYPE_OIDC => ['issuer', 'client_id', 'client_secret', 'scopes'],
        self::TYPE_SAML => ['idp_entity_id', 'idp_sso_url', 'idp_x509cert'],
    ];

    public const SECRET_FIELDS = ['bind_password', 'client_secret'];

    public $host;
    public $port;
    public $encryption = 'starttls';
    public $directory_type = 'ad';
    public $bind_dn;
    public $bind_password;
    public $base_dn;
    public $user_filter;
    public $group_dn;
    public $id_attribute = '';
    public $ca_cert;
    public $issuer;
    public $client_id;
    public $client_secret;
    public $scopes = 'openid email profile';
    public $idp_entity_id;
    public $idp_sso_url;
    public $idp_x509cert;

    /** @var int[] the workspaces new people join */
    public $workspaceIds = [];

    public static function tableName()
    {
        return '{{%' . Module::$tablePrefix . 'provider}}';
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_LDAP => 'LDAP / Active Directory',
            self::TYPE_OIDC => 'OpenID Connect (Entra ID, Okta, Google Workspace)',
            self::TYPE_SAML => 'SAML 2.0 (ADFS, Entra ID, Okta)',
        ];
    }

    public static function newUserStatusLabels(): array
    {
        return [
            self::NEW_USER_ACTIVE => 'Active at once',
            self::NEW_USER_VERIFY_EMAIL => 'After email verification',
            self::NEW_USER_APPROVAL => 'After administrator approval',
        ];
    }

    public static function encryptionLabels(): array
    {
        return ['starttls' => 'StartTLS', 'ssl' => 'LDAPS (SSL)'];
    }

    public static function directoryTypeLabels(): array
    {
        return ['ad' => 'Active Directory', 'ldap' => 'Other LDAP directory (OpenLDAP, …)'];
    }

    public function rules()
    {
        $ldap = fn () => $this->type === self::TYPE_LDAP;
        $oidc = fn () => $this->type === self::TYPE_OIDC;
        $saml = fn () => $this->type === self::TYPE_SAML;
        $whenClient = fn (string $type) => "function () { return $('#authprovider-type').val() === '" . $type . "'; }";

        return [
            [['name', 'type', 'domain', 'new_user_status'], 'required'],
            ['name', 'string', 'max' => 128],
            ['type', 'in', 'range' => array_keys(self::typeLabels())],
            ['domain', 'filter', 'filter' => fn ($v) => ltrim(mb_strtolower(trim((string) $v)), '@')],
            ['domain', 'match', 'pattern' => '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                'message' => Module::t('Enter a domain such as company.com.')],
            ['new_user_status', 'in', 'range' => array_keys(self::newUserStatusLabels())],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_PASSIVE]],
            ['workspaceIds', 'validateWorkspaces'],

            [['host', 'base_dn', 'group_dn'], 'required', 'when' => $ldap, 'whenClient' => $whenClient(self::TYPE_LDAP)],
            [['host', 'bind_dn', 'base_dn', 'user_filter', 'group_dn', 'id_attribute'], 'string', 'max' => 1000],
            ['host', 'validateHost', 'when' => $ldap],
            ['bind_password', 'validateSecretPresent', 'when' => fn () => $ldap() && trim((string) $this->bind_dn) !== '', 'skipOnEmpty' => false],
            ['domain', 'validateDomainAllowed'],
            ['port', 'integer', 'min' => 1, 'max' => 65535],
            ['port', 'in', 'range' => \portalium\auth\components\LdapDirectory::ALLOWED_PORTS,
                'when' => fn () => $ldap() && !\portalium\auth\components\NetworkGuard::privateAllowed(),
                'message' => Module::t('Use one of the LDAP ports: 389, 636, 3268 or 3269.')],
            ['encryption', 'in', 'range' => array_keys(self::encryptionLabels()), 'when' => $ldap],
            ['directory_type', 'in', 'range' => array_keys(self::directoryTypeLabels()), 'when' => $ldap],
            ['user_filter', 'match', 'pattern' => '/\{username\}/', 'when' => fn () => $ldap() && trim((string) $this->user_filter) !== '',
                'message' => Module::t('The filter must contain {username}.')],
            [['bind_password', 'client_secret'], 'string', 'max' => 1000],
            ['ca_cert', 'string', 'max' => 20000],

            [['issuer', 'client_id'], 'required', 'when' => $oidc, 'whenClient' => $whenClient(self::TYPE_OIDC)],
            ['issuer', 'url', 'validSchemes' => ['https'], 'when' => $oidc],
            [['client_id', 'scopes'], 'string', 'max' => 500],
            ['client_secret', 'validateSecretPresent', 'when' => $oidc, 'skipOnEmpty' => false],

            [['idp_entity_id', 'idp_sso_url', 'idp_x509cert'], 'required', 'when' => $saml, 'whenClient' => $whenClient(self::TYPE_SAML)],
            ['idp_sso_url', 'url', 'validSchemes' => ['https'], 'when' => $saml],
            ['idp_entity_id', 'string', 'max' => 500],
            ['idp_x509cert', 'validateCertificate', 'when' => $saml],
        ];
    }

    public function attributeLabels()
    {
        return [
            'name' => Module::t('Name'),
            'type' => Module::t('Type'),
            'domain' => Module::t('Email domain'),
            'new_user_status' => Module::t('New people'),
            'status' => Module::t('Status'),
            'workspaceIds' => Module::t('Workspaces new people join'),
            'host' => Module::t('LDAP server'),
            'port' => Module::t('Port'),
            'encryption' => Module::t('Encryption'),
            'directory_type' => Module::t('Directory type'),
            'bind_dn' => Module::t('Service account DN'),
            'bind_password' => Module::t('Service account password'),
            'base_dn' => Module::t('Search base DN'),
            'user_filter' => Module::t('User filter'),
            'group_dn' => Module::t('Allowed group DN'),
            'id_attribute' => Module::t('Unique ID attribute'),
            'ca_cert' => Module::t('CA certificate (PEM)'),
            'issuer' => Module::t('Issuer URL'),
            'client_id' => Module::t('Client ID'),
            'client_secret' => Module::t('Client secret'),
            'scopes' => Module::t('Scopes'),
            'idp_entity_id' => Module::t('IdP entity ID'),
            'idp_sso_url' => Module::t('IdP sign-in URL'),
            'idp_x509cert' => Module::t('IdP signing certificate (PEM)'),
        ];
    }

    public function getWorkspace()
    {
        return $this->hasOne(Workspace::class, ['id_workspace' => 'id_workspace']);
    }

    /** "dns", or "admin" (verified_by also keeps the approving administrator: "admin:<id_user>"). */
    public function verifiedBy(): ?string
    {
        return $this->verified_by === null ? null : explode(':', $this->verified_by)[0];
    }

    public function isVerified(): bool
    {
        return $this->date_verified !== null;
    }

    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }

    /** The provider's settings for its client (LdapDirectory, the OIDC and SAML clients), secrets included. */
    public function connection(): array
    {
        return array_intersect_key($this->decodeConfig(), array_flip(self::FIELDS[$this->type] ?? []));
    }

    public function hasSecret(string $field): bool
    {
        return ($this->decodeConfig()[$field] ?? '') !== '';
    }

    /** The DNS TXT record value the domain owner adds. */
    public function dnsRecord(): string
    {
        return self::DNS_PREFIX . $this->verify_token;
    }

    /**
     * The workspaces whose administrator (the app module's workspace admin role) this user is; the only ones a
     * provider of theirs may add people to.
     *
     * @return array<int, string> id_workspace => title
     */
    public static function manageableWorkspaces(int $idUser): array
    {
        $role = (string) Yii::$app->setting->getValue('app::workspace::admin_role');
        $ids = WorkspaceUser::find()->select('id_workspace')
            ->where(['id_user' => $idUser, 'id_module' => 'app', 'role' => $role])->column();

        return Workspace::find()->select(['title', 'id_workspace'])->where(['id_workspace' => $ids])
            ->orderBy(['title' => SORT_ASC])->indexBy('id_workspace')->column();
    }

    public function validateWorkspaces($attribute)
    {
        $ids = array_values(array_unique(array_map('intval', (array) $this->workspaceIds)));
        if ($ids === []) {
            $this->addError($attribute, Module::t('Choose at least one workspace.'));
            return;
        }
        $allowed = Yii::$app->user->can('authWebProviderManage')
            ? null
            : array_keys(self::manageableWorkspaces((int) Yii::$app->user->id));
        foreach ($ids as $id) {
            if ($allowed !== null && !in_array($id, $allowed, true) && $id !== (int) $this->id_workspace) {
                $this->addError($attribute, Module::t('You can only choose workspaces you manage.'));
                return;
            }
        }
        $this->workspaceIds = $ids;
    }

    public function validateHost($attribute)
    {
        if (!\portalium\auth\components\NetworkGuard::validHostName(trim((string) $this->$attribute))) {
            $this->addError($attribute, Module::t('Enter only a host name or IP address, such as ldap.company.com.'));
        }
    }

    /** Public email domains and the platform's own are never a company's: one wrong approval would open all of them. */
    public function validateDomainAllowed($attribute)
    {
        if (in_array((string) $this->$attribute, self::blockedDomains(), true)) {
            $this->addError($attribute, Module::t('{domain} cannot be used for a corporate sign-in.', ['domain' => (string) $this->$attribute]));
        }
    }

    /** @return string[] */
    public static function blockedDomains(): array
    {
        $extra = [];
        try {
            $extra = preg_split('/[\s,]+/', mb_strtolower((string) Yii::$app->setting->getValue(self::BLOCKED_DOMAINS_SETTING)), -1, PREG_SPLIT_NO_EMPTY);
        } catch (Throwable $e) {
            $extra = [];
        }

        return array_merge(self::PUBLIC_DOMAINS, $extra);
    }

    public function validateSecretPresent($attribute)
    {
        if (trim((string) $this->$attribute) !== '') {
            return;
        }
        if (!$this->hasSecret($attribute)) {
            $this->addError($attribute, Module::t('{attribute} cannot be blank.', ['attribute' => $this->getAttributeLabel($attribute)]));
        } elseif ($this->targetChanged()) {
            $this->addError($attribute, Module::t('Enter {attribute} again: the server address changed.', ['attribute' => $this->getAttributeLabel($attribute)]));
        }
    }

    /** Fields that decide where a saved secret is sent; when one changes, the saved secret is not reused. */
    public const SECRET_TARGET_FIELDS = [self::TYPE_LDAP => ['host', 'port', 'encryption'], self::TYPE_OIDC => ['issuer']];

    public function targetChanged(): bool
    {
        if ($this->isNewRecord) {
            return false;
        }
        $stored = $this->decodeConfig();
        if ((string) $this->getOldAttribute('type') !== (string) $this->type) {
            return true;
        }
        foreach (self::SECRET_TARGET_FIELDS[$this->type] ?? [] as $field) {
            if (trim((string) ($stored[$field] ?? '')) !== trim((string) $this->$field)) {
                return true;
            }
        }

        return false;
    }

    public function validateCertificate($attribute)
    {
        if (!function_exists('openssl_x509_read') || @openssl_x509_read(self::pem((string) $this->$attribute)) === false) {
            $this->addError($attribute, Module::t('This is not a valid certificate.'));
        }
    }

    /** A certificate as PEM, whether pasted with or without its BEGIN/END lines. */
    public static function pem(string $certificate): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certificate);

        return "-----BEGIN CERTIFICATE-----\n" . chunk_split($body, 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    public function afterFind()
    {
        parent::afterFind();
        foreach ($this->decodeConfig() as $field => $value) {
            if (property_exists($this, $field) && !in_array($field, self::SECRET_FIELDS, true)) {
                $this->$field = $value;
            }
        }
        $this->workspaceIds = array_map('intval', json_decode((string) $this->workspaces, true) ?: []);
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        if ($insert) {
            $this->date_create = $now;
            $this->verify_token = Yii::$app->security->generateRandomString(32);
        }
        $this->date_update = $now;
        if (!$insert && $this->isAttributeChanged('domain')) {
            $this->date_verified = null;
            $this->verified_by = null;
            $this->verify_token = Yii::$app->security->generateRandomString(32);
        }

        $stored = $this->decodeConfig();
        $keepSecrets = !$this->targetChanged();
        $config = [];
        foreach (self::FIELDS[$this->type] ?? [] as $field) {
            $value = is_string($this->$field) ? trim($this->$field) : $this->$field;
            if (in_array($field, self::SECRET_FIELDS, true) && ($value === '' || $value === null)) {
                $value = $keepSecrets ? ($stored[$field] ?? '') : '';
            }
            $config[$field] = (string) ($value ?? '');
        }
        // the proof is for this domain AND this server: a verified domain pointed at another server must be proved again
        $storedConfig = array_map('strval', array_intersect_key($stored, $config));
        if (!$insert && ($this->isAttributeChanged('type') || $storedConfig != $config)) {
            $this->date_verified = null;
            $this->verified_by = null;
        }
        $this->config = self::encrypt($config);
        $this->workspaces = json_encode(array_values($this->workspaceIds));

        return true;
    }

    private function decodeConfig(): array
    {
        if ($this->config === null || $this->config === '') {
            return [];
        }
        try {
            $json = Yii::$app->security->decryptByKey(base64_decode($this->config), self::encryptionKey());
        } catch (Throwable $e) {
            Yii::error('Identity provider config could not be decrypted: ' . get_class($e), __METHOD__);
            return [];
        }
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private static function encrypt(array $config): string
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return base64_encode(Yii::$app->security->encryptByKey($json, self::encryptionKey()));
    }

    /**
     * Same interim decision as app::llm_provider_key: the key lives in the settings table, so secrets never show in
     * the UI, logs or API output, though a full database dump could still decrypt them. An empty key (the SQL set-up
     * leaves it empty, as MariaDB has no good random source) is made here once, with PHP's.
     */
    private static function encryptionKey(): string
    {
        $key = (string) Yii::$app->setting->getValue(self::ENCRYPTION_KEY_SETTING);
        if ($key !== '') {
            return $key;
        }
        $table = \portalium\site\Module::$tablePrefix . 'setting';
        $db = Yii::$app->db;
        $new = Yii::$app->security->generateRandomString(32);
        $empty = ['and', ['name' => self::ENCRYPTION_KEY_SETTING], ['or', ['value' => ''], ['value' => null]]];
        $updated = $db->createCommand()->update($table, ['value' => $new], $empty)->execute();
        if ($updated === 0) {
            throw new \yii\base\InvalidConfigException(self::ENCRYPTION_KEY_SETTING . ' setting is missing. Run the auth module migrations.');
        }

        return (string) (new \yii\db\Query())->select('value')->from($table)->where(['name' => self::ENCRYPTION_KEY_SETTING])->scalar($db);
    }
}
