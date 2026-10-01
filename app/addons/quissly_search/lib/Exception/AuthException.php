<?php

declare(strict_types=1);

namespace Quissly\Search\Exception;

/**
 * 401 (bad token / signature), 402 (unpaid), 403 (plan / sig failed).
 *
 * NO fallback swap: the customer still sees native results (graceful), but the
 * merchant must be alerted via an admin notice — these are config/billing
 * problems, not transient failures.
 */
final class AuthException extends QuisslyException
{
    public function shouldFallbackToNative(): bool
    {
        return false;
    }

    public function isMerchantAlert(): bool
    {
        return true;
    }
}
