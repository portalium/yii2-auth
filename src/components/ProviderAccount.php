<?php

namespace portalium\auth\components;

use portalium\auth\models\AuthProvider;
use portalium\auth\models\SignupForm;
use portalium\user\models\User;
use portalium\workspace\models\WorkspaceUser;
use Throwable;
use Yii;

/**
 * The Suite account of a person a corporate identity provider let in (LDAP, OIDC, SAML), for every provider type.
 *
 * In this order:
 * 1. the account already linked to this provider and this person (auth_oauth_identity, provider "sso:<id>");
 * 2. the account with the same email, linked now (decision 2026-11-05: the domain is verified, so its owner owns
 *    these addresses and could reach them through a password reset anyway) — only an active, not virtual account whose
 *    email was verified, so an account someone opened early with that address is not taken over. Linking renews its
 *    auth key and API key and ends its other sessions, so whatever the early opener held stops working;
 * 3. a new account, with the provider's new-user status (active / email verification / administrator approval).
 * The email must be in the provider's verified domain, or nobody is let in. Privileged accounts (any global role
 * besides the plain user role, e.g. a site admin or a global workspace admin) never sign in through a provider, so
 * their local password keeps working when the directory is down and a company directory cannot speak for them.
 * A person joins the provider's workspaces once active (ProviderLogin), and only those its owner still manages.
 *
 * An account linked to an active provider signs in only through it: not with a local password, not with Google or
 * Apple (corporateOnly), so someone removed from the company directory cannot sign in another way. Its API keys keep
 * working until renewed; this is a known limit.
 */
class ProviderAccount
{
    public const PREFIX = 'sso:';

    public const REFUSE_DOMAIN = 'sso_domain_mismatch';
    public const REFUSE_ADMIN = 'sso_admin_not_linked';
    public const REFUSE_LINKED_ELSEWHERE = 'sso_linked_elsewhere';
    public const REFUSE_SIGNUP = 'sso_signup_failed';
    public const REFUSE_UNVERIFIED_ACCOUNT = 'sso_account_not_linkable';

    /** Why the last resolve() gave no account (REFUSE_*), for the login log and the message. */
    public static $refusal;

    public static function key(AuthProvider $provider): string
    {
        return self::PREFIX . $provider->id_provider;
    }

    /**
     * @param array $person id (the provider's unique id of the person), email, username, first_name, last_name
     */
    public static function resolve(AuthProvider $provider, array $person): ?User
    {
        self::$refusal = null;
        $subject = (string) ($person['id'] ?? '');
        if ($subject === '') {
            self::$refusal = self::REFUSE_SIGNUP;
            return null;
        }
        $linked = OAuthLink::findUser(self::key($provider), $subject);
        if ($linked !== null) {
            if (self::isPrivileged($linked)) {
                self::$refusal = self::REFUSE_ADMIN;
                return null;
            }
            return $linked;
        }
        $email = mb_strtolower(trim((string) ($person['email'] ?? '')));
        if (!self::inDomain($email, $provider->domain)) {
            self::$refusal = self::REFUSE_DOMAIN;
            return null;
        }
        $existing = User::find()->where(['email' => $email])->one();
        if ($existing !== null) {
            if (self::isPrivileged($existing)) {
                self::$refusal = self::REFUSE_ADMIN;
                return null;
            }
            $linkable = (int) $existing->status === User::STATUS_ACTIVE
                && (int) $existing->email_verify === User::EMAIL_VERIFY
                && (int) $existing->is_virtual !== 1;
            if (!$linkable) {
                self::$refusal = self::REFUSE_UNVERIFIED_ACCOUNT;
                return null;
            }
            if (OAuthLink::link((int) $existing->id_user, self::key($provider), $subject, $email) !== null) {
                self::$refusal = self::REFUSE_LINKED_ELSEWHERE;
                return null;
            }
            self::renewKeys($existing);

            return $existing;
        }

        $user = self::create($provider, $person, $email);
        if ($user === null) {
            self::$refusal = self::REFUSE_SIGNUP;
            return null;
        }
        OAuthLink::link((int) $user->id_user, self::key($provider), $subject, $email);

        return $user;
    }

    /**
     * Whether this account may only sign in through its corporate provider: it is linked to an active, verified
     * provider and is not a site administrator.
     */
    public static function corporateOnly(User $user): bool
    {
        $ids = [];
        foreach (array_keys(OAuthLink::identities((int) $user->id_user)) as $provider) {
            if (strpos((string) $provider, self::PREFIX) === 0) {
                $ids[] = (int) substr((string) $provider, strlen(self::PREFIX));
            }
        }
        if ($ids === []) {
            return false;
        }
        $active = AuthProvider::find()
            ->where(['id_provider' => $ids, 'status' => AuthProvider::STATUS_ACTIVE])
            ->andWhere(['not', ['date_verified' => null]])
            ->exists();

        return $active && !self::isPrivileged($user);
    }

