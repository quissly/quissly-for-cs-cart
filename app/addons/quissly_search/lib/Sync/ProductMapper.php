<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

/**
 * Maps a CS-Cart product onto the Quissly catalog record (the ProductItem that
 * /v1beta/catalog accepts). Contract verified live by the WooCommerce plugin and
 * the Magento plugin against the same backend:
 *
 *  - id is a STRING on the wire (an integer id is silently dropped by ingest);
 *  - title, description, category, images and original_price are REQUIRED on /add:
 *    description falls back to the short description, then the title; category to
 *    'uncategorized'; original_price is always a number (null is rejected);
 *  - discounted_price is the selling price when CS-Cart's list price is higher,
 *    else null ("not on sale");
 *  - in_stock: tracking disabled or backorders allowed means buyable, else amount > 0;
 *  - up to 10 images, main image first, duplicates dropped.
 *
 * Variations: the parent and its active children go out as `variants`, in the shape
 * the WooCommerce plugin sends (live-confirmed there): each variant is a full
 * ProductItem - the required fields (title, description, category, images,
 * original_price) copied from the parent where the child has none, because /add does
 * not inherit them - with price and stock only where they differ from the parent,
 * and its sku and option values in its own `metadata` (plus `selected_options`, the
 * [{name, value}] form Quissly builds facets from, naming every option that varies
 * within the group). The parent is the FIRST variant: in CS-Cart it is itself one of
 * the buyable options (e.g. "Medium"), and Quissly indexes only the variants of a
 * product that has them - left out, an in-stock parent whose children are sold out
 * vanished from search (live 2026-09-24). The parent's in_stock is true when it or any
 * child is buyable. Option values also go in each product's metadata under the
 * lower-cased feature name ("Color" -> metadata.color).
 *
 * Features: the product features the Catalog data checklist sends (CatalogFeatures - by
 * default the ones the storefront shows: Brand, Material, Storage Capacity...) go in
 * metadata the same way ("Storage Capacity" -> storage_capacity), so a search for a brand
 * or a material finds the product even when its title and description never name it.
 * ProductSource applies the checklist; this mapper takes what it is given. A feature never
 * replaces the record's own metadata (sku, vendor, an option...). A variant carries only
 * the features whose value differs from its parent's.
 *
 * Bundles (CS-Cart's Product bundles add-on): a bundle is not a product - it has no
 * page and cannot be a search result; it is an offer shown on its members' pages. So
 * it travels with each member, as `metadata.bundles` [{id, name, description,
 * original_price (all parts at their own price), discounted_price (the bundle price),
 * discount_percent, items [{id, title, qty}]}]. Quissly captions and keyword-indexes
 * metadata, so "camping set" finds the members, and the chat can tell the deal.
 *
 * Pure: the input is the normalized array ProductSource builds, so this is unit-tested
 * without CS-Cart.
 */
final class ProductMapper
{
    /** CS-Cart `tracking`: 'D' = inventory tracking disabled. */
    private const TRACKING_DISABLED = 'D';

    /** CS-Cart `out_of_stock_actions`: 'B' = buy in advance (backorder). */
    private const BACKORDER = 'B';

    private const MAX_IMAGES = 10;

    /**
     * @param array<string,mixed> $product normalized product (see ProductSource::load())
     *
     * @return array<string,mixed>
     */
    public static function map(array $product): array
    {
        $record = self::record($product);

        $variations = (array) ($product['variations'] ?? []);
        if ($variations === []) {
            return $record;
        }

        // The parent first (its own stock, before the roll-up below), then its children.
        $sources = array_merge([$product], array_map(static fn ($child): array => (array) $child, $variations));
        $records = [$record];
        foreach (array_slice($sources, 1) as $child) {
            $own = self::record($child);
            if (!empty($own['in_stock'])) {
                $record['in_stock'] = true;
            }
            $records[] = $own;
        }
        $varying = self::varyingOptions(array_map(static fn (array $source): array => (array) ($source['options'] ?? []), $sources));
        $parentFeatures = self::features($product);

        $variants = [];
        foreach ($records as $i => $own) {
            // A child's description defaults to its title (record()); when it has no
            // text of its own, the parent's description is the right one to inherit.
            if ($i > 0 && self::plainText((string) ($sources[$i]['full_description'] ?? '')) === ''
                && self::plainText((string) ($sources[$i]['short_description'] ?? '')) === '') {
                $own['description'] = '';
            }
            $features = $i > 0 ? array_diff_assoc(self::features($sources[$i]), $parentFeatures) : [];
            $variants[] = self::variant($own, $record, (array) ($sources[$i]['options'] ?? []), $varying, $features);
        }
        $record['variants'] = $variants;

        return $record;
    }

