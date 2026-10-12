<?php
/**
 * Quissly for CS-Cart — the add-on settings page's own Save also saves Configuration's
 * "Search Examples" and "Search Suggestions" (lib/Admin/SuggestionsView.php; written to Quissly,
 * only when changed) and "Catalog data" checklist (lib/Admin/CatalogView.php), all drawn inside
 * that form. Runs before CS-Cart's addons.update, which ignores these fields. A changed
 * checklist re-sends the whole catalog, so existing products pick it up
 * (the Magento plugin does the same).
 */

use Quissly\Search\Credentials;
use Quissly\Search\Panel\QChatDirectory;
use Quissly\Search\SearchSuggestions;
use Quissly\Search\Sync\CatalogFeatures;
use Quissly\Search\Sync\DbSyncStore;
use Quissly\Search\Sync\ProductSource;
use Quissly\Search\Sync\Sync;

defined('BOOTSTRAP') or die('Access denied');

// Until Quissly Setup is finished, the menu's Configuration entry opens Setup instead (the
// Dashboard and the panel redirect in quissly_search.php).
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    && $mode === 'update'
    && ($_REQUEST['addon'] ?? '') === 'quissly_search'
    && !(new \Quissly\Search\Setup\Onboarding(new DbSyncStore(), Credentials::fromRegistry()->isConfigured(), time()))->isComplete()
) {
    return [CONTROLLER_STATUS_REDIRECT, 'quissly_search.setup'];
}

$quisslySaving = $_SERVER['REQUEST_METHOD'] === 'POST'
    && $mode === 'update'
    && ($_REQUEST['addon'] ?? '') === 'quissly_search';

// Search Examples: kept in Quissly, per language; written back only when they changed.
if ($quisslySaving && isset($_REQUEST['quissly_suggestions_present'])) {
    $store = new DbSyncStore();
    $serviceId = QChatDirectory::cachedSearchServiceId($store);
    $main = fn_quissly_search_main_language();
    $lists = fn_quissly_search_posted_lists('quissly_examples');
    $current = SearchSuggestions::read($serviceId, fn_quissly_search_http_get(10));
    if ($lists !== null && $current !== null) {
        $queries = $lists[$main] ?? [];
        unset($lists[$main]);
        $enabled = ($_REQUEST['quissly_typing_enabled'] ?? '') === 'Y';
        $error = '';
        foreach (array_merge([$queries], array_values($lists)) as $list) {
            $error = $error !== '' ? $error : SearchSuggestions::validate($list);
        }
        if ($error === '' && ($current['enabled'] !== $enabled || $current['queries'] !== $queries || $current['by_language'] !== array_filter($lists))) {
            // The merchant's own lists from now on: Quissly's generator keeps any list that
            // differs from what it generated, so nothing is recorded here.
            $error = SearchSuggestions::save(
                $store, Credentials::fromRegistry(), $serviceId, $enabled, $queries, $lists, $main,
                fn_quissly_search_http_post(10), fn_quissly_search_http_get(10), fn_quissly_search_http_put(10)
            );
        }
        if ($error !== '') {
            fn_quissly_search_suggestions_error($error);
        }
    }
}

// Search Suggestions: the buttons' choice and Manual lists, kept in Quissly (and a copy here).
if ($quisslySaving && isset($_REQUEST['quissly_buttons_present'])) {
    $store = new DbSyncStore();
    $serviceId = QChatDirectory::cachedSearchServiceId($store);
    $buttonsMode = ($_REQUEST['quissly_chips_mode'] ?? '') === 'manual' ? 'manual' : 'automatic';
    $lists = fn_quissly_search_posted_lists('quissly_chips_manual');
    $error = '';
    if ($lists === null) {
        $lists = SearchSuggestions::localButtons($store)['lists'];
    }
    foreach ($lists as $list) {
        $error = $error !== '' ? $error : SearchSuggestions::validateButtons($list);
    }
    if ($error === '') {
        $current = SearchSuggestions::read($serviceId, fn_quissly_search_http_get(10));
        $local = SearchSuggestions::localButtons($store);
        $unchanged = $current !== null && $current['chips_mode'] === $buttonsMode && $current['manual_chips'] === array_filter($lists);
        if (!$unchanged || $local['mode'] !== $buttonsMode || $local['lists'] !== array_filter($lists)) {
            $error = SearchSuggestions::saveButtons(
                $store, Credentials::fromRegistry(), $serviceId, $buttonsMode, $lists,
                fn_quissly_search_http_post(10), fn_quissly_search_http_get(10), fn_quissly_search_http_put(10)
            );
        }
    }
    if ($error !== '') {
        fn_quissly_search_suggestions_error($error);
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $mode === 'update'
    && ($_REQUEST['addon'] ?? '') === 'quissly_search'
    && isset($_REQUEST['quissly_catalog_offered'])
) {
    $changed = CatalogFeatures::save(
        new DbSyncStore(),
        (array) $_REQUEST['quissly_catalog_offered'],
        (array) ($_REQUEST['quissly_catalog_features'] ?? [])
    );
    if ($changed && Credentials::fromRegistry()->isConfigured()) {
        // Queued only: the cron (or the Dashboard's "Send queued") sends it.
        Sync::worker()->startFullSync((new ProductSource())->activeIds());
        fn_set_notification('N', __('notice'), __('quissly_search.catalog_resync'));
    }
}
