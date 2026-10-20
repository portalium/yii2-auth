<?php

namespace portalium\auth\components;

use Yii;

/**
 * A single status message shown persistently on the login card (verification result, pending approval, resend).
 *
 * Flash messages show as a toast for a few seconds and disappear; these messages instead stay inside the card
 * until the page reloads, since they tell the user what to do next. Read once.
 */
class AuthNotice
{
    public const SESSION_KEY = 'auth.notice';
    public const TYPES = ['success', 'info', 'warning', 'danger'];

    public static function set(string $type, string $text): void
    {
        if (!in_array($type, self::TYPES, true)) {
            $type = 'info';
        }
        Yii::$app->session->set(self::SESSION_KEY, ['type' => $type, 'text' => $text]);
    }

    /**
     * @return array{type:string, text:string}|null
     */
    public static function take(): ?array
    {
        $notice = Yii::$app->session->get(self::SESSION_KEY);
        Yii::$app->session->remove(self::SESSION_KEY);
        if (!is_array($notice) || !isset($notice['type'], $notice['text']) || !is_string($notice['text'])) {
            return null;
        }

        return [
            'type' => in_array($notice['type'], self::TYPES, true) ? $notice['type'] : 'info',
            'text' => $notice['text'],
        ];
    }
}
