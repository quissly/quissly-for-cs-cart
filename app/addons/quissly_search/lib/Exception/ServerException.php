<?php

declare(strict_types=1);

namespace Quissly\Search\Exception;

/**
 * 5xx — Quissly-side error. Page-1 failure falls back to native.
 */
final class ServerException extends QuisslyException
{
    public function shouldFallbackToNative(): bool
    {
        return true;
    }
}
