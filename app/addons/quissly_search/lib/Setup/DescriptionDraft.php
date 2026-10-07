<?php

declare(strict_types=1);

namespace Quissly\Search\Setup;

/**
 * A starting point for the workspace description on Quissly Setup, drafted from the store.
 *
 * A port of the Shopify app's `app/lib/description-draft.ts` (composeDescriptionDraft): the same
 * sentences in the same order, locked by its own test cases (tests/Unit/Setup/DescriptionDraftTest),
 * and the same logic as quissly-for-magento's and quissly-for-woocommerce's copies. The
 * description becomes the Quissly organization's; merchants facing an empty box write thin ones,
 * so Setup offers a draft they edit instead. Facts only, no model call, so it cannot invent
 * anything; the least telling sentences are dropped first when it runs long. Pure.
 */
final class DescriptionDraft
{
    /** The middleware rejects organization descriptions over 500 characters. */
    public const MAX_LENGTH = 500;

    /** The draft stays well under the limit, leaving the merchant room to add. */
    private const TARGET_LENGTH = 400;
    private const MAX_META_LENGTH = 280;
    private const MAX_KINDS = 4;

    /** Store-wide categories that say nothing about what the store sells (matched on the URL key). */
    private const GENERIC = '/^(all|all-products|frontpage|home|homepage|home-page|sale|sales|on-sale|clearance'
        . '|new|new-in|new-arrivals?|latest|best-?sellers?|bestsellers|featured.*|trending|gift-?cards?'
        . '|uncategorized|default-category)$/';

    /**
     * The draft text, or null when the store gives nothing worth saying.
     *
     * @param array $input shop_name, meta_description, product_count, count_is_lower_bound,
     *     product_kinds (list of strings), collections (list of {title, handle, product_count}),
     *     location ({city, country}|null), prices ({min, max, currency}|null), vendors (list)
     * @return string|null
     */
    public static function compose(array $input): ?string
    {
        $name = self::clean($input['shop_name'] ?? null);
        $subject = $name !== '' ? $name : 'The store';
        $meta = self::clean($input['meta_description'] ?? null);
        $usefulMeta = self::len($meta) >= 30 && mb_strtolower($meta) !== mb_strtolower($name);

        $kinds = self::topKinds((array)($input['product_kinds'] ?? []));
        $collections = self::topCollections((array)($input['collections'] ?? []));
        $range = $kinds !== [] ? $kinds : $collections;
        $count = self::countPhrase((int)($input['product_count'] ?? 0), !empty($input['count_is_lower_bound']));
        $where = self::locationPhrase($input['location'] ?? null);

        // What it is and what it sells - the sentence the rest hangs off.
        $offers = '';
        if ($count !== '' && $range !== []) {
            $offers = 'offers ' . $count . ', including ' . self::listOf($range);
        } elseif ($count !== '') {
            $offers = 'offers ' . $count;
        } elseif ($range !== []) {
            $offers = 'offers ' . self::listOf($range);
        }
        $catalog = '';
        if ($where !== '' && $offers !== '') {
            $catalog = $subject . ' is based in ' . $where . ' and ' . $offers . '.';
        } elseif ($offers !== '') {
            $catalog = $subject . ' ' . $offers . '.';
        } elseif ($where !== '') {
            $catalog = $subject . ' is based in ' . $where . '.';
        }

        $priceLine = self::pricePhrase($input['prices'] ?? null);

        $shopKey = mb_strtolower($name);
        $vendors = array_values(array_filter(
            (array)($input['vendors'] ?? []),
            static function ($vendor) use ($shopKey): bool {
                return mb_strtolower(self::clean((string)$vendor)) !== $shopKey;
            }
        ));
        $brands = self::topKinds($vendors, 3);
        $brandLine = $brands !== [] ? 'Brands include ' . self::listOf($brands) . '.' : '';

        // Categories as extra colour, only when kinds made the catalog line and they name
        // something the kinds did not.
        $kindKeys = array_map('mb_strtolower', $kinds);
        $extra = $kinds !== []
            ? array_slice(array_values(array_filter($collections, static function (string $c) use ($kindKeys): bool {
                return !in_array(mb_strtolower($c), $kindKeys, true);
            })), 0, 3)
            : [];
        $collectionLine = $extra !== [] ? 'Collections include ' . self::listOf($extra) . '.' : '';

        // Facts only; with none at all there is nothing honest to draft.
        if (!$usefulMeta && $catalog === '') {
            return null;
        }

        // Most telling first; the tail is dropped first when the draft runs long.
        $facts = array_values(array_filter([$catalog, $priceLine, $brandLine, $collectionLine], 'strlen'));
        $fit = static function (string $metaPart) use ($facts): string {
            $kept = $facts;
            $text = self::join($metaPart, $kept);
            while (self::len($text) > self::TARGET_LENGTH && count($kept) > 1) {
                array_pop($kept);
                $text = self::join($metaPart, $kept);
            }
            return $text;
        };

        $draft = $fit($usefulMeta ? self::endSentence(self::shorten($meta, self::MAX_META_LENGTH)) : '');
        if (self::len($draft) > self::TARGET_LENGTH && $usefulMeta) {
            // Still long: the meta description is the flexible part.
            $room = self::TARGET_LENGTH - ($catalog !== '' ? self::len($catalog) + 1 : 0);
            $draft = $room >= 60
                ? $fit(self::endSentence(self::shorten($meta, $room - 1)))
                : self::shorten($draft, self::TARGET_LENGTH);
        }
        if (self::len($draft) > self::TARGET_LENGTH) {
            $draft = self::shorten($draft, self::TARGET_LENGTH);
        }

        return mb_substr($draft, 0, self::MAX_LENGTH);
    }

