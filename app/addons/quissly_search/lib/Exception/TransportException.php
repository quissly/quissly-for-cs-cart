<?php

declare(strict_types=1);

namespace Quissly\Search\Exception;

/**
 * Network transport failure or timeout (2.5s budget) — no HTTP status reached.
 * Page-1 failure falls back to native.
 */
final class TransportException extends QuisslyException
{
    public function shouldFallbackToNative(): bool
    {
        return true;
    }
}
