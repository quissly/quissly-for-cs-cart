<?php

declare(strict_types=1);

namespace Quissly\Search\Media;

/**
 * {@see RateLimiter} on the add-on's quissly_search_rate table (one row per bucket). Rows older than
 * an hour are dropped now and then, and the table never holds more than MAX_ROWS: past that the
 * oldest buckets go (a dropped visitor's count starts again; the store-wide buckets stay current).
 */
final class DbRateLimiter implements RateLimiter
{
    public const MAX_ROWS = 20000;

    public function allow(string $bucket, int $max, int $windowSeconds, int $now): bool
    {
        $key = substr(hash('sha256', $bucket), 0, 40);
        $row = db_get_row('SELECT window_start, hits FROM ?:quissly_search_rate WHERE bucket = ?s', $key);
        if (empty($row) || (int) $row['window_start'] <= $now - $windowSeconds) {
            db_query('REPLACE INTO ?:quissly_search_rate (bucket, window_start, hits) VALUES (?s, ?i, 1)', $key, $now);
            if (random_int(1, 50) === 1) {
                db_query('DELETE FROM ?:quissly_search_rate WHERE window_start < ?i', $now - 3600);
                $over = (int) db_get_field('SELECT COUNT(*) FROM ?:quissly_search_rate') - self::MAX_ROWS;
                if ($over > 0) {
                    db_query('DELETE FROM ?:quissly_search_rate ORDER BY window_start ASC LIMIT ?i', $over);
                }
            }

            return true;
        }
        if ((int) $row['hits'] >= $max) {
            return false;
        }
        db_query('UPDATE ?:quissly_search_rate SET hits = hits + 1 WHERE bucket = ?s', $key);

        return true;
    }
}
