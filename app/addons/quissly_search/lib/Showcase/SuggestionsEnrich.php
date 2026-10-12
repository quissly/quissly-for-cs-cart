<?php

declare(strict_types=1);

namespace Quissly\Search\Showcase;

/**
 * The request to, and the answer from, Quissly's suggestion generator.
 *
 * `POST /v2beta/qsearch/suggestions_enrich` (v2-signed, like a search) writes a store's search
 * bar suggestions itself: Quissly generates them in each language, checks each one finds
 * products, and writes them into the QSearch service's widget_config, keeping any list the
 * merchant edited. Every Quissly connector calls it (the Shopify app, the
 * Magento and WooCommerce plugins); no add-on holds a model key. What the add-on
 * sends is what only the store knows: its languages and its catalog as shoppers read it.
 *
 * Pure: builds the body and reads the status, nothing else.
 */
final class SuggestionsEnrich
{
    /** The signed path. */
    public const PATH = '/v2beta/qsearch/suggestions_enrich';

    /** The generator these lists come from (a list from any other is made again once). */
    public const GENERATOR = 'suggestions-enrich-v1';

    /** Products sent per language; Quissly shows its model at most 40. */
    public const MAX_PRODUCTS = 60;

    /** Values sent per product option. */
    public const MAX_OPTION_VALUES = 20;

    /**
     * Options sent per product, and a title's length: the backend's own limits (ProductFactIn) -
     * one product over either fails the whole request (422). Shopify never reaches them (3
     * options, 255-character titles); CS-Cart options can.
     */
    public const MAX_OPTIONS = 10;
    public const MAX_TITLE_LENGTH = 300;

    /** Languages asked for at once (the primary one first). */
    public const MAX_LANGUAGES = 4;

    /** Attempts before Quissly may write a shorter list than it would like (accept_partial). */
    public const MAX_ATTEMPTS = 6;

    private const HOUR = 3600;

    /**
     * The request body. A product without a title is left out, and a language without products
     * is too (Quissly then uses its own catalog, which is in one language).
     *
     * @param list<string>                $languages every language asked for, the primary one first
     * @param array<string, array>        $catalogs  per language: {shop_name, currency, products[]}
     * @param array{main?: list<string>, by_language?: array<string, list<string>>}|null $previous what was generated before
     * @param string                      $mode      fill | regenerate (lists from an older generator)
     */
    public static function body(string $primary, array $languages, array $catalogs, ?array $previous, string $mode, bool $acceptPartial): array
    {
        $catalog = [];
        $shopName = null;
        $currency = null;
        foreach ($languages as $language) {
            $facts = $catalogs[$language] ?? null;
            if (!is_array($facts)) {
                continue;
            }
            $shopName = $shopName ?? (self::text($facts['shop_name'] ?? null) ?: null);
            $currency = $currency ?? (self::text($facts['currency'] ?? null) ?: null);
            $products = [];
            foreach ((array) ($facts['products'] ?? []) as $product) {
                $title = self::text($product['title'] ?? null);
                if ($title === '') {
                    continue;
                }
                $products[] = [
                    'title'        => mb_substr($title, 0, self::MAX_TITLE_LENGTH),
                    'product_type' => self::text($product['product_type'] ?? null) ?: null,
                    'vendor'       => self::text($product['vendor'] ?? null) ?: null,
                    'options'      => self::options((array) ($product['options'] ?? [])),
                    'min_price'    => self::price($product['min_price'] ?? null),
                    'max_price'    => self::price($product['max_price'] ?? ($product['min_price'] ?? null)),
                ];
                if (count($products) >= self::MAX_PRODUCTS) {
                    break;
                }
            }
            if ($products !== []) {
                $catalog[$language] = $products;
            }
        }

        $body = [
            'primary_language' => $primary,
            'languages'        => array_values($languages),
            'shop_name'        => $shopName,
            'currency'         => $currency,
            'catalog'          => (object) $catalog,
        ];
        $main = array_values((array) ($previous['main'] ?? []));
        $byLanguage = array_filter((array) ($previous['by_language'] ?? []));
        if ($main !== [] || $byLanguage !== []) {
            $body['previous_generated'] = ['main' => $main, 'by_language' => (object) $byLanguage];
        }
        $body['mode'] = $mode === 'regenerate' ? 'regenerate' : 'fill';
        $body['accept_partial'] = $acceptPartial;

        return $body;
    }

    /**
     * What an answer means for the schedule: done (stop asking until something changes), or ask
     * again after `retry` seconds.
     *
     * @return array{done: bool, retry: int, reason: string}
     */
    public static function schedule(string $status): array
    {
        switch ($status) {
            case 'written':
            case 'unchanged':
                return ['done' => true, 'retry' => 0, 'reason' => ''];
            case 'insufficient':
                // Most likely the search index is still filling after the first sync.
                return ['done' => false, 'retry' => self::HOUR, 'reason' => 'Too few suggestions returned results yet.'];
            case 'no_products':
                return ['done' => false, 'retry' => 24 * self::HOUR, 'reason' => 'No active products to build suggestions from.'];
            case 'no_widget_service':
                return ['done' => false, 'retry' => 24 * self::HOUR, 'reason' => 'The search service is not set up in Quissly yet.'];
        }

        return ['done' => false, 'retry' => self::HOUR, 'reason' => 'Quissly could not generate suggestions.'];
    }

    /** A CS-Cart language code as the widget_config key ("ka", "pt-br"). */
    public static function languageKey(string $code): string
    {
        $code = strtolower(str_replace('_', '-', trim($code)));

        return $code !== '' ? $code : 'en';
    }

    /** @return list<array{name: string, values: list<string>}> */
    private static function options(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            $name = self::text($option['name'] ?? null);
            $values = array_values(array_filter(array_map([self::class, 'text'], (array) ($option['values'] ?? [])), 'strlen'));
            if ($name !== '' && $values !== []) {
                $out[] = ['name' => $name, 'values' => array_slice($values, 0, self::MAX_OPTION_VALUES)];
            }
            if (count($out) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return $out;
    }

    /** @param mixed $value */
    private static function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param mixed $value */
    private static function price($value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? round((float) $value, 2) : null;
    }

    private function __construct()
    {
    }
}
