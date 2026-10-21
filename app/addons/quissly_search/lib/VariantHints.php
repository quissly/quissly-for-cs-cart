<?php

declare(strict_types=1);

namespace Quissly\Search;

use Quissly\Search\Sync\ProductMapper;
use Tygh\Registry;

/**
 * Per-request memory of which variant a search matched, for the search page being rendered.
 *
 * QSearch answers a product that has variants with the product's id AND the variant that
 * matched (`top_variant_id`, the id the catalog sync sent): a classic option value or colour x
 * size combination ("11-o1-11" = product 11, option 1, value 11; "11-o1-2-o2-8" = option 1
 * value 2 and option 2 value 8 - ProductMapper::combinationId) or a Variations child (its own
 * product id). CS-Cart renders the product, so without this a shopper searching "green tee in
 * large" got the tee's default picture and page. With it the card shows the matched colour's
 * picture and opens the product with every matched value selected
 * (`combination=<option>_<value>[_<option>_<value>]`, read by products.view on 4.11 and 4.21),
 * or shows the matched child's picture and opens the child's page - as the Shopify app
 * (?variant=) and the Magento plugin (VariantHints there) do.
 *
 * Written by SearchInterceptor for the one intercepted text search; read by the
 * `gather_additional_products_data_post` hook (pictures) and the `url_post` hook (links).
 * Both read nothing outside the storefront search page (products.search), so a category
 * page, a block, the cart, an email or the admin can never get a hint, whatever was recorded;
 * and only what the products' own data confirms (the option and value still active on that
 * product, the child an active variation of it) is used. The picture is swapped once, in the
 * search controller's own gather of the results; a link is changed for every
 * `products.view?product_id=<that product>` the page builds.
 */
final class VariantHints
{
    /** @var array<int, array{options:array<int,int>}|array{child:int}> product id => what matched */
    private static array $matched = [];

    /** @var array<int, array{combination:string}|array{child:int}> confirmed by the product's data */
    private static array $links = [];

    private static bool $picturesDone = false;

    /** Test seam: replaces the "is this the storefront search page" check; null = the real one. */
    private static ?\Closure $pageCheck = null;

    /**
     * A top_variant_id as a hint for product $productId, null when it is none: a Variations
     * child ("57", not the product itself) or one of this product's option values or
     * combinations ("<product>-o<option>-<value>[-o<option>-<value>]": `options`, option id =>
     * value id). Anything else - absent, malformed, another product's value - is no hint (the
     * product renders as it would without one).
     *
     * @param mixed $topVariantId
     * @return array{options:array<int,int>}|array{child:int}|null
     */
    public static function parse(int $productId, $topVariantId): ?array
    {
        if (is_int($topVariantId)) {
            $topVariantId = (string) $topVariantId;
        }
        if (!is_string($topVariantId)) {
            return null;
        }
        $id = trim($topVariantId);
        if (preg_match('/^[1-9]\d{0,18}$/', $id) === 1) {
            return (int) $id !== $productId ? ['child' => (int) $id] : null;
        }
        $parsed = ProductMapper::parseVariantId($id);

        return $parsed !== null && $parsed['product_id'] === $productId ? ['options' => $parsed['options']] : null;
    }

    /**
     * Record this search's matches (Result::$variants).
     *
     * @param array<int, array{options:array<int,int>}|array{child:int}> $variants
     */
    public static function remember(array $variants): void
    {
        foreach ($variants as $productId => $hint) {
            if ((int) $productId > 0 && is_array($hint)) {
                self::$matched[(int) $productId] = $hint;
            }
        }
    }

    /**
     * The Variations children this page's matches name (to look up), none off the search page.
     *
     * @return list<int>
     */
    public static function childIds(): array
    {
        if (!self::onSearchPage()) {
            return [];
        }
        $ids = [];
        foreach (self::$matched as $hint) {
            if (isset($hint['child'])) {
                $ids[] = (int) $hint['child'];
            }
        }

        return $ids;
    }

    /** Whether there is anything to apply on this page. */
    public static function pending(): bool
    {
        return !self::$picturesDone && self::$matched !== [] && self::onSearchPage();
    }