    /**
     * Most common kinds first, case-insensitive, keeping the most used spelling.
     *
     * Ties keep first-seen order, as the Shopify app's stable sort does.
     *
     * @param array $kinds
     * @param int $limit
     * @return string[]
     */
    public static function topKinds(array $kinds, int $limit = self::MAX_KINDS): array
    {
        $groups = [];
        foreach ($kinds as $raw) {
            $kind = self::clean((string)$raw);
            if ($kind === '') {
                continue;
            }
            $key = mb_strtolower($kind);
            if (!isset($groups[$key])) {
                $groups[$key] = ['count' => 0, 'order' => count($groups), 'spellings' => []];
            }
            $groups[$key]['count']++;
            $groups[$key]['spellings'][$kind] = ($groups[$key]['spellings'][$kind] ?? 0) + 1;
        }
        $groups = array_values($groups);
        usort($groups, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: $a['order'] <=> $b['order'];
        });
        $out = [];
        foreach (array_slice($groups, 0, $limit) as $group) {
            $best = null;
            foreach ($group['spellings'] as $spelling => $n) {
                if ($best === null || $n > $group['spellings'][$best]) {
                    $best = (string)$spelling;
                }
            }
            $out[] = $best;
        }
        return $out;
    }

    /**
     * Categories by size, without the store-wide ones.
     *
     * Unlike Shopify's collections, category names repeat (Women > Tops, Men > Tops): one
     * name is listed once, with the counts added.
     *
     * @param array $collections
     * @return string[]
     */
    private static function topCollections(array $collections): array
    {
        $kept = [];
        foreach ($collections as $i => $c) {
            $title = self::clean((string)($c['title'] ?? ''));
            if ((int)($c['product_count'] ?? 0) > 0 && $title !== ''
                && !preg_match(self::GENERIC, mb_strtolower((string)($c['handle'] ?? '')))) {
                $key = mb_strtolower($title);
                if (!isset($kept[$key])) {
                    $kept[$key] = ['title' => $title, 'count' => 0, 'order' => $i];
                }
                $kept[$key]['count'] += (int)$c['product_count'];
            }
        }
        $kept = array_values($kept);
        usort($kept, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: $a['order'] <=> $b['order'];
        });
        return array_map(static function (array $c): string {
            return $c['title'];
        }, array_slice($kept, 0, self::MAX_KINDS));
    }

    /**
     * Collapse whitespace and trim.
     *
     * @param string|null $text
     * @return string
     */
    private static function clean(?string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', (string)$text));
    }

    /**
     * Length in characters.
     *
     * @param string $text
     * @return int
     */
    private static function len(string $text): int
    {
        return mb_strlen($text);
    }

    /**
     * Cut at a sentence end if one is close, otherwise at a word, never mid-word.
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    private static function shorten(string $text, int $max): string
    {
        if (self::len($text) <= $max) {
            return $text;
        }
        $head = mb_substr($text, 0, $max);
        $sentenceEnd = max(self::lastPos($head, '. '), self::lastPos($head, '! '), self::lastPos($head, '? '));
        if ($sentenceEnd >= $max * 0.5) {
            return mb_substr($head, 0, $sentenceEnd + 1);
        }
        $wordEnd = self::lastPos($head, ' ');
        $cut = mb_substr($head, 0, $wordEnd > 0 ? $wordEnd : $max);
        return (string)preg_replace('/[\s,;:–-]+$/u', '', $cut) . '…';
    }

    /**
     * Last position of a needle, -1 when absent.
     *
     * @param string $haystack
     * @param string $needle
     * @return int
     */
    private static function lastPos(string $haystack, string $needle): int
    {
        $pos = mb_strrpos($haystack, $needle);
        return $pos === false ? -1 : $pos;
    }

    /**
     * End a sentence with a full stop unless it already has one.
     *
     * @param string $text
     * @return string
     */
    private static function endSentence(string $text): string
    {
        return preg_match('/[.!?…]$/u', $text) ? $text : $text . '.';
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param string[] $items
     * @return string
     */
    private static function listOf(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    /**
     * Join the meta part and the kept facts with spaces.
     *
     * @param string $metaPart
     * @param string[] $facts
     * @return string
     */
    private static function join(string $metaPart, array $facts): string
    {
        return implode(' ', array_values(array_filter(array_merge([$metaPart], $facts), 'strlen')));
    }

    /**
     * A price as en-US writes it: "1,240", "9.50".
     *
     * @param float $amount
     * @return string
     */
    private static function formatPrice(float $amount): string
    {
        return floor($amount) == $amount ? number_format($amount) : number_format($amount, 2);
    }

    /**
     * The price sentence.
     *
     * @param array|null $prices
     * @return string
     */
    private static function pricePhrase(?array $prices): string
    {
        $max = (float)($prices['max'] ?? 0);
        if ($prices === null || $max <= 0) {
            return '';
        }
        $min = (float)($prices['min'] ?? 0);
        $cur = self::clean((string)($prices['currency'] ?? ''));
        if ($min === $max || $min <= 0) {
            return $min === $max
                ? 'Products cost ' . self::formatPrice($max) . ' ' . $cur . '.'
                : 'Prices go up to ' . self::formatPrice($max) . ' ' . $cur . '.';
        }
        return 'Prices range from ' . self::formatPrice($min) . ' to ' . self::formatPrice($max) . ' ' . $cur . '.';
    }

    /**
     * "City, Country", or whichever is known.
     *
     * @param array|null $location
     * @return string
     */
    private static function locationPhrase(?array $location): string
    {
        $city = self::clean((string)($location['city'] ?? ''));
        $country = self::clean((string)($location['country'] ?? ''));
        if ($city !== '' && $country !== '' && mb_strtolower($city) !== mb_strtolower($country)) {
            return $city . ', ' . $country;
        }
        return $country !== '' ? $country : $city;
    }

    /**
     * "1,240 products", "10,000+ products", "1 product".
     *
     * @param int $count
     * @param bool $lowerBound
     * @return string
     */
    private static function countPhrase(int $count, bool $lowerBound): string
    {
        if ($count < 1) {
            return '';
        }
        if ($lowerBound) {
            return number_format($count) . '+ products';
        }
        return $count === 1 ? '1 product' : number_format($count) . ' products';
    }
}
