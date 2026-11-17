<?php

namespace portalium\auth\components;

use portalium\auth\Module;
use portalium\base\Event;
use Yii;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\helpers\Url;
use yii\web\IdentityInterface;

/**
 * "Complete registration" step: documents other modules require the user to accept before using the app.
 *
 * The auth module only asks and records nothing itself. Modules answer Module::EVENT_REGISTRATION_REQUIREMENTS
 * (see RegistrationEvent) and store the acceptance on Module::EVENT_REGISTRATION_ACCEPT. Without a handler there
 * are no items and nothing changes.
 *
 * Web ('web' application only): once per session the signed-in user is checked; while a required item is missing
 * every page redirects to auth/registration/complete. This covers password, Google, Apple and remembered (cookie)
 * sign-ins alike. A document added or re-versioned later applies from the user's next session.
 * API: sign-in responses carry registration_required and registration_items (apiFields()); the client shows the
 * step and stores the acceptance through the owning module's endpoint.
 */
class RegistrationRequirements
{
    public const SESSION_CHECKED = 'auth.registrationChecked';
    public const SESSION_PENDING = 'auth.registrationPending';
    public const SESSION_OPEN_ROUTES = 'auth.registrationOpenRoutes';
    public const ROUTE = 'auth/registration/complete';

    /** Routes that stay open while the step is pending (handlers add theirs with RegistrationEvent::addOpenRoute). */
    public const OPEN_ROUTES = [
        self::ROUTE,
        'auth/default/logout',
    ];

    public static function register(): void
    {
        Yii::$app->on(Application::EVENT_BEFORE_ACTION, [self::class, 'gate']);
    }

    /**
     * @return array[] every item reported for the user (key, label, url, required, accepted)
     */
    public static function items(IdentityInterface $user): array
    {
        return array_values(self::collect($user)->items);
    }

    /**
     * @return array[] required items the user has not accepted
     */
    public static function missingRequired(array $items): array
    {
        return array_values(array_filter($items, static function (array $item) {
            return $item['required'] && !$item['accepted'];
        }));
    }

    /**
     * @return array[] items (required or optional) the user has not accepted
     */
    public static function pending(array $items): array
    {
        return array_values(array_filter($items, static function (array $item) {
            return !$item['accepted'];
        }));
    }

    public static function isRequired(IdentityInterface $user): bool
    {
        return self::missingRequired(self::items($user)) !== [];
    }

    /**
     * Records the ticked items. Keys that are unknown or already accepted are ignored.
     *
     * @param string[] $keys
     * @return string[] keys that could not be saved
     */
    public static function accept(IdentityInterface $user, array $keys): array
    {
        $open = array_column(self::pending(self::items($user)), 'key');
        $keys = array_values(array_intersect($open, array_map('strval', array_filter($keys, 'is_scalar'))));
        if ($keys === []) {
            return [];
        }

        $event = new RegistrationEvent(['user' => $user, 'payload' => $user, 'keys' => $keys]);
        Event::trigger(Module::class, Module::EVENT_REGISTRATION_ACCEPT, $event);

        return array_values(array_unique($event->failed));
    }

    /**
     * Fields added to the API sign-in response so the mobile app can show the step right away.
     */
    public static function apiFields(IdentityInterface $user): array
    {
        $items = self::items($user);

        return [
            'registration_required' => self::missingRequired($items) !== [],
            'registration_items' => $items,
        ];
    }

    /**
     * Marks the current session as done (after the user accepted everything required).
     */
    public static function clear(): void
    {
        $session = Yii::$app->session;
        $session->set(self::SESSION_CHECKED, (int) Yii::$app->user->id);
        $session->remove(self::SESSION_PENDING);
        $session->remove(self::SESSION_OPEN_ROUTES);
    }

    /**
     * Where to go after the step: the page the user wanted, never the step itself (that would loop).
     */
    public static function returnUrl(): string
    {
        $url = (string) Yii::$app->user->getReturnUrl();
        if (strpos($url, self::ROUTE) !== false) {
            $url = Yii::$app->homeUrl;
            Yii::$app->user->setReturnUrl($url);
        }

        return $url;
    }

    /**
     * Application::EVENT_BEFORE_ACTION handler ('web' application only).
     */
    public static function gate(ActionEvent $event): void
    {
        $app = Yii::$app;
        if ($app->id !== 'web' || !$app instanceof \yii\web\Application || !$app->has('user')) {
            return;
        }
        if (!$app->user->enableSession || $app->user->isGuest) {
            return;
        }

        $session = $app->session;
        $id = (int) $app->user->id;
        if ((int) $session->get(self::SESSION_CHECKED, 0) !== $id) {
            // Ask first: if a handler throws, nothing is marked and the next request asks again (fails closed).
            $result = self::collect($app->user->identity);
            if (self::missingRequired(array_values($result->items)) !== []) {
                $session->set(self::SESSION_PENDING, $id);
                $session->set(self::SESSION_OPEN_ROUTES, $result->openRoutes);
            } else {
                $session->remove(self::SESSION_PENDING);
                $session->remove(self::SESSION_OPEN_ROUTES);
            }
            $session->set(self::SESSION_CHECKED, $id);
        }
        if ((int) $session->get(self::SESSION_PENDING, 0) !== $id) {
            return;
        }
        if (self::isOpenRoute($event->action->getUniqueId(), (array) $session->get(self::SESSION_OPEN_ROUTES, []))) {
            return;
        }

        if ($app->request->isGet && !$app->request->isAjax) {
            $app->user->setReturnUrl($app->request->url);
        }
        $app->response->redirect(Url::to(['/' . self::ROUTE]));
        $event->isValid = false;
    }

    /**
     * @param string[] $extra routes opened by handlers (e.g. the page showing a document's text)
     */
    public static function isOpenRoute(string $route, array $extra = []): bool
    {
        if (in_array($route, self::OPEN_ROUTES, true) || in_array($route, $extra, true)) {
            return true;
        }
        $errorAction = Yii::$app->errorHandler->errorAction ?? null;
        if ($errorAction !== null && $route === trim($errorAction, '/')) {
            return true;
        }

        return strpos($route, 'debug/') === 0 || strpos($route, 'gii/') === 0;
    }

    private static function collect(IdentityInterface $user): RegistrationEvent
    {
        $event = new RegistrationEvent(['user' => $user, 'payload' => $user]);
        Event::trigger(Module::class, Module::EVENT_REGISTRATION_REQUIREMENTS, $event);

        return $event;
    }
}
