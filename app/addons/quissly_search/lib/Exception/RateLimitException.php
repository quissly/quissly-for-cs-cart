<?php

declare(strict_types=1);

namespace Quissly\Search\Exception;

/**
 * 429 — rate limited. Fall back to native rather than make the shopper wait.
 */
final class RateLimitException extends QuisslyException
{
    public function shouldFallbackToNative(): bool
    {
        return true;
    }
}
