<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * Maps CS-Cart storefront sort controls to Quissly's sort_by / sort_type codes,
 * and back to the effective CS-Cart sort key/order to render.
 *
 * Quissly's sort_by codes, as its search engine reads them (since 2026-06-22):
 *
 *   0 = relevance (default)   1 = price (discounted_price)   2 = discount (discount_percent)
 *
 * Any other number is relevance. sort_type: 1 = asc, 2 = desc. Until 2026-10-19 this map
 * sent 2 for price - read as "price" from a June test in which sorting by discount also
 * reordered the results - so a price sort ordered by discount (found testing PrestaShop).
 * The engine sorts on discounted_price, which ProductMapper therefore always sends.
 *
 * Of CS-Cart's native storefront sort controls (name/price/date/popularity), only
 * `price` maps to a Quissly-honored sort. Relevance is the default (Quissly's
 * natural order). Discount is honored by Quissly but has no native CS-Cart control,
 * so it is not exposed (that would require a custom UI control — out of v1 scope).
 *
 * Pure logic, no CS-Cart dependency except the optional `__()` label lookup in
 * registerRelevanceSorting — locked by a known-input/known-output test.
 */
final class SortMap
{
    /** Quissly sort_by codes. */
    public const Q_RELEVANCE = 0;
    public const Q_PRICE     = 1;
    public const Q_DISCOUNT  = 2;

    /** Quissly sort_type codes. */
    public const T_ASC  = 1;
    public const T_DESC = 2;

    /** CS-Cart sort_by key used to render Quissly's natural relevance order. */
    public const RELEVANCE_KEY = 'quissly_relevance';

    /**
     * Resolve a CS-Cart storefront sort selection to the Quissly request codes
     * plus the effective CS-Cart sort key/order to render in the dropdown.
     *
     * Only Quissly-honored sorts get a dedicated code; everything else (name,
     * date, popularity, none) falls back to relevance — those return relevance
     * order from Quissly anyway and are kept out of the dropdown.
     *
     * @return array{key:string,order:string,sort_by:int,sort_type:int}
     */
    public static function resolve(?string $csSortBy, ?string $csSortOrder): array
    {
        $order = strtolower((string) $csSortOrder) === 'desc' ? 'desc' : 'asc';

        if (strtolower((string) $csSortBy) === 'price') {
            return [
                'key'       => 'price',
                'order'     => $order,
                'sort_by'   => self::Q_PRICE,
                'sort_type' => $order === 'desc' ? self::T_DESC : self::T_ASC,
            ];
        }

        // Relevance (default). Quissly ignores sort_type for relevance; send a
        // stable default. Rendered ascending by the returned ID sequence.
        return [
            'key'       => self::RELEVANCE_KEY,
            'order'     => 'asc',
            'sort_by'   => self::Q_RELEVANCE,
            'sort_type' => self::T_DESC,
        ];
    }

    /**
     * The `available_product_list_sortings` map (`'<key>-<order>' => 'Y'`) for an
     * intercepted Quissly search page: only the honored options (relevance +
     * price asc/desc). Date, popularity, and name are excluded.
     *
     * @return array<string,string>
     */
    public static function availableSortings(): array
    {
        return [
            self::RELEVANCE_KEY . '-asc' => 'Y',
            'price-asc'                  => 'Y',
            'price-desc'                 => 'Y',
        ];
    }

    /**
     * Register the synthetic `quissly_relevance` option in the products-sorting
     * label map (fn_get_products_sorting) so the storefront dropdown can render it.
     * `desc => false` keeps it to a single (ascending) entry — relevance has no
     * reverse. Idempotent.
     *
     * @param array<string,mixed> $sorting
     */
    public static function registerRelevanceSorting(array &$sorting): void
    {
        if (isset($sorting[self::RELEVANCE_KEY])) {
            return;
        }

        $sorting[self::RELEVANCE_KEY] = [
            'description'   => function_exists('__') ? __('sort_by_' . self::RELEVANCE_KEY . '_asc') : 'Relevance',
            'default_order' => 'asc',
            'desc'          => false,
        ];
    }

    private function __construct()
    {
    }
}
