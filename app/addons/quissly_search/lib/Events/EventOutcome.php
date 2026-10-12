<?php

declare(strict_types=1);

namespace Quissly\Search\Events;

/**
 * What a send's answer means for a queued shopping event.
 *
 * 2xx: delivered. 400 / 413 / 422: Quissly read the event and refused it - sending the same body
 * again cannot succeed, so it is dropped. Anything else - no answer, a timeout, 401/403
 * (credentials being fixed), 404, 429, 5xx - is tried again later, a little later each time, at
 * most MAX_ATTEMPTS times in all. The Shopify app gives up after 5 tries.
 *
 * Pure.
 */
final class EventOutcome
{
    public const SENT = 'sent';
    public const DROP = 'drop';
    public const RETRY = 'retry';

    public const MAX_ATTEMPTS = 6;

    /** Events still unsent after this long are dropped. */
    public const MAX_AGE_SECONDS = 3 * 86400;

    /** Seconds before the next try, after the 1st, 2nd... failed one. */
    private const DELAYS = [60, 300, 900, 3600, 21600];

    /**
     * @param int $status   HTTP status, 0 = no answer
     * @param int $attempts tries made before this one
     * @return array{action: string, delay: int}
     */
    public static function after(int $status, int $attempts): array
    {
        if ($status >= 200 && $status < 300) {
            return ['action' => self::SENT, 'delay' => 0];
        }
        if (in_array($status, [400, 413, 422], true)) {
            return ['action' => self::DROP, 'delay' => 0];
        }
        $made = $attempts + 1;
        if ($made >= self::MAX_ATTEMPTS) {
            return ['action' => self::DROP, 'delay' => 0];
        }

        return ['action' => self::RETRY, 'delay' => self::DELAYS[min($made, count(self::DELAYS)) - 1]];
    }

    private function __construct()
    {
    }
}
