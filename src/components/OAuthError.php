<?php

namespace portalium\auth\components;

/**
 * An OAuth error response (RFC 6749 §4.1.2.1, §5.2): its code, a description, and — for an authorization request
 * whose client and redirect URI were valid — where to send it.
 */
class OAuthError extends \RuntimeException
{
    /** @var string */
    public $error;

    /** @var string|null */
    public $redirectUri;

    /** @var string|null */
    public $state;

    public function __construct(string $error, string $description, ?string $redirectUri = null, ?string $state = null)
    {
        parent::__construct($description);
        $this->error = $error;
        $this->redirectUri = $redirectUri;
        $this->state = $state;
    }

    public function body(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }

    /**
     * The client's redirect URI carrying the error, or null when it must not be redirected to.
     */
    public function redirect(): ?string
    {
        if ($this->redirectUri === null) {
            return null;
        }
        $query = array_filter($this->body() + ['state' => $this->state], static fn($v) => $v !== null);
        return $this->redirectUri . (strpos($this->redirectUri, '?') === false ? '?' : '&') . http_build_query($query);
    }
}
