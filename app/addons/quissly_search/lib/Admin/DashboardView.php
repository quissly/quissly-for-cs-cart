<?php

declare(strict_types=1);

namespace Quissly\Search\Admin;

use Quissly\Search\StatusReport;

/**
 * The "Quissly Dashboard" page (the WooCommerce plugin's Dashboard): is search on
 * and why not, is the store connected, and where the catalog sync stands — with the
 * states where it cannot finish on its own spelled out (Quissly refusing the
 * account, a sync that delivered nothing, products Quissly has not confirmed,
 * automatic sync not set up). Controls only where they can act.
 *
 * Pure: render() takes the model the controller builds, returns escaped HTML.
 */
final class DashboardView
{
    /** Cron counts as running when it ran within this many seconds. */
    /** Sync log lines on the Dashboard - the log's only place in the admin. */
    public const LOG_LINES = 200;

    public const CRON_FRESH_SECONDS = 900;

    private const REASONS = [
        'master_toggle_off'          => 'quissly_search.reason_master_toggle_off',
        'credentials_not_configured' => 'quissly_search.reason_credentials_not_configured',
        'auth_error'                 => 'quissly_search.reason_auth_error',
        'initial_sync_pending'       => 'quissly_search.reason_initial_sync_pending',
    ];

    /**
     * @param array{
     *   connected:bool, environment:string, status:array, active_products:int,
     *   synced:int, queued:int, waiting:array{products:int, oldest_sent_at:?int},
     *   progress:?array, initial_sync:bool, gate_blocked:?array, refusal:?array,
     *   last_run:?array, last_cron_at:?int, cron_command:string, log:list<array{at:int, message:string}>,
     *   now:int, urls:array<string,string>, security_hash:string
     * } $m
     */
    public static function render(array $m): string
    {
        return self::searchStatus($m)
            . self::connection($m)
            . ($m['connected'] ? self::features($m) . self::sync($m) : '')
            . self::log($m)
            . '<p style="margin-top:24px">'
            . self::link($m['urls']['settings'], self::t('quissly_search.dash_open_settings'), 'btn')
            . ' ' . self::link($m['urls']['panel'], self::t('quissly_search.dash_open_panel'), 'btn')
            . '</p>';
    }

    private static function searchStatus(array $m): string
    {
        $active = ($m['status']['state'] ?? '') === StatusReport::STATE_ACTIVE;
        $html = self::h(self::t('quissly_search.dash_search_status'))
            . '<p>' . ($active
                ? '<span class="label label-success">' . self::e(self::t('quissly_search.active')) . '</span> ' . self::e(self::t('quissly_search.dash_search_active'))
                : '<span class="label label-warning">' . self::e(self::t('quissly_search.inactive')) . '</span> ' . self::e(self::t('quissly_search.dash_search_inactive')))
            . '</p>';
        if (!$active && !empty($m['status']['reasons'])) {
            $items = '';
            foreach ($m['status']['reasons'] as $reason) {
                $items .= '<li>' . self::e(self::t(self::REASONS[$reason] ?? (string) $reason)) . '</li>';
            }
            $html .= '<ul class="quissly-dash__reasons">' . $items . '</ul>';
        }

        return $html;
    }

    private static function connection(array $m): string
    {
        $html = self::h(self::t('quissly_search.dash_connection'));
        if (!$m['connected']) {
            // Connect lives at the top of Configuration (the add-on's settings page).
            return $html . self::alert('warning', self::t('quissly_search.dash_not_connected'))
                . '<p>' . self::link($m['urls']['settings'], self::t('quissly_search.setup_connect'), 'btn btn-primary') . '</p>';
        }

        return $html . self::table([
            [self::t('quissly_search.dash_connected'), '<span class="label label-success">' . self::e(self::t('quissly_search.yes')) . '</span>'],
            [self::t('quissly_search.dash_environment'), self::e($m['environment'])],
        ]) . '<p>' . self::link($m['urls']['test_connection'], self::t('quissly_search.test_connection'), 'btn') . '</p>';
    }

