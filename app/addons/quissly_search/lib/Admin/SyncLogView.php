<?php

declare(strict_types=1);

namespace Quissly\Search\Admin;

/**
 * The sync's own log as a table (newest first) - shown on the Dashboard. It records
 * counts, product ids and HTTP statuses — never payloads or secrets.
 */
final class SyncLogView
{
    /** @param list<array{at:int, message:string}> $lines */
    public static function table(array $lines): string
    {
        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<tr><td style="white-space:nowrap;padding-right:16px;color:#6b7280">' . self::e(gmdate('Y-m-d H:i:s', $line['at'])) . ' UTC</td>'
                . '<td>' . self::e($line['message']) . '</td></tr>';
        }

        return '<table class="table table-condensed quissly-log" style="max-width:1100px">' . $rows . '</table>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function __construct()
    {
    }
}
