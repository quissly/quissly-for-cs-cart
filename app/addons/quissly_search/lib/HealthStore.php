<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * Persistent, single-row health record for the add-on.
 *
 * A storefront 401/402/403 happens in the SHOPPER's session, so a session
 * notification can never reach the merchant. We persist the last failure/success
 * to a one-row table (?:quissly_search_health) that the admin status page reads.
 *
 * All access is defensive: if the table is missing (e.g. an older install), reads
 * return null and writes no-op — health tracking must never break search.
 */
final class HealthStore
{
    private const TABLE = 'quissly_search_health';

    /**
     * Record an auth/plan failure (401/402/403) seen during a storefront search.
     */
    public static function recordFailure(int $status, string $message): void
    {
        self::write($status, self::truncate($message), true);
    }

    /** Record a successful, authorized call (e.g. a "Test connection" or a live search). */
    public static function recordSuccess(): void
    {
        self::write(200, '', false);
    }

    /**
     * @return array{last_status:int,last_message:string,last_failure_at:int,last_success_at:int}|null
     */
    public static function get(): ?array
    {
        if (!function_exists('db_get_row')) {
            return null;
        }

        try {
            $row = db_get_row('SELECT last_status, last_message, last_failure_at, last_success_at FROM ?:' . self::TABLE . ' WHERE lock_id = 1');
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($row)) {
            return null;
        }

        return [
            'last_status'     => (int) $row['last_status'],
            'last_message'    => (string) $row['last_message'],
            'last_failure_at' => (int) $row['last_failure_at'],
            'last_success_at' => (int) $row['last_success_at'],
        ];
    }

    private static function write(int $status, string $message, bool $isFailure): void
    {
        if (!function_exists('db_query')) {
            return;
        }

        $now = defined('TIME') ? (int) TIME : time();
        $failureAt = $isFailure ? $now : 0;
        $successAt = $isFailure ? 0 : $now;

        try {
            if ($isFailure) {
                db_query(
                    'INSERT INTO ?:' . self::TABLE . ' (lock_id, last_status, last_message, last_failure_at)'
                    . ' VALUES (1, ?i, ?s, ?i)'
                    . ' ON DUPLICATE KEY UPDATE last_status = ?i, last_message = ?s, last_failure_at = ?i',
                    $status, $message, $failureAt,
                    $status, $message, $failureAt
                );
            } else {
                db_query(
                    'INSERT INTO ?:' . self::TABLE . ' (lock_id, last_status, last_message, last_success_at)'
                    . ' VALUES (1, ?i, ?s, ?i)'
                    . ' ON DUPLICATE KEY UPDATE last_status = ?i, last_message = ?s, last_success_at = ?i',
                    $status, $message, $successAt,
                    $status, $message, $successAt
                );
            }
        } catch (\Throwable $e) {
            // Health tracking is best-effort; never let it surface to the shopper.
        }
    }

    private static function truncate(string $message): string
    {
        return mb_substr($message, 0, 250);
    }

    private function __construct()
    {
    }
}