    /**
     * The search controller's gather of the results (fn_gather_additional_products_data, with
     * the products' options): matched option values that are all still among the product's
     * active values put their picture on the card (the colour's - an option named as one - else
     * the first with a picture, when one has one) and their link in place; a
     * matched child that is an active variation of the product does the same with the child's
     * main picture and page. Once per request: the first gather holding a matched product.
     *
     * @param array<int|string, array<string,mixed>>                          $products
     * @param array<int, array{parent_product_id:int, main_pair:array<mixed>}> $children the active
     *        children of childIds(), with their main image pair (empty when none)
     */
    public static function applyPictures(array &$products, array $children): void
    {
        if (!self::pending()) {
            return;
        }
        foreach ($products as &$product) {
            $productId = (int) ($product['product_id'] ?? 0);
            $hint = self::$matched[$productId] ?? null;
            if ($hint === null) {
                continue;
            }
            self::$picturesDone = true;
            if (isset($hint['child'])) {
                $child = $children[$hint['child']] ?? null;
                if ($child === null || (int) $child['parent_product_id'] !== $productId) {
                    continue;
                }
                if (!empty($child['main_pair'])) {
                    $product['main_pair'] = $child['main_pair'];
                }
                self::$links[$productId] = ['child' => (int) $hint['child']];
                continue;
            }
            $values = [];
            foreach ($hint['options'] as $optionId => $variantId) {
                $value = self::optionValue((array) ($product['product_options'] ?? []), (int) $optionId, (int) $variantId);
                if ($value === null) {
                    continue 2;
                }
                $values[] = $value;
            }
            // The colour's picture first: a size value seldom has one, but when it does it is not the look.
            usort($values, static fn (array $a, array $b): int => (int) !ProductMapper::isColourName((string) $a['option_name']) <=> (int) !ProductMapper::isColourName((string) $b['option_name']));
            foreach ($values as $value) {
                if (!empty($value['image_pair'])) {
                    $product['main_pair'] = $value['image_pair'];
                    break;
                }
            }
            $pairs = [];
            foreach ($hint['options'] as $optionId => $variantId) {
                $pairs[] = (int) $optionId . '_' . (int) $variantId;
            }
            self::$links[$productId] = ['combination' => implode('_', $pairs)];
        }
        unset($product);
    }

    /**
     * The link change for a URL the page builds (fn_url's input): for
     * `products.view?product_id=<a confirmed product>` exactly, the combination to attach or
     * the child to link instead; null for anything else.
     *
     * @return array{combination:string}|array{child:int}|null
     */
    public static function linkFor(string $url): ?array
    {
        if (self::$links === [] || preg_match('/^products\.view\?product_id=([1-9]\d{0,18})$/', $url, $m) !== 1 || !self::onSearchPage()) {
            return null;
        }

        return self::$links[(int) $m[1]] ?? null;
    }

    /**
     * The value $variantId of option $optionId in the product's options as CS-Cart gathered
     * them (option_id => option with `variants`), with its option's name as `option_name`; null
     * when the product has no such value.
     *
     * @param array<int|string, mixed> $options
     * @return ?array<string,mixed>
     */
    private static function optionValue(array $options, int $optionId, int $variantId): ?array
    {
        foreach ($options as $option) {
            if (!is_array($option) || (int) ($option['option_id'] ?? 0) !== $optionId) {
                continue;
            }
            foreach ((array) ($option['variants'] ?? []) as $value) {
                if (is_array($value) && (int) ($value['variant_id'] ?? 0) === $variantId) {
                    return ['option_name' => (string) ($option['option_name'] ?? '')] + $value;
                }
            }
        }

        return null;
    }

    /** The storefront search page (products.search), the only page hints describe. */
    private static function onSearchPage(): bool
    {
        if (self::$pageCheck !== null) {
            return (bool) (self::$pageCheck)();
        }

        return defined('AREA') && AREA === 'C'
            && Registry::get('runtime.controller') === 'products' && Registry::get('runtime.mode') === 'search';
    }

    /** Test seam: forget this request's hints. */
    public static function reset(): void
    {
        self::$matched = [];
        self::$links = [];
        self::$picturesDone = false;
    }

    /** Test seam: decide "on the search page" without CS-Cart (null restores the real check). */
    public static function setPageCheck(?callable $check): void
    {
        self::$pageCheck = $check === null ? null : \Closure::fromCallable($check);
    }

    private function __construct()
    {
    }
}
