<?php
/**
 * Quissly for CS-Cart — one "Quissly" entry in the Add-ons menu with the Magento plugin's
 * three pages in its order: Configuration (the add-on's settings, with setup at the top
 * until the store is connected), Dashboard (sync log included) and the embedded Quissly
 * Admin Panel.
 *
 * It sits in the Add-ons section rather than being a section of its own: CS-Cart 4.21
 * drops top-level menu sections added by third-party add-ons
 * (Tygh\BackendMenu::cleanUpTopLevelMenus keeps only core ones). Within that section the
 * pages are `subitems` of a single entry, so the menu shows one "Quissly" line instead
 * of three. Titles come from the langvars named by the keys.
 */

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
// Right after "Downloaded add-ons" (position 1), before "Upgrades" (10).
$schema['top']['addons']['items']['quissly'] = [
    'href'     => 'addons.update?addon=quissly_search',
    'position' => 2,
    'attrs'    => [
        'class' => 'is-addon',
    ],
    'subitems' => [
        'quissly_settings'    => ['href' => 'addons.update?addon=quissly_search', 'position' => 10],
        'quissly_menu_dashboard' => ['href' => 'quissly_search.dashboard', 'position' => 20],
        'quissly_admin_panel' => ['href' => 'quissly_search.panel', 'position' => 30],
    ],
];

return $schema;
