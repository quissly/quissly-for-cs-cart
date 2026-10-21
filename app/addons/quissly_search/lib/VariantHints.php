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
 * The card's Add to cart follows the match too. A card of a product with options or variations
 * never adds directly: the 4.21 themes built on show_quick_view_for_options (nova) open CS-Cart's
 * options dialog (`products.quick_view?product_id=<id>&show_quick_view_for_options=Y`), which gets
 * the same `combination` (or the matched child's id: the dialog then shows and adds the child, at
 * its own price and stock); the responsive theme (4.11 and 4.21) links "Select options" /
 * "Select variation" to products.view, which the link change already covers. The card's own
 * price includes the matched values' modifiers: the search gather starts with them as the
 * product's `selected_options` (the other options at their defaults, as checkout.add and the
 * chat's resolve choose them), and CS-Cart applies them as for any chosen option.
 *
 * Written by SearchInterceptor for the one intercepted text search; read by the
 * `gather_additional_products_data_pre` hook (chosen values), the
 * `gather_additional_products_data_post` hook (pictures) and the `url_post` hook (links).
 * All read nothing outside the storefront search page (products.search), so a category
 * page, a block, the cart, an email or the admin can never get a hint, whatever was recorded;
 * and only what the products' own data confirms (the option and value still active on that
 * product, the child an active variation of it) is used. The values and picture are set once, in
 * the search controller's own gather of the results; a link is changed for every
 * `products.view?product_id=<that product>` and options dialog of it the page builds.
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
     * The start of the search controller's gather of the results: each product matched through
     * option values it still has gets them as its `selected_options`, every other option at its
     * default, so CS-Cart prices the card with the values' modifiers (fn_gather_additional_
     * product_data reads selected_options as products.view reads a combination). A product
     * already carrying a choice is left alone.
     *
     * @param array<int|string, array<string,mixed>> $products
     * @param callable(list<int>): array<int, array{options:array<int|string,mixed>, defaults:array<int,int>}> $optionsOf
     *        each product's active selectable options (as fn_get_product_options) and its
     *        default values (option id => value id, as fn_get_default_product_options)
     */
    public static function presetOptions(array &$products, callable $optionsOf): void
    {
        if (!self::pending()) {
            return;
        }
        $ids = [];
        foreach ($products as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            if (isset(self::$matched[$productId]['options']) && empty($product['selected_options']) && empty($product['combination'])) {
                $ids[] = $productId;
            }
        }
        if ($ids === []) {
            return;
        }
        $known = $optionsOf($ids);
        foreach ($products as &$product) {
            $productId = (int) ($product['product_id'] ?? 0);
            if (!in_array($productId, $ids, true) || !isset($known[$productId])) {
                continue;
            }
            $chosen = self::chosenOptions((array) $known[$productId]['options'], (array) $known[$productId]['defaults'], self::$matched[$productId]['options']);
            if ($chosen !== null) {
                $product['selected_options'] = $chosen;
            }
        }
        unset($product);
    }

    /**
     * Every option's value with the matched ones in place: the defaults, then each matched
     * value - null when one of them is not among the product's options.
     *
     * @param array<int|string, mixed> $options  as fn_get_product_options
     * @param array<int, int>          $defaults option id => value id
     * @param array<int, int>          $matched  option id => value id
     * @return ?array<int, int>
     */
    public static function chosenOptions(array $options, array $defaults, array $matched): ?array
    {
        $chosen = [];
        foreach ($defaults as $optionId => $variantId) {
            $chosen[(int) $optionId] = (int) $variantId;
        }
        foreach ($matched as $optionId => $variantId) {
            if (self::optionValue($options, (int) $optionId, (int) $variantId) === null) {
                return null;
            }
            $chosen[(int) $optionId] = (int) $variantId;
        }

        return $chosen;
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
     * The change for the card's Add to cart dialog (fn_url's input
     * `products.quick_view?product_id=<a confirmed product>&...&show_quick_view_for_options=Y...`):
     * the combination to attach, or the same URL naming the matched child instead; null for
     * anything else - the image's plain quick view included, and a URL already carrying a
     * combination.
     *
     * @return array{combination:string}|array{url:string}|null
     */
    public static function dialogFor(string $url): ?array
    {
        $prefix = 'products.quick_view?';
        if (self::$links === [] || strpos($url, $prefix) !== 0 || !self::onSearchPage()) {
            return null;
        }
        parse_str(substr($url, strlen($prefix)), $query);
        $id = $query['product_id'] ?? null;
        if (!is_string($id) || preg_match('/^[1-9]\d{0,18}$/', $id) !== 1
            || ($query['show_quick_view_for_options'] ?? null) !== 'Y' || isset($query['combination'])) {
            return null;
        }
        $link = self::$links[(int) $id] ?? null;
        if (isset($link['child'])) {
            return ['url' => (string) preg_replace('/(?<=[?&])product_id=' . $id . '(?=&|$)/', 'product_id=' . (int) $link['child'], $url, 1)];
        }

        return $link;
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
