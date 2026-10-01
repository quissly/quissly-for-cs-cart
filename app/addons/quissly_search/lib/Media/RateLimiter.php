<?php

declare(strict_types=1);

namespace Quissly\Search\Media;

/**
 * A fixed-window per-visitor throttle for the public voice/image endpoints: a
 * guard against casual abuse (each call costs a Quissly request), not a hardened
 * distributed limiter — same scope as the WooCommerce plugin's.
 */
interface RateLimiter
{
    /** Count one hit; false when the bucket is already full for this window. */
    public function allow(string $bucket, int $max, int $windowSeconds, int $now): bool;
}
