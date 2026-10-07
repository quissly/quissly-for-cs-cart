<?php
/**
 * Quissly for CS-Cart — hook callbacks.
 *
 * CS-Cart invokes `fn_<addon_id>_<hook_name>` for each registered hook. The real
 * work lives in the namespaced lib classes; these functions are thin adapters.
 */

use Quissly\Search\AdminStatusView;
use Quissly\Search\Admin\CatalogView;
use Quissly\Search\Admin\SetupView;
use Quissly\Search\Sync\CatalogFeatures;
use Quissly\Search\SearchSuggestions;
use Quissly\Search\Showcase\ShowcaseRunner;
use Quissly\Search\SearchInterceptor;
use Quissly\Search\SortMap;
use Quissly\Search\StatusReport;
use Quissly\Search\Sync\Sync;
use Quissly\Search\Credentials;
use Quissly\Search\Features;
use Quissly\Search\Panel\QChatDirectory;
use Quissly\Search\Storefront;
use Quissly\Search\Sync\DbSyncStore;
use Quissly\Search\Sync\SyncWorker;

defined('BOOTSTRAP') or die('Access denied');

// CS-Cart loads func.php during installation (to call settings handlers) before
// the add-on's init.php runs, so register the autoloader here too. Idempotent.
require_once __DIR__ . '/autoload.php';

/**
 * Settings-page status block (info-type setting handler).
 *
 * Returns final HTML rendered into the add-on's Settings page: ACTIVE/INACTIVE
 * with reasons, configured environment, any persisted storefront auth alert, and
 * a "Test connection" button. Returning a string (rather than fetching a .tpl)
 * keeps the add-on fully self-contained under app/addons/.
 */
/** The signed-in admin's row (email, names), for Connect. */
function fn_quissly_search_admin_user(): array
{
    $userId = (int) (Tygh::$app['session']['auth']['user_id'] ?? 0);

    return $userId > 0 ? (array) db_get_row('SELECT email, firstname, lastname FROM ?:users WHERE user_id = ?i', $userId) : [];
}

/**
 * X-Quissly-Search on a storefront request carrying ?quissly_debug=1 (lib/SearchOrigin):
 * after the controller ran the search, before any output.
 */
function fn_quissly_search_dispatch_before_display()
{
    $value = \Quissly\Search\SearchSignal::headerValue();
    if (AREA === 'C' && $value !== null && \Quissly\Search\SearchOrigin::asked($_REQUEST) && !headers_sent()) {
        header('X-Quissly-Search: ' . $value);
    }
    // The daily update check, for a store whose cron is not set up: at the end of an admin
    // page, once the page is built (the cron dispatch runs it too; lib/Update/).
    if (AREA === 'A') {
        register_shutdown_function(static function () {
            \Quissly\Search\Update\Updater::runIfDue(new DbSyncStore(), time());
        });
    }
}

/**
 * Uninstall: the automatic update's work folder (lib/Update/Updater) holds the last
 * version's files as a backup; it goes with the add-on.
 */
function fn_quissly_search_uninstall()
{
    fn_rm(rtrim((string) \Tygh\Registry::get('config.dir.var'), '/') . '/quissly_search_update');
}

/**
 * The ?quissly_debug=1 badge (lib/SearchOrigin), drawn by hooks/index/scripts.post.tpl.
 */
function fn_quissly_search_debug_badge(): string
{
    return \Quissly\Search\SearchOrigin::badge(\Quissly\Search\SearchOrigin::asked($_REQUEST), [
        'quissly' => (string) __('quissly_search.origin_quissly'),
        'native'  => (string) __('quissly_search.origin_native'),
        'skipped' => (string) __('quissly_search.origin_skipped'),
    ]);
}

/**
 * Configuration's "Catalog data" block: the features sent to Quissly (lib/Admin/CatalogView).
 */
function fn_quissly_search_catalog_handler()
{
    return CatalogView::render(CatalogFeatures::catalog(), CatalogFeatures::choices(new DbSyncStore()));
}