    /**
     * One product's own record (no variants).
     *
     * @param array<string,mixed> $product
     * @return array<string,mixed>
     */
    private static function record(array $product): array
    {
        $title = trim((string) ($product['product'] ?? ''));
        $price = self::money($product['price'] ?? null);
        $listPrice = self::money($product['list_price'] ?? null);
        $onSale = $price !== null && $listPrice !== null && $listPrice > $price;

        $metadata = [];
        $sku = trim((string) ($product['product_code'] ?? ''));
        if ($sku !== '') {
            $metadata['sku'] = $sku;
        }
        $metadata['categories'] = array_values(array_filter(array_map('strval', (array) ($product['category_names'] ?? [])), static fn (string $name): bool => $name !== ''));
        $vendor = trim((string) ($product['company'] ?? ''));
        if ($vendor !== '') {
            $metadata['vendor'] = $vendor;
        }
        foreach ((array) ($product['options'] ?? []) as $name => $value) {
            $metadata[self::optionKey((string) $name)] = (string) $value;
        }
        $metadata += self::features($product);
        $bundles = self::bundles((array) ($product['bundles'] ?? []));
        if ($bundles !== []) {
            $metadata['bundles'] = $bundles;
        }

        $category = trim((string) ($product['category_name'] ?? ''));

        return [
            'id'               => (string) (int) ($product['product_id'] ?? 0),
            'title'            => $title,
            'description'      => self::description($product, $title),
            'original_price'   => $onSale ? $listPrice : ($price ?? 0.0),
            'discounted_price' => $onSale ? $price : null,
            'category'         => $category !== '' ? $category : 'uncategorized',
            'in_stock'         => self::inStock($product),
            'images'           => self::images((array) ($product['images'] ?? [])),
            'url'              => (string) ($product['url'] ?? ''),
            'metadata'         => $metadata,
        ];
    }

    /**
     * One option of the group as a variant of $parent: required fields present
     * (inherited when it has none), price and stock only when they differ, the
     * options that vary within the group in metadata.
     *
     * @param array<string,mixed>  $own     the option's own record
     * @param array<string,mixed>  $parent  the parent's record (stock rolled up)
     * @param array<string,string> $options the option's feature values
     * @param list<string>         $varying the feature names that vary within the group
     * @param array<string,string> $features its feature metadata that differs from the parent's
     * @return array<string,mixed>
     */
    private static function variant(array $own, array $parent, array $options, array $varying, array $features): array
    {
        // Titled by the options that vary within the product ("Size: Large"): Quissly
        // stores a variant as "<parent title> - <variant title>", and a child's own name
        // ("T-shirt, Color: Blue") repeated the parent's and left the size out, so the size
        // could not be matched. The child's name only when no option varies.
        $optionTitle = implode(', ', array_map(
            static fn (string $name): string => $name . ': ' . (string) $options[$name],
            array_values(array_filter($varying, static fn (string $name): bool => isset($options[$name]) && (string) $options[$name] !== ''))
        ));
        $variant = [
            'id'             => $own['id'],
            'title'          => $optionTitle !== '' ? $optionTitle : ($own['title'] !== '' ? $own['title'] : $parent['title']),
            'description'    => $own['description'] !== '' ? $own['description'] : $parent['description'],
            'category'       => $own['category'] !== 'uncategorized' ? $own['category'] : $parent['category'],
            'images'         => $own['images'] !== [] ? $own['images'] : $parent['images'],
            'original_price' => $own['original_price'] > 0 ? $own['original_price'] : $parent['original_price'],
            'url'            => $own['url'] !== '' ? $own['url'] : $parent['url'],
        ];
        if ($own['discounted_price'] !== null && $own['discounted_price'] !== $parent['discounted_price']) {
            $variant['discounted_price'] = $own['discounted_price'];
        }
        if ($own['in_stock'] !== $parent['in_stock']) {
            $variant['in_stock'] = $own['in_stock'];
        }

        $metadata = [];
        $sku = (string) ($own['metadata']['sku'] ?? '');
        if ($sku !== '' && $sku !== (string) ($parent['metadata']['sku'] ?? '')) {
            $metadata['sku'] = $sku;
        }
        $selected = [];
        foreach ($varying as $name) {
            if (isset($options[$name])) {
                $metadata[self::optionKey($name)] = (string) $options[$name];
                $selected[] = ['name' => $name, 'value' => (string) $options[$name]];
            }
        }
        $metadata += $features;
        $metadata['selected_options'] = $selected;
        if (isset($own['metadata']['bundles']) && $own['metadata']['bundles'] !== ($parent['metadata']['bundles'] ?? null)) {
            $metadata['bundles'] = $own['metadata']['bundles'];
        }
        $variant['metadata'] = $metadata;

        return $variant;
    }

