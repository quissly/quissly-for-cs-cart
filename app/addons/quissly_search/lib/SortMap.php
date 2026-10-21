<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * Maps CS-Cart storefront sort controls to Quissly's sort_by / sort_type codes,
 * and back to the effective CS-Cart sort key/order to render.
 *
 * Quissly's sort_by codes, as its search engine reads them (codes 3-5 since 2026-10-20):
 *
 *   0 = relevance (default)   1 = price (discounted_price)   2 = discount (discount_percent)
 *   3 = title (case-insensitive name)   4 = popularity   5 = created_at
 *
 * Any other number is relevance. sort_type: 1 = asc, 2 = desc. Until 2026-10-19 this map
 * sent 2 for price - read as "price" from a June test in which sorting by discount also
 * reordered the results - so a price sort ordered by discount (found testing PrestaShop).
 * The engine sorts on discounted_price, which ProductMapper therefore always sends.
 *
 * Quissly sorts ALL matches (after collapsing variants to one row per parent) before it
 * paginates, reading each field from the product's metadata; a product without the field
 * goes last in both directions. ProductMapper sends `popularity` and `created_at` (ints) on
 * every product for codes 4 and 5; title and the prices are on every record anyway.
 *
 * CS-Cart's native storefront sorts (fn_get_products_sorting) and what they become:
 *
 *   product (name) -> 3   price -> 1   popularity -> 4   timestamp (date) -> 5
 *   on_sale (the Bestsellers & on-sale add-on: 100 - price * 100 / list_price) -> 2
 *
 * each in the order the shopper chose. `position` (the merchant's own order) and `null` have
 * no Quissly sort: relevance, and kept out of the dropdown. Relevance is the default: a
 * search page whose shopper has not picked a sort shows Quissly's order even when the
 * store's default product sorting is, say, newest first ({@see shopperPicked()}).
 *
 * Pure logic, no CS-Cart dependency except the optional `__()` label lookup in
 * registerRelevanceSorting — locked by a known-input/known-output test.
 */
final class SortMap
{
    /** Quissly sort_by codes. */
    public const Q_RELEVANCE  = 0;
    public const Q_PRICE      = 1;
    public const Q_DISCOUNT   = 2;
    public const Q_TITLE      = 3;
    public const Q_POPULARITY = 4;
    public const Q_CREATED_AT = 5;

    /** Quissly sort_type codes. */
    public const T_ASC  = 1;
    public const T_DESC = 2;

    /** CS-Cart sort_by key used to render Quissly's natural relevance order. */
    public const RELEVANCE_KEY = 'quissly_relevance';

    /**
     * The CS-Cart sort keys Quissly sorts by: key => [Quissly code, CS-Cart's default order].
     */
    private const HONORED = [
        'product'    => [self::Q_TITLE, 'asc'],
        'price'      => [self::Q_PRICE, 'asc'],
        'popularity' => [self::Q_POPULARITY, 'desc'],
        'timestamp'  => [self::Q_CREATED_AT, 'desc'],
        'on_sale'    => [self::Q_DISCOUNT, 'desc'],
    ];

    /**
     * The search page's dropdown when the store's own list is empty: CS-Cart's install
     * default (Appearance > Available product list sortings) plus discount, newest first.
     */
    private const DEFAULT_SORTINGS = [
        'product-asc', 'product-desc', 'price-asc', 'price-desc', 'timestamp-desc', 'popularity-desc', 'on_sale-desc',
    ];

    /**
     * Resolve a CS-Cart storefront sort selection to the Quissly request codes
     * plus the effective CS-Cart sort key/order to render in the dropdown.
     *
     * A sort Quissly has a code for keeps the shopper's order (CS-Cart's default
     * order for that sort when none is given); everything else (position, none, an
     * unknown key, nothing) is relevance.
     *
     * @return array{key:string,order:string,sort_by:int,sort_type:int}
     */
    public static function resolve(?string $csSortBy, ?string $csSortOrder): array
    {
        $key = strtolower((string) $csSortBy);

        if (isset(self::HONORED[$key])) {
            [$code, $default] = self::HONORED[$key];
            $given = strtolower((string) $csSortOrder);
            $order = $given === 'asc' || $given === 'desc' ? $given : $default;

            return [
                'key'       => $key,
                'order'     => $order,
                'sort_by'   => $code,
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
     * Whether the shopper chose a sort for this search: the request carries one, or an
     * earlier search in the session did (CS-Cart's search controller keeps the choice in
     * session['search_params'], fn_change_session_param). Without one, fn_get_products
     * has already filled in the store's default product sorting (newest first,
     * popularity...) by the time the interception runs - meant for category pages, not for
     * a search, which shows Quissly's relevance.
     *
     * @param array<string,mixed> $request       $_REQUEST
     * @param array<string,mixed> $searchSession session['search_params']
     */
    public static function shopperPicked(array $request, array $searchSession): bool
    {
        // The controller's own test: fn_change_session_param, then `if ($sort_by = ...)`.
        $picked = isset($request['sort_by']) ? $request['sort_by'] : ($searchSession['sort_by'] ?? null);

        return is_scalar($picked) && !empty($picked);
    }

    /**
     * The `available_product_list_sortings` map (`'<key>-<order>' => 'Y'`) for an
     * intercepted Quissly search page: relevance, then the sorts Quissly honors that the
     * store offers (its own Appearance setting, $store), or {@see DEFAULT_SORTINGS} when
     * the store's list is empty. Position and none are never offered.
     *
     * @param mixed $store the store's settings.Appearance.available_product_list_sortings
     * @return array<string,string>
     */
    public static function availableSortings($store = []): array
    {
        $out = [self::RELEVANCE_KEY . '-asc' => 'Y'];
        $offered = [];
        if (is_array($store)) {
            foreach ($store as $option => $on) {
                if ($on === 'Y') {
                    $offered[] = (string) $option;
                }
            }
        }
        $candidates = $offered !== [] ? $offered : self::DEFAULT_SORTINGS;
        foreach (self::HONORED as $key => $unused) {
            foreach (['asc', 'desc'] as $order) {
                if (in_array($key . '-' . $order, $candidates, true)) {
                    $out[$key . '-' . $order] = 'Y';
                }
            }
        }

        return $out;
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