function fn_quissly_search_status_handler()
{
    $credentials = Credentials::fromRegistry();
    $admin = fn_quissly_search_admin_user();

    return '<h4>' . htmlspecialchars((string) __('quissly_search.setup_title'), ENT_QUOTES, 'UTF-8') . '</h4>'
        . SetupView::render([
            'source'        => $credentials->source(),
            'account_email' => $credentials->accountEmail(),
            'environment'   => $credentials->environment(),
            'admin_email'   => (string) ($admin['email'] ?? ''),
        ])
        . AdminStatusView::render(StatusReport::build());
}

/**
 * Storefront search interception.
 *
 * Fires inside fn_get_products via the `get_products_before_select` hook. The
 * by-reference argument list is fixed by fn_get_products (app/functions/fn.products.php):
 *   ($params, $join, $condition, $u_condition, $inventory_condition, $sortings,
 *    $total, $items_per_page, $lang_code, $having)
 *
 * We forward only the pieces the interceptor rewrites. The guard inside decides
 * whether this is the primary products.search fetch; everything else passes through
 * untouched.
 */
function fn_quissly_search_get_products_before_select(
    &$params,
    &$join,
    &$condition,
    &$u_condition,
    &$inventory_condition,
    &$sortings,
    &$total,
    &$items_per_page,
    &$lang_code,
    &$having
) {
    SearchInterceptor::interceptBeforeSelect($params, $condition, $sortings, $total, $items_per_page);
}

/**
 * Register the synthetic `quissly_relevance` sort option in CS-Cart's
 * products-sorting label map (fn_get_products_sorting), so the storefront "Sort
 * by" dropdown can render "Relevance" as the default option on intercepted
 * search pages. The option is only shown where
 * settings.Appearance.available_product_list_sortings enables it
 * (SearchInterceptor::restrictSortControl on a Quissly-driven search) — adding
 * the label here is harmless everywhere else. Skipped in simple-titles mode.
 *
 * @param array<string, mixed> $sorting
 * @param mixed                $simple_mode
 */
function fn_quissly_search_products_sorting(&$sorting, &$simple_mode)
{
    if (empty($simple_mode) && is_array($sorting)) {
        SortMap::registerRelevanceSorting($sorting);
    }
}

/**
 * Catalog sync: queue a product whenever it is created or saved (admin form,
 * import, API). The worker reads its current status and deletes it from Quissly
 * if it is no longer active.
 *
 * @param array<string, mixed> $product_data
 * @param int|string           $product_id
 */
function fn_quissly_search_update_product_post($product_data, $product_id, $lang_code, $create)
{
    Sync::productSaved((int) $product_id);
}

/**
 * Before a product is deleted: if it is a variation child, its parent is re-sent
 * without it (afterwards the parent can no longer be found).
 *
 * @param int|string $product_id
 */
function fn_quissly_search_delete_product_pre($product_id, $status)
{
    Sync::productDeleting((int) $product_id);
}

/** @param int|string $product_id */
function fn_quissly_search_delete_product_post($product_id, $product_deleted)
{
    if ($product_deleted) {
        Sync::productDeleted((int) $product_id);
    }
}

/**
 * Stock changed by an order or return (fn_update_product_amount). Only an
 * in-stock / out-of-stock flip is queued.
 */
function fn_quissly_search_update_product_amount(&$new_amount, $product_id, $cart_id, $tracking, $notify, $order_info, $amount_delta, $current_amount, $original_amount, $sign)
{
    Sync::amountChanged((int) $product_id, $current_amount, $new_amount);
}

/**
 * A status toggle in an admin list (fn_tools_update_status).
 *
 * @param array<string, mixed> $params
 */
function fn_quissly_search_tools_change_status($params, $result)
{
    if ($result) {
        Sync::statusChanged((array) $params);
    }
}

/**
 * Multi-Vendor: a vendor's status is about to change (suspend, activate...). Its products
 * are queued now; the worker reads the vendor's status when it sends, so it removes
 * them from Quissly or sends them again accordingly.
 */
