<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * The cron's work for a store whose server cron is not set up (WordPress's WP-Cron way): at most
 * once every EVERY_SECONDS, a storefront page asks the add-on's storefront address
 * `quissly_search.tick` (with token()) in the background - after the page has gone to the
 * browser where PHP-FPM allows it - without waiting for the answer. That address does the cron
 * dispatch's work (fn_quissly_search_cron_work) unless the real cron has run in the last 10
 * minutes. Product changes, shopping activity, the plan and updates then move whenever shoppers
 * visit; the Dashboard still recommends the cron line, since visits are not a schedule.
 */
final class VisitTick
{
    public const EVERY_SECONDS = 300;
    /** The real cron stands for this long: a visit's call does nothing meanwhile. */
    public const CRON_FRESH_SECONDS = 600;

    /** Whether a visit at $now should ask, the last one having asked at $lastTick. */
    public static function due(int $lastTick, int $now): bool
    {
        return $now - $lastTick >= self::EVERY_SECONDS;
    }

    /** The address's token: derived from config.local.php's crypt_key, so nothing is stored. */
    public static function token(string $cryptKey): string
    {
        return substr(hash_hmac('sha256', 'quissly_search.tick', $cryptKey), 0, 32);
    }

    public static function valid(string $given, string $cryptKey): bool
    {
        return $given !== '' && $cryptKey !== '' && hash_equals(self::token($cryptKey), $given);
    }

    /**
     * On a storefront page: claim the tick (a file's time - no query on every page) and ask the
     * address in the background when due.
     */
    public static function maybe(string $claimFile, string $url, int $now): void
    {
        if (!self::due((int) @filemtime($claimFile), $now) || !@touch($claimFile, $now)) {
            return;
        }
        register_shutdown_function(static function () use ($url) {
            // The shopper has the page already: there is time to send the request properly.
            $sent = function_exists('fastcgi_finish_request') && fastcgi_finish_request();
            self::spawn($url, $sent ? 3000 : 500);
        });
    }

    /** Start a request and leave without its answer (the address keeps running). */
    public static function spawn(string $url, int $timeoutMs): void
    {
        if (!function_exists('curl_init')) {
            return;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_NOSIGNAL          => true,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_USERAGENT         => 'Quissly visit tick',
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function __construct()
    {
    }
}
