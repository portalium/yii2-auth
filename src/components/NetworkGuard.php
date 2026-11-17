<?php

namespace portalium\auth\components;

use Throwable;
use Yii;

/**
 * Where a workspace's identity provider may make Suite connect. Workspace admins type the LDAP server and the OIDC
 * issuer themselves; without this the connection test would let anyone probe Suite's own network (the database,
 * 127.0.0.1, cloud metadata) and read the error back.
 *
 * A host must be a plain host name or IP address, and every address it resolves to must be public — unless
 * auth::provider_allow_private_network is on: an on-premises installation whose directory is on the same network.
 * (The address is resolved again when connecting; a host that changes its answer in between is a known limit.)
 */
class NetworkGuard
{
    public const SETTING = 'auth::provider_allow_private_network';

    /** @var callable|null resolve(string $host): string[]; replaced in tests */
    public static $resolver;

    /** Ranges filter_var's NO_PRIV/NO_RES flags do not cover: CGNAT, IETF protocol, benchmarking, multicast. */
    private const BLOCKED_V4 = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4'];

    public static function validHostName(string $host): bool
    {
        if (self::canonicalIpv4($host) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }
        // A name whose last label is numeric or hex (0177.0.0.1, 2130706433, 0x7f.1) is an address in a form the
        // resolver reads differently (octal, hex, short); only the plain dotted quad above is accepted.
        if (preg_match('/(^|\.)(\d+|0x[0-9a-f]*)$/i', $host)) {
            return false;
        }

        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $host);
    }

    /** Four decimal octets without leading zeros: the only IPv4 form every parser reads the same way. */
    public static function canonicalIpv4(string $host): bool
    {
        return (bool) preg_match('/^((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/', $host);
    }

    /** Whether an address (as resolved, not as typed) is a public unicast one. */
    public static function publicAddress(string $address): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }
        if (strlen($binary) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible (::a.b.c.d) forms carry an IPv4 address
            if (substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff" || substr($binary, 0, 12) === str_repeat("\0", 12)) {
                return self::publicAddress(inet_ntop(substr($binary, 12)));
            }
            return (bool) filter_var(inet_ntop($binary), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        $ip = inet_ntop($binary);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $long = ip2long($ip);
        foreach (self::BLOCKED_V4 as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }

    public static function allowed(string $host): bool
    {
        $host = trim($host, " \t[]");
        if ($host === '' || !self::validHostName($host)) {
            return false;
        }
        if (self::privateAllowed()) {
            return true;
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (!self::publicAddress((string) $address)) {
                return false;
            }
        }

        return true;
    }

    public static function allowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && self::allowed((string) ($parts['host'] ?? ''));
    }

    public static function privateAllowed(): bool
    {
        try {
            return (bool) Yii::$app->setting->getValue(self::SETTING);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return string[] */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return (array) (self::$resolver)($host);
        }
        $addresses = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
            $addresses[] = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        }

        return array_values(array_filter($addresses));
    }
}
