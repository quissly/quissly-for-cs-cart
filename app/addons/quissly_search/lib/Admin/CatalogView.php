<?php

declare(strict_types=1);

namespace Quissly\Search\Admin;

use Quissly\Search\Sync\CatalogFeatures;

/**
 * Configuration's "Catalog data" block (the Magento plugin's): the product features sent
 * to Quissly, pre-ticked where the storefront shows them.
 *
 * Drawn INSIDE CS-Cart's settings form, so the ticks are saved by the page's own Save:
 * controllers/backend/addons.pre.php hands them to CatalogFeatures::save(). Each listed
 * feature also posts its id as "offered", so a feature missing from the ticks reads as
 * unticked rather than unknown.
 *
 * Pure: render() takes the catalog and the stored choices, returns escaped HTML.
 */
final class CatalogView
{
    /**
     * @param array<int, array{name:string, shown:bool, products:int, variation:int}> $catalog
     * @param array<int, bool> $choices
     */
    public static function render(array $catalog, array $choices): string
    {
        if ($catalog === []) {
            return '<p>' . self::e(self::t('quissly_search.catalog_none')) . '</p>';
        }

        $items = '';
        foreach ($catalog as $id => $feature) {
            $notes = [];
            if (!$feature['shown']) {
                $notes[] = self::t('quissly_search.catalog_hidden');
            }
            if ($feature['variation'] > 0) {
                $notes[] = self::t('quissly_search.catalog_variation');
            }
            $checked = CatalogFeatures::included((int) $id, $feature['shown'], $choices) ? ' checked' : '';
            $items .= '<input type="hidden" name="quissly_catalog_offered[]" value="' . (int) $id . '">'
                . '<label class="checkbox" style="display:block;break-inside:avoid;margin:0 0 6px">'
                . '<input type="checkbox" name="quissly_catalog_features[]" value="' . (int) $id . '"' . $checked . '> '
                . self::e($feature['name'])
                . ($notes !== [] ? ' <span class="muted">(' . self::e(implode('; ', $notes)) . ')</span>' : '')
                . '</label>';
        }

        return '<div class="quissly-catalog" style="columns:2 260px;max-width:760px">' . $items . '</div>'
            . '<p class="muted description" style="max-width:760px">' . self::e(self::t('quissly_search.catalog_help')) . '</p>';
    }

    private static function t(string $key): string
    {
        return function_exists('__') ? (string) __($key) : $key;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function __construct()
    {
    }
}
