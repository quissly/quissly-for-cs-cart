<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

/**
 * CS-Cart glue: which products to sync, and each one in the normalized shape
 * {@see ProductMapper::map()} takes. Everything CS-Cart-specific about reading a
 * product lives here, so the mapper stays pure.
 *
 * Reads as an anonymous shopper (usergroups ALL + GUEST) in the store's default
 * language, so prices and descriptions are what a visitor sees. Verified on CS-Cart
 * 4.21.1 (the demo catalog): fn_get_product_data() returns price/list_price as
 * decimal strings, `main_pair`/`image_pairs` with `detailed.image_path` as full URLs.
 *
 * Variations (CS-Cart's Product Variations): every option of a product is a full
 * product in a variation group. A parent (`product_type` P, `parent_product_id` 0)
 * is a catalog card and is itself buyable; its children (`V`, `parent_product_id` =
 * the parent) are the other options of that card, chosen on its page. The sync unit
 * is the parent: it is sent with its active children as `variations`, and a child is
 * never sent on its own. Each product's option values come from the group's features
 * (e.g. Color: Blue, Size: Small).
 *
 * Features: the ones the Catalog data checklist sends (CatalogFeatures) - by default those
 * the storefront shows anywhere: its page, the catalog, or the product header (where the
 * demo's Brand lives). Read with CS-Cart's own fn_get_product_features_list() (its export
 * zone, which also returns Hidden features, for a merchant who ticked one), so category
 * scope and feature groups are decided the way the storefront decides them.
 *
 * Classic options (not Variations): the product's own and the global options linked to it
 * that a shopper picks one value of (select box, radio group), active, with their active
 * values - name, price modifier, picture (`variant_image`). ProductMapper decides what to
 * send. Read for a product, not for a variation child (which travels inside its parent).
 *
 * Bundles (CS-Cart's Product bundles add-on, when active): the active bundles a
 * product is in, read through that add-on's own service so its status, date window
 * and validity rules decide, the way the product page shows them.
 */
final class ProductSource
{
    private const GUEST_AUTH = ['user_id' => 0, 'usergroup_ids' => [0, 1], 'area' => 'C'];

    /** Option types a shopper picks one value of: select box, radio group (not checkbox, text, file). */
    private const CHOICE_TYPES = ['S', 'R'];

    /** CS-Cart 4.11's "Track with options" (`tracking` O): each option combination has its own stock. */
    private const TRACK_WITH_OPTIONS = 'O';

    /**
     * Ids of every product the sync sends: active products that are not a variation
     * child (children travel inside their parent), of an active vendor. Ascending.
     *
     * @return list<int>
     */
    public function activeIds(): array
    {
        return array_map('intval', db_get_fields('SELECT product_id FROM ?:products WHERE status = ?s AND parent_product_id = 0?p ORDER BY product_id', 'A', self::vendorCondition()));
    }

    public function countActive(): int
    {
        return (int) db_get_field('SELECT COUNT(*) FROM ?:products WHERE status = ?s AND parent_product_id = 0?p', 'A', self::vendorCondition());
    }

    /**
     * Every active product id of an active vendor, children included (the chat can
     * name either).
     *
     * @return list<int>
     */
    public function allActiveIds(): array
    {
        return array_map('intval', db_get_fields('SELECT product_id FROM ?:products WHERE status = ?s?p', 'A', self::vendorCondition()));
    }

    /**
     * Every product of a vendor that the sync would send were the vendor active (to
     * queue them when the vendor is suspended or reactivated).
     *
     * @return list<int>
     */
    public function vendorProductIds(int $companyId): array
    {
        return array_map('intval', db_get_fields('SELECT product_id FROM ?:products WHERE company_id = ?i AND parent_product_id = 0 ORDER BY product_id', $companyId));
    }