    /**
     * The storefront feature switches and whether each is on — all six, like the other
     * plugins; one this add-on has not built yet says so, so an "On" there is never
     * mistaken for a working feature.
     */
    private static function features(array $m): string
    {
        $rows = [];
        foreach ($m['features'] as $feature) {
            $state = $feature['on']
                ? '<span class="label label-success">' . self::e(self::t('quissly_search.feature_on')) . '</span>'
                : '<span class="label">' . self::e(self::t('quissly_search.feature_off')) . '</span>';
            if (!$feature['built']) {
                $state .= ' <span class="muted">' . self::e(self::t('quissly_search.feature_not_built')) . '</span>';
            } elseif (!empty($feature['note'])) {
                $state .= ' <span class="muted">' . self::e((string) $feature['note']) . '</span>';
            }
            $rows[] = [self::t($feature['label']), $state];
        }

        return self::h(self::t('quissly_search.dash_features'))
            . self::table($rows)
            . '<p>' . self::link($m['urls']['settings'], self::t('quissly_search.feature_change')) . '</p>';
    }

    private static function sync(array $m): string
    {
        $html = self::h(self::t('quissly_search.dash_catalog_sync'));

        if (!empty($m['refusal'])) {
            $html .= self::alert('error', self::t('quissly_search.dash_refused', ['[code]' => (string) (int) $m['refusal']['code']]));
        }
        if (!empty($m['gate_blocked'])) {
            $html .= self::alert('warning', self::t('quissly_search.dash_gate_blocked'));
        }
        if ($m['waiting']['products'] > 0) {
            $html .= self::alert('info', self::t('quissly_search.dash_waiting', [
                '[count]' => (string) $m['waiting']['products'],
                '[since]' => self::ago((int) $m['waiting']['oldest_sent_at'], $m['now']),
            ]));
        }

        $rows = [
            [self::t('quissly_search.dash_in_quissly'), self::e(self::t('quissly_search.dash_of_active', ['[synced]' => (string) $m['synced'], '[active]' => (string) $m['active_products']]))],
            [self::t('quissly_search.dash_queued'), self::e((string) $m['queued'])],
            [self::t('quissly_search.dash_waiting_label'), self::e((string) $m['waiting']['products'])],
            [self::t('quissly_search.dash_last_run'), self::lastRun($m)],
        ];
        $html .= self::table($rows);

        $progress = $m['progress'];
        if (!empty($progress['running'])) {
            $total = max(1, (int) $progress['total']);
            $done = min($total, (int) $progress['ok'] + (int) $progress['failed']);
            $percent = (int) floor(100 * $done / $total);
            $html .= '<p>' . self::e(self::t('quissly_search.dash_progress', [
                '[done]' => (string) $done, '[total]' => (string) (int) $progress['total'], '[failed]' => (string) (int) $progress['failed'],
            ])) . '</p>'
                . '<div class="progress" style="max-width:640px"><div class="bar" style="width:' . $percent . '%"></div></div>';
        }

        // No second full sync while one is running: it would re-send every product.
        $buttons = [];
        if (empty($progress['running'])) {
            $full = $m['initial_sync'] ? self::t('quissly_search.dash_resync') : self::t('quissly_search.dash_start_sync');
            $buttons[] = self::post($m, ['full' => 'Y'], $full, 'btn btn-primary');
        }
        if ($m['queued'] > 0 || $m['waiting']['products'] > 0) {
            $label = $m['queued'] > 0 ? self::t('quissly_search.dash_send_queued') : self::t('quissly_search.dash_check_again');
            $buttons[] = self::post($m, [], $label, $buttons === [] ? 'btn btn-primary' : 'btn');
        }
        $html .= '<p>' . implode(' ', $buttons) . '</p>';

        $cronFresh = $m['last_cron_at'] !== null && $m['now'] - $m['last_cron_at'] <= self::CRON_FRESH_SECONDS;
        $html .= '<h4>' . self::e(self::t('quissly_search.dash_auto_sync')) . '</h4>';
        if ($cronFresh) {
            $html .= '<p><span class="label label-success">' . self::e(self::t('quissly_search.dash_cron_running')) . '</span> '
                . self::e(self::t('quissly_search.dash_cron_last', ['[ago]' => self::ago((int) $m['last_cron_at'], $m['now'])])) . '</p>';
        } else {
            $html .= self::alert('warning', self::t('quissly_search.dash_cron_missing'))
                . '<pre class="quissly-dash__cron" style="max-width:900px;white-space:pre-wrap">*/5 * * * * ' . self::e($m['cron_command']) . '</pre>';
        }

        return $html;
    }

