<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * What every anonymous storefront POST of the add-on must pass before it does anything
 * (controllers/frontend/quissly_search.php): event, quick, voice, image, cart_adjust,
 * client_error.
 *
 *  1. The session's form token: CS-Cart's own security_hash, which the add-on's scripts send
 *     with every POST (the storefront config carries it). A request made without opening a page
 *     of the store in that session - a bot posting straight to the address - has none and is
 *     refused (403) before anything is counted or written. CS-Cart leaves its storefront CSRF
 *     check off for an add-on's controller, so the add-on checks it itself.
 *  2. A store-wide limit per endpoint (LIMITS), on top of each endpoint's own per-visitor
 *     (REMOTE_ADDR) limit: many addresses together still cannot write or spend more than this.
 */
final class StorefrontGuard
{
    /** Per endpoint: [requests, window seconds], for the whole store. */
    public const LIMITS = [
        'event'        => [1200, 60],
        'quick'        => [1200, 60],
        'cart_adjust'  => [600, 60],
        'voice'        => [60, 600],
        'image'        => [60, 600],
        'client_error' => [60, 600],
    ];

    /** Whether the request carries this session's token. */
    public static function sameSession(string $given, string $session): bool
    {
        return $given !== '' && $session !== '' && hash_equals($session, $given);
    }

    /**
     * Why a POST to $mode is refused (an HTTP status), or null to let it through.
     *
     * @param callable(string $bucket, int $max, int $window): bool $allow the rate limiter
     */
    public static function refusal(string $mode, string $given, string $session, callable $allow): ?int
    {
        if (!isset(self::LIMITS[$mode])) {
            return null;
        }
        if (!self::sameSession($given, $session)) {
            return 403;
        }
        [$max, $window] = self::LIMITS[$mode];

        return $allow('store|' . $mode, $max, $window) ? null : 429;
    }

    private function __construct()
    {
    }
}
