<?php

declare(strict_types=1);

namespace Quissly\Search;

use Quissly\Search\Panel\PanelSession;
use Quissly\Search\Sync\SyncStore;

/**
 * Search bar suggestions: the queries the search overlay types into its empty bar (port
 * of the Shopify app's "Search bar suggestions"; the WooCommerce plugin has the same).
 *
 * The list lives in Quissly, not here - in the store's QSearch service's widget_config,
 * under the Shopify app's keys (`client_specific_queries`, a list of strings, and
 * `search_typing_enabled`, absent = on), so every platform and Quissly read one list.
 *  - read():          the public widget-config read; no config row yet (404) = on, none;
 *  - forStorefront(): read() cached in the add-on's state for five minutes (a failed read
 *                     for one) - what the overlay types;
 *  - save():          sign in as the store (PanelSession, the embedded panel's sign-in),
 *                     read, set OUR two keys, write the whole blob back (PUT replaces it,
 *                     so every other key is carried over).
 * The QSearch service id comes from QChatDirectory's service lookup (cached in its state).
 *
 * The network is passed in (callables), as QChatDirectory does, so this is unit-tested
 * without CS-Cart.
 */
final class SearchSuggestions
{
    public const QUERIES_KEY = 'client_specific_queries';
    public const TYPING_KEY = 'search_typing_enabled';

    /** At most this many suggestions, each at most MAX_LENGTH characters (as Shopify). */
    public const MAX_COUNT = 20;
    public const MAX_LENGTH = 80;

    public const STATE = 'search_suggestions';
    public const CACHE_SECONDS = 300;
    public const FAILED_CACHE_SECONDS = 60;

    /**
     * Trimmed, non-empty, de-duplicated (case-insensitive), in order.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function clean($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $query = trim((string) preg_replace('/\s+/u', ' ', $item));
            $key = mb_strtolower($query);
            if ($query === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $query;
        }

        return $out;
    }

    /**
     * Why a list cannot be saved (a langvar name), or '' when it can.
     *
     * @param list<string> $queries cleaned list
     */
    public static function validate(array $queries): string
    {
        if (count($queries) > self::MAX_COUNT) {
            return 'quissly_search.suggestions_too_many';
        }
        foreach ($queries as $query) {
            if (mb_strlen($query) > self::MAX_LENGTH) {
                return 'quissly_search.suggestions_too_long';
            }
        }

        return '';
    }

    /**
     * The suggestions a widget_config holds (null = no config row: on, none).
     *
     * @param mixed $config
     * @return array{enabled:bool, queries:list<string>}
     */
    public static function fromConfig($config): array
    {
        $config = is_array($config) ? $config : [];

        return [
            'enabled' => !(array_key_exists(self::TYPING_KEY, $config) && $config[self::TYPING_KEY] === false),
            'queries' => self::clean($config[self::QUERIES_KEY] ?? []),
        ];
    }

    /**
     * The current list from Quissly, or null when it cannot be read.
     *
     * @param callable(string, list<string>): ?array{status:int, body:string} $get (url, header lines)
     * @return array{enabled:bool, queries:list<string>}|null
     */
    public static function read(string $serviceId, callable $get): ?array
    {
        if ($serviceId === '') {
            return null;
        }
        $response = $get(self::readUrl($serviceId), []);
        if ($response === null) {
            return null;
        }
        if ($response['status'] === 404) {
            return self::fromConfig(null);
        }

        return $response['status'] === 200 ? self::fromConfig(json_decode($response['body'], true)) : null;
    }

    /**
     * What the storefront overlay types: the list when typing is on, else none. Cached in
     * the add-on's state, so a storefront page reads Quissly at most once per five minutes.
     *
     * @param callable(string, list<string>): ?array{status:int, body:string} $get
     * @return list<string>
     */
    public static function forStorefront(SyncStore $store, string $serviceId, callable $get, int $now): array
    {
        $cached = $store->getState(self::STATE);
        if (is_array($cached) && ($cached['service'] ?? '') === $serviceId && (int) ($cached['until'] ?? 0) > $now) {
            return self::clean($cached['queries'] ?? []);
        }
        $read = self::read($serviceId, $get);
        $queries = $read !== null && $read['enabled'] ? $read['queries'] : [];
        $store->setState(self::STATE, [
            'service' => $serviceId,
            'queries' => $queries,
            'until'   => $now + ($read === null ? self::FAILED_CACHE_SECONDS : self::CACHE_SECONDS),
        ]);

        return $queries;
    }

    /**
     * Write the list to Quissly. '' when saved, else a langvar name for the reason.
     *
     * @param list<string> $queries cleaned, validated list
     * @param callable(string, string): ?array{status:int, body:string}                $post (url, JSON body)
     * @param callable(string, list<string>): ?array{status:int, body:string}          $get  (url, header lines)
     * @param callable(string, string, list<string>): ?array{status:int, body:string}  $put  (url, JSON body, header lines)
     */
    public static function save(
        SyncStore $store,
        Credentials $credentials,
        string $serviceId,
        bool $enabled,
        array $queries,
        callable $post,
        callable $get,
        callable $put
    ): string {
        if ($serviceId === '' || !$credentials->isConfigured()) {
            return 'quissly_search.suggestions_no_service';
        }
        $session = PanelSession::open($credentials->projectId(), $credentials->accountEmail(), $credentials->bearerToken(), $post);
        if (!$session['ok']) {
            return 'quissly_search.suggestions_signin_failed';
        }
        $headers = ['Authorization: Bearer ' . $session['access_token'], 'X-Platform: ' . Config::PLATFORM];
        $current = $get(self::readUrl($serviceId), $headers);
        if ($current === null || ($current['status'] !== 200 && $current['status'] !== 404)) {
            return 'quissly_search.suggestions_save_failed';
        }
        $config = $current['status'] === 200 ? json_decode($current['body'], true) : [];
        $config = is_array($config) ? $config : [];
        $config[self::QUERIES_KEY] = array_values($queries);
        $config[self::TYPING_KEY] = $enabled;

        $written = $put(self::writeUrl($serviceId), (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $headers);
        if ($written === null || $written['status'] !== 200) {
            return 'quissly_search.suggestions_save_failed';
        }
        $store->setState(self::STATE, null); // the storefront reads the new list at once

        return '';
    }

    public static function readUrl(string $serviceId): string
    {
        return Config::CONSOLE_URL . '/api/v1/services/widget-config/' . rawurlencode($serviceId);
    }

    public static function writeUrl(string $serviceId): string
    {
        return Config::CONSOLE_URL . '/api/v1/services/' . rawurlencode($serviceId) . '/widget-config';
    }

    private function __construct()
    {
    }
}