function fn_quissly_search_change_company_status_pre($company_id, $status_to, $reason, $status_from, $skip_query, $notify)
{
    if ($status_to !== $status_from) {
        Sync::vendorChanged((int) $company_id);
    }
}

/**
 * A vendor saved from its edit form, where the status can change too.
 *
 * @param array<string, mixed> $company_data
 */
function fn_quissly_search_update_company($company_data, $company_id, $lang_code, $action)
{
    if ($action === 'update' && isset($company_data['status'])) {
        Sync::vendorChanged((int) $company_id);
    }
}

/**
 * Product variations: a variation group was created or changed (variations generated,
 * products added or removed, a new default product). CS-Cart writes generated
 * products straight to the database, so update_product_post never fires for them:
 * every product of the group is queued, and so is any product taken out of it (now a
 * product of its own). The worker sends a queued child as its parent.
 *
 * @param object              $service
 * @param object              $group   Tygh\Addons\ProductVariations\Product\Group\Group
 * @param array<int, object>  $events  its Events\* (ProductRemovedEvent carries the removed product)
 */
function fn_quissly_search_variation_group_save_group($service, $group, $events)
{
    $ids = is_object($group) && method_exists($group, 'getProductIds') ? (array) $group->getProductIds() : [];
    foreach ((array) $events as $event) {
        if ($event instanceof \Tygh\Addons\ProductVariations\Product\Group\Events\ProductRemovedEvent) {
            $ids[] = $event->getProduct()->getProductId();
        }
    }
    Sync::productsSaved(array_map('intval', $ids));
}

/**
 * Product bundles (CS-Cart's Product bundles add-on): a bundle travels in its members'
 * metadata, so saving, re-linking or deleting one re-sends its members. Before the
 * save: the current members (one may be taken out); new links: the new members.
 *
 * @param array<string, mixed> $bundle_data
 * @param int|string           $bundle_id
 */
function fn_quissly_search_product_bundle_service_update_bundle($bundle_data, $bundle_id)
{
    Sync::bundleChanged((int) $bundle_id);
}

/**
 * @param int|string                        $bundle_id
 * @param array<int|string, array<string>>  $products_data
 * @param array<int, array<string, mixed>>  $data          the new links
 */
function fn_quissly_search_product_bundle_service_update_links($bundle_id, $products_data, $data)
{
    Sync::bundleChanged(0, array_map('intval', array_column((array) $data, 'product_id')));
}

/** @param int|string $bundle_id */
function fn_quissly_search_product_bundle_service_delete_bundle_pre($bundle_id)
{
    Sync::bundleChanged((int) $bundle_id);
}

/**
 * Storefront variables for the theme hook (hooks/index/scripts.post.tpl): the
 * search overlay, the voice/image buttons and the QChat widget — lib/Storefront.php
 * decides. Called from the template as {$q = ""|fn_quissly_search_storefront}.
 *
 * @return array<string, mixed>
 */