    /**
     * The product's bundles in the wire shape (see the class comment); a bundle
     * without a name or members is left out, and so is an absent description or price.
     *
     * @param list<array<string,mixed>> $bundles normalized bundles (ProductSource)
     * @return list<array<string,mixed>>
     */
    private static function bundles(array $bundles): array
    {
        $out = [];
        foreach ($bundles as $bundle) {
            $bundle = (array) $bundle;
            $items = [];
            foreach ((array) ($bundle['items'] ?? []) as $item) {
                $item = (array) $item;
                $id = (int) ($item['product_id'] ?? 0);
                $title = trim((string) ($item['product'] ?? ''));
                if ($id > 0 && $title !== '') {
                    $items[] = ['id' => (string) $id, 'title' => $title, 'qty' => max(1, (int) ($item['amount'] ?? 1))];
                }
            }
            $name = trim((string) ($bundle['name'] ?? ''));
            if ($name === '' || $items === []) {
                continue;
            }
            $entry = ['id' => (string) (int) ($bundle['bundle_id'] ?? 0), 'name' => $name];
            $description = self::plainText((string) ($bundle['description'] ?? ''));
            if ($description !== '') {
                $entry['description'] = $description;
            }
            $total = self::money($bundle['total_price'] ?? null);
            $price = self::money($bundle['discounted_price'] ?? null);
            if ($total !== null && $price !== null && $total > 0) {
                $entry['original_price'] = $total;
                $entry['discounted_price'] = $price;
                $entry['discount_percent'] = (int) round((1 - $price / $total) * 100);
            }
            $entry['items'] = $items;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * The product's storefront features as metadata (feature name -> key); blank values
     * are left out, and the first of two names that make the same key wins.
     *
     * @param array<string,mixed> $product
     * @return array<string,string>
     */
    private static function features(array $product): array
    {
        $metadata = [];
        foreach ((array) ($product['features'] ?? []) as $name => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $metadata += [self::optionKey((string) $name) => $value];
            }
        }

        return $metadata;
    }

    /**
     * The feature names whose value is not the same across the whole group, in order.
     *
     * @param list<array<string,string>> $optionSets each option's feature values
     * @return list<string>
     */
    private static function varyingOptions(array $optionSets): array
    {
        $values = [];
        foreach ($optionSets as $options) {
            foreach ($options as $name => $value) {
                $values[(string) $name][(string) $value] = true;
            }
        }
        $varying = [];
        foreach ($values as $name => $seen) {
            if (count($seen) > 1) {
                $varying[] = (string) $name;
            }
        }

        return $varying;
    }

    /**
     * A feature name as a metadata key ("Color" -> color). A key that would clash
     * with a top-level ProductItem field (the backend cannot hold one name inside and
     * outside metadata - the Magento plugin's guardMetadata) gets an attr_ prefix.
     */
    private static function optionKey(string $name): string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        $reserved = ['id', 'title', 'description', 'category', 'images', 'url', 'in_stock', 'original_price',
            'discounted_price', 'discount_percent', 'variants', 'metadata', 'sku', 'categories', 'vendor', 'selected_options', 'bundles'];

        return $key === '' || in_array($key, $reserved, true) ? 'attr_' . $key : $key;
    }

    /** A price as a number, or null when absent/blank/zero. */
    private static function money($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $number = round((float) $value, 2);

        return $number > 0 ? $number : null;
    }

    /** @param array<string,mixed> $product */
    private static function description(array $product, string $title): string
    {
        foreach (['full_description', 'short_description'] as $field) {
            $text = self::plainText((string) ($product[$field] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return $title;
    }

    private static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @param array<string,mixed> $product */
    private static function inStock(array $product): bool
    {
        if (($product['tracking'] ?? '') === self::TRACKING_DISABLED || ($product['out_of_stock_actions'] ?? '') === self::BACKORDER) {
            return true;
        }

        return (float) ($product['amount'] ?? 0) > 0;
    }

    /**
     * @param array<int,mixed> $images
     *
     * @return list<string>
     */
    private static function images(array $images): array
    {
        $urls = array_values(array_unique(array_filter(array_map('strval', $images), static fn (string $url): bool => $url !== '')));

        return array_slice($urls, 0, self::MAX_IMAGES);
    }

    private function __construct()
    {
    }
}
