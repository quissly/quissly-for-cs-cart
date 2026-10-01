<?php

declare(strict_types=1);

namespace Quissly\Search\Exception;

/**
 * 400 / 404 / 422 — a malformed request, wrong endpoint, or missing required
 * field. This is a bug on our side: log loudly, then fall back to native.
 */
final class ClientErrorException extends QuisslyException
{
    public function shouldFallbackToNative(): bool
    {
        return true;
    }
}
