<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * Renders the read-only status block shown on the add-on's Settings page, plus a
 * "Test connection" button. Returned as a final HTML string from the info-type
 * settings handler (so the whole add-on stays self-contained under app/addons/
 * with no template files under design/).
 *
 * Admin-only surface — this is NOT the storefront theme.
 */
final class AdminStatusView
{
    private const REASON_LABELS = [
        'master_toggle_off'          => 'quissly_search.reason_master_toggle_off',
        'credentials_not_configured' => 'quissly_search.reason_credentials_not_configured',
        'auth_error'                 => 'quissly_search.reason_auth_error',
        'initial_sync_pending'       => 'quissly_search.reason_initial_sync_pending',
    ];

    /**
     * @param array<string, mixed> $report a {@see StatusReport::build()} model
     */
    public static function render(array $report): string
    {
        $isActive = ($report['state'] ?? StatusReport::STATE_INACTIVE) === StatusReport::STATE_ACTIVE;

        $badge = $isActive
            ? '<span class="label label-success">' . self::t('quissly_search.active') . '</span>'
            : '<span class="label label-warning">' . self::t('quissly_search.inactive') . '</span>';

        $rows = [];
        $rows[] = self::row(self::t('quissly_search.interception_status'), $badge);

        // Reasons (only when inactive).
        if (!$isActive && !empty($report['reasons'])) {
            $items = [];
            foreach ((array) $report['reasons'] as $reason) {
                $key = self::REASON_LABELS[$reason] ?? null;
                $items[] = '<li>' . self::escape($key ? self::t($key) : (string) $reason) . '</li>';
            }
            $rows[] = self::row(self::t('quissly_search.inactive_reason'), '<ul>' . implode('', $items) . '</ul>');
        }

        // Environment / configured.
        $configured = !empty($report['configured']);
        $rows[] = self::row(
            self::t('quissly_search.credentials'),
            $configured
                ? self::escape(self::t('quissly_search.configured')) . ' (' . self::escape((string) $report['environment']) . ')'
                : self::escape(self::t('quissly_search.not_configured'))
        );

        // Persisted auth alert from a storefront 401/402/403.
        if (!empty($report['auth_alert'])) {
            $alert = $report['auth_alert'];
            $rows[] = self::row(
                self::t('quissly_search.last_auth_error'),
                '<span class="label label-danger">HTTP ' . (int) $alert['status'] . '</span> '
                . self::escape(self::formatTime((int) $alert['at']))
            );
        }

        $button = '<a class="btn btn-primary" href="' . self::escape(self::url('quissly_search.test_connection')) . '">'
            . self::escape(self::t('quissly_search.test_connection')) . '</a>';

        return '<div class="control-group setting-wide quissly-search-status">'
            . '<table class="table table-condensed" style="max-width:640px">' . implode('', $rows) . '</table>'
            . '<div class="control-group">' . $button . '</div>'
            . '</div>';
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><th style="text-align:left;white-space:nowrap;padding-right:16px">'
            . self::escape($label) . '</th><td>' . $value . '</td></tr>';
    }

    private static function formatTime(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return self::t('quissly_search.never');
        }
        if (function_exists('fn_date_format')) {
            return (string) fn_date_format($timestamp, '%Y-%m-%d %H:%M');
        }

        return date('Y-m-d H:i', $timestamp);
    }

    private static function t(string $key): string
    {
        return function_exists('__') ? (string) __($key) : $key;
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
