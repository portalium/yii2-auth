<?php

namespace portalium\auth\components;

use FreeDSx\Ldap\Exception\BindException;
use FreeDSx\Ldap\Exception\ConnectionException;
use FreeDSx\Ldap\Exception\OperationException;
use FreeDSx\Ldap\LdapClient;
use FreeDSx\Ldap\Operations;
use FreeDSx\Ldap\Exception\FilterParseException;
use FreeDSx\Ldap\Search\Filter\FilterInterface;
use FreeDSx\Ldap\Search\FilterParser;
use FreeDSx\Ldap\Search\Filters;
use Throwable;
use Yii;

/**
 * Checks a username and password against a workspace's company directory (LDAP / Active Directory provider,
 * AuthProvider::connection()), with freedsx/ldap: LDAP spoken in plain PHP, so no PHP extension and no server image
 * rebuild (decision 2026-11-05; VendorLibraries makes the copied library loadable).
 *
 * authenticate(): connect over LDAPS or StartTLS only, with the server certificate checked against the provider's
 * CA certificate when one is given, else the system's (per connection: one provider's CA never reaches another);
 * bind with the service account; find exactly one person entry for the typed name (user_filter, {username} escaped)
 * that is in the allowed group — nested groups on Active Directory, memberOf on other directories; then bind as that
 * entry with the typed password. Connecting and every answer have a time limit, so a directory that stops answering
 * cannot hold the login. An empty password, or one with a NUL byte, is refused before anything is sent (servers take
 * an empty one as an anonymous bind).
 *
 * Created through Yii::createObject(LdapDirectory::class, [$config]), so tests replace it with Yii::$container->set().
 */
class LdapDirectory
{
    public const ENCRYPTION_SSL = 'ssl';
    public const ENCRYPTION_STARTTLS = 'starttls';

    public const TYPE_ACTIVE_DIRECTORY = 'ad';
    public const TYPE_OTHER = 'ldap';

    public const DEFAULT_FILTER = '(&(objectCategory=person)(objectClass=user)(|(sAMAccountName={username})(userPrincipalName={username})(mail={username})))';

    public const DEFAULT_OTHER_FILTER = '(&(objectClass=person)(|(uid={username})(mail={username})))';

    /** Seconds connecting, and each answer, may take. */
    public const TIMEOUT = 8;

    /**
     * LDAP, LDAPS and the Active Directory global catalog: the connection test cannot be used as a port scanner.
     * An on-premises installation (NetworkGuard::privateAllowed) may use any port.
     */
    public const ALLOWED_PORTS = [389, 636, 3268, 3269];

    /** One message for every network-level failure, so open and closed ports look the same from outside. */
    public const UNREACHABLE = 'The directory could not be reached.';

    /** The Active Directory rule that also matches members of nested groups. */
    private const NESTED_MEMBER = '1.2.840.113556.1.4.1941';

    /** @var array the provider's settings: host, port, encryption, directory_type, bind_dn, bind_password, base_dn, user_filter, group_dn, id_attribute, ca_cert */
    private $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public static function available(): bool
    {
        VendorLibraries::boot();

        return class_exists(LdapClient::class);
    }

    /**
     * The directory entry when the name and password are right: id (objectGUID as hex, or the id attribute),
     * dn, username, email (mail, else userPrincipalName), first_name, last_name. Null for a wrong name or password,
     * or a person outside the allowed group.
     *
     * @throws LdapUnavailable when the directory cannot be asked (not reachable, misconfigured, service account refused)
     */
    public function authenticate(string $username, string $password): ?array
    {
        $username = trim($username);
        if ($username === '' || $password === '' || strpos($password, "\0") !== false || strpos($username, "\0") !== false) {
            return null;
        }
        $client = $this->connect();
        try {
            $entry = $this->find($client, $username);
            if ($entry === null) {
                return null;
            }
            try {
                $client->bind($entry['dn'], $password);
            } catch (BindException $e) {
                return null;
            }

            return $entry;
        } catch (ConnectionException $e) {
            Yii::warning('LDAP connection lost: ' . $e->getMessage(), __METHOD__);
            throw new LdapUnavailable(self::UNREACHABLE);
        } finally {
            self::close($client);
        }
    }

    /**
     * Connects and binds with the service account, nothing more (the provider form's connection test).
     *
     * @throws LdapUnavailable with the reason when it does not work
     */
    public function testConnection(): void
    {
        if ($this->value('group_dn') === '') {
            throw new LdapUnavailable('No allowed group is set.');
        }
        self::close($this->connect());
    }

    private function connect(): LdapClient
    {
        $host = $this->value('host');
        if ($host === '' || $this->value('base_dn') === '') {
            throw new LdapUnavailable('The server or the search base is not set.');
        }
        $encryption = $this->value('encryption') ?: self::ENCRYPTION_STARTTLS;
        if (!in_array($encryption, [self::ENCRYPTION_SSL, self::ENCRYPTION_STARTTLS], true)) {
            throw new LdapUnavailable('Only LDAPS or StartTLS connections are allowed.');
        }
        if (!NetworkGuard::allowed($host)) {
            throw new LdapUnavailable('This server address is not allowed (a public host name or IP address is needed).');
        }
        if (!self::available()) {
            throw new LdapUnavailable('The LDAP library (freedsx/ldap) is not installed on this server.');
        }
        $port = (int) $this->value('port') ?: ($encryption === self::ENCRYPTION_SSL ? 636 : 389);
        if (!in_array($port, self::ALLOWED_PORTS, true) && !NetworkGuard::privateAllowed()) {
            throw new LdapUnavailable('Only the LDAP ports 389, 636, 3268 and 3269 are allowed.');
        }
        $client = new LdapClient([
            'servers' => [$host],
            'port' => $port,
            'use_ssl' => $encryption === self::ENCRYPTION_SSL,
            'ssl_validate_cert' => true,
            'ssl_allow_self_signed' => false,
            'ssl_ca_cert' => $this->caFile(),
            'timeout_connect' => self::TIMEOUT,
            'timeout_read' => self::TIMEOUT,
            'referral' => 'throw',
        ]);
        try {
            if ($encryption === self::ENCRYPTION_STARTTLS) {
                $client->startTls();
            }
            $bindDn = $this->value('bind_dn');
            if ($bindDn !== '') {
                $client->bind($bindDn, (string) ($this->config['bind_password'] ?? ''));
            }
        } catch (BindException $e) {
            self::close($client);
            throw new LdapUnavailable('The service account was refused: ' . $e->getMessage());
        } catch (Throwable $e) {
            self::close($client);
            Yii::warning('LDAP connection to ' . $host . ':' . $port . ' failed: ' . $e->getMessage(), __METHOD__);
            throw new LdapUnavailable(self::UNREACHABLE);
        }

        return $client;
    }

