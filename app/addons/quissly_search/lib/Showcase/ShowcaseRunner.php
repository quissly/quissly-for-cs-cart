<?php

declare(strict_types=1);

namespace Quissly\Search\Showcase;

use Quissly\Search\SearchSuggestions;
use Quissly\Search\Sync\SyncStore;

/**
 * Asks Quissly for the store's search bar suggestions, in the background (the add-on's cron),
 * and keeps track of when to ask - the Quissly Shopify app's showcase runner (the Magento and
 * WooCommerce plugins have the same).
 *
 * Quissly makes the suggestions (`POST /v2beta/qsearch/suggestions_enrich`, SuggestionsEnrich):
 * it writes them in each language, checks each one finds products, and writes them into the
 * QSearch widget_config itself - keeping any list the merchant edited, so nothing here needs to
 * know about the merchant's own lists. What this sends is what only CS-Cart knows: the store's
 * languages (the storefront default first, at most SuggestionsEnrich::MAX_LANGUAGES) and its
 * catalog as each language reads it.
 *
 * When: after the first catalog sync (before it there is nothing to search). Right after the
 * sync the index can still be filling - Quissly answers "insufficient" and this asks again in
 * an hour; after MAX_ATTEMPTS it accepts a shorter list. Done once Quissly answers written or
 * unchanged, until a language is added or the generator changes (lists from an older one are
 * made again once).
 *
 * The catalog and the call are passed in (callables), so this is unit-tested without CS-Cart.
 */
final class ShowcaseRunner
{
    public const STATE = 'showcase';
    private const LOCK_SECONDS = 600;
    private const HOUR = 3600;

    /**
     * @return array<string, mixed> generated_at, generator, languages_done, generated,
     *                              generated_languages, attempts, next_at, locked_at, last_error, last_status
     */
    public static function state(SyncStore $store): array
    {
        return (array) $store->getState(self::STATE);
    }

    /** Quissly has made the main list. */
    public static function finished(SyncStore $store): bool
    {
        return !empty(self::state($store)['generated_at']);
    }

    /**
     * Something to ask for: never asked, a language not asked for yet, or an older generator.
     *
     * @param list<string> $languages
     */
    public static function outstanding(SyncStore $store, array $languages): bool
    {
        $state = self::state($store);

        return empty($state['generated_at'])
            || ($state['generator'] ?? '') !== SuggestionsEnrich::GENERATOR
            || array_diff($languages, (array) ($state['languages_done'] ?? [])) !== [];
    }

    /**
     * What Quissly generated last, as it answered here ([] when nothing yet). Configuration
     * prefers Quissly's own copy (client_specific_queries_generated).
     *
     * @return list<string>
     */
    public static function generated(SyncStore $store, ?string $language = null): array
    {
        $state = self::state($store);

        return SearchSuggestions::clean($language === null
            ? ($state['generated'] ?? [])
            : ($state['generated_languages'][$language] ?? []));
    }

    /**
     * The cron tick: ask Quissly when due. Never throws.
     *
     * @param list<string>                                   $languages the storefront languages, default first
     * @param callable(string): array                        $facts     catalog facts in a language
     * @param callable(array): ?array{status:int, body:string} $call    the signed POST (null = no answer)
     * @return string what happened
     */
    public static function tick(SyncStore $store, bool $ready, int $now, array $languages, callable $facts, callable $call): string
    {
        if (!$ready) {
            return 'not_ready';
        }
        $languages = array_slice(array_values(array_unique($languages)), 0, SuggestionsEnrich::MAX_LANGUAGES);
        if ($languages === [] || !self::outstanding($store, $languages)) {
            return 'finished';
        }
        $state = self::state($store);
        if ((int) ($state['next_at'] ?? 0) > $now) {
            return 'not_due';
        }
        if ((int) ($state['locked_at'] ?? 0) > $now - self::LOCK_SECONDS) {
            return 'locked';
        }
        self::update($store, ['locked_at' => $now]);

        try {
            return self::ask($store, $now, $languages, $state, $facts, $call);
        } catch (\Throwable $e) {
            return self::retry($store, $now, self::HOUR, substr($e->getMessage(), 0, 300), 'error');
        }
    }

    /**
     * @param list<string> $languages
     * @param array<string, mixed> $state
     */
    private static function ask(SyncStore $store, int $now, array $languages, array $state, callable $facts, callable $call): string
    {
        $catalogs = [];
        foreach ($languages as $language) {
            $catalogs[$language] = $facts($language);
        }
        $attempts = (int) ($state['attempts'] ?? 0) + 1;
        $regenerate = !empty($state['generated_at']) && ($state['generator'] ?? '') !== SuggestionsEnrich::GENERATOR;
        $body = SuggestionsEnrich::body(
            $languages[0],
            $languages,
            $catalogs,
            ['main' => self::generated($store), 'by_language' => array_filter(array_map([SearchSuggestions::class, 'clean'], (array) ($state['generated_languages'] ?? [])))],
            $regenerate ? 'regenerate' : 'fill',
            $attempts >= SuggestionsEnrich::MAX_ATTEMPTS
        );

        $response = $call($body);
        $answer = $response === null ? null : json_decode($response['body'], true);
        if ($response === null || $response['status'] < 200 || $response['status'] >= 300 || !is_array($answer)) {
            return self::retry($store, $now, self::HOUR, 'suggestions_enrich ' . ($response === null ? 'no answer' : 'http=' . $response['status']), 'error');
        }

        $status = (string) ($answer['status'] ?? '');
        $next = SuggestionsEnrich::schedule($status);
        if (!$next['done']) {
            return self::retry($store, $now, $next['retry'], $next['reason'], $status !== '' ? $status : 'error');
        }

        $lists = [];
        foreach ($languages as $language) {
            $lists[$language] = SearchSuggestions::clean($answer['languages'][$language] ?? []);
        }
        $main = SearchSuggestions::clean($answer['main'] ?? []);
        self::update($store, [
            'generated_at'        => (int) ($state['generated_at'] ?? 0) ?: $now,
            'generator'           => SuggestionsEnrich::GENERATOR,
            'languages_done'      => $languages,
            'generated'           => $main !== [] ? $main : ($state['generated'] ?? []),
            'generated_languages' => $lists,
            'attempts'            => 0,
            'next_at'             => 0,
            'locked_at'           => 0,
            'last_error'          => '',
            'last_status'         => $status,
        ]);

        return $status;
    }

    private static function retry(SyncStore $store, int $now, int $in, string $error, string $outcome): string
    {
        self::update($store, [
            'attempts'    => (int) (self::state($store)['attempts'] ?? 0) + 1,
            'next_at'     => $now + $in,
            'locked_at'   => 0,
            'last_error'  => $error,
            'last_status' => $outcome,
        ]);

        return $outcome;
    }

    /** @param array<string, mixed> $changes */
    private static function update(SyncStore $store, array $changes): void
    {
        $store->setState(self::STATE, array_merge(self::state($store), $changes));
    }

    private function __construct()
    {
    }
}
