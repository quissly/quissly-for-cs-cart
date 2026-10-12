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
use Quissly\Search\Events\EventBody;
use Quissly\Search\Events\EventSender;

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
            $store = new DbSyncStore();
            \Quissly\Search\Update\Updater::runIfDue($store, time());
            fn_quissly_search_version_report($store);
            // Shopping events wait for the cron; a store without one sends them here, a few
            // seconds' worth, when the cron has not run for 10 minutes.
            if ((int) ($store->getState('last_cron')['at'] ?? 0) < time() - 600) {
                fn_quissly_search_events_send(5.0);
            }
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
    return fn_quissly_search_info_row(CatalogView::render(CatalogFeatures::catalog(), CatalogFeatures::choices(new DbSyncStore())));
}

/**
 * An info block in the settings' field column, under the labels' column like every other
 * setting (CS-Cart draws an info item as a bare <div> at the form's left edge).
 */
function fn_quissly_search_info_row(string $html): string
{
    return '<div class="control-group setting-wide"><div class="controls">' . $html . '</div></div>';
}

/**
 * Configuration's sections opening and closing (js/addons/quissly_search/settings-sections.js,
 * design/backend/css/addons/quissly_search/settings.css), inlined like fn_quissly_search_config_assets().
 */
function fn_quissly_search_sections_assets(): string
{
    $root = rtrim((string) \Tygh\Registry::get('config.dir.root'), '/');
    $css = (string) @file_get_contents($root . '/design/backend/css/addons/quissly_search/settings.css');
    $js = (string) @file_get_contents($root . '/js/addons/quissly_search/settings-sections.js');

    return '<style>' . $css . '</style><script>' . str_replace('</script', '<\/script', $js) . '</script>';
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
        . AdminStatusView::render(StatusReport::build())
        . fn_quissly_search_sections_assets();
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
            'event'        => fn_url('quissly_search.event'),
        ],
        'security_hash' => fn_generate_security_hash(),
        'event'         => $configured ? fn_quissly_search_page_event() : null,
        'mount_selector' => (string) \Tygh\Registry::get('addons.quissly_search.overlay_mount_selector'),
        // Search bar suggestions for the shopper's language: the cached service id only - a
        // storefront page never looks it up.
        'suggestions'    => $configured
            ? SearchSuggestions::forStorefront($store, QChatDirectory::cachedSearchServiceId($store), fn_quissly_search_http_get(5), time(), fn_quissly_search_current_language(), fn_quissly_search_main_language())
            : [],
        // The buttons under the bar: the merchant's own (Manual) or what Quissly generated for the
        // shopper's language (Automatic, the default), as Quissly holds the choice - never the typing list.
        'chips'          => SearchSuggestions::buttonsForStorefront(
            $store,
            $configured ? QChatDirectory::cachedSearchServiceId($store) : '',
            fn_quissly_search_http_get(5),
            time(),
            fn_quissly_search_current_language(),
            fn_quissly_search_main_language()
        ),
        // The merchant's Most loved products (lib/MostLoved.php; none while off or short of picks).
        'most_loved'     => $configured ? fn_quissly_search_most_loved_cards($store) : [],
        'i18n'          => [
            'search'             => __('search'),
            'close'              => __('close'),
            'placeholder'        => __('quissly_search.sf_placeholder'),
            'suggestions'        => trim((string) \Tygh\Registry::get('settings.Company.company_name')) !== ''
                ? __('quissly_search.sf_suggestions', ['[store]' => trim((string) \Tygh\Registry::get('settings.Company.company_name'))])
                : __('quissly_search.sf_suggestions_plain'),
            'mostLoved'          => trim((string) \Tygh\Registry::get('settings.Company.company_name')) !== ''
                ? __('quissly_search.sf_most_loved', ['[store]' => trim((string) \Tygh\Registry::get('settings.Company.company_name'))])
                : __('quissly_search.sf_most_loved_plain'),
            'rating'             => __('quissly_search.sf_rating'),
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
 * The storefront's languages as widget_config keys => their names, the main language first.
 *
 * @return array<string, string>
 */
function fn_quissly_search_languages(): array
{
    $main = fn_quissly_search_main_language();
    $out = [$main => $main];
    foreach (db_get_hash_single_array('SELECT lang_code, name FROM ?:languages WHERE status = ?s ORDER BY lang_code', ['lang_code', 'name'], 'A') as $code => $name) {
        $out[\Quissly\Search\Showcase\SuggestionsEnrich::languageKey((string) $code)] = (string) $name;
    }

    return $out;
}

/** The storefront's main language, as a widget_config key. */
function fn_quissly_search_main_language(): string
{
    return \Quissly\Search\Showcase\SuggestionsEnrich::languageKey((string) (\Tygh\Registry::get('settings.Appearance.frontend_default_language') ?: (defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en')));
}

/** The shopper's language, as a widget_config key. */
function fn_quissly_search_current_language(): string
{
    return \Quissly\Search\Showcase\SuggestionsEnrich::languageKey(defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : '');
}

/**
 * The suggestion editors' script and style (js/addons/quissly_search/config.js,
 * design/backend/css/addons/quissly_search/config.css), inlined: the settings form can be
 * loaded into a dialog, where a linked file would not be.
 */
function fn_quissly_search_config_assets(): string
{
    $root = rtrim((string) \Tygh\Registry::get('config.dir.root'), '/');
    $css = (string) @file_get_contents($root . '/design/backend/css/addons/quissly_search/config.css');
    $js = (string) @file_get_contents($root . '/js/addons/quissly_search/config.js');

    return '<style>' . $css . '</style><script>' . str_replace('</script', '<\/script', $js) . '</script>';
}

/**
 * What Quissly holds for the store's QSearch service, read once per admin page (null = unreadable).
 */
function fn_quissly_search_admin_suggestions(DbSyncStore $store): ?array
{
    static $read = false;
    if ($read === false) {
        $serviceId = Credentials::fromRegistry()->isConfigured() ? fn_quissly_search_search_service_id($store, true) : '';
        $read = SearchSuggestions::read($serviceId, fn_quissly_search_http_get(10));
    }

    return $read;
}

/**
 * Configuration's "Search Examples" block (lib/Admin/SuggestionsView), drawn inside the add-on
 * settings form and saved by controllers/backend/addons.pre.php.
 */
function fn_quissly_search_suggestions_handler()
{
    $store = new DbSyncStore();
    $current = fn_quissly_search_admin_suggestions($store);

    return fn_quissly_search_info_row(\Quissly\Search\Admin\SuggestionsView::examples(
        $current,
        fn_quissly_search_languages(),
        // Quissly's own copy of what its generator wrote, else what it last answered here.
        ($current['generated'] ?? []) !== [] ? $current['generated'] : ShowcaseRunner::generated($store),
        fn_quissly_search_showcase_enabled() && !ShowcaseRunner::finished($store),
        fn_quissly_search_config_assets()
    ));
}

/**
 * Configuration's "Search Suggestions" buttons block (lib/Admin/SuggestionsView), saved by
 * controllers/backend/addons.pre.php.
 */
function fn_quissly_search_buttons_handler()
{
    $store = new DbSyncStore();

    return fn_quissly_search_info_row(\Quissly\Search\Admin\SuggestionsView::buttons(
        fn_quissly_search_admin_suggestions($store),
        fn_quissly_search_languages(),
        SearchSuggestions::localButtons($store),
        fn_quissly_search_config_assets()
    ));
}

/**
 * Configuration's suggestion lists as posted (controllers/backend/addons.pre.php): one JSON field {language: [queries]}, cleaned; null when it is not JSON.
 *
 * @return array<string, list<string>>|null
 */
function fn_quissly_search_posted_lists(string $field): ?array
{
    $decoded = json_decode((string) ($_REQUEST[$field] ?? ''), true);
    if (!is_array($decoded)) {
        return null;
    }
    $out = [];
    foreach ($decoded as $language => $queries) {
        $out[\Quissly\Search\Showcase\SuggestionsEnrich::languageKey((string) $language)] = \Quissly\Search\SearchSuggestions::clean($queries);
    }

    return $out;
}

/** A suggestion list that could not be saved: why, as a notification. */
function fn_quissly_search_suggestions_error(string $error): void
{
    fn_set_notification('E', __('error'), str_replace(
        ['[count]', '[length]'],
        [(string) \Quissly\Search\SearchSuggestions::MAX_COUNT, (string) \Quissly\Search\SearchSuggestions::MAX_LENGTH],
        (string) __($error, ['[count]' => (string) \Quissly\Search\SearchSuggestions::MAX_COUNT, '[length]' => (string) \Quissly\Search\SearchSuggestions::MAX_LENGTH, '[max]' => (string) \Quissly\Search\SearchSuggestions::MAX_CHIPS])
    ));
}

/**
 * Most loved: whether it is on, and the picked product codes.
 *
 * @return array{enabled:bool, shown:list<string>, backups:list<string>}
 */
function fn_quissly_search_most_loved_picks(): array
{
    return [
        'enabled' => \Tygh\Registry::get('addons.quissly_search.most_loved') === 'Y',
        'shown'   => \Quissly\Search\MostLoved::codes((string) \Tygh\Registry::get('addons.quissly_search.most_loved_shown'), \Quissly\Search\MostLoved::SHOWN),
        'backups' => \Quissly\Search\MostLoved::codes((string) \Tygh\Registry::get('addons.quissly_search.most_loved_backups'), \Quissly\Search\MostLoved::BACKUPS),
    ];
}

/**
 * Configuration's Most Loved Products status (lib/Admin/MostLovedView): what each code is and
 * what needs fixing.
 */
function fn_quissly_search_most_loved_handler()
{
    $picks = fn_quissly_search_most_loved_picks();
    $list = [];
    foreach ([[$picks['shown'], false], [$picks['backups'], true]] as [$codes, $backup]) {
        foreach ($codes as $code) {
            $list[] = ['code' => $code, 'backup' => $backup] + fn_quissly_search_most_loved_state($code);
        }
    }

    return fn_quissly_search_info_row(\Quissly\Search\Admin\MostLovedView::render(
        $picks['enabled'],
        $list,
        $picks['enabled'] ? \Quissly\Search\MostLoved::problems($picks['shown'], $picks['backups'], 'fn_quissly_search_most_loved_state') : null
    )) . fn_quissly_search_most_loved_pickers($picks);
}

/**
 * The product pickers over the two code boxes (lib/Admin/MostLovedView::pickers()).
 *
 * @param array{shown:list<string>, backups:list<string>} $picks
 */
function fn_quissly_search_most_loved_pickers(array $picks): string
{
    $count = static fn (string $key): string => str_replace(['[count]', '[max]'], ['%1', '%2'], (string) __($key));
    $script = (string) @file_get_contents(rtrim((string) \Tygh\Registry::get('config.dir.root'), '/') . '/js/addons/quissly_search/most-loved-picker.js');

    return \Quissly\Search\Admin\MostLovedView::pickers(
        [
            'shown'   => array_map('fn_quissly_search_most_loved_item', $picks['shown']),
            'backups' => array_map('fn_quissly_search_most_loved_item', $picks['backups']),
        ],
        (string) fn_url('quissly_search.most_loved_search'),
        [
            'placeholder' => (string) __('quissly_search.most_loved_search'),
            'remove'      => (string) __('quissly_search.most_loved_remove'),
            'move'        => (string) __('quissly_search.most_loved_move'),
            'moveHint'    => (string) __('quissly_search.most_loved_move_hint'),
            'searching'   => (string) __('quissly_search.most_loved_searching'),
            'none'        => (string) __('quissly_search.most_loved_none'),
            'error'       => (string) __('quissly_search.most_loved_search_error'),
            'picked'      => (string) __('quissly_search.most_loved_picked'),
            'inShown'     => (string) __('quissly_search.most_loved_in_shown'),
            'inBackups'   => (string) __('quissly_search.most_loved_in_backups'),
            'count'       => $count('quissly_search.most_loved_count'),
            'full'        => $count('quissly_search.most_loved_full'),
        ],
        $script
    );
}

/**
 * A pick as the picker shows it: its code, name, first image and why it cannot be shown ('' when it can).
 *
 * @return array{sku:string, name:string, image:string, note:string}
 */
function fn_quissly_search_most_loved_item(string $code): array
{
    $state = fn_quissly_search_most_loved_state($code);
    $product = fn_quissly_search_most_loved_product($code)['product'];

    return [
        'sku'   => $code,
        'name'  => (string) $state['title'],
        'image' => $product !== null ? (string) ($product['images'][0] ?? '') : '',
        'note'  => $state['reason'] === '' ? '' : (string) __('quissly_search.most_loved_reason_' . $state['reason']),
    ];
}

/**
 * The picker's search: products whose name (in the admin's language) or code contains $query,
 * at most 20, each as fn_quissly_search_most_loved_item(). Products, not their variations: a
 * variation's code shows its parent's card anyway.
 *
 * @return list<array{sku:string, name:string, image:string, note:string}>
 */
function fn_quissly_search_most_loved_search(string $query): array
{
    $query = trim($query);
    if (mb_strlen($query) < 2) {
        return [];
    }
    $like = '%' . $query . '%';
    $codes = db_get_fields(
        "SELECT p.product_code FROM ?:products AS p"
        . " LEFT JOIN ?:product_descriptions AS d ON d.product_id = p.product_id AND d.lang_code = ?s"
        . " WHERE p.product_code != '' AND p.parent_product_id = 0 AND (d.product LIKE ?l OR p.product_code LIKE ?l)"
        . " GROUP BY p.product_code ORDER BY MIN(d.product) LIMIT 20",
        defined('DESCR_SL') ? DESCR_SL : CART_LANGUAGE,
        $like,
        $like
    );

    return array_map('fn_quissly_search_most_loved_item', array_map('strval', (array) $codes));
}

/**
 * A picked product: found by its product code (a variation's code shows its card's parent).
 *
 * @return array{id:int, product:?array}
 */
function fn_quissly_search_most_loved_product(string $code): array
{
    static $loaded = [];
    if (!array_key_exists($code, $loaded)) {
        $row = db_get_row('SELECT product_id, parent_product_id FROM ?:products WHERE product_code = ?s ORDER BY parent_product_id, product_id LIMIT 1', $code);
        $id = !empty($row) ? ((int) $row['parent_product_id'] ?: (int) $row['product_id']) : 0;
        $loaded[$code] = ['id' => $id, 'product' => $id > 0 ? (new \Quissly\Search\Sync\ProductSource())->load($id) : null];
    }

    return $loaded[$code];
}

/**
 * A picked product's name, and why it cannot be shown ('' when it can): not_found,
 * not_visible (disabled, hidden, or its vendor not active), out_of_stock.
 *
 * @return array{title:string, reason:string}
 */
function fn_quissly_search_most_loved_state(string $code): array
{
    $p = fn_quissly_search_most_loved_product($code)['product'];
    if ($p === null) {
        return ['title' => $code, 'reason' => 'not_found'];
    }
    if ($p['status'] !== 'A' || !$p['vendor_active']) {
        return ['title' => $p['product'], 'reason' => 'not_visible'];
    }
    $buyable = static function (array $q): bool {
        return $q['tracking'] === 'D' || $q['out_of_stock_actions'] === 'B' || (float) $q['amount'] > 0;
    };
    $inStock = $buyable($p);
    foreach ($p['variations'] as $child) {
        $inStock = $inStock || $buyable($child);
    }

    return ['title' => $p['product'], 'reason' => $inStock ? '' : 'out_of_stock'];
}

/**
 * The Most loved cards for the shopper's language and currency (none while off or short of
 * picks), kept five minutes: loading 12 products is not for every page.
 *
 * @return list<array{title:string, url:string, image:string, price:string, rating:float, ratingCount:int, variants:string}>
 */
function fn_quissly_search_most_loved_cards(DbSyncStore $store): array
{
    $picks = fn_quissly_search_most_loved_picks();
    if (!$picks['enabled']) {
        return [];
    }
    $currencyCode = defined('CART_SECONDARY_CURRENCY') ? (string) CART_SECONDARY_CURRENCY : '';
    $key = md5(json_encode([$picks, defined('CART_LANGUAGE') ? CART_LANGUAGE : '', $currencyCode]));
    $cached = $store->getState(\Quissly\Search\MostLoved::CACHE);
    if (is_array($cached) && ($cached['key'] ?? '') === $key && (int) ($cached['until'] ?? 0) > time()) {
        return (array) $cached['cards'];
    }
    $codes = \Quissly\Search\MostLoved::pick($picks['shown'], $picks['backups'], static function (string $code): bool {
        return fn_quissly_search_most_loved_state($code)['reason'] === '';
    });
    $currency = (array) (\Tygh\Registry::get('currencies.' . $currencyCode) ?: []);
    $cards = [];
    foreach ($codes as $code) {
        $found = fn_quissly_search_most_loved_product($code);
        $cards[] = fn_quissly_search_most_loved_card($found['id'], (array) $found['product'], $currency, $currencyCode);
    }
    $store->setState(\Quissly\Search\MostLoved::CACHE, ['key' => $key, 'cards' => $cards, 'until' => time() + \Quissly\Search\MostLoved::CACHE_SECONDS]);

    return $cards;
}

/**
 * A card: name, link, image, price (the lowest of its variations), its rating (the Product
 * reviews add-on's, else the older Comments and reviews add-on's), and how many variations
 * there are ("3 colors available").
 *
 * @param array<string, mixed> $p ProductSource::load()
 * @param array<string, mixed> $currency
 * @return array{title:string, url:string, image:string, price:string, rating:float, ratingCount:int, variants:string}
 */
function fn_quissly_search_most_loved_card(int $id, array $p, array $currency, string $currencyCode): array
{
    $prices = [];
    foreach (array_merge([$p], (array) $p['variations']) as $q) {
        if (is_numeric($q['price'] ?? null)) {
            $prices[] = (float) $q['price'];
        }
    }
    $price = $prices !== [] ? min($prices) : null;
    if ($price !== null && $currencyCode !== '' && defined('CART_PRIMARY_CURRENCY') && function_exists('fn_format_price_by_currency')) {
        $price = (float) fn_format_price_by_currency($price, CART_PRIMARY_CURRENCY, $currencyCode);
    }
    [$rating, $count] = fn_quissly_search_most_loved_rating($id);

    return [
        'title'       => (string) $p['product'],
        'url'         => (string) fn_url('products.view?product_id=' . $id),
        'image'       => (string) ($p['images'][0] ?? ''),
        'price'       => $price !== null ? \Quissly\Search\MostLoved::formatPrice($price, $currency) : '',
        'rating'      => $rating,
        'ratingCount' => $count,
        'variants'    => \Quissly\Search\MostLoved::variantsLabel(
            $p['variations'] !== [] ? count($p['variations']) + 1 : 0,
            (string) (array_keys((array) $p['options'])[0] ?? ''),
            (string) __('quissly_search.sf_variants'),
            (string) __('quissly_search.sf_variants_plain')
        ),
    ];
}

/**
 * A product's average rating out of 5 and how many reviews: the Product reviews add-on
 * (CS-Cart 4.12+), else Comments and reviews (discussion); [0, 0] without either.
 *
 * @return array{0: float, 1: int}
 */
function fn_quissly_search_most_loved_rating(int $id): array
{
    try {
        if (\Tygh\Registry::get('addons.product_reviews.status') === 'A') {
            $row = db_get_row('SELECT average_rating, reviews_count FROM ?:product_review_prepared_data WHERE product_id = ?i ORDER BY reviews_count DESC LIMIT 1', $id);

            return [round((float) ($row['average_rating'] ?? 0), 1), (int) ($row['reviews_count'] ?? 0)];
        }
        if (\Tygh\Registry::get('addons.discussion.status') === 'A') {
            $row = db_get_row(
                'SELECT AVG(r.rating_value) AS average, COUNT(r.post_id) AS total FROM ?:discussion d'
                . ' JOIN ?:discussion_posts p ON p.thread_id = d.thread_id AND p.status = ?s'
                . ' JOIN ?:discussion_rating r ON r.post_id = p.post_id AND r.rating_value > 0'
                . ' WHERE d.object_id = ?i AND d.object_type = ?s',
                'A',
                $id,
                'P'
            );

            return [round((float) ($row['average'] ?? 0), 1), (int) ($row['total'] ?? 0)];
        }
    } catch (\Throwable $e) {
        unset($e); // a store without these tables: no stars
    }

    return [0.0, 0];
}

/** Whether Quissly is asked for suggestions ($config['quissly_showcase_disabled'] switches it off). */
function fn_quissly_search_showcase_enabled(): bool
{
    return empty(\Tygh\Registry::get('config.quissly_showcase_disabled'));
}

/**
 * The storefront's languages as widget_config keys, the default one first.
 *
 * @return list<string>
 */
function fn_quissly_search_showcase_languages(): array
{
    $default = (string) (\Tygh\Registry::get('settings.Appearance.frontend_default_language') ?: (defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en'));
    $codes = array_merge([$default], db_get_fields('SELECT lang_code FROM ?:languages WHERE status = ?s ORDER BY lang_code', 'A'));

    return array_values(array_unique(array_map([\Quissly\Search\Showcase\SuggestionsEnrich::class, 'languageKey'], $codes)));
}

/**
 * The cron's showcase step (lib/Showcase/ShowcaseRunner): ask Quissly's generator for the
 * search bar suggestions after the first sync (POST /v2beta/qsearch/suggestions_enrich).
 */
function fn_quissly_search_showcase_tick(DbSyncStore $store): string
{
    if (!fn_quissly_search_showcase_enabled()) {
        return 'finished';
    }
    $credentials = Credentials::fromRegistry();
    $serviceId = $credentials->isConfigured() ? fn_quissly_search_search_service_id($store, true) : '';
    $ready = $serviceId !== '' && SyncWorker::isGateOpen($store);

    return ShowcaseRunner::tick(
        $store,
        $ready,
        time(),
        fn_quissly_search_showcase_languages(),
        'fn_quissly_search_showcase_facts',
        static function (array $body) use ($credentials): ?array {
            // One call can take minutes: a model call and up to 12 searches per language.
            return (new \Quissly\Search\Client($credentials))->post(\Quissly\Search\Showcase\SuggestionsEnrich::PATH, $body, 180);
        }
    );
}

/**
 * Catalog facts for Quissly's suggestion generator: the 60 most recently changed active
 * products - title, main category, Brand feature, features and options with their values,
 * price - plus the store's name and primary currency. In another language than the admin's,
 * the titles and categories are that language's (features stay out: their values would mix
 * languages).
 */
function fn_quissly_search_showcase_facts(string $language = ''): array
{
    $source = new \Quissly\Search\Sync\ProductSource();
    $current = defined('CART_LANGUAGE') ? strtolower((string) CART_LANGUAGE) : '';
    $translated = $language !== '' && $language !== $current;
    $products = [];
    $ids = db_get_fields('SELECT product_id FROM ?:products WHERE status = ?s AND parent_product_id = 0 ORDER BY updated_timestamp DESC LIMIT ?i', 'A', \Quissly\Search\Showcase\SuggestionsEnrich::MAX_PRODUCTS);
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
        $title = (string) $p['product'];
        $type = (string) ($p['category_name'] ?? '');
        if ($translated) {
            $title = (string) (db_get_field('SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s', (int) $id, $language) ?: $title);
            $main = (int) db_get_field('SELECT category_id FROM ?:products_categories WHERE product_id = ?i AND link_type = ?s', (int) $id, 'M');
            $type = $main > 0 ? ((string) fn_get_category_name($main, $language) ?: $type) : $type;
            $options = [];
        }
        $price = is_numeric($p['price'] ?? null) ? (float) $p['price'] : null;
        $products[] = [
            'title'        => $title,
            'product_type' => $type,
            'vendor'       => $brand,
            'options'      => $options,
            'min_price'    => $price,
            'max_price'    => $price,
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

/*
 * Shopping activity for Quissly's analytics (lib/Events/): a product page view, a search, an
 * add to cart, an add to the wishlist and a paid order - the Quissly Shopify app's five events,
 * sent to POST /v2beta/qsearch/events. Recorded only while "Shopping activity" is on (default
 * on) and the store is connected. A shopper's own event carries the user_id a search does
 * (SearchInterceptor::resolveUserId) and is NOT recorded for a guest who has not consented in
 * the GDPR add-on's banner; a paid order is the store's record and always goes, as
 * customer:<id> or with no user_id. A request only writes a queue row; the cron sends them.
 */

/** Whether shopping events are recorded and sent. */
function fn_quissly_search_events_enabled(): bool
{
    $on = array_column(Features::all(), 'on', 'id');

    return !empty($on['enable_events']) && Credentials::fromRegistry()->isConfigured();
}

/**
 * The catalog's ids for a product: a variation is its parent's variant (lib/Sync/ProductSource).
 *
 * @return array{0: int, 1: int|null} [product id, variant id]
 */
function fn_quissly_search_event_ids(int $productId): array
{
    $parent = (int) db_get_field('SELECT parent_product_id FROM ?:products WHERE product_id = ?i', $productId);

    return $parent > 0 ? [$parent, $productId] : [$productId, null];
}

/** The store's host ("shop.example.com") - Shopify sends its myshopify domain. */
function fn_quissly_search_event_shop(): string
{
    $host = (string) (\Tygh\Registry::get('config.current_host') ?: \Tygh\Registry::get('config.http_host'));

    return strtolower((string) preg_replace('/:\d+$/', '', $host));
}

/** Record the current shopper's event; never throws. */
function fn_quissly_search_event_shopper(string $type, array $metadata): bool
{
    try {
        if (AREA !== 'C' || !fn_quissly_search_events_enabled()) {
            return false;
        }
        $userId = SearchInterceptor::resolveUserId();
        if ($userId === null) {
            return false;
        }
        $shopper = \Quissly\Search\Shopper::deviceAndOs((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        (new \Quissly\Search\Events\DbEventQueue())->add(EventBody::envelope($type, $metadata, $userId, $shopper, fn_quissly_search_event_shop(), EventBody::SOURCE_STOREFRONT, \Quissly\Search\Shopper::newUuid(), time()), time());

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Record a paid order; never throws. */
function fn_quissly_search_event_order(array $order): bool
{
    try {
        if (!fn_quissly_search_events_enabled() || empty($order['order_id'])) {
            return false;
        }
        $lines = [];
        foreach ((array) ($order['products'] ?? []) as $item) {
            [$productId, $variantId] = fn_quissly_search_event_ids((int) ($item['product_id'] ?? 0));
            $lines[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'name'       => (string) ($item['product'] ?? ''),
                'quantity'   => (float) ($item['amount'] ?? 1),
                'price'      => (float) ($item['price'] ?? 0),
            ];
        }
        $customer = (int) ($order['user_id'] ?? 0);
        $metadata = EventBody::purchase((string) $order['order_id'], (float) ($order['total'] ?? 0), defined('CART_PRIMARY_CURRENCY') ? (string) CART_PRIMARY_CURRENCY : '', $lines);
        (new \Quissly\Search\Events\DbEventQueue())->add(EventBody::envelope(EventBody::PURCHASE, $metadata, $customer > 0 ? \Quissly\Search\Shopper::customerId($customer) : null, [], fn_quissly_search_event_shop(), EventBody::SOURCE_ORDER, \Quissly\Search\Shopper::newUuid(), time()), time());

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Hook post_add_to_cart: an add_to_cart event per product added (not for a cart update).
 */
function fn_quissly_search_post_add_to_cart($product_data, $cart, $auth, $update, $ids)
{
    if ($update || !is_array($ids)) {
        return;
    }
    foreach ($ids as $productId) {
        $amount = 1;
        foreach ((array) $product_data as $key => $data) {
            if ((int) ($data['product_id'] ?? $key) === (int) $productId) {
                $amount = (float) ($data['amount'] ?? 1);
                break;
            }
        }
        [$catalogId, $variantId] = fn_quissly_search_event_ids((int) $productId);
        fn_quissly_search_event_shopper(EventBody::ADD_TO_CART, EventBody::addToCart($catalogId, $variantId, $amount));
    }
}

/**
 * Hook pre_add_to_wishlist (the wishlist add-on has no "after" hook): an add_to_wishlist event
 * per product.
 */
function fn_quissly_search_pre_add_to_wishlist($product_data, $wishlist, $auth)
{
    foreach ((array) $product_data as $key => $data) {
        $productId = (int) ($data['product_id'] ?? $key);
        if ($productId > 0) {
            fn_quissly_search_event_shopper(EventBody::WISHLIST, EventBody::wishlist(fn_quissly_search_event_ids($productId)[0]));
        }
    }
}

/**
 * Hook change_order_status: an order becoming paid is a purchase. Paid = CS-Cart's own fn_get_order_paid_statuses() - the statuses that take the stock
 * (by default Processed, Complete and Open, so a cash-on-delivery order counts when placed, as
 * the Magento plugin counts an offline order). Counted when it enters a paid status from an
 * unpaid one, so Processed -> Complete does not count it twice.
 */
function fn_quissly_search_change_order_status($status_to, $status_from, $order_info, $force_notification = [], $order_statuses = [], $place_order = false)
{
    $paid = function_exists('fn_get_order_paid_statuses') ? (array) fn_get_order_paid_statuses() : ['P', 'C'];
    if (in_array($status_to, $paid, true) && !in_array($status_from, $paid, true) && is_array($order_info)) {
        fn_quissly_search_event_order($order_info);
    }
}

/**
 * What this storefront page reports (lib/Storefront.php renders it): a product page's view, or a
 * search results page's search (page 1 only - page 2 of the same search is not another one).
 *
 * @return array<string, string>|null
 */
function fn_quissly_search_page_event(): ?array
{
    if (AREA !== 'C') {
        return null;
    }
    $controller = (string) \Tygh\Registry::get('runtime.controller');
    $mode = (string) \Tygh\Registry::get('runtime.mode');
    if ($controller === 'products' && $mode === 'view') {
        $id = (int) ($_REQUEST['product_id'] ?? 0);
        return $id > 0 ? ['type' => EventBody::VIEW, 'product_id' => (string) fn_quissly_search_event_ids($id)[0]] : null;
    }
    if ($controller !== 'products' || $mode !== 'search' || (int) ($_REQUEST['page'] ?? 1) > 1) {
        return null;
    }
    if (!empty($_REQUEST[\Quissly\Search\Media\MediaEndpoint::QUERY_VAR_VOICE])) {
        return ['type' => EventBody::SEARCH, 'query' => '', 'modality' => 'voice'];
    }
    if (!empty($_REQUEST[\Quissly\Search\Media\MediaEndpoint::QUERY_VAR_IMAGE])) {
        return ['type' => EventBody::SEARCH, 'query' => '', 'modality' => 'image'];
    }
    $query = trim((string) ($_REQUEST['q'] ?? ''));

    return $query !== '' ? ['type' => EventBody::SEARCH, 'query' => $query, 'modality' => 'text'] : null;
}

/**
 * Queue the version report when due (lib/Events/VersionReport): only from a connected store -
 * an unconnected one keeps tracking, so its first report says where it came from. Never throws.
 */
function fn_quissly_search_version_report(DbSyncStore $store): void
{
    try {
        $version = \Quissly\Search\Update\Release::versionIn((string) @file_get_contents(__DIR__ . '/addon.xml'))
            ?? (string) fn_get_addon_version('quissly_search');
        if ($version === '') {
            return;
        }
        $stored = (array) ($store->getState(\Quissly\Search\Events\VersionReport::STATE) ?? []);
        $result = \Quissly\Search\Events\VersionReport::track($stored, $version, time());
        $state = $result['state'];
        if ($result['report'] && Credentials::fromRegistry()->isConfigured()) {
            (new \Quissly\Search\Events\DbEventQueue())->add(EventBody::envelope(\Quissly\Search\Events\VersionReport::EVENT, \Quissly\Search\Events\VersionReport::metadata($state), null, [], fn_quissly_search_event_shop(), EventBody::SOURCE_STORE, \Quissly\Search\Shopper::newUuid(), time()), time());
            $state['reported_at'] = time();
        }
        if ($state !== $stored) {
            $store->setState(\Quissly\Search\Events\VersionReport::STATE, $state);
        }
    } catch (\Throwable $e) {
        return;
    }
}

/**
 * Send the queued events (the cron; an admin page's end when the cron is not set up).
 *
 * @return array{sent: int, dropped: int, retried: int}
 */
function fn_quissly_search_events_send(float $budgetSeconds): array
{
    $credentials = Credentials::fromRegistry();
    $client = $credentials->isConfigured() ? new \Quissly\Search\Client($credentials) : null;
    $counts = EventSender::run(
        new \Quissly\Search\Events\DbEventQueue(),
        $client !== null && fn_quissly_search_events_enabled(),
        static fn (array $body): ?array => $client !== null ? $client->post(EventSender::PATH, $body, EventSender::TIMEOUT_SECONDS) : null,
        time(),
        $budgetSeconds,
        $client !== null
    );
    if ($counts['sent'] + $counts['dropped'] + $counts['retried'] > 0) {
        \Quissly\Search\Logger::notice(sprintf('events sent=%d dropped=%d retried=%d', $counts['sent'], $counts['dropped'], $counts['retried']));
    }

    return $counts;
}