    /** The provider's CA certificate as a file (the TLS layer reads a path), or null for the system's CAs. */
    private function caFile(): ?string
    {
        $ca = trim((string) ($this->config['ca_cert'] ?? ''));
        if ($ca === '') {
            return null;
        }
        $file = Yii::getAlias('@runtime') . '/ldap-ca-' . hash('sha256', $ca) . '.pem';
        if (!is_file($file)) {
            file_put_contents($file, $ca . "\n", LOCK_EX);
        }

        return $file;
    }

    private function find(LdapClient $client, string $username): ?array
    {
        $group = $this->value('group_dn');
        if ($group === '') {
            throw new LdapUnavailable('No allowed group is set.');
        }
        $activeDirectory = ($this->value('directory_type') ?: self::TYPE_ACTIVE_DIRECTORY) === self::TYPE_ACTIVE_DIRECTORY;
        $idAttribute = $this->value('id_attribute') ?: ($activeDirectory ? 'objectGUID' : 'entryUUID');
        $attributes = [$idAttribute, 'sAMAccountName', 'uid', 'mail', 'userPrincipalName', 'givenName', 'sn'];
        try {
            $filter = self::searchFilter($username, $this->value('user_filter'), $group, $activeDirectory);
        } catch (FilterParseException $e) {
            throw new LdapUnavailable('The user filter is not a valid LDAP filter: ' . $e->getMessage());
        }
        $request = Operations::search($filter, ...$attributes)
            ->base($this->value('base_dn'))
            ->useSubtreeScope()
            ->sizeLimit(2)
            ->timeLimit(self::TIMEOUT);
        try {
            $entries = $client->search($request);
        } catch (OperationException $e) {
            if ($e->getCode() === 4) {
                return null;
            }
            throw new LdapUnavailable('The directory search failed: ' . $e->getMessage());
        }
        if ($entries->count() !== 1) {
            return null;
        }
        $entry = $entries->first();
        $first = static function (string $name) use ($entry): string {
            $attribute = $entry->get($name);
            return $attribute === null ? '' : (string) $attribute->firstValue();
        };
        $id = $first($idAttribute);
        if ($id === '') {
            return null;
        }

        return [
            'id' => strcasecmp($idAttribute, 'objectGUID') === 0 ? bin2hex($id) : $id,
            'dn' => (string) $entry->getDn(),
            'username' => $first('sAMAccountName') ?: ($first('uid') ?: explode('@', $username)[0]),
            'email' => $first('mail') ?: $first('userPrincipalName'),
            'first_name' => $first('givenName'),
            'last_name' => $first('sn'),
        ];
    }

    /**
     * The search filter: the user filter (default: an Active Directory person, or a person by uid/mail on other
     * directories) with the name escaped, in the group — nested on Active Directory, memberOf elsewhere.
     */
    public static function filter(string $username, string $userFilter, string $groupDn, bool $activeDirectory = true): string
    {
        $default = $activeDirectory ? self::DEFAULT_FILTER : self::DEFAULT_OTHER_FILTER;
        $filter = str_replace('{username}', self::escape($username), trim($userFilter) ?: $default);
        $member = $activeDirectory ? 'memberOf:' . self::NESTED_MEMBER . ':=' : 'memberOf=';

        return '(&' . $filter . '(' . $member . self::escape($groupDn) . '))';
    }

    /**
     * The search filter as sent: the user filter parsed, AND the group condition built as an object. The library's
     * parser reads "memberOf:<rule>:=" as a dnAttributes match, which changes what Active Directory compares, so the
     * nested-group rule is never parsed from text.
     *
     * @throws FilterParseException when the user filter is not valid
     */
    public static function searchFilter(string $username, string $userFilter, string $groupDn, bool $activeDirectory = true): FilterInterface
    {
        $default = $activeDirectory ? self::DEFAULT_FILTER : self::DEFAULT_OTHER_FILTER;
        $user = FilterParser::parse(str_replace('{username}', self::escape($username), trim($userFilter) ?: $default));
        $group = $activeDirectory
            ? Filters::extensible('memberOf', $groupDn, self::NESTED_MEMBER, false)
            : Filters::equal('memberOf', $groupDn);

        return Filters::and($user, $group);
    }

    /** A value for a search filter (RFC 4515): \ * ( ) and NUL as \hex, so typed text cannot change the filter. */
    public static function escape(string $value): string
    {
        return preg_replace_callback('/[\\\\*()\x00]/', static fn ($m) => '\\' . bin2hex($m[0]), $value);
    }

    private static function close(LdapClient $client): void
    {
        try {
            $client->unbind();
        } catch (Throwable $e) {
            // the connection is gone either way
        }
    }

    private function value(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
