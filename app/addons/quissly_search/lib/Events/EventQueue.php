<?php

declare(strict_types=1);

namespace Quissly\Search\Events;

/**
 * Shopping events waiting to be sent. DbEventQueue keeps them in `?:quissly_search_events`;
 * the unit tests use an in-memory one. Times are unix seconds.
 */
interface EventQueue
{
    /** @param array<string, mixed> $body EventBody::envelope() */
    public function add(array $body, int $now): void;

    /**
     * The oldest events due now.
     *
     * @return list<array{event_id: int, body: array<string, mixed>, attempts: int, created_at: int}>
     */
    public function due(int $now, int $limit): array;

    /** @param list<int> $ids */
    public function remove(array $ids): void;

    public function retry(int $id, int $attempts, int $nextAt): void;

    /** Drop the events created before a time (null = all of them); the count dropped. */
    public function clear(?int $before = null): int;

    /** Keep at most $keep events, dropping the oldest; the count dropped. */
    public function trim(int $keep): int;
}
