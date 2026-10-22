<?php

namespace portalium\auth\components;

use Yii;
use portalium\auth\Module;
use portalium\auth\models\ResendVerificationEmailForm;
use portalium\user\models\User;
use yii\web\NotFoundHttpException;

/**
 * An account's sign-in status and the "awaiting administrator approval" message shown to the user.
 *
 * Password login, email verification, Google/Apple and the API all use the same status and the same text.
 * The machine-readable "status" field in API responses is one of this class's constants. A pending user gets
 * an extra "If you have a question ... you can write to" line: the address is the auth::approval_contact
 * setting, falling back to the site's contact address (email::address; the contact form also goes there); the
 * line is omitted if that address looks like a noreply address.
 */
class AccountStatus
{
    public const ACTIVE = 'active';
    public const PENDING_APPROVAL = 'pending_approval';
    public const EMAIL_NOT_VERIFIED = 'email_not_verified';
    public const INACTIVE = 'inactive';

    public const CONTACT_SETTING = 'auth::approval_contact';
    public const SITE_CONTACT_SETTING = 'email::address';

    /**
     * Active; waiting for the verification link (has a signup token, see
     * ResendVerificationEmailForm::canReceive); waiting for administrator approval (passive); or disabled (deleted).
     */
    public static function of(User $user): string
    {
        $status = (int) $user->status;
        if ($status === User::STATUS_ACTIVE) {
            return self::ACTIVE;
        }
        if ($status !== User::STATUS_PASSIVE) {
            return self::INACTIVE;
        }

        return ResendVerificationEmailForm::canReceive($user) ? self::EMAIL_NOT_VERIFIED : self::PENDING_APPROVAL;
    }

    /**
     * Message explaining why an inactive account cannot sign in. The account's status is not changed here.
     * An account waiting for the verification link gets a new one sent (subject to the 60s rate limit).
     */
    public static function inactiveMessage(User $user): string
    {
        switch (self::of($user)) {
            case self::INACTIVE:
                return Module::t('Your account is not active.');
            case self::EMAIL_NOT_VERIFIED:
                return ResendVerificationEmailForm::sendTo($user)
                    ? Module::t('Your email address is not verified yet. We sent you a new verification link.')
                    : Module::t('Your email address is not verified yet. Please use the link in the verification email we sent you.');
            case self::PENDING_APPROVAL:
                return self::approvalMessage(
                    (bool) Yii::$app->setting->getValue('site::verifyEmail')
                    && (int) $user->email_verify === User::EMAIL_VERIFY
                );
            default:
                return '';
        }
    }

    /**
     * "Your account is awaiting administrator approval" text; notes that no email is sent on approval, and
     * appends the contact address if one is configured.
     *
     * @param bool $emailVerified whether the text should open with "Your email has been verified"
     */
    public static function approvalMessage(bool $emailVerified = false): string
    {
        $text = $emailVerified
            ? Module::t('Your email has been verified. Your account is awaiting administrator approval.')
            : Module::t('Your account is awaiting administrator approval.');
        $text .= ' ' . Module::t('You can sign in once an administrator approves your account.');
        $text .= ' ' . Module::t('You will not receive an email when it is approved, so try signing in again later.');

        $contact = self::approvalContact();
        if ($contact !== null) {
            $text .= ' ' . Module::t('If you have a question or do not want to wait, you can write to {contact}.', ['contact' => $contact]);
        }

        return $text;
    }

    /**
     * The address a pending user can write to: auth::approval_contact; if empty (or the setting row does not
     * exist yet), falls back to the site's contact address email::address. Null if both are empty or the site
     * address looks like a noreply address.
     */
    public static function approvalContact(): ?string
    {
        $contact = self::settingValue(self::CONTACT_SETTING);
        if ($contact !== null) {
            return $contact;
        }
        $site = self::settingValue(self::SITE_CONTACT_SETTING);

        return $site === null || self::isNoReplyAddress($site) ? null : $site;
    }

    /**
     * Whether this looks like an unmonitored address, e.g. "noreply@", "no-reply@", "donotreply@".
     */
    public static function isNoReplyAddress(string $address): bool
    {
        return (bool) preg_match('/(^|[^a-z])(no|do[-_.]?not)[-_.]?reply/i', $address);
    }

    private static function settingValue(string $name): ?string
    {
        try {
            $value = Yii::$app->setting->getValue($name);
        } catch (NotFoundHttpException $e) {
            return null;
        }
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
