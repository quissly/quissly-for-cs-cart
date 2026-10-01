<?php

declare(strict_types=1);

namespace Quissly\Search\Exception;

/**
 * Credentials are missing or unreadable in config.local.php. We never intercept
 * without them, so this degrades to native search. The admin status page
 * surfaces "credentials not configured" separately.
 */
final class ConfigurationException extends QuisslyException
{
    public function shouldFallbackToNative(): bool
    {
        return true;
    }
}
