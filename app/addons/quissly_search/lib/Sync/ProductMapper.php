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
 *  - discounted_price is ALWAYS the selling price, and original_price the list price when
 *    it is higher, else the selling price too (the Quissly Shopify app's and the Magento
 *    plugin's rule): Quissly sorts by price on discounted_price, and a record without it went
 *    last in a price sort (2026-10-19);
 *  - in_stock: tracking disabled or backorders allowed means buyable, else amount > 0;
 *  - up to 10 images, main image first, duplicates dropped;
 *  - metadata.popularity (CS-Cart's popularity score, 0 when it has none - never left out,
 *    as Quissly sorts a product without it after every other) and metadata.created_at (unix
 *    seconds, CS-Cart's product date) on every product, ints only - Quissly's sort compares
 *    the values as they are and a string among ints would break it (SortMap: sort_by 4, 5).
 *    A variant inherits its parent's, so only the product's own record carries them.
 *
 * Variations: the parent and its active children go out as `variants`, in the shape
 * the WooCommerce plugin sends: each variant is a full
 * ProductItem - the required fields (title, description, category, images,
 * original_price) copied from the parent where the child has none, because /add does
 * not inherit them - with its own selling price and stock always,
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
 * Classic product options (CS-Cart's "Options": a select box or radio group of values on the
 * product, or a global option linked to it - not Variations, which are products of their own):
 * a product outside a variation group sends ONE colour-like option as `variants`, a variant
 * per value ({@see colourOption()}), so "yellow tee" finds the tee whose colour is an option.
 * Each value's variant: id "<product>-o<option>-<value>" ({@see optionVariantId()}; search
 * answers with it as top_variant_id - VariantHints), title "<Option>: <Value>" (Quissly names it
 * "<product> - Color: Yellow"), the value's picture first, the product's price with the value's
 * modifier applied as CS-Cart applies it (absolute, or a percent of the base), the product's
 * stock (an option has none of its own since 4.10 - except a 4.11 product still tracked with
 * options, whose values carry their own), a link that opens the product with that value
 * selected (`combination`), and the value in metadata + `selected_options`. Every other
 * option's values go in metadata as one readable list under the option's name ("size" =>
 * "S, M, L"), so a keyword search finds them; a product in a variation group sends all its
 * options that way and keeps its variations as the variants.
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

    /** The most values of one option sent as variants; the rest are left out (ProductSource logs it). */
    public const MAX_OPTION_VARIANTS = 50;

    /**
     * An option name that means colour (case-insensitive, a whole word or its inflection):
     * color/colour (colors, colores, colore, colorway), couleur, Farbe, farve, farge, färg,
     * kleur, kolor, renk, cor, barva, szín, culoare, χρώμα, цвет, колір, ფერი.
     */
    private const COLOUR_NAME = '/(?<!\p{L})(?:colou?r\p{L}*|couleurs?|farbe\p{L}*|farve|farge|f\x{00E4}rg\p{L}*|kleur\p{L}*|kolor\p{L}*|renk\p{L}*|cor(?:es)?|barv\p{L}*|sz\x{00ED}n\p{L}*|culoare|\x{03C7}\x{03C1}\x{03CE}\x{03BC}\p{L}*|\x{0446}\x{0432}\x{0435}\x{0442}\p{L}*|\x{043A}\x{043E}\x{043B}\x{0456}\x{0440}\p{L}*|\x{10E4}\x{10D4}\x{10E0}\p{L}*)(?!\p{L})/iu';

    /**
     * @param array<string,mixed> $product normalized product (see ProductSource::load())
     *
     * @return array<string,mixed>
     */
    public static function map(array $product): array
    {
        $variations = (array) ($product['variations'] ?? []);
        // A product in a variation group keeps its variations as the variants: its options
        // are only listed in metadata.
        $colour = $variations === [] ? self::colourOption((array) ($product['product_options'] ?? [])) : null;
        $record = self::record($product, $colour !== null ? (int) $colour['option_id'] : 0);

        if ($colour !== null) {
            $record['variants'] = self::optionVariants($record, $product, $colour);

            return $record;
        }
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
     * @param int                 $expanded the option sent as variants (0: none), left out of the lists
     * @return array<string,mixed>
     */
    private static function record(array $product, int $expanded = 0): array
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
        $metadata['popularity'] = max(0, (int) ($product['popularity'] ?? 0));
        $createdAt = (int) ($product['timestamp'] ?? 0);
        if ($createdAt > 0) {
            $metadata['created_at'] = $createdAt;
        }
        foreach ((array) ($product['product_options'] ?? []) as $option) {
            $option = (array) $option;
            $values = self::valueNames($option);
            $name = trim((string) ($option['name'] ?? ''));
            if ((int) ($option['option_id'] ?? 0) !== $expanded && $name !== '' && $values !== []) {
                $metadata += [self::optionKey($name) => implode(', ', $values)];
            }
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
            'discounted_price' => $price ?? 0.0,
            'category'         => $category !== '' ? $category : 'uncategorized',
            'in_stock'         => self::inStock($product),
            'images'           => self::images((array) ($product['images'] ?? [])),
            'url'              => (string) ($product['url'] ?? ''),
            'metadata'         => $metadata,
        ];
    }

    /**
     * One option of the group as a variant of $parent: required fields present
     * (inherited when it has none), its own selling price and its own stock
     * always, the options that vary within the group in metadata.
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
        // Always its own selling price, on sale or not: Quissly keeps each variant as a record
        // of its own, shows its own price (a sale applied to the whole product, every variant at
        // -20%, was shown at full price in the chat) and sorts by it.
        $variant['discounted_price'] = $own['discounted_price'] > 0 ? $own['discounted_price'] : $variant['original_price'];
        // Always its own: a variant without in_stock is stored as in stock (ProductItem's default),
        // so a product sold out in every option - the parent rolled up to false, like each variant -
        // showed every option as available.
        $variant['in_stock'] = $own['in_stock'];

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
     * The option a product outside a variation group sends as variants, or null: one whose
     * name means colour ({@see COLOUR_NAME}) or whose values have pictures. Of several, a
     * colour name first, then one with pictures, then the first in the product's order.
     *
     * @param list<array<string,mixed>> $options normalized options (ProductSource)
     * @return ?array<string,mixed>
     */
    public static function colourOption(array $options): ?array
    {
        $best = null;
        $bestScore = 0;
        foreach ($options as $option) {
            $option = (array) $option;
            $name = trim((string) ($option['name'] ?? ''));
            if ($name === '' || (int) ($option['option_id'] ?? 0) <= 0 || self::valueNames($option) === []) {
                continue;
            }
            $pictures = false;
            foreach ((array) ($option['values'] ?? []) as $value) {
                $pictures = $pictures || trim((string) ($value['image'] ?? '')) !== '';
            }
            $score = (preg_match(self::COLOUR_NAME, $name) === 1 ? 2 : 0) + ($pictures ? 1 : 0);
            if ($score > $bestScore) {
                $best = $option;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * The Quissly id of one option value's variant: "<product>-o<option>-<value>" (strings on
     * the wire, like every id; search answers it as top_variant_id - VariantHints::parse()).
     */
    public static function optionVariantId(int $productId, int $optionId, int $variantId): string
    {
        return $productId . '-o' . $optionId . '-' . $variantId;
    }

    /**
     * A variant per value of $option (at most MAX_OPTION_VARIANTS), see the class comment.
     *
     * @param array<string,mixed> $record  the product's own record
     * @param array<string,mixed> $product the normalized product
     * @param array<string,mixed> $option  the option sent as variants
     * @return list<array<string,mixed>>
     */
    private static function optionVariants(array $record, array $product, array $option): array
    {
        $optionId = (int) $option['option_id'];
        $name = trim((string) $option['name']);
        $price = self::money($product['price'] ?? null);
        $listPrice = self::money($product['list_price'] ?? null);
        $variants = [];
        foreach ((array) ($option['values'] ?? []) as $value) {
            $value = (array) $value;
            $label = trim((string) ($value['name'] ?? ''));
            $variantId = (int) ($value['variant_id'] ?? 0);
            if ($label === '' || $variantId <= 0) {
                continue;
            }
            if (count($variants) >= self::MAX_OPTION_VARIANTS) {
                break;
            }
            $own = $price !== null ? self::modified($price, $value) : null;
            $list = $listPrice !== null ? self::modified($listPrice, $value) : null;
            $stock = $value['in_stock'] ?? null;
            $variants[] = [
                'id'               => self::optionVariantId((int) $record['id'], $optionId, $variantId),
                'title'            => $name . ': ' . $label,
                'description'      => $record['description'],
                'category'         => $record['category'],
                'images'           => self::images(array_merge([(string) ($value['image'] ?? '')], (array) ($product['images'] ?? []))),
                'original_price'   => $own !== null && $list !== null && $list > $own ? $list : ($own ?? 0.0),
                'discounted_price' => $own ?? 0.0,
                'url'              => self::withCombination($record['url'], $optionId, $variantId),
                // The value's own stock only where it has one (a 4.11 product tracked with options).
                'in_stock'         => is_bool($stock) && !self::alwaysBuyable($product) ? $stock : $record['in_stock'],
                'metadata'         => [
                    self::optionKey($name) => $label,
                    'selected_options'     => [['name' => $name, 'value' => $label]],
                ],
            ];
        }

        return $variants;
    }

    /**
     * A price with an option value's modifier, as CS-Cart's fn_apply_options_modifiers: an
     * absolute amount (`A`) or a percent of the base, never below 0.
     *
     * @param array<string,mixed> $value
     */
    private static function modified(float $base, array $value): float
    {
        $modifier = is_numeric($value['modifier'] ?? null) ? (float) $value['modifier'] : 0.0;
        $change = ($value['modifier_type'] ?? 'A') === 'A' ? $modifier : $modifier * $base / 100;

        return round(max(0.0, $base + $change), 2);
    }

    /** The product's link with the value selected: products.view takes `combination=<option>_<value>`. */
    private static function withCombination(string $url, int $optionId, int $variantId): string
    {
        if ($url === '') {
            return '';
        }

        return $url . (strpos($url, '?') === false ? '?' : '&') . 'combination=' . $optionId . '_' . $variantId;
    }

    /**
     * An option's value names, in order, blanks left out.
     *
     * @param array<string,mixed> $option
     * @return list<string>
     */
    private static function valueNames(array $option): array
    {
        $names = [];
        foreach ((array) ($option['values'] ?? []) as $value) {
            $name = trim((string) ($value['name'] ?? ''));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
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
            'discounted_price', 'discount_percent', 'variants', 'metadata', 'sku', 'categories', 'vendor', 'selected_options', 'bundles',
            'popularity', 'created_at'];

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
        // A <style> or <script> block in a description is code, not words: strip_tags would keep it.
        $html = (string) preg_replace('#<(style|script)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @param array<string,mixed> $product */
    private static function inStock(array $product): bool
    {
        if (self::alwaysBuyable($product)) {
            return true;
        }

        return (float) ($product['amount'] ?? 0) > 0;
    }

    /** @param array<string,mixed> $product */
    private static function alwaysBuyable(array $product): bool
    {
        return ($product['tracking'] ?? '') === self::TRACKING_DISABLED || ($product['out_of_stock_actions'] ?? '') === self::BACKORDER;
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
