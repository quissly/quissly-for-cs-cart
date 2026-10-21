<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * CS-Cart's price slider on a Quissly-answered search (pure; SearchInterceptor is the glue).
 *
 * The slider is a product filter of field_type 'P'. It travels in `features_hash` as
 * `<filter_id>-<from>-<to>-<currency>` (the currency shown, e.g. `2-300-1000-EUR`; without
 * it when the theme sends none), several filters joined by `_` (`2-300-1000-EUR_7-41-42` is
 * the price and two variants of filter 7) - fn_parse_filters_hash / fn_generate_filter_hash,
 * the same on 4.11 and 4.21. Before `get_products_before_select`, fn_get_products has already
 * turned it into `price_from` / `price_to` in the store's PRIMARY currency
 * (fn_generate_filter_field_params: each value floored to the filter's `round_to`, then
 * multiplied by the shown currency's coefficient and rounded to that currency's decimals),
 * which a search form may also send directly. Those two values are what CS-Cart's own SQL
 * compares, inclusively, with `prices.price` - and what Quissly gets, as a range on
 * `discounted_price` (the price the catalog sync sends: CS-Cart's `price` for a guest, no
 * taxes, no catalog promotions - the same column the native filter reads for a guest).
 *
 * Only a search whose refinements are ALL price is answered by Quissly; anything else in the
 * hash stays CS-Cart's (SearchInterceptor::refinement).
 */
final class PriceFilter
{
    /** product_filters.field_type of the price filter (ProductFilterProductFieldTypes::PRICE). */
    public const FIELD_TYPE = 'P';

    /** The Quissly field the range applies to (each result row's selling price). */
    public const FIELD = 'discounted_price';

    private const FILTER_SEPARATOR = '_';
    private const VALUE_SEPARATOR = '-';

    /**
     * features_hash as fn_parse_filters_hash reads it: filter id => its values.
     *
     * @return array<string, list<string>>
     */
    public static function parse(string $hash): array
    {
        $filters = [];
        if ($hash === '') {
            return $filters;
        }
        foreach (explode(self::FILTER_SEPARATOR, $hash) as $part) {
            $values = explode(self::VALUE_SEPARATOR, $part);
            $id = (string) array_shift($values);
            $filters[$id] = $values;
        }

        return $filters;
    }

    /**
     * The filter ids in features_hash (well-formed positive ones only).
     *
     * @return list<int>
     */
    public static function filterIds(string $hash): array
    {
        $ids = [];
        foreach (array_keys(self::parse($hash)) as $id) {
            if (preg_match('/^[1-9][0-9]*$/', (string) $id) === 1) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * True when every filter in features_hash is one of $priceFilterIds (active price filters)
     * carrying a numeric from-to range, with or without a currency. Anything else - another
     * filter, a malformed entry - is a refinement Quissly cannot apply.
     *
     * @param list<int> $priceFilterIds
     */
    public static function onlyPrice(string $hash, array $priceFilterIds): bool
    {
        $filters = self::parse($hash);
        if ($filters === []) {
            return false;
        }
        foreach ($filters as $id => $values) {
            if (preg_match('/^[1-9][0-9]*$/', (string) $id) !== 1 || !in_array((int) $id, $priceFilterIds, true)) {
                return false;
            }
            if (count($values) < 2 || count($values) > 3 || !is_numeric(trim($values[0])) || !is_numeric(trim($values[1]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Quissly's range from fn_get_products' price_from / price_to (primary currency), null when
     * neither is a number - CS-Cart applies only a numeric one (fn_is_numeric), so does this.
     * $convert is CS-Cart's fn_convert_price, which its own SQL runs each value through.
     *
     * @param mixed $from
     * @param mixed $to
     * @return array{gte?:float,lte?:float}|null
     */
    public static function range($from, $to, ?callable $convert = null): ?array
    {
        $range = [];
        foreach (['gte' => $from, 'lte' => $to] as $bound => $value) {
            if (is_scalar($value) && is_numeric(trim((string) $value))) {
                $number = trim((string) $value);
                $range[$bound] = (float) ($convert !== null ? $convert($number) : $number);
            }
        }

        return $range === [] ? null : $range;
    }

    /**
     * The QSearch `filters` body for a range (FilterModel), [] for none.
     *
     * @param array{gte?:float,lte?:float}|null $range
     * @return array<string, mixed>
     */
    public static function body(?array $range): array
    {
        return $range === null || $range === [] ? [] : ['range' => [self::FIELD => $range]];
    }

    /**
     * The products the slider's bounds are read from on a Quissly-answered text search, and
     * what to remember for the shopper's next request. Quissly answers one page of ids, so its
     * whole result set is known only when that page holds all of it (unfiltered, page 1, every
     * result on it): those ids, remembered, so the bounds stay put once the shopper moves the
     * slider and the answer narrows. Otherwise null: the whole storefront catalog, which holds
     * every result's price (CS-Cart's keyword match would hide the slider, or cut off results,
     * whenever Quissly finds what it does not).
     *
     * @param mixed     $remembered what this returned last time (session), anything when none
     * @param list<int> $ids        this answer's ids
     * @return array{0: list<int>|null, 1: array{q:string,ids:list<int>}|null} [bounds ids, to remember]
     */
    public static function boundsSet($remembered, string $key, bool $narrowed, int $page, array $ids, int $total): array
    {
        if ($narrowed) {
            $known = is_array($remembered) && ($remembered['q'] ?? null) === $key && is_array($remembered['ids'] ?? null)
                ? array_values(array_filter(array_map('intval', $remembered['ids']), static fn (int $id): bool => $id > 0))
                : [];

            return $known === [] ? [null, is_array($remembered) ? $remembered : null] : [$known, $remembered];
        }
        if ($page === 1 && $ids !== [] && count($ids) >= $total) {
            return [$ids, ['q' => $key, 'ids' => $ids]];
        }

        return [null, null];
    }

    private function __construct()
    {
    }
}
