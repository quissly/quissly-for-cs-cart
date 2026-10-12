<?php

declare(strict_types=1);

namespace Quissly\Search\Events;

/**
 * The event queue in `?:quissly_search_events` (addon.xml creates it on install - and the
 * automatic update runs the install queries again, so an updated store gets it too).
 */
final class DbEventQueue implements EventQueue
{
    public function add(array $body, int $now): void
    {
        db_query('INSERT INTO ?:quissly_search_events ?e', [
            'body'       => (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'attempts'   => 0,
            'created_at' => $now,
            'next_at'    => 0,
        ]);
    }

    public function due(int $now, int $limit): array
    {
        $rows = db_get_array('SELECT event_id, body, attempts, created_at FROM ?:quissly_search_events WHERE next_at <= ?i ORDER BY event_id ASC LIMIT ?i', $now, $limit);
        $out = [];
        foreach ((array) $rows as $row) {
            $body = json_decode((string) $row['body'], true);
            $out[] = [
                'event_id'   => (int) $row['event_id'],
                'body'       => is_array($body) ? $body : [],
                'attempts'   => (int) $row['attempts'],
                'created_at' => (int) $row['created_at'],
            ];
        }

        return $out;
    }

    public function remove(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids !== []) {
            db_query('DELETE FROM ?:quissly_search_events WHERE event_id IN (?n)', $ids);
        }
    }

    public function retry(int $id, int $attempts, int $nextAt): void
    {
        db_query('UPDATE ?:quissly_search_events SET ?u WHERE event_id = ?i', ['attempts' => $attempts, 'next_at' => $nextAt], $id);
    }

    public function clear(?int $before = null): int
    {
        return $before === null
            ? (int) db_query('DELETE FROM ?:quissly_search_events')
            : (int) db_query('DELETE FROM ?:quissly_search_events WHERE created_at < ?i', $before);
    }

    public function trim(int $keep): int
    {
        $cutoff = db_get_field('SELECT event_id FROM ?:quissly_search_events ORDER BY event_id DESC LIMIT ?i, 1', $keep);
        if ($cutoff === null || $cutoff === false || $cutoff === '') {
            return 0;
        }

        return (int) db_query('DELETE FROM ?:quissly_search_events WHERE event_id <= ?i', (int) $cutoff);
    }
}
