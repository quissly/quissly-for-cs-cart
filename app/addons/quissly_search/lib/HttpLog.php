<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * What Administration -> Logs keeps of the add-on's requests to Quissly.
 *
 * Tygh\Http logs every request's URL, body and response (Settings -> Logging, "Requests: http",
 * on by default) - for the add-on that was the account email, the sign-up code, the store's API
 * key, the Admin Panel's tokens and shoppers' search words (found by a tester, 1.0.18). Every
 * call to Quissly passes extra() so the log keeps where it went and the sizes, never the bodies.
 * GitHub's update checks carry nothing private and are logged as before.
 */
final class HttpLog
{
    /**
     * Tygh\Http's $extra with the add-on's log filter.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function extra(array $extra): array
    {
        return $extra + ['log_preprocessor' => [self::class, 'entry']];
    }

    /**
     * The log entry for one request: its URL without the query, and the sizes of what was sent and received.
     *
     * @param mixed $data    the request body (a string, or an array for a GET)
     * @param mixed $content the response body
     * @return array{0: string, 1: string, 2: string} url, request, response
     */
    public static function entry(string $method, string $url, $data, array $extra, $content): array
    {
        $size = static function ($value): int {
            return is_string($value) ? strlen($value) : strlen((string) json_encode($value));
        };

        return [
            (string) strtok($url, '?#'),
            sprintf('%s, %d bytes - not logged by the Quissly add-on', $method, $size($data)),
            sprintf('%d bytes - not logged by the Quissly add-on', $size($content)),
        ];
    }

    private function __construct()
    {
    }
}
