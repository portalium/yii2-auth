<?php

namespace portalium\auth\components;

/** The directory could not be asked (not reachable, not configured, service account refused, no ldap extension). */
class LdapUnavailable extends \RuntimeException
{
}
