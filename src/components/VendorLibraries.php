<?php

namespace portalium\auth\components;

use Yii;

/**
 * Libraries the corporate sign-in uses that the servers do not get through composer (CLAUDE.md, item 15): their
 * folders are copied into vendor/ and found through Yii aliases, so no autoload file needs a patch. A composer
 * install (kickstarter) loads them the usual way and this does nothing.
 * - SAML: onelogin/php-saml with robrichards/xmlseclibs, phpseclib/phpseclib, paragonie/constant_time_encoding;
 * - LDAP: freedsx/ldap with freedsx/asn1, freedsx/sasl, freedsx/socket (psr/log is already there).
 */
class VendorLibraries
{
    private const ALIASES = [
        '@OneLogin' => '@vendor/onelogin/php-saml/src',
        '@RobRichards/XMLSecLibs' => '@vendor/robrichards/xmlseclibs/src',
        '@phpseclib3' => '@vendor/phpseclib/phpseclib/phpseclib',
        '@ParagonIE/ConstantTime' => '@vendor/paragonie/constant_time_encoding/src',
        '@FreeDSx/Ldap' => '@vendor/freedsx/ldap/src/FreeDSx/Ldap',
        '@FreeDSx/Asn1' => '@vendor/freedsx/asn1/src/FreeDSx/Asn1',
        '@FreeDSx/Sasl' => '@vendor/freedsx/sasl/src/FreeDSx/Sasl',
        '@FreeDSx/Socket' => '@vendor/freedsx/socket/src/FreeDSx/Socket',
    ];

    private static $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        foreach (self::ALIASES as $alias => $path) {
            $dir = Yii::getAlias($path, false);
            if (Yii::getAlias($alias, false) === false && $dir !== false && is_dir($dir)) {
                Yii::setAlias($alias, $dir);
            }
        }
    }
}
