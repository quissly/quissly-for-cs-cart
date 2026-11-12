<?php

declare(strict_types=1);

namespace Quissly\Search\Orders;

use Quissly\Search\Config;
use Quissly\Search\Sync\SyncStore;

/**
 * Tells Quissly where this store answers order lookups (mode quissly_search.order_lookup) - or that it
 * does not, while "Order status in QChat" is off: `PUT <console>/api/v1/store/order-lookup` with the
 * store API key, from the cron and an admin page's end, once a day and whenever the address or the
 * switch changed. A failure goes to the sync log once and is tried again the next day.
 */
final class LookupRegistration
{
    public const PATH = '/api/v1/store/order-lookup';
    public const STATE = 'order_lookup';
    public const EVERY = 86400;

    /**
     * @param ?string $url the lookup address, or null while the switch is off
     * @param callable(string, string, array): ?array $put url, body, headers -> {status, body} or null
     */
    public static function maybe(SyncStore $store, string $storeKey, string $projectId, ?string $url, string $version, int $now, callable $put): void
    {
        if ($storeKey === '' || $projectId === '') {
            return;
        }
        $state = (array) ($store->getState(self::STATE) ?? []);
        if (!self::due($state, $url, $now)) {
            return;
        }
        // Claimed before the network: another request meanwhile does not ask again.
        $store->setState(self::STATE, ['url' => $url, 'at' => $now, 'ok' => $state['ok'] ?? false]);
        $answer = $put(
            Config::CONSOLE_URL . self::PATH,
            (string) json_encode(['project_id' => $projectId, 'url' => $url, 'version' => $version], JSON_UNESCAPED_SLASHES),
            ['Accept: application/json', 'X-Store-Api-Key: ' . $storeKey]
        );
        $status = (int) ($answer['status'] ?? 0);
        $ok = $status >= 200 && $status < 300;
        if (!$ok && ($state['ok'] ?? true)) {
            $store->log(sprintf('Quissly could not be told where to check order status (HTTP %d); tried again tomorrow.', $status), $now);
        }
        $store->setState(self::STATE, ['url' => $url, 'at' => $now, 'ok' => $ok]);
    }

    /** Whether to tell Quissly now: the address (or the switch) changed, or a day passed. PURE. */
    public static function due(array $state, ?string $url, int $now): bool
    {
        $same = array_key_exists('url', $state) && $state['url'] === $url;

        return !$same || $now - (int) ($state['at'] ?? 0) >= self::EVERY;
    }

    private function __construct()
    {
    }
}