function fn_quissly_search_storefront()
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }
    $credentials = Credentials::fromRegistry();
    $store = new DbSyncStore();
    $configured = $credentials->isConfigured();
    $result = Storefront::build([
        'configured'    => $configured,
        'gate_open'     => $configured && SyncWorker::isGateOpen($store),
        'features'      => Features::all(),
        'agent_id'      => $configured ? QChatDirectory::cached($store) : '',
        'urls'          => [
            'voice'   => fn_url('quissly_search.voice'),
            'image'   => fn_url('quissly_search.image'),
            'results' => fn_url('products.search?subcats=Y&pcode_from_q=Y&pshort=Y&pfull=Y&pname=Y&pkeywords=Y&search_performed=Y'),
            'cart_add'    => fn_url('checkout.add'),
            'cart_adjust' => fn_url('quissly_search.cart_adjust'),
            'cart_resolve' => fn_url('quissly_search.resolve'),
        ],
        'security_hash' => fn_generate_security_hash(),
        'mount_selector' => (string) \Tygh\Registry::get('addons.quissly_search.overlay_mount_selector'),
        // Search bar suggestions: the cached service id only - a storefront page never looks it up.
        'suggestions'    => $configured
            ? SearchSuggestions::forStorefront($store, QChatDirectory::cachedSearchServiceId($store), fn_quissly_search_http_get(5), time())
            : [],
        'i18n'          => [
            'search'             => __('search'),
            'close'              => __('close'),
            'placeholder'        => __('quissly_search.sf_placeholder'),
            'voice'              => __('quissly_search.sf_voice'),
            'image'              => __('quissly_search.sf_image'),
            'listening'          => __('quissly_search.sf_listening'),
            'searching'          => __('quissly_search.sf_searching'),
            'micUnsupported'     => __('quissly_search.sf_mic_unsupported'),
            'micInsecure'        => __('quissly_search.sf_mic_insecure'),
            'micDenied'          => __('quissly_search.sf_mic_denied'),
            'failed'             => __('quissly_search.sf_failed'),
            'voiceFallbackQuery' => __('quissly_search.sf_voice_fallback'),
            'imageFallbackQuery' => __('quissly_search.sf_image_fallback'),
        ],
    ]);

    return $result;
}

/**
 * HTTP for the console (tests pass their own): GET (url, header lines), POST (url, JSON
 * body), PUT (url, JSON body, header lines) -> {status, body}, or null when no answer.
 */
function fn_quissly_search_http_get(int $timeout): callable
{
    return static function (string $url, array $headers) use ($timeout): ?array {
        $response = \Tygh\Http::get($url, [], ['headers' => $headers, 'timeout' => $timeout]);
        $status = \Tygh\Http::getStatus();
        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };
}

function fn_quissly_search_http_post(int $timeout): callable
{
    return static function (string $url, string $body) use ($timeout): ?array {
        $response = \Tygh\Http::post($url, $body, ['headers' => ['Content-Type: application/json'], 'timeout' => $timeout]);
        $status = \Tygh\Http::getStatus();
        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };
}

function fn_quissly_search_http_put(int $timeout): callable
{
    return static function (string $url, string $body, array $headers) use ($timeout): ?array {
        $response = \Tygh\Http::put($url, $body, ['headers' => array_merge(['Content-Type: application/json'], $headers), 'timeout' => $timeout]);
        $status = \Tygh\Http::getStatus();
        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };
}

/**
 * The store's QSearch service id (where the search bar suggestions live): the cached one,
 * else - admin only, never on a storefront page - looked up now.
 */
function fn_quissly_search_search_service_id(DbSyncStore $store, bool $lookUp): string
{
    $id = QChatDirectory::cachedSearchServiceId($store);
    if ($id === '' && $lookUp) {
        QChatDirectory::refresh($store, Credentials::fromRegistry(), fn_quissly_search_http_post(10), fn_quissly_search_http_get(10), time());
        $id = QChatDirectory::cachedSearchServiceId($store);
    }

    return $id;
}

/**
 * Configuration's "Search bar suggestions" block (lib/Admin/SuggestionsView), drawn inside
 * the add-on settings form and saved by controllers/backend/addons.pre.php.
 */
function fn_quissly_search_suggestions_handler()
{
    $store = new DbSyncStore();
    $serviceId = Credentials::fromRegistry()->isConfigured() ? fn_quissly_search_search_service_id($store, true) : '';

    return \Quissly\Search\Admin\SuggestionsView::render(
        SearchSuggestions::read($serviceId, fn_quissly_search_http_get(10)),
        ShowcaseRunner::generated($store),
        fn_quissly_search_showcase_enabled() && !ShowcaseRunner::finished($store)
    );
}

/** Whether suggestions are generated from the catalog ($config['quissly_showcase_disabled'] switches it off). */
function fn_quissly_search_showcase_enabled(): bool
{
    return empty(\Tygh\Registry::get('config.quissly_showcase_disabled'));
}

/**
 * The cron's showcase step (lib/Showcase/ShowcaseRunner): generate the search bar
 * suggestions from the catalog once, after the first sync, when Quissly holds no list.
 */
