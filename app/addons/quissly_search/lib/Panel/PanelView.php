<?php

declare(strict_types=1);

namespace Quissly\Search\Panel;

use Quissly\Search\Config;
use Quissly\Search\Credentials;

/**
 * The "Quissly Admin Panel" page body: the panel in an iframe, already signed in —
 * or, when it can't be, why, in the merchant's terms, with the panel in a new tab
 * wherever that can help (it signs in with the merchant's own Quissly login).
 * Never a dead control: a store with no credentials gets no panel link at all,
 * since it has nothing to sign in with there either.
 *
 * Returns final HTML (like AdminStatusView), placed into the admin mainbox by
 * views/quissly_search/panel.tpl. Admin-only — not the storefront theme.
 */
final class PanelView
{
    /**
     * @param callable(string, string): ?array{status:int, body:string} $post the console transport
     */
    public static function render(Credentials $credentials, callable $post): string
    {
        if (!$credentials->isConfigured()) {
            return self::notice(self::t('quissly_search.panel_not_connected'))
                . '<p><a class="btn" href="' . self::escape(self::url('quissly_search.setup')) . '">'
                . self::escape(self::t('quissly_search.panel_go_to_settings')) . '</a></p>';
        }

        if ($credentials->projectId() === '' || $credentials->accountEmail() === '') {
            return self::unavailable(self::t('quissly_search.panel_missing_account'));
        }

        $session = PanelSession::open($credentials->projectId(), $credentials->accountEmail(), $credentials->bearerToken(), $post);
        if ($session['ok']) {
            // referrerpolicy: the session tokens are in this URL; without it the panel's
            // own outbound requests would carry them to third parties in a Referer header.
            return '<iframe class="quissly-panel__frame" src="'
                . self::escape(PanelSession::embedUrl(Config::PANEL_URL, $session['access_token'], $session['refresh_token']))
                . '" referrerpolicy="no-referrer" title="' . self::escape(self::t('quissly_search.panel_title')) . '"'
                . ' style="display:block;width:100%;height:calc(100vh - 170px);min-height:600px;border:1px solid #dcdfe6;border-radius:4px;background:#fff"></iframe>';
        }

        switch ($session['error']) {
            case 'rejected':
                $reason = self::t('quissly_search.panel_rejected', ['[host]' => Config::CONSOLE_URL]);
                break;
            case 'platform_unsupported':
                $reason = self::t('quissly_search.panel_platform_unsupported');
                break;
            case 'transport_error':
                $reason = self::t('quissly_search.panel_unreachable', ['[host]' => Config::CONSOLE_URL]);
                break;
            default:
                $reason = self::t('quissly_search.panel_unavailable');
        }

        return self::unavailable($reason);
    }

    private static function unavailable(string $reason): string
    {
        return self::notice($reason)
            . '<p><a class="btn btn-primary" href="' . self::escape(Config::PANEL_URL) . '" target="_blank" rel="noopener noreferrer">'
            . self::escape(self::t('quissly_search.panel_open_new_tab')) . '</a></p>';
    }

    private static function notice(string $text): string
    {
        return '<div class="alert alert-warning quissly-panel__notice">' . self::escape($text) . '</div>';
    }

    /** @param array<string, string> $params */
    private static function t(string $key, array $params = []): string
    {
        return function_exists('__') ? (string) __($key, $params) : $key . ($params ? ' ' . implode(' ', $params) : '');
    }

    private static function url(string $dispatch): string
    {
        return function_exists('fn_url') ? (string) fn_url($dispatch) : $dispatch;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function __construct()
    {
    }
}
