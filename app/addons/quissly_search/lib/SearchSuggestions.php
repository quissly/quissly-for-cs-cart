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
 *                     read, set OUR keys, write the whole blob back (PUT replaces it,
 *                     so every other key is carried over).
 * The QSearch service id comes from QChatDirectory's service lookup (cached in its state; the
 * cron's showcase step looks it up, so the storefront never waits for Configuration).
 *
 * A store with several languages has a list per language, in the Magento and WooCommerce
 * plugins' keys: `client_specific_queries` is the main language's (named in
 * `client_specific_queries_language`), the others are under `client_specific_queries_by_language`.
 * A shopper sees their language's list; a language without one uses the main language's (the
 * Shopify app's rule).
 *
 * The Search Suggestions buttons are kept in Quissly too, in the Shopify app's keys:
 * `search_chips_mode` (automatic | manual) and `search_chips_manual` ({language: [...]}, the
 * main language's included) - saveButtons(), buttonsForStorefront(). The add-on keeps a copy
 * (state row LOCAL_BUTTONS), used while Quissly does not have the choice yet.
 *
 * The network is passed in (callables), as QChatDirectory does, so this is unit-tested
 * without CS-Cart.
 */
final class SearchSuggestions
{
    public const QUERIES_KEY = 'client_specific_queries';
    public const TYPING_KEY = 'search_typing_enabled';
    /** The main list's language ("ka"), and the other languages' lists {"en": [...]}. */
    public const PRIMARY_KEY = 'client_specific_queries_language';
    public const BY_LANGUAGE_KEY = 'client_specific_queries_by_language';
    /** The Search Suggestions buttons: which ones, and the Manual lists per language. */
    public const CHIPS_MODE_KEY = 'search_chips_mode';
    public const MANUAL_CHIPS_KEY = 'search_chips_manual';
    /** The add-on's copy of the buttons' choice and Manual lists ({mode, lists}). */
    public const LOCAL_BUTTONS = 'suggestion_buttons';
    /** What Quissly's generator last wrote, {main, by_language} (written by Quissly, read only here). */
    public const GENERATED_KEY = 'client_specific_queries_generated';

    /** At most this many suggestions, each at most MAX_LENGTH characters (as Shopify). */
    public const MAX_COUNT = 20;
    public const MAX_LENGTH = 80;

    /** Suggestion buttons under the overlay's bar, at most (the Shopify app's MAX_CHIPS). */
    public const MAX_CHIPS = 10;

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
     * @return array{enabled:bool, queries:list<string>, language:string, by_language:array<string, list<string>>, chips_mode:string, manual_chips:array<string, list<string>>, generated:list<string>, generated_by_language:array<string, list<string>>}
     */
    public static function fromConfig($config): array
    {
        $config = is_array($config) ? $config : [];
        $generated = is_array($config[self::GENERATED_KEY] ?? null) ? $config[self::GENERATED_KEY] : [];
        $primary = $config[self::PRIMARY_KEY] ?? '';
        $mode = $config[self::CHIPS_MODE_KEY] ?? '';

        return [
            'enabled'     => !(array_key_exists(self::TYPING_KEY, $config) && $config[self::TYPING_KEY] === false),
            'queries'     => self::clean($config[self::QUERIES_KEY] ?? []),
            'language'    => is_string($primary) ? strtolower(trim($primary)) : '',
            'by_language' => self::listsByLanguage($config[self::BY_LANGUAGE_KEY] ?? []),
            // '' = not in Quissly yet: the add-on's copy decides (buttonsForStorefront()).
            'chips_mode'   => in_array($mode, ['automatic', 'manual'], true) ? $mode : '',
            'manual_chips' => self::listsByLanguage($config[self::MANUAL_CHIPS_KEY] ?? []),
            // What Quissly's generator last wrote (its merchant-edit baseline): "Reset to generated".
            'generated' => self::clean($generated['main'] ?? []),
            'generated_by_language' => self::listsByLanguage($generated['by_language'] ?? []),
        ];
    }

    /**
     * {language: list} cleaned, keys lowercased, empty lists left out.
     *
     * @param mixed $raw
     * @return array<string, list<string>>
     */
    public static function listsByLanguage($raw): array
    {
        $out = [];
        foreach ((array) $raw as $language => $queries) {
            $queries = self::clean($queries);
            if (is_string($language) && $language !== '' && $queries !== []) {
                $out[strtolower($language)] = $queries;
            }
        }

        return $out;
    }

    /**
     * The typing list for a shopper's language: the main list in the main language (or with no
     * language given), else that language's list (or its base language's: "pt-br" -> "pt"),
     * else the main list - the Shopify app's "a language with no list of its own uses the
     * default one".
     *
     * @param array{queries?: list<string>, language?: string, by_language?: array<string, list<string>>} $lists fromConfig()
     * @return list<string>
     */
    public static function pick(array $lists, string $language, string $main): array
    {
        $main = ($lists['language'] ?? '') !== '' ? (string) $lists['language'] : $main;
        $language = self::key($language);
        if ($language === '' || $language === $main) {
            return (array) ($lists['queries'] ?? []);
        }
        $byLanguage = (array) ($lists['by_language'] ?? []);

        return $byLanguage[$language] ?? $byLanguage[explode('-', $language)[0]] ?? (array) ($lists['queries'] ?? []);
    }

    /**
     * The overlay's Automatic suggestion buttons for a language: only what Quissly's generator
     * wrote, never the typing list - the Shopify app's chipsForLocale(). That language's list
     * first (then its base language's: the generator's main list stays the old one when the
     * merchant edited the typing list), else the generated main list; none before anything was
     * generated. A language without a list of its own gets the main language's. At most MAX_CHIPS.
     *
     * @param array{generated?: list<string>, generated_by_language?: array<string, list<string>>} $lists
     * @return list<string>
     */
    public static function pickGenerated(array $lists, string $language, string $main = ''): array
    {
        $byLanguage = (array) ($lists['generated_by_language'] ?? []);
        $language = self::key($language);
        foreach ([$language, explode('-', $language)[0], self::key($main)] as $key) {
            if ($key !== '' && !empty($byLanguage[$key])) {
                return array_slice(array_values($byLanguage[$key]), 0, self::MAX_CHIPS);
            }
        }

        return array_slice(array_values((array) ($lists['generated'] ?? [])), 0, self::MAX_CHIPS);
    }

    /**
     * The Manual buttons for a shopper's language: that language's list, its base language's,
     * else the main language's (the Shopify app's manualChipsForLocale()). At most MAX_CHIPS.
     *
     * @param array{manual_chips?: array<string, list<string>>} $lists
     * @return list<string>
     */
    public static function pickManual(array $lists, string $language, string $main): array
    {
        $manual = (array) ($lists['manual_chips'] ?? []);
        $language = self::key($language);
        foreach ([$language, explode('-', $language)[0], self::key($main)] as $key) {
            if ($key !== '' && !empty($manual[$key])) {
                return array_slice(array_values($manual[$key]), 0, self::MAX_CHIPS);
            }
        }

        return [];
    }

    /**
     * Why a language's Manual buttons cannot be saved (a langvar name), or '' when they can.
     *
     * @param list<string> $queries cleaned list
     */
    public static function validateButtons(array $queries): string
    {
        if (count($queries) > self::MAX_CHIPS) {
            return 'quissly_search.buttons_too_many';
        }

        return self::validate($queries);
    }

    /**
     * The add-on's copy of the buttons' choice and Manual lists.
     *
     * @return array{mode:string, lists:array<string, list<string>>}
     */
    public static function localButtons(SyncStore $store): array
    {
        $local = (array) ($store->getState(self::LOCAL_BUTTONS) ?? []);

        return [
            'mode'  => ($local['mode'] ?? '') === 'manual' ? 'manual' : 'automatic',
            'lists' => self::listsByLanguage($local['lists'] ?? []),
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
     * What the storefront overlay types: the shopper's language's list (pick()) when typing is
     * on, else none. Cached in the add-on's state, so a storefront page reads Quissly at most
     * once per five minutes.
     *
     * @param callable(string, list<string>): ?array{status:int, body:string} $get
     * @return list<string>
     */
    public static function forStorefront(SyncStore $store, string $serviceId, callable $get, int $now, string $language = '', string $main = ''): array
    {
        $lists = self::storefrontLists($store, $serviceId, $get, $now);
        if (!$lists['enabled']) {
            return [];
        }
        // An empty list is nothing chosen yet, not "show nothing" (the switch hides the typing):
        // Quissly's generated list until the merchant's own has entries. A list saved empty
        // before anything was generated kept the typing off until "Reset to generated".
        $picked = self::pick($lists, $language, $main);

        return $picked !== [] ? $picked : self::pickGenerated($lists, $language, $main);
    }

    /**
     * The Search Suggestions buttons for a shopper's language, from the same cached read: the
     * Manual list or the generated one, as Quissly holds the choice - or, while Quissly does not
     * have it yet, as the add-on's copy says.
     *
     * @param callable(string, list<string>): ?array{status:int, body:string} $get
     * @return list<string>
     */
    public static function buttonsForStorefront(SyncStore $store, string $serviceId, callable $get, int $now, string $language, string $main): array
    {
        $lists = $serviceId !== '' ? self::storefrontLists($store, $serviceId, $get, $now) : self::fromConfig([]);
        if ($lists['chips_mode'] === '') {
            $local = self::localButtons($store);
            $lists['chips_mode'] = $local['mode'];
            $lists['manual_chips'] = $local['lists'];
        }

        return $lists['chips_mode'] === 'manual'
            ? self::pickManual($lists, $language, $main)
            : self::pickGenerated($lists, $language, $main);
    }

    /**
     * Quissly's lists, cached in the add-on's state (a failed read for a minute).
     *
     * @param callable(string, list<string>): ?array{status:int, body:string} $get
     * @return array{enabled:bool, queries:list<string>, generated:list<string>, generated_by_language:array<string, list<string>>}
     */
    private static function storefrontLists(SyncStore $store, string $serviceId, callable $get, int $now): array
    {
        $cached = $store->getState(self::STATE);
        // A cache entry written before the per-language and button lists were cached is read again.
        if (is_array($cached) && ($cached['service'] ?? '') === $serviceId && (int) ($cached['until'] ?? 0) > $now
            && isset($cached['lists']['generated_by_language'], $cached['lists']['by_language'], $cached['lists']['manual_chips'])) {
            return $cached['lists'];
        }
        $read = self::read($serviceId, $get);
        $lists = $read ?? self::fromConfig([self::TYPING_KEY => false]);
        $store->setState(self::STATE, [
            'service' => $serviceId,
            'lists'   => $lists,
            'until'   => $now + ($read === null ? self::FAILED_CACHE_SECONDS : self::CACHE_SECONDS),
        ]);

        return $lists;
    }

    /**
     * Write the typing lists to Quissly. '' when saved, else a langvar name for the reason.
     *
     * @param list<string>                     $queries    cleaned, validated main list
     * @param array<string, list<string>>|null $byLanguage the other languages' lists (null = leave them as they are)
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
        ?array $byLanguage,
        string $main,
        callable $post,
        callable $get,
        callable $put
    ): string {
        return self::write($store, $credentials, $serviceId, static function (array $config) use ($enabled, $queries, $byLanguage, $main): array {
            $config[self::QUERIES_KEY] = array_values($queries);
            $config[self::TYPING_KEY] = $enabled;
            if ($byLanguage !== null) {
                // As an object, also when empty: the Shopify app reads a language map.
                $config[self::BY_LANGUAGE_KEY] = (object) array_map('array_values', array_filter($byLanguage));
                $config[self::PRIMARY_KEY] = self::key($main);
            }

            return $config;
        }, $post, $get, $put);
    }

    /**
     * Write the Search Suggestions buttons (the Shopify app's saveSearchChips()): the choice and
     * every language's Manual list, replaced whole; an empty list is left out, so that language
     * uses the main one. The add-on's copy is written first, whatever Quissly answers. '' when
     * saved, else a langvar name for the reason.
     *
     * @param array<string, list<string>> $lists cleaned Manual lists by language, the main one included
     */
    public static function saveButtons(
        SyncStore $store,
        Credentials $credentials,
        string $serviceId,
        string $mode,
        array $lists,
        callable $post,
        callable $get,
        callable $put
    ): string {
        $mode = $mode === 'manual' ? 'manual' : 'automatic';
        $lists = array_map('array_values', array_filter($lists));
        $store->setState(self::LOCAL_BUTTONS, ['mode' => $mode, 'lists' => $lists]);

        return self::write($store, $credentials, $serviceId, static function (array $config) use ($mode, $lists): array {
            $config[self::CHIPS_MODE_KEY] = $mode;
            $config[self::MANUAL_CHIPS_KEY] = (object) $lists;

            return $config;
        }, $post, $get, $put);
    }

    /**
     * Sign in as the store, read the whole widget_config, change it, write it back (PUT replaces
     * the whole blob, so every other key is kept). '' on success, else a langvar name.
     *
     * @param callable(array): array $change
     */
    private static function write(SyncStore $store, Credentials $credentials, string $serviceId, callable $change, callable $post, callable $get, callable $put): string
    {
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
        $config = $change(is_array($config) ? $config : []);

        $written = $put(self::writeUrl($serviceId), (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $headers);
        if ($written === null || $written['status'] !== 200) {
            return 'quissly_search.suggestions_save_failed';
        }
        $store->setState(self::STATE, null); // the storefront reads the new lists at once

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

    /** A language as a widget_config key: "pt_BR" -> "pt-br". */
    private static function key(string $language): string
    {
        return strtolower(str_replace('_', '-', trim($language)));
    }

    private function __construct()
    {
    }
}
