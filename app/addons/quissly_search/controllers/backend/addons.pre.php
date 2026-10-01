<?php
/**
 * Quissly for CS-Cart — the add-on settings page's own Save also saves Configuration's
 * "Search bar suggestions" (lib/Admin/SuggestionsView.php; written to Quissly, only when
 * changed) and "Catalog data" checklist (lib/Admin/CatalogView.php), both drawn inside that
 * form. Runs before CS-Cart's addons.update, which ignores these fields. A changed
 * checklist re-sends the whole catalog, so existing products pick it up
 * (the Magento plugin does the same).
 */

use Quissly\Search\Credentials;
use Quissly\Search\Panel\QChatDirectory;
use Quissly\Search\SearchSuggestions;
use Quissly\Search\Showcase\ShowcaseRunner;
use Quissly\Search\Sync\CatalogFeatures;
use Quissly\Search\Sync\DbSyncStore;
use Quissly\Search\Sync\ProductSource;
use Quissly\Search\Sync\Sync;

defined('BOOTSTRAP') or die('Access denied');

// Search bar suggestions: kept in Quissly; written back only when they changed.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $mode === 'update'
    && ($_REQUEST['addon'] ?? '') === 'quissly_search'
    && isset($_REQUEST['quissly_suggestions_present'])
) {
    $store = new DbSyncStore();
    $credentials = Credentials::fromRegistry();
    $serviceId = QChatDirectory::cachedSearchServiceId($store);
    $queries = SearchSuggestions::clean(preg_split('/\r\n|\r|\n/', (string) ($_REQUEST['quissly_suggestions'] ?? '')));
    if (!empty($_REQUEST['quissly_suggestions_use_generated'])) {
        $queries = ShowcaseRunner::generated($store); // "use the generated suggestions"
    }
    $enabled = ($_REQUEST['quissly_typing_enabled'] ?? '') === 'Y';
    $error = SearchSuggestions::validate($queries);
    if ($error === '') {
        $current = SearchSuggestions::read($serviceId, fn_quissly_search_http_get(10));
        if ($current === null || $current['enabled'] !== $enabled || $current['queries'] !== $queries) {
            $error = SearchSuggestions::save(
                $store, $credentials, $serviceId, $enabled, $queries,
                fn_quissly_search_http_post(10), fn_quissly_search_http_get(10), fn_quissly_search_http_put(10)
            );
            if ($error === '' && ($current === null || $current['queries'] !== $queries)) {
                // The merchant's own list from now on: generation never writes over it.
                ShowcaseRunner::merchantSaved($store);
            }
        }
    }
    if ($error !== '') {
        fn_set_notification('E', __('error'), str_replace(
            ['[count]', '[length]'],
            [(string) SearchSuggestions::MAX_COUNT, (string) SearchSuggestions::MAX_LENGTH],
            (string) __($error)
        ));
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
