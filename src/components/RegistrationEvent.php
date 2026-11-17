<?php

namespace portalium\auth\components;

use portalium\base\Event;

/**
 * Carries the "complete registration" items between the auth module and the modules that own them.
 *
 * Module::EVENT_REGISTRATION_REQUIREMENTS: handlers call addItem() for every document the user has to (or may)
 * accept, e.g. terms of use or a privacy notice, with its current acceptance state.
 * Module::EVENT_REGISTRATION_ACCEPT: $keys holds the items the user ticked; a handler saves the ones it owns and
 * reports the keys it could not save through fail().
 */
class RegistrationEvent extends Event
{
    /** @var \yii\web\IdentityInterface */
    public $user;

    /** @var string[] accepted item keys (accept event only) */
    public $keys = [];

    /** @var array<string, array> items by key */
    public $items = [];

    /** @var string[] keys a handler could not save */
    public $failed = [];

    /** @var string[] routes that stay open while the step is pending (e.g. the page showing a document) */
    public $openRoutes = [];

    /**
     * @param string|null $url page with the document's text: http(s) or a site path starting with "/"; anything
     *     else (javascript:, aliases) is dropped
     */
    public function addItem(string $key, string $label, ?string $url, bool $required, bool $accepted): void
    {
        $this->items[$key] = [
            'key' => $key,
            'label' => $label,
            'url' => self::safeUrl($url),
            'required' => $required,
            'accepted' => $accepted,
        ];
    }

    /**
     * Keeps a route (e.g. "printer/default/privacy") reachable while the step is pending, so the user can read
     * the document before accepting it.
     */
    public function addOpenRoute(string $route): void
    {
        $this->openRoutes[] = trim($route, '/');
    }

    private static function safeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url) || (strpos($url, '/') === 0 && strpos($url, '//') !== 0)) {
            return $url;
        }

        return null;
    }

    public function fail(string $key): void
    {
        $this->failed[] = $key;
    }
}
