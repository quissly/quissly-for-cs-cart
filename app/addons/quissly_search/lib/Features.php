<?php

declare(strict_types=1);

namespace Quissly\Search;

use Tygh\Registry;

/**
 * The storefront feature switches (add-on settings), the same six as
 * the WooCommerce plugin and the Magento plugin, and which of them this add-on
 * has built. A switch for an unbuilt feature is saved but changes nothing yet —
 * so its Settings tooltip and the dashboard say so, instead of offering a control
 * that silently does nothing (the name itself matches the other plugins, e.g.
 * "Quick Recommendations"). Flip `built` as each ships.
 */
final class Features
{
    /** setting id => [label langvar, built] */
    public const ALL = [
        'search_interception' => ['quissly_search.feature_qsearch', true],
        'enable_overlay'      => ['quissly_search.feature_overlay', true],
        'enable_quick'        => ['quissly_search.feature_quick', false],
        'enable_voice'        => ['quissly_search.feature_voice', true],
        'enable_image'        => ['quissly_search.feature_image', true],
        'enable_qchat'        => ['quissly_search.feature_qchat', true],
    ];

    /** Defaults when a setting was never saved (matches addon.xml). */
    private const DEFAULT_ON = ['search_interception', 'enable_overlay'];

    /**
     * Quick Recommendations style (setting quick_style), the Magento plugin's two: a list
     * (thumbnail, name and price per row) or a carousel of large cards. Presentation only.
     */
    public const QUICK_STYLES = ['rows', 'carousel'];

    /**
     * @param callable(string): mixed|null $read setting id -> stored value ('Y'/'N'/null)
     * @return list<array{id:string, label:string, on:bool, built:bool}>
     */
    public static function all(?callable $read = null): array
    {
        $read = $read ?? static fn (string $id) => Registry::get('addons.quissly_search.' . $id);
        $list = [];
        foreach (self::ALL as $id => [$label, $built]) {
            $value = $read($id);
            $on = $value === null || $value === '' ? in_array($id, self::DEFAULT_ON, true) : $value === 'Y';
            $list[] = ['id' => $id, 'label' => $label, 'on' => $on, 'built' => $built];
        }

        return $list;
    }

    /**
     * The Quick Recommendations style to render: 'rows' (the default) or 'carousel'.
     *
     * @param callable(string): mixed|null $read setting id -> stored value
     */
    public static function quickStyle(?callable $read = null): string
    {
        $read = $read ?? static fn (string $id) => Registry::get('addons.quissly_search.' . $id);
        $value = (string) $read('quick_style');

        return in_array($value, self::QUICK_STYLES, true) ? $value : self::QUICK_STYLES[0];
    }

    private function __construct()
    {
    }
}
