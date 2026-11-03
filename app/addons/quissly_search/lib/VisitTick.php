<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * The cron's work for a store whose server cron is not set up (WordPress's WP-Cron way): at most
 * once every EVERY_SECONDS, a storefront page asks the add-on's storefront address
 * `quissly_search.tick` (with token()), waiting at most SPAWN_TIMEOUT_MS and never for the answer.
 * That address does the cron dispatch's work (fn_quissly_search_cron_work). Product changes,
 * shopping activity, the plan and updates then move whenever shoppers visit; the Dashboard still
 * recommends the cron line, since visits are not a schedule.
 *
 * Nothing is asked while the real cron has run in the last CRON_FRESH_SECONDS, nor when the
 * merchant switched it off (setting visit_tick: a store with the cron, or one whose server cannot
 * reach its own public address - behind Cloudflare, say). Only a full storefront page asks
 * (func.php): never an AJAX request, and the page's output is never cut short -
 * fastcgi_finish_request is not called, since CS-Cart writes an AJAX answer in Tygh\Ajax's
 * destructor, after every shutdown function (1.0.33 called it and emptied such answers).
 */
final class VisitTick
{
    public const EVERY_SECONDS = 300;
    /** The real cron stands for this long: a visit asks nothing meanwhile. */
    public const CRON_FRESH_SECONDS = 600;
    /** The longest a page waits to hand the address its request (the address runs on without it). */
    public const SPAWN_TIMEOUT_MS = 500;

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

    /** Whether the real cron ran at $lastCron recently enough that visits need not ask. */
    public static function cronFresh(int $lastCron, int $now): bool
    {
        return $lastCron >= $now - self::CRON_FRESH_SECONDS;
    }

    /**
     * On a full storefront page: claim the tick (a file's time - no query on every page) and, when
     * due and the cron has not just run ($cronFresh, asked only then), ask the address at the end.
     *
     * @param callable(): bool $cronFresh
     */
    public static function maybe(string $claimFile, string $url, int $now, callable $cronFresh): void
    {
        if (!self::due((int) @filemtime($claimFile), $now) || !@touch($claimFile, $now) || $cronFresh()) {
            return;
        }
        register_shutdown_function(static function () use ($url) {
            self::spawn($url, self::SPAWN_TIMEOUT_MS);
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
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // a no-op since 8.0, deprecated in 8.5
        }
    }

    private function __construct()
    {
    }
}
