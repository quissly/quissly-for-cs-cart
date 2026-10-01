<?php

declare(strict_types=1);

namespace Quissly\Search\Showcase;

use Quissly\Search\SearchSuggestions;
use Quissly\Search\Sync\SyncStore;

/**
 * Generates a store's search bar suggestions from its own catalog - once, in the background
 * (the add-on's cron), after the first catalog sync (port of the Quissly Shopify app's; the
 * WooCommerce plugin has the same):
 *
 *  1. read catalog facts (the 100 most recently changed active products);
 *  2. build candidates (ShowcaseQueries) and RUN each through search - kept only with at
 *     least 2 results (up to 12 searches);
 *  3. fewer than 3 kept = the index may still be filling: retry in an hour (6 attempts; the
 *     last keeps whatever it found, or - nothing at all - gives up, where the Shopify
 *     runner retries for ever);
 *  4. write them as the list (SearchSuggestions) ONLY while Quissly holds no list - a
 *     merchant's own list is never overwritten, even one written elsewhere.
 *
 * A merchant who saves their own list (an actual change) ends it for good; saving the page
 * unchanged does not (the Shopify app stops on any save). The generated list is kept for
 * the settings page's "use the generated suggestions".
 *
 * The catalog, search and Quissly are passed in (callables), so this is unit-tested
 * without CS-Cart.
 */
final class ShowcaseRunner
{
    public const STATE = 'showcase';
    public const MIN_ACCEPTABLE = 3;
    public const MAX_ATTEMPTS = 6;
    private const LOCK_SECONDS = 600;
    private const HOUR = 3600;
    private const DAY = 86400;

    /** @return array<string, mixed> generated, generated_at, attempts, next_at, locked_at, last_error, merchant_saved */
    public static function state(SyncStore $store): array
    {
        return (array) $store->getState(self::STATE);
    }

    /** Nothing left to do: generated (or given up), or the merchant wrote their own list. */
    public static function finished(SyncStore $store): bool
    {
        $state = self::state($store);

        return !empty($state['generated_at']) || !empty($state['merchant_saved']);
    }

    /** @return list<string> the generated list, [] when none */
    public static function generated(SyncStore $store): array
    {
        return SearchSuggestions::clean(self::state($store)['generated'] ?? []);
    }

    /** A merchant wrote their own list: generation never writes after this. */
    public static function merchantSaved(SyncStore $store): void
    {
        self::update($store, ['merchant_saved' => true]);
    }

    /**
     * The cron tick: generate when due. Never throws.
     *
     * @param callable(): array                                           $facts  catalog facts (ShowcaseQueries)
     * @param callable(string): int                                        $search query => number of results (may throw)
     * @param callable(): ?array{enabled:bool, queries:list<string>}       $read   what Quissly holds (null = unreadable)
     * @param callable(bool, list<string>): string                         $write  '' when written, else the reason
     * @return string what happened
     */
    public static function tick(SyncStore $store, bool $ready, int $now, callable $facts, callable $search, callable $read, callable $write): string
    {
        if (self::finished($store)) {
            return 'finished';
        }
        if (!$ready) {
            return 'not_ready';
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
            $candidates = ShowcaseQueries::buildCandidates($facts());
            if ($candidates === []) {
                return self::retry($store, $now, self::DAY, 'No active products to build suggestions from.', 'no_products');
            }
            $accepted = ShowcaseQueries::validate($candidates, $search);

            $attempts = (int) ($state['attempts'] ?? 0) + 1;
            $lastAttempt = $attempts >= self::MAX_ATTEMPTS;
            if ($lastAttempt && $accepted === []) {
                self::update($store, [
                    'generated' => [], 'generated_at' => $now, 'attempts' => $attempts, 'locked_at' => 0,
                    'last_error' => 'No candidate search returned results after ' . $attempts . ' attempts.',
                ]);

                return 'gave_up';
            }
            if (count($accepted) < self::MIN_ACCEPTABLE && !($lastAttempt && $accepted !== [])) {
                // Most likely the search index is still filling after the first sync.
                return self::retry($store, $now, self::HOUR, 'Only ' . count($accepted) . ' of ' . count($candidates) . ' candidate searches returned results.', 'only_' . count($accepted) . '_validated');
            }

            $queries = ShowcaseQueries::pick($accepted);
            $current = $read();
            if ($current === null) {
                return self::retry($store, $now, self::HOUR, 'Quissly could not be read.', 'unreadable');
            }
            $done = ['generated' => $queries, 'generated_at' => $now, 'attempts' => $attempts, 'locked_at' => 0, 'last_error' => ''];
            if ($current['queries'] !== [] || !empty(self::state($store)['merchant_saved'])) {
                // Someone already wrote a list: keep theirs, remember ours for "use generated".
                self::update($store, $done);

                return 'kept_existing';
            }
            $error = $write($current['enabled'], $queries);
            if ($error !== '') {
                return self::retry($store, $now, self::HOUR, $error, 'write_failed');
            }
            self::update($store, $done);

            return 'written';
        } catch (\Throwable $e) {
            return self::retry($store, $now, self::HOUR, substr($e->getMessage(), 0, 300), 'error');
        }
    }

    private static function retry(SyncStore $store, int $now, int $in, string $error, string $outcome): string
    {
        self::update($store, [
            'attempts'   => (int) (self::state($store)['attempts'] ?? 0) + 1,
            'next_at'    => $now + $in,
            'locked_at'  => 0,
            'last_error' => $error,
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
