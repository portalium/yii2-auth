<?php

namespace portalium\auth\components;

/** An OpenID Connect sign-in that could not be completed (OidcClient); the message is for the log, not the user. */
class OidcError extends \RuntimeException
{
}
