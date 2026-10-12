<?php

declare(strict_types=1);

namespace Quissly\Search\Events;

/**
 * Sends the queued shopping events to `POST /v2beta/qsearch/events`, one signed call each (the
 * endpoint takes one event per call) - from the add-on's cron, and at the end of an admin page
 * on a store whose cron is not set up (func.php fn_quissly_search_events_send).
 *
 * What each answer means is EventOutcome's. Switched off, or no longer connected: the queue is
 * emptied - nothing more leaves the store. A run sends at most BATCH events, stops after its time
 * budget and after FAILURES failed sends in a row, so a burst is spread over a few runs and a
 * Quissly outage costs one short run, not a page of timeouts.
 *
 * The send is passed in (a callable), so this is unit-tested without CS-Cart.
 */
final class EventSender
{
    public const PATH = '/v2beta/qsearch/events';
    public const BATCH = 200;
    public const KEEP = 50000;
    public const TIMEOUT_SECONDS = 5;
    private const FAILURES = 5;

    /**
     * @param callable(array<string, mixed>): ?array{status: int, body: string} $post the signed POST (null = no answer)
     * @return array{sent: int, dropped: int, retried: int}
     */
    public static function run(EventQueue $queue, bool $enabled, callable $post, int $now, float $budgetSeconds): array
    {
        $counts = ['sent' => 0, 'dropped' => 0, 'retried' => 0];
        if (!$enabled) {
            $counts['dropped'] = $queue->clear();
            return $counts;
        }
        $counts['dropped'] += $queue->clear($now - EventOutcome::MAX_AGE_SECONDS);
        $counts['dropped'] += $queue->trim(self::KEEP);

        $started = microtime(true);
        $done = [];
        $failures = 0;
        foreach ($queue->due($now, self::BATCH) as $row) {
            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }
            if ($row['body'] === []) {
                $done[] = $row['event_id'];
                $counts['dropped']++;
                continue;
            }
            try {
                $answer = $post($row['body']);
            } catch (\Throwable $e) {
                $answer = null;
            }
            $next = EventOutcome::after($answer === null ? 0 : (int) $answer['status'], $row['attempts']);
            if ($next['action'] === EventOutcome::RETRY) {
                $queue->retry($row['event_id'], $row['attempts'] + 1, $now + $next['delay']);
                $counts['retried']++;
                // Quissly is down or refusing this store: the rest waits for the next run.
                if (++$failures >= self::FAILURES) {
                    break;
                }
                continue;
            }
            $failures = 0;
            $done[] = $row['event_id'];
            $counts[$next['action'] === EventOutcome::SENT ? 'sent' : 'dropped']++;
        }
        $queue->remove($done);

        return $counts;
    }

    private function __construct()
    {
    }
}