    public static function inDomain(string $email, string $domain): bool
    {
        $at = strrpos($email, '@');

        return $at !== false && $domain !== '' && substr($email, $at + 1) === mb_strtolower($domain);
    }

    /**
     * Adds the person to the provider's workspaces (each module's default role); the first becomes active if none is.
     * Only the provider's own workspace and those its owner still manages: an owner removed from a workspace stops
     * sending people there. Returns the reasons another module refused a membership (e.g. the plan's member limit)
     * when the person ends up in none of the workspaces.
     *
     * @return string[]
     */
    public static function join(User $user, AuthProvider $provider): array
    {
        $refusals = [];
        $modules = array_keys(Yii::$app->workspace->getSupportModules());
        $hasActive = WorkspaceUser::find()->where(['id_user' => $user->id_user, 'status' => WorkspaceUser::STATUS_ACTIVE])->exists();
        $allowed = array_merge([(int) $provider->id_workspace], array_keys(AuthProvider::manageableWorkspaces((int) $provider->id_user)));
        $targets = array_values(array_intersect(array_map('intval', (array) $provider->workspaceIds), $allowed));
        foreach ($targets as $index => $idWorkspace) {
            foreach ($modules as $module) {
                try {
                    $role = (string) Yii::$app->setting->getValue($module . '::workspace::default_role');
                } catch (Throwable $e) {
                    $role = '';
                }
                if ($role === '' || !Yii::$app->workspace->isAvailableRole($module, $role)) {
                    continue;
                }
                $exists = WorkspaceUser::find()
                    ->where(['id_workspace' => $idWorkspace, 'id_user' => $user->id_user, 'id_module' => $module])
                    ->exists();
                if ($exists) {
                    continue;
                }
                $member = new WorkspaceUser([
                    'id_workspace' => (int) $idWorkspace,
                    'id_user' => (int) $user->id_user,
                    'id_module' => $module,
                    'role' => $role,
                    'status' => !$hasActive && $index === 0 ? WorkspaceUser::STATUS_ACTIVE : WorkspaceUser::STATUS_INACTIVE,
                ]);
                if (!$member->save()) {
                    Yii::warning('Corporate member could not be added: ' . json_encode(array_keys($member->getErrors())), __METHOD__);
                    $refusals[] = (string) $member->getFirstError('id_workspace');
                }
            }
        }
        $member = $targets !== [] && WorkspaceUser::find()->where(['id_user' => $user->id_user, 'id_workspace' => $targets])->exists();

        return $member ? [] : array_values(array_unique(array_filter($refusals)));
    }

    private static function create(AuthProvider $provider, array $person, string $email): ?User
    {
        $base = preg_replace('/[^a-z0-9_.]+/', '_', mb_strtolower((string) ($person['username'] ?? ''))) ?: explode('@', $email)[0];
        $base = trim(mb_substr($base, 0, 40), '_.') ?: 'user';
        $username = $base;
        for ($i = 2; User::find()->where(['username' => $username])->exists() && $i < 100; $i++) {
            $username = $base . $i;
        }
        $password = Yii::$app->security->generateRandomString(32);
        $form = new SignupForm([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'password_confirm' => $password,
            'first_name' => (string) ($person['first_name'] ?? ''),
            'last_name' => (string) ($person['last_name'] ?? ''),
        ]);
        $verify = $provider->new_user_status === AuthProvider::NEW_USER_VERIFY_EMAIL;
        SignupSource::expect(SignupSource::CORPORATE, (int) $provider->id_provider);
        $user = $form->signup($verify);
        Yii::$app->session->removeFlash('success');
        if ($user === null) {
            Yii::warning('Corporate account could not be created: ' . json_encode(array_keys($form->getErrors())), __METHOD__);
            return null;
        }
        if (!$verify) {
            $user->status = $provider->new_user_status === AuthProvider::NEW_USER_APPROVAL ? User::STATUS_PASSIVE : User::STATUS_ACTIVE;
            $user->email_verify = User::EMAIL_VERIFY;
            $user->save(false);
        }

        return $user;
    }

    /** Any global role or permission besides the plain user role (site::user_role). */
    public static function isPrivileged(User $user): bool
    {
        try {
            $plain = (string) Yii::$app->setting->getValue('site::user_role') ?: 'user';
            $assigned = array_keys(Yii::$app->authManager->getAssignments($user->id_user));
            return array_diff($assigned, [$plain]) !== [];
        } catch (Throwable $e) {
            return true;
        }
    }

    /** A newly linked account: what an earlier holder of it may have kept (API key, cookies, sessions) stops working. */
    private static function renewKeys(User $user): void
    {
        $user->generateAuthKey();
        $user->access_token = Yii::$app->security->generateRandomString(40);
        $user->save(false, ['auth_key', 'access_token']);
        foreach (\portalium\auth\models\AuthSession::findActive()->andWhere(['id_user' => $user->id_user])->all() as $row) {
            SessionTracker::end($row, \portalium\auth\models\AuthSession::END_BY_ADMIN);
        }
    }
}