    /**
     * Multi-Vendor hides a vendor's products from the storefront unless the vendor is
     * active (a suspended, pending or disabled vendor's products do not show). The
     * sync mirrors it, so Quissly never answers with a product the storefront then
     * hides (an empty results page). Other editions have no vendors: no condition.
     */
    private static function vendorCondition(): string
    {
        if (!self::isMultiVendor()) {
            return '';
        }

        return db_quote(' AND (company_id = 0 OR company_id IN (SELECT company_id FROM ?:companies WHERE status = ?s))', 'A');
    }

    private static function isMultiVendor(): bool
    {
        return function_exists('fn_allowed_for') && fn_allowed_for('MULTIVENDOR');
    }

    /** Whether the product's vendor lets the storefront show it. */
    public static function vendorActive(int $companyId): bool
    {
        if ($companyId === 0 || !self::isMultiVendor()) {
            return true;
        }

        return db_get_field('SELECT status FROM ?:companies WHERE company_id = ?i', $companyId) === 'A';
    }

    /** The variation parent of a product, or 0 when it is not a child. */
    public function parentOf(int $productId): int
    {
        return (int) db_get_field('SELECT parent_product_id FROM ?:products WHERE product_id = ?i', $productId);
    }

    /**
     * The normalized product (whatever its status — the worker decides), or null
     * when CS-Cart no longer has it.
     *
     * @return ?array<string,mixed>
     */
    public function load(int $productId, bool $withVariations = true): ?array
    {
        $auth = self::GUEST_AUTH;
        // (id, auth, lang, field list, get_add_pairs, get_main_pair, get_taxes,
        //  get_qty_discounts, preview, features, skip_company_condition)
        $p = fn_get_product_data($productId, $auth, CART_LANGUAGE, '', true, true, true, false, false, false, true);
        if (empty($p) || empty($p['product_id'])) {
            return null;
        }

        $categoryIds = array_map('intval', (array) ($p['category_ids'] ?? []));
        $mainCategory = (int) ($p['main_category'] ?? ($categoryIds[0] ?? 0));
        $categoryNames = [];
        foreach ($categoryIds as $categoryId) {
            $name = (string) fn_get_category_name($categoryId, CART_LANGUAGE);
            if ($name !== '') {
                $categoryNames[] = $name;
            }
        }

        return [
            'product_id'           => (int) $p['product_id'],
            'status'               => (string) ($p['status'] ?? ''),
            // false = a Multi-Vendor vendor that is not active: the storefront hides the
            // product, so the worker removes it from Quissly like a disabled one.
            'vendor_active'        => self::vendorActive((int) ($p['company_id'] ?? 0)),
            'parent_product_id'    => (int) ($p['parent_product_id'] ?? 0),
            'options'              => $this->options((int) $p['product_id']),
            'product_options'      => (int) ($p['parent_product_id'] ?? 0) === 0 ? $this->productOptions((int) $p['product_id'], (string) ($p['tracking'] ?? '')) : [],
            'features'             => self::features($p),
            'variations'           => $withVariations && (int) ($p['parent_product_id'] ?? 0) === 0 ? $this->variations((int) $p['product_id']) : [],
            'product'              => (string) ($p['product'] ?? ''),
            'product_code'         => (string) ($p['product_code'] ?? ''),
            'price'                => $p['price'] ?? null,
            'list_price'           => $p['list_price'] ?? null,
            'amount'               => $p['amount'] ?? 0,
            'tracking'             => (string) ($p['tracking'] ?? ''),
            'out_of_stock_actions' => (string) ($p['out_of_stock_actions'] ?? ''),
            'full_description'     => (string) ($p['full_description'] ?? ''),
            'short_description'    => (string) ($p['short_description'] ?? ''),
            'category_name'        => $mainCategory > 0 ? (string) fn_get_category_name($mainCategory, CART_LANGUAGE) : '',
            'category_names'       => $categoryNames,
            'images'               => self::images($p),
            'url'                  => (string) fn_url('products.view?product_id=' . (int) $p['product_id'], 'C', 'https'),
            'company'              => !empty($p['company_id']) ? (string) fn_get_company_name((int) $p['company_id']) : '',
            'bundles'              => $this->bundles((int) $p['product_id']),
            // For Quissly's newest-first and popularity sorts: what CS-Cart's own `timestamp`
            // and `popularity` sorts order by (products.timestamp, product_popularity.total).
            'timestamp'            => (int) ($p['timestamp'] ?? 0),
            'popularity'           => self::popularity($p),
        ];
    }