function fn_quissly_search_showcase_tick(DbSyncStore $store): string
{
    if (!fn_quissly_search_showcase_enabled() || ShowcaseRunner::finished($store)) {
        return 'finished';
    }
    $credentials = Credentials::fromRegistry();
    $serviceId = $credentials->isConfigured() ? fn_quissly_search_search_service_id($store, true) : '';
    $ready = $serviceId !== '' && SyncWorker::isGateOpen($store);
    $sort = \Quissly\Search\SortMap::resolve(null, null);

    return ShowcaseRunner::tick(
        $store,
        $ready,
        time(),
        'fn_quissly_search_showcase_facts',
        static function (string $query) use ($credentials, $sort): int {
            // Unattributed, like the Shopify app's validation searches.
            $result = (new \Quissly\Search\Client($credentials))->qsearch($query, '', 1, 5, $sort['sort_by'], $sort['sort_type']);

            return max($result->numTotalResults, $result->count());
        },
        static fn (): ?array => SearchSuggestions::read($serviceId, fn_quissly_search_http_get(10)),
        static fn (bool $enabled, array $queries): string => SearchSuggestions::save(
            $store, $credentials, $serviceId, $enabled, $queries,
            fn_quissly_search_http_post(10), fn_quissly_search_http_get(10), fn_quissly_search_http_put(10)
        )
    );
}

/**
 * Catalog facts for the showcase (lib/Showcase/ShowcaseQueries): the 100 most recently
 * changed active products - title, main category, Brand feature, features and options with
 * their values, price - plus the store's name and primary currency.
 */
function fn_quissly_search_showcase_facts(): array
{
    $source = new \Quissly\Search\Sync\ProductSource();
    $products = [];
    $ids = db_get_fields('SELECT product_id FROM ?:products WHERE status = ?s AND parent_product_id = 0 ORDER BY updated_timestamp DESC LIMIT 100', 'A');
    foreach ($ids as $id) {
        $p = $source->load((int) $id, false);
        if ($p === null) {
            continue;
        }
        $options = [];
        $brand = '';
        foreach ((array) ($p['features'] ?? []) + (array) ($p['options'] ?? []) as $name => $value) {
            if (mb_strtolower((string) $name) === 'brand') {
                $brand = (string) $value;
            }
            $values = array_values(array_filter(array_map('trim', preg_split('/\s*[,|]\s*/u', (string) $value)), 'strlen'));
            $options[] = ['name' => (string) $name, 'values' => $values];
        }
        $products[] = [
            'title'        => (string) $p['product'],
            'product_type' => (string) ($p['category_name'] ?? ''),
            'category'     => null,
            'vendor'       => $brand,
            'options'      => $options,
            'min_price'    => is_numeric($p['price'] ?? null) ? (float) $p['price'] : null,
        ];
    }

    return [
        'shop_name' => (string) \Tygh\Registry::get('settings.Company.company_name'),
        'currency'  => defined('CART_PRIMARY_CURRENCY') ? (string) CART_PRIMARY_CURRENCY : '',
        'products'  => $products,
    ];
}

/**
 * Look the qsearch namespace up (panel session + the account's services) and cache
 * it; a failure is remembered for an hour so shoppers' requests never retry it.
 */
function fn_quissly_search_lookup_namespace(DbSyncStore $store): string
{
    $post = static function (string $url, string $body): ?array {
        $response = \Tygh\Http::post($url, $body, ['headers' => ['Content-Type: application/json'], 'timeout' => 10]);
        $status = \Tygh\Http::getStatus();
        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };
    $get = static function (string $url, array $headers): ?array {
        $response = \Tygh\Http::get($url, [], ['headers' => $headers, 'timeout' => 10]);
        $status = \Tygh\Http::getStatus();
        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };
    $found = QChatDirectory::refresh($store, Credentials::fromRegistry(), $post, $get, time());
    if ($found['namespace'] === '') {
        $store->setState('namespace_lookup_failed', ['at' => time()]);
    }

    return $found['namespace'];
}
