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
 * Quissly creates an account only with the code it emailed (since 2026-10-19): "Send code" asks
 * quissly_search.setup_send_code for it (Setup's endpoint) and the code goes with Connect as
 * verification_code. While Quissly Setup is not finished there is no Connect here at all, only a
 * link to Setup, which also asks for the store's name, what it sells and a plan (CS-Cart 4.11 opens
 * this block in a popup from the add-ons list, where the redirect to Setup never runs).
 *
 * Pure: render() takes the model the caller builds, returns escaped HTML.
 */
final class SetupView
{
    /** "Send code": Setup's endpoint, Quissly's own words back under the field. */
    private const SEND_CODE_JS = <<<'JS'
(function () {
    var send = document.getElementById('quissly_connect_send');
    if (!send || send.getAttribute('data-ready')) { return; }
    send.setAttribute('data-ready', '1');
    send.addEventListener('click', function () {
        var email = (document.getElementById('quissly_connect_email') || {}).value || '';
        var status = document.getElementById('quissly_connect_code_status');
        var label = send.textContent;
        var body = new URLSearchParams();
        body.append('email', email.trim());
        body.append('security_hash', send.getAttribute('data-hash'));
        send.disabled = true;
        send.textContent = send.getAttribute('data-sending');
        fetch(send.getAttribute('data-url'), {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) { return r.json(); })
            .then(function (a) {
                status.textContent = a.ok ? send.getAttribute('data-sent').replace('[email]', email.trim()) : (a.message || '');
                status.className = a.ok ? 'muted description' : 'text-error';
                if (a.ok) { document.getElementById('quissly_connect_code').focus(); }
            })
            .catch(function () { status.textContent = send.getAttribute('data-failed'); status.className = 'text-error'; })
            .then(function () { send.disabled = false; send.textContent = label; });
    });
})();
JS;

    /**
     * @param array{source:string, account_email:string, environment:string, admin_email:string,
     *     in_setup:bool, active:bool, setup_url:string, send_code_url:string, security_hash:string} $m
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

        if ($m['in_setup'] && !$m['active']) {
            // A disabled add-on's pages answer 404 (4.11's gear icon shows these settings either way).
            return '<p style="max-width:760px">' . self::e(self::t('quissly_search.setup_enable_first')) . '</p>';
        }
        if ($m['in_setup']) {
            return '<p style="max-width:760px">' . self::e(self::t('quissly_search.setup_open_intro')) . '</p>'
                . '<p><a class="btn btn-primary" href="' . self::e($m['setup_url']) . '">' . self::e(self::t('quissly_search.setup_open')) . '</a></p>';
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
            . '<div class="control-group"><label class="control-label" for="quissly_connect_code">' . self::e(self::t('quissly_search.su_verify_label')) . '</label>'
            . '<div class="controls"><input type="text" id="quissly_connect_code" name="verification_code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="input-small"> '
            . '<button type="button" id="quissly_connect_send" class="btn" data-url="' . self::e($m['send_code_url']) . '" data-hash="' . self::e($m['security_hash']) . '"'
            . ' data-sending="' . self::e(self::t('quissly_search.su_verify_sending')) . '" data-sent="' . self::e(self::t('quissly_search.su_verify_sent')) . '" data-failed="' . self::e(self::t('quissly_search.setup_code_failed')) . '">' . self::e(self::t('quissly_search.su_verify_send')) . '</button>'
            . '<p class="muted description" id="quissly_connect_code_status">' . self::e(self::t('quissly_search.setup_code_hint')) . '</p></div></div>'
            . '<script>' . self::SEND_CODE_JS . '</script>'
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