    /**
     * CS-Cart's popularity score of the product (product_popularity.total: views, adds to
     * cart, removals and purchases, weighted), 0 when it has none. fn_get_product_data
     * joins it as `popularity`; read directly where it did not.
     *
     * @param array<string,mixed> $p the product as fn_get_product_data() returns it
     */
    private static function popularity(array $p): int
    {
        $total = array_key_exists('popularity', $p)
            ? $p['popularity']
            : db_get_field('SELECT total FROM ?:product_popularity WHERE product_id = ?i', (int) $p['product_id']);

        return max(0, (int) $total);
    }

    /**
     * The product ids a bundle links (to re-send them when the bundle changes); empty
     * when the Product bundles add-on is not installed.
     *
     * @return list<int>
     */
    public function bundleMemberIds(int $bundleId): array
    {
        if (!self::bundlesActive()) {
            return [];
        }

        return array_map('intval', db_get_fields('SELECT DISTINCT product_id FROM ?:product_bundle_product_links WHERE bundle_id = ?i', $bundleId));
    }

    /**
     * The active bundles this product is in: name, description, full and bundle
     * price, members (a member listed more than once is counted up).
     *
     * @return list<array<string,mixed>>
     */
    private function bundles(int $productId): array
    {
        if (!self::bundlesActive()) {
            return [];
        }
        [$bundles] = \Tygh\Addons\ProductBundles\ServiceProvider::getService()->getBundles([
            'product_id'     => $productId,
            'full_info'      => true,
            'status'         => 'A',
            'active'         => true,
            'with_image'     => false,
            'items_per_page' => 0,
            'area'           => 'C',
            'lang_code'      => CART_LANGUAGE,
        ]);
        $out = [];
        foreach ((array) $bundles as $bundle) {
            $items = [];
            foreach ((array) ($bundle['products'] ?? []) as $item) {
                $id = (int) ($item['product_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $items[$id] = $items[$id] ?? ['product_id' => $id, 'product' => (string) ($item['product_name'] ?? ''), 'amount' => 0];
                $items[$id]['amount'] += (int) ($item['amount'] ?? 1);
            }
            $out[] = [
                'bundle_id'        => (int) ($bundle['bundle_id'] ?? 0),
                'name'             => (string) (($bundle['storefront_name'] ?? '') !== '' ? $bundle['storefront_name'] : ($bundle['name'] ?? '')),
                'description'      => (string) ($bundle['description'] ?? ''),
                'total_price'      => $bundle['total_price'] ?? null,
                'discounted_price' => $bundle['discounted_price'] ?? null,
                'items'            => array_values($items),
            ];
        }

        return $out;
    }

    private static function bundlesActive(): bool
    {
        return \Tygh\Registry::get('addons.product_bundles.status') === 'A'
            && class_exists(\Tygh\Addons\ProductBundles\ServiceProvider::class);
    }

    /**
     * The active variation children of a parent, normalized (without children of
     * their own).
     *
     * @return list<array<string,mixed>>
     */
    private function variations(int $parentId): array
    {
        $children = [];
        foreach (db_get_fields('SELECT product_id FROM ?:products WHERE parent_product_id = ?i AND status = ?s ORDER BY product_id', $parentId, 'A') as $childId) {
            $child = $this->load((int) $childId, false);
            if ($child !== null) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * The product's values for its variation group's features, feature name => value
     * (e.g. ['Color' => 'Blue', 'Size' => 'Small']); empty outside a group.
     *
     * @return array<string, string>
     */
    private function options(int $productId): array
    {
        $rows = db_get_array(
            'SELECT fd.description AS name, vd.variant AS value'
            . ' FROM ?:product_variation_group_products gp'
            . ' JOIN ?:product_variation_group_features gf ON gf.group_id = gp.group_id'
            . ' JOIN ?:product_features_values fv ON fv.feature_id = gf.feature_id AND fv.product_id = gp.product_id AND fv.lang_code = ?s'
            . ' JOIN ?:product_features_descriptions fd ON fd.feature_id = gf.feature_id AND fd.lang_code = ?s'
            . ' JOIN ?:product_feature_variant_descriptions vd ON vd.variant_id = fv.variant_id AND vd.lang_code = ?s'
            . ' WHERE gp.product_id = ?i ORDER BY gf.feature_id',
            CART_LANGUAGE,
            CART_LANGUAGE,
            CART_LANGUAGE,
            $productId
        );
        $options = [];
        foreach ($rows ?: [] as $row) {
            if ((string) $row['name'] !== '' && (string) $row['value'] !== '') {
                $options[(string) $row['name']] = (string) $row['value'];
            }
        }

        return $options;
    }

    /**
     * The product's classic options a shopper picks one value of (see the class comment), in
     * the product's option order, values in theirs - the order fn_get_product_options() uses.
     *
     * @return list<array{option_id:int,name:string,values:list<array{variant_id:int,name:string,modifier:string,modifier_type:string,image:string,in_stock:?bool}>}>
     */
    private function productOptions(int $productId, string $tracking): array
    {
        // ?:product_options un-aliased: see optionVariantIds().
        $options = db_get_hash_array(
            'SELECT ?:product_options.option_id, d.option_name FROM ?:product_options'
            . ' LEFT JOIN ?:product_options_descriptions d ON d.option_id = ?:product_options.option_id AND d.lang_code = ?s'
            . ' WHERE ?:product_options.status = ?s AND ?:product_options.option_type IN (?a)'
            . ' AND (?:product_options.product_id = ?i OR (?:product_options.product_id = 0 AND ?:product_options.option_id IN (SELECT option_id FROM ?:product_global_option_links WHERE product_id = ?i)))'
            . ' ORDER BY ?:product_options.position, ?:product_options.option_id',
            'option_id',
            CART_LANGUAGE,
            'A',
            self::CHOICE_TYPES,
            $productId,
            $productId
        );
        if (empty($options)) {
            return [];
        }
        $rows = db_get_array(
            'SELECT v.variant_id, v.option_id, v.modifier, v.modifier_type, vd.variant_name FROM ?:product_option_variants v'
            . ' LEFT JOIN ?:product_option_variants_descriptions vd ON vd.variant_id = v.variant_id AND vd.lang_code = ?s'
            . ' WHERE v.option_id IN (?n) AND v.status = ?s ORDER BY v.position, v.variant_id',
            CART_LANGUAGE,
            array_keys($options),
            'A'
        );
        $pictures = $rows ? fn_get_image_pairs(array_column($rows, 'variant_id'), 'variant_image', 'V', true, true, CART_LANGUAGE) : [];
        $stock = $tracking === self::TRACK_WITH_OPTIONS ? self::valueStock($productId) : [];

        $values = [];
        foreach ($rows ?: [] as $row) {
            $id = (int) $row['variant_id'];
            $pair = !empty($pictures[$id]) ? (array) reset($pictures[$id]) : [];
            $values[(int) $row['option_id']][] = [
                'variant_id'    => $id,
                'name'          => (string) ($row['variant_name'] ?? ''),
                'modifier'      => (string) $row['modifier'],
                'modifier_type' => (string) $row['modifier_type'],
                'image'         => self::pairUrl($pair),
                'in_stock'      => $stock[(int) $row['option_id'] . '_' . $id] ?? null,
            ];
        }
        $out = [];
        foreach ($options as $optionId => $option) {
            $out[] = ['option_id' => (int) $optionId, 'name' => (string) ($option['option_name'] ?? ''), 'values' => $values[(int) $optionId] ?? []];
        }
        $colour = ProductMapper::colourOption($out);
        if ($colour !== null && count($colour['values']) > ProductMapper::MAX_OPTION_VARIANTS) {
            \Quissly\Search\Logger::notice('Product option has more values than are sent as variants', [
                'product_id' => $productId, 'option_id' => $colour['option_id'], 'values' => count($colour['values']), 'sent' => ProductMapper::MAX_OPTION_VARIANTS,
            ]);
        }

        return $out;
    }

    /**
     * CS-Cart 4.11, a product tracked with options: whether each option value is in stock in
     * some combination ("<option>_<value>" => bool, from ?:product_options_inventory, whose
     * combination is "<option>_<value>_<option>_<value>..."). Later versions have no such stock.
     *
     * @return array<string, bool>
     */
    private static function valueStock(int $productId): array
    {
        $stock = [];
        foreach (db_get_array('SELECT combination, amount FROM ?:product_options_inventory WHERE product_id = ?i', $productId) ?: [] as $row) {
            $parts = explode('_', (string) $row['combination']);
            for ($i = 0; $i + 1 < count($parts); $i += 2) {
                $key = (int) $parts[$i] . '_' . (int) $parts[$i + 1];
                $stock[$key] = ($stock[$key] ?? false) || (int) $row['amount'] > 0;
            }
        }

        return $stock;
    }

    /**
     * Every option value an active product could send as a variant, as its Quissly id
     * (ProductMapper::optionVariantId) => product id: a superset of what the sync sends (any
     * select box or radio group), for translating the ids the chat names.
     *
     * @return array<string, int>
     */
    public function optionVariantIds(): array
    {
        // ?:product_options is never aliased, and these are two queries, not a UNION: on CS-Cart
        // Ultimate's storefront (a company selected) a SELECT whose first table is a shared one
        // (product_options, product_features, product_filters, pages, profile_fields) gets
        // `INNER JOIN ?:ult_objects_sharing ON ... = ?:product_options.option_id` added
        // (fn_ult_db_query_process) - an alias left that name undefined and the query died
        // ("Unknown column"), which broke the chat's add to cart. Unaliased, the sharing applies.
        $own = db_get_array(
            'SELECT ?:product_options.product_id, ?:product_options.option_id, v.variant_id FROM ?:product_options'
            . ' JOIN ?:product_option_variants v ON v.option_id = ?:product_options.option_id'
            . ' JOIN ?:products p ON p.product_id = ?:product_options.product_id AND p.status = ?s'
            . ' WHERE ?:product_options.product_id > 0 AND ?:product_options.status = ?s'
            . ' AND ?:product_options.option_type IN (?a) AND v.status = ?s',
            'A', 'A', self::CHOICE_TYPES, 'A'
        );
        $global = db_get_array(
            'SELECT l.product_id, ?:product_options.option_id, v.variant_id FROM ?:product_options'
            . ' JOIN ?:product_global_option_links l ON l.option_id = ?:product_options.option_id'
            . ' JOIN ?:product_option_variants v ON v.option_id = ?:product_options.option_id'
            . ' JOIN ?:products p ON p.product_id = l.product_id AND p.status = ?s'
            . ' WHERE ?:product_options.product_id = 0 AND ?:product_options.status = ?s'
            . ' AND ?:product_options.option_type IN (?a) AND v.status = ?s',
            'A', 'A', self::CHOICE_TYPES, 'A'
        );
        $ids = [];
        foreach (array_merge($own ?: [], $global ?: []) as $row) {
            $ids[ProductMapper::optionVariantId((int) $row['product_id'], (int) $row['option_id'], (int) $row['variant_id'])] = (int) $row['product_id'];
        }

        return $ids;
    }

    /**
     * Whether $variantId is an active value of an active option a shopper picks one value of,
     * the product's own or a global one linked to it - the pairs optionVariantIds() covers (to
     * check one sent by the browser).
     */
    public function hasOptionValue(int $productId, int $optionId, int $variantId): bool
    {
        // ?:product_options un-aliased: see optionVariantIds().
        return (bool) db_get_field(
            'SELECT 1 FROM ?:product_options'
            . ' JOIN ?:product_option_variants v ON v.option_id = ?:product_options.option_id AND v.variant_id = ?i AND v.status = ?s'
            . ' WHERE ?:product_options.option_id = ?i AND ?:product_options.status = ?s AND ?:product_options.option_type IN (?a)'
            . ' AND (?:product_options.product_id = ?i OR (?:product_options.product_id = 0 AND ?:product_options.option_id IN (SELECT option_id FROM ?:product_global_option_links WHERE product_id = ?i)))',
            $variantId,
            'A',
            $optionId,
            'A',
            self::CHOICE_TYPES,
            $productId,
            $productId
        );
    }

    /**
     * The product's features that go to Quissly, feature name => display value ("Brand" =>
     * "Samsung", "Ports" => "HDMI, USB", "Storage Capacity" => "64 GB"): the Catalog data
     * checklist decides (CatalogFeatures::included()); an unticked checkbox is left out.
     *
     * @param array<string,mixed> $p the product as fn_get_product_data() returns it
     * @return array<string, string>
     */
    private static function features(array $p): array
    {
        static $status = null, $choices = null;
        if ($status === null) {
            $status = db_get_hash_single_array('SELECT feature_id, status FROM ?:product_features', ['feature_id', 'status']);
            $choices = CatalogFeatures::choices(new DbSyncStore());
        }
        $out = [];
        // 'EXIM' = every feature; a string, as CS-Cart 4.11 takes it (the enum class ProductFeaturesDisplayOn,
        // with the same value, only exists from 4.12 - on 4.11 it is missing and the sync died).
        foreach (fn_get_product_features_list($p, 'EXIM', CART_LANGUAGE) as $f) {
            $id = (int) ($f['feature_id'] ?? 0);
            $shown = ($status[$id] ?? '') === 'A'
                && (($f['display_on_product'] ?? '') === 'Y' || ($f['display_on_catalog'] ?? '') === 'Y' || ($f['display_on_header'] ?? '') === 'Y');
            $name = trim((string) ($f['description'] ?? ''));
            if (!CatalogFeatures::included($id, $shown, $choices) || $name === '') {
                continue;
            }
            $value = self::featureValue($f);
            if ($value !== '') {
                $out[$name] = trim((string) ($f['prefix'] ?? '') . $value . (string) ($f['suffix'] ?? ''));
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $f one feature of fn_get_product_features_list() */
    private static function featureValue(array $f): string
    {
        $type = (string) ($f['feature_type'] ?? '');
        if ($type === \Tygh\Enum\ProductFeatures::SINGLE_CHECKBOX) {
            return ($f['value'] ?? '') === 'Y' ? (string) __('yes') : '';
        }
        if (!empty($f['variants'])) {
            $names = array_filter(array_map(static fn ($v): string => trim((string) ($v['variant'] ?? '')), (array) $f['variants']), static fn (string $v): bool => $v !== '');

            return implode(', ', $names);
        }
        if ($type === \Tygh\Enum\ProductFeatures::DATE) {
            return (int) ($f['value_int'] ?? 0) > 0 ? date('Y-m-d', (int) $f['value_int']) : '';
        }
        if ($type === \Tygh\Enum\ProductFeatures::NUMBER_FIELD) {
            return is_numeric($f['value_int'] ?? null) ? (string) (float) $f['value_int'] : '';
        }

        return trim((string) ($f['value'] ?? ''));
    }

    /**
     * Main image first, then the additional ones; the detailed (full-size) image of
     * each pair, else its icon.
     *
     * @param array<string,mixed> $p
     * @return list<string>
     */
    private static function images(array $p): array
    {
        $pairs = [];
        if (!empty($p['main_pair'])) {
            $pairs[] = $p['main_pair'];
        }
        foreach ((array) ($p['image_pairs'] ?? []) as $pair) {
            $pairs[] = $pair;
        }

        $urls = [];
        foreach ($pairs as $pair) {
            $url = self::pairUrl((array) $pair);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * An image pair's detailed (full-size) image, else its icon; '' when it has neither.
     *
     * @param array<string,mixed> $pair
     */
    private static function pairUrl(array $pair): string
    {
        return (string) ($pair['detailed']['image_path'] ?? ($pair['icon']['image_path'] ?? ''));
    }
}
