<?php

declare(strict_types=1);

namespace Quissly\Search\Admin;

use Quissly\Search\Credentials;

/**
 * Setup, at the top of the add-on's settings page (Configuration - where
 * the Magento plugin keeps its "Keys & Connection" block): one-click Connect for a store
 * that isn't connected; otherwise which account it is connected to, and how.
 *
 * It is drawn INSIDE CS-Cart's settings form, so it has no form of its own: the Connect
 * button is named dispatch[quissly_search.connect], CS-Cart's way of sending a form to
 * another controller (the settings form already carries the security hash). The email
 * field is not `required`, or an empty one would block the page's own Save; the connect
 * handler validates it.
 *
 * Pure: render() takes the model the caller builds, returns escaped HTML.
 */
final class SetupView
{
    /**
     * @param array{source:string, account_email:string, environment:string, admin_email:string} $m
     */
    public static function render(array $m): string
    {
        switch ($m['source']) {
            case Credentials::SOURCE_CONNECT:
                return self::p(self::t('quissly_search.setup_connected', ['[email]' => $m['account_email'], '[env]' => $m['environment']]), 'success');
            case Credentials::SOURCE_CONFIG:
                return self::p(self::t('quissly_search.setup_connected_config', ['[env]' => $m['environment']]), 'success');
            case Credentials::SOURCE_UNDECRYPTABLE:
                return self::p(self::t('quissly_search.setup_undecryptable'), 'error');
        }

        $steps = '';
        foreach (['quissly_search.setup_step_account', 'quissly_search.setup_step_key', 'quissly_search.setup_step_sync'] as $key) {
            $steps .= '<li>' . self::e(self::t($key)) . '</li>';
        }

        return '<p>' . self::e(self::t('quissly_search.setup_intro')) . '</p>'
            . '<ol style="max-width:760px">' . $steps . '</ol>'
            . '<div class="control-group"><label class="control-label" for="quissly_connect_email">' . self::e(self::t('quissly_search.setup_email')) . '</label>'
            . '<div class="controls"><input type="email" id="quissly_connect_email" name="email" value="' . self::e($m['admin_email']) . '" class="input-large" style="min-width:320px">'
            . '<p class="muted description">' . self::e(self::t('quissly_search.setup_email_hint')) . '</p></div></div>'
            . '<div class="control-group"><div class="controls"><button type="submit" name="dispatch[quissly_search.connect]" class="btn btn-primary">' . self::e(self::t('quissly_search.setup_connect')) . '</button>'
            . '<p class="muted description">' . self::e(self::t('quissly_search.setup_connect_hint')) . '</p></div></div>';
    }

    private static function p(string $text, string $type): string
    {
        return '<div class="alert alert-' . self::e($type) . '" style="max-width:900px">' . self::e($text) . '</div>';
    }

    /** @param array<string, string> $params */
    private static function t(string $key, array $params = []): string
    {
        return function_exists('__') ? (string) __($key, $params) : $key . ($params === [] ? '' : ' ' . implode(' ', $params));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function __construct()
    {
    }
}
