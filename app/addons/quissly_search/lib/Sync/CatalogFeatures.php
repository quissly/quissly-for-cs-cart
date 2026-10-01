<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

/**
 * Catalog data: which product features go to Quissly (the Magento plugin's "Product
 * attributes sent to Quissly").
 *
 * The merchant's checklist is stored as DEVIATIONS from the default: a feature is sent
 * when the storefront shows it (Active, and shown on the product page, the catalog or the
 * product header), unless the merchant ticked a hidden one on (true) or unticked a shown
 * one off (false). Storing only the deviations means a feature created later just follows
 * its storefront settings, and saving the list unchanged changes nothing. A Disabled
 * feature is never offered or sent. Variation-group features (Color, Size) always go as
 * the options that tell the variants apart, whatever this list says.
 *
 * included() and choicesFrom() are pure; the rest reads CS-Cart.
 */
final class CatalogFeatures
{
    public const STATE = 'catalog_choices';

    /**
     * Whether a feature goes to Quissly.
     *
     * @param array<int, bool> $choices feature_id => bool
     */
    public static function included(int $featureId, bool $shown, array $choices): bool
    {
        return array_key_exists($featureId, $choices) ? (bool) $choices[$featureId] : $shown;
    }

    /**
     * The choices a submitted checklist amounts to: only the features whose tick differs
     * from what the list showed pre-ticked.
     *
     * @param array<int, bool> $defaults offered feature_id => pre-ticked (shown on the storefront)
     * @param list<int|string> $ticked   feature ids ticked on submit
     * @return array<int, bool>
     */
    public static function choicesFrom(array $defaults, array $ticked): array
    {
        $ticked = array_flip(array_map('intval', $ticked));
        $choices = [];
        foreach ($defaults as $id => $default) {
            $on = isset($ticked[(int) $id]);
            if ($on !== (bool) $default) {
                $choices[(int) $id] = $on;
            }
        }
        ksort($choices);

        return $choices;
    }

    /** @return array<int, bool> */
    public static function choices(SyncStore $store): array
    {
        $choices = [];
        foreach ((array) $store->getState(self::STATE) as $id => $on) {
            $choices[(int) $id] = (bool) $on;
        }

        return $choices;
    }

    /**
     * Every feature that is not Disabled and not a group, id => {name, shown, products,
     * variation}: whether the storefront shows it, how many products carry it, and in how
     * many variation groups it tells the variants apart. Sorted by name.
     *
     * @return array<int, array{name:string, shown:bool, products:int, variation:int}>
     */
    public static function catalog(): array
    {
        $rows = db_get_array(
            'SELECT f.feature_id, fd.description AS name, f.status, f.display_on_product, f.display_on_catalog, f.display_on_header,'
            . ' (SELECT COUNT(DISTINCT v.product_id) FROM ?:product_features_values v WHERE v.feature_id = f.feature_id AND v.lang_code = ?s) AS products,'
            . ' (SELECT COUNT(*) FROM ?:product_variation_group_features gf WHERE gf.feature_id = f.feature_id) AS variation'
            . ' FROM ?:product_features f'
            . ' JOIN ?:product_features_descriptions fd ON fd.feature_id = f.feature_id AND fd.lang_code = ?s'
            . ' WHERE f.feature_type != ?s AND f.status IN (?a)',
            CART_LANGUAGE,
            CART_LANGUAGE,
            'G',
            ['A', 'H']
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(int) $row['feature_id']] = [
                'name'      => (string) $row['name'],
                'shown'     => $row['status'] === 'A'
                    && ($row['display_on_product'] === 'Y' || $row['display_on_catalog'] === 'Y' || $row['display_on_header'] === 'Y'),
                'products'  => (int) $row['products'],
                'variation' => (int) $row['variation'],
            ];
        }
        uasort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Save a submitted checklist; true when the choices changed (so the catalog must be
     * re-sent).
     *
     * @param list<int|string> $offered feature ids the form listed
     * @param list<int|string> $ticked  feature ids ticked
     */
    public static function save(SyncStore $store, array $offered, array $ticked): bool
    {
        $catalog = self::catalog();
        $defaults = [];
        foreach (array_map('intval', $offered) as $id) {
            if (isset($catalog[$id])) {
                $defaults[$id] = $catalog[$id]['shown'];
            }
        }
        $choices = self::choicesFrom($defaults, $ticked);
        if ($choices === self::choices($store)) {
            return false;
        }
        $store->setState(self::STATE, $choices === [] ? null : $choices);

        return true;
    }

    private function __construct()
    {
    }
}