    private static function lastRun(array $m): string
    {
        $run = $m['last_run'];
        if (empty($run['at'])) {
            return self::e(self::t('quissly_search.never'));
        }
        $text = self::t('quissly_search.dash_last_run_value', [
            '[ago]' => self::ago((int) $run['at'], $m['now']),
            '[source]' => (string) $run['source'],
            '[confirmed]' => (string) (int) $run['confirmed'],
            '[waiting]' => (string) (int) $run['waiting'],
            '[failed]' => (string) (int) $run['failed'],
        ]);
        if (!empty($run['stopped'])) {
            $text .= ' — ' . $run['stopped'];
        }

        return self::e($text);
    }

    private static function log(array $m): string
    {
        $html = self::h(self::t('quissly_search.dash_recent_log'));
        if ($m['log'] === []) {
            return $html . '<p>' . self::e(self::t('quissly_search.log_empty')) . '</p>';
        }

        // Its only place in the admin (there is no Sync Log page): newest first, scrolling.
        return $html . '<div style="max-height:420px;overflow:auto;max-width:1100px">' . SyncLogView::table($m['log']) . '</div>';
    }

    /** @param array<string, string> $fields */
    private static function post(array $m, array $fields, string $label, string $class): string
    {
        $inputs = '<input type="hidden" name="security_hash" value="' . self::e($m['security_hash']) . '">';
        foreach ($fields as $name => $value) {
            $inputs .= '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
        }

        return '<form method="post" action="' . self::e($m['urls']['sync_now']) . '" style="display:inline">' . $inputs
            . '<button type="submit" class="' . self::e($class) . '">' . self::e($label) . '</button></form>';
    }

    /** @param list<array{0:string, 1:string}> $rows label (plain), value (HTML) */
    private static function table(array $rows): string
    {
        $html = '';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th style="text-align:left;white-space:nowrap;padding-right:16px">' . self::e($label) . '</th><td>' . $value . '</td></tr>';
        }

        return '<table class="table table-condensed" style="max-width:640px">' . $html . '</table>';
    }

    private static function ago(int $at, int $now): string
    {
        $seconds = max(0, $now - $at);
        if ($seconds < 60) {
            return self::t('quissly_search.ago_seconds', ['[n]' => (string) $seconds]);
        }
        if ($seconds < 3600) {
            return self::t('quissly_search.ago_minutes', ['[n]' => (string) intdiv($seconds, 60)]);
        }
        if ($seconds < 172800) {
            return self::t('quissly_search.ago_hours', ['[n]' => (string) intdiv($seconds, 3600)]);
        }

        return self::t('quissly_search.ago_days', ['[n]' => (string) intdiv($seconds, 86400)]);
    }

    private static function h(string $text): string
    {
        return '<h3 style="margin-top:20px">' . self::e($text) . '</h3>';
    }

    private static function alert(string $type, string $text): string
    {
        return '<div class="alert alert-' . self::e($type) . '" style="max-width:900px">' . self::e($text) . '</div>';
    }

    private static function link(string $url, string $text, string $class = ''): string
    {
        return '<a href="' . self::e($url) . '"' . ($class !== '' ? ' class="' . self::e($class) . '"' : '') . '>' . self::e($text) . '</a>';
    }

    /** @param array<string, string> $params */
    private static function t(string $key, array $params = []): string
    {
        if (function_exists('__')) {
            return (string) __($key, $params);
        }

        return $key . ($params === [] ? '' : ' ' . implode(' ', $params));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function __construct()
    {
    }
}
