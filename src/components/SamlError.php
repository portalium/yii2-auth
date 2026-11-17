<?php

namespace portalium\auth\components;

/** A SAML sign-in that could not be completed (SamlClient); the message is for the log, not the user. */
class SamlError extends \RuntimeException
{
}
