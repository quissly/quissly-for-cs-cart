<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * Thin logging facade.
 *
 * Routes through fn_log_event('addons', 'quissly_search', ...) so entries appear
 * in CS-Cart's standard log viewer, and ALWAYS mirrors
 * to error_log() so diagnostics survive even when the 'addons' log type is
 * disabled in Logging settings.
 *
 * NEVER pass secrets or query strings here: no private key, bearer token,
 * signature, or shopper query text.
 * Pass status codes, counts, and stable identifiers only.
 */
final class Logger
{
    /**
     * @param array<string, scalar> $context safe, secret-free context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write($message, $context);
    }

    /**
     * Operational/diagnostic signal (not a failure). Same routing as error().
     *
     * @param array<string, scalar> $context
     */
    public static function notice(string $message, array $context = []): void
    {
        self::write($message, $context);
    }

    /**
     * @param array<string, scalar> $context
     */
    private static function write(string $message, array $context): void
    {
        $suffix = $context !== [] ? ' ' . self::encodeContext($context) : '';
        $line = '[quissly_search] ' . $message . $suffix;

        if (function_exists('fn_log_event')) {
            fn_log_event('addons', 'quissly_search', ['data' => $line, 'message' => $line]);
        }

        error_log($line);
    }

    /**
     * @param array<string, scalar> $context
     */
    private static function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    private function __construct()
    {
    }
}
