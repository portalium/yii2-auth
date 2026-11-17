<?php

namespace portalium\auth\components;

use portalium\auth\models\AuthProvider;
use portalium\auth\Module;
use Yii;

/**
 * Proves that a provider's email domain belongs to its workspace, before anyone is sent to it (?domain=…).
 *
 * The connection test only proves the server works; the domain is text typed into the form. Without this anyone
 * could set up their own LDAP server or Okta tenant, add it under "abc.com", take that domain before ABC arrives,
 * send ABC's people to a look-alike sign-in page, and get into existing @abc.com accounts. Only the real owner of
 * abc.com can add a DNS record there, so:
 * - dns(): the domain's TXT records must contain "novavision-verify=<token>" (AuthProvider::dnsRecord());
 * - approve(): a site administrator approves by hand, for a customer who cannot change their DNS.
 * A domain is verified for one provider at a time.
 */
class DomainVerifier
{
    /** @var callable|null txt(string $domain): string[]; replaced in tests */
    public static $resolver;

    /** @return array{0: bool, 1: string} ok, message for the user */
    public static function dns(AuthProvider $provider): array
    {
        if (in_array($provider->domain, AuthProvider::blockedDomains(), true)) {
            return [false, Module::t('{domain} cannot be used for a corporate sign-in.', ['domain' => $provider->domain])];
        }
        $records = self::txt($provider->domain);
        if (!in_array($provider->dnsRecord(), $records, true)) {
            return [false, Module::t('The TXT record was not found on {domain} yet. DNS changes can take up to a few hours.', [
                'domain' => $provider->domain,
            ])];
        }

        return self::locked($provider, function () use ($provider) {
            $taken = self::takenBy($provider);
            if ($taken !== null) {
                return [false, $taken];
            }
            self::mark($provider, AuthProvider::VERIFIED_DNS);

            return [true, Module::t('{domain} is verified.', ['domain' => $provider->domain])];
        });
    }

    /** @return array{0: bool, 1: string} */
    public static function approve(AuthProvider $provider): array
    {
        if (in_array($provider->domain, AuthProvider::blockedDomains(), true)) {
            return [false, Module::t('{domain} cannot be used for a corporate sign-in.', ['domain' => $provider->domain])];
        }
        return self::locked($provider, function () use ($provider) {
            $taken = self::takenBy($provider);
            if ($taken !== null) {
                return [false, $taken];
            }
            self::mark($provider, AuthProvider::VERIFIED_ADMIN . ':' . (int) Yii::$app->user->id);

            return [true, Module::t('{domain} is approved.', ['domain' => $provider->domain])];
        });
    }

    /** Takes the verification back; a new token, so the old DNS record cannot simply verify it again. */
    public static function revoke(AuthProvider $provider): void
    {
        $provider->updateAttributes([
            'date_verified' => null,
            'verified_by' => null,
            'verify_token' => Yii::$app->security->generateRandomString(32),
        ]);
    }

    /**
     * The daily check of DNS-verified domains (console "auth/provider/recheck"): a domain whose TXT records no longer
     * hold its record loses the verification. No answer at all (a DNS outage) changes nothing.
     *
     * @return string[] the domains that lost it
     */
    public static function recheck(): array
    {
        $lost = [];
        $query = AuthProvider::find()->where(['verified_by' => AuthProvider::VERIFIED_DNS]);
        foreach ($query->each() as $provider) {
            $records = self::txt($provider->domain);
            if ($records !== [] && !in_array($provider->dnsRecord(), $records, true)) {
                self::revoke($provider);
                Yii::warning('Domain ' . $provider->domain . ' lost its DNS record; provider ' . $provider->id_provider . ' unverified', __METHOD__);
                $lost[] = $provider->domain;
            }
        }

        return $lost;
    }

    /** The TXT records of a domain, each as one string (long records come in parts). */
    public static function txt(string $domain): array
    {
        if (self::$resolver !== null) {
            return (array) (self::$resolver)($domain);
        }
        $records = @dns_get_record($domain, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }

        return array_map(
            static fn (array $r) => isset($r['entries']) ? implode('', (array) $r['entries']) : (string) ($r['txt'] ?? ''),
            $records
        );
    }

    /** Why this domain cannot be verified for this provider: another provider has it. */
    private static function takenBy(AuthProvider $provider): ?string
    {
        $other = AuthProvider::find()
            ->where(['domain' => $provider->domain])
            ->andWhere(['not', ['date_verified' => null]])
            ->andWhere(['!=', 'id_provider', $provider->id_provider])
            ->exists();

        return $other ? Module::t('{domain} is already verified for another identity provider.', ['domain' => $provider->domain]) : null;
    }

    /** One verification of a domain at a time, so two at the same moment cannot both win. */
    private static function locked(AuthProvider $provider, callable $work): array
    {
        $db = Yii::$app->db;
        $name = 'auth_domain_' . substr(hash('sha256', $provider->domain), 0, 40);
        if (!$db->createCommand('SELECT GET_LOCK(:n, 5)', [':n' => $name])->queryScalar()) {
            return [false, Module::t('Another verification of {domain} is running; try again.', ['domain' => $provider->domain])];
        }
        try {
            return $work();
        } finally {
            $db->createCommand('SELECT RELEASE_LOCK(:n)', [':n' => $name])->queryScalar();
        }
    }

    private static function mark(AuthProvider $provider, string $by): void
    {
        $provider->updateAttributes(['date_verified' => date('Y-m-d H:i:s'), 'verified_by' => $by]);
        Yii::info('Domain ' . $provider->domain . ' verified (' . $by . ') for provider ' . $provider->id_provider, __METHOD__);
    }
}
