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
 * A hook of the add-on failed: reported to Quissly (lib/Diagnostics/) and logged, and goes no
 * further - the store's own work (the save, the cart, the order, the page) goes on without it.
 */
function fn_quissly_search_failed(\Throwable $e, string $in): void
{
    try {
        \Quissly\Search\Diagnostics\ErrorCapture::exception($e, $in);
        \Quissly\Search\Logger::notice('Quissly hook failed', ['in' => $in, 'exception' => get_class($e), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    } catch (\Throwable $ignored) {
        // Reporting never gets in the store's way.
    }
}

/**
 * POST quissly_search.client_error (storefront and admin): an error in one of the add-on's scripts,
 * sent by js/addons/quissly_search/report.js, kept for Quissly (lib/Diagnostics/). A body cap and a
 * per-visitor limit (10 in 10 minutes); answers 204 and stops.
 */
function fn_quissly_search_client_error(): void
{
    $raw = (string) file_get_contents('php://input', false, null, 0, 8192);
    $report = json_decode($raw, true);
    $status = 204;
    if (!is_array($report) || strlen($raw) > 6000) {
        $status = 400;
    } elseif (!(new \Quissly\Search\Media\DbRateLimiter())->allow('jserr|' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 10, 600, time())) {
        $status = 429;
    } else {
        $shopper = \Quissly\Search\Shopper::deviceAndOs((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        \Quissly\Search\Diagnostics\ErrorCapture::browser($report, trim((string) ($shopper['device'] ?? '') . ' ' . (string) ($shopper['os'] ?? '')));
    }
    http_response_code($status);
    header('Cache-Control: no-store');
    exit;
}

/**
 * Hook set_notification_pre: an error notice on one of the add-on's pages (Configuration
 * included) is reported to Quissly, as what the merchant was told.
 */
function fn_quissly_search_set_notification_pre($type, $title, $message, $message_state, $extra, $init_message)
{
    $dispatch = (string) ($_REQUEST['dispatch'] ?? '');
    $ours = strpos($dispatch, 'quissly_search.') === 0
        || (strpos($dispatch, 'addons.') === 0 && ($_REQUEST['addon'] ?? '') === 'quissly_search');
    if ($type === 'E' && $ours && is_scalar($message)) {
        \Quissly\Search\Diagnostics\ErrorCapture::refused('admin_notice', strip_tags((string) $message));
    }
}

/**
 * X-Quissly-Search on a storefront request carrying ?quissly_debug=1 (lib/SearchOrigin):
 * after the controller ran the search, before any output.
 */
function fn_quissly_search_dispatch_before_display()
{
    try {
        $value = \Quissly\Search\SearchSignal::headerValue();
        if (AREA === 'C' && $value !== null && \Quissly\Search\SearchOrigin::asked($_REQUEST) && !headers_sent()) {
            header('X-Quissly-Search: ' . $value);
        }
        // The cron's work every few minutes while shoppers visit, for a store without a server cron.
        $cryptKey = (string) \Tygh\Registry::get('config.crypt_key');
        if (AREA === 'C' && $cryptKey !== '') {
            \Quissly\Search\VisitTick::maybe(
                rtrim((string) \Tygh\Registry::get('config.dir.var'), '/') . '/quissly_search_tick',
                (string) fn_url('quissly_search.tick?token=' . \Quissly\Search\VisitTick::token($cryptKey), 'C'),
                time()
            );
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'dispatch_before_display');
    }
    // The daily update check, for a store whose cron is not set up: at the end of an admin
    // page, once the page is built (the cron dispatch runs it too; lib/Update/).
    if (AREA === 'A') {
        register_shutdown_function(static function () {
            try {
                $store = new DbSyncStore();
                \Quissly\Search\Update\Updater::runIfDue($store, time());
                fn_quissly_search_version_report($store);
                // Shopping events wait for the cron; a store without one sends them here, a few
                // seconds' worth, when the cron has not run for 10 minutes.
                if ((int) ($store->getState('last_cron')['at'] ?? 0) < time() - 600) {
                    \Quissly\Search\Diagnostics\ErrorCapture::persist();
                    fn_quissly_search_error_report($store);
                    fn_quissly_search_events_send(5.0);
                }
            } catch (\Throwable $e) {
                fn_quissly_search_failed($e, 'admin page end');
            }
            // ErrorCapture's own shutdown function ran before this one.
            \Quissly\Search\Diagnostics\ErrorCapture::persist();
        });
    }
}

/**
 * The cron's work - the cron dispatch (controllers/backend/quissly_search.php) and, for a store
 * without a server cron, storefront visits (lib/VisitTick.php, quissly_search.tick). The
 * update check runs last: an update replaces the add-on's files, and the rest of the request
 * runs the old code.
 *
 * @return array<string, mixed>|null the catalog sync's summary; null when not connected
 */
function fn_quissly_search_cron_work(DbSyncStore $store): ?array
{
    $summary = null;
    if (Credentials::fromRegistry()->isConfigured()) {
        @set_time_limit(\Quissly\Search\Sync\Sync::CRON_BUDGET_SECONDS + 60);
        $summary = \Quissly\Search\Sync\Sync::worker()->run(\Quissly\Search\Sync\Sync::CRON_BUDGET_SECONDS, 'cron');
        // Search bar suggestions from the catalog: once per store, when due (lib/Showcase/).
        fn_quissly_search_showcase_tick($store);
    }
    // Shopping activity for Quissly's analytics: the queued events (lib/Events/), after the
    // version report when due. Also when not connected (any longer): the sender then empties the queue.
    fn_quissly_search_version_report($store);
    // The add-on's own errors (lib/Diagnostics/): what this request hit so far first.
    \Quissly\Search\Diagnostics\ErrorCapture::persist();
    fn_quissly_search_error_report($store);
    fn_quissly_search_events_send(40.0);
    // What the store's plan includes (lib/Setup/PlanGate.php), asked again every 10 minutes.
    fn_quissly_search_plan_refresh($store, false);
    // A newer release from GitHub, checked once a day and installed (lib/Update/).
    \Quissly\Search\Update\Updater::runIfDue($store, time());

    return $summary;
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
    try {
        return \Quissly\Search\SearchOrigin::badge(\Quissly\Search\SearchOrigin::asked($_REQUEST), [
            'quissly' => (string) __('quissly_search.origin_quissly'),
            'native'  => (string) __('quissly_search.origin_native'),
            'skipped' => (string) __('quissly_search.origin_skipped'),
        ]);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'debug badge');

        return '';
    }
}

/**
 * Configuration's "Catalog data" block: the features sent to Quissly (lib/Admin/CatalogView).
 */
function fn_quissly_search_catalog_handler()
{
    if (\Quissly\Search\Update\Updater::reinstalling()) {
        return ''; // an update rebuilding the settings: drawn for nobody (lib/Update/Updater.php)
    }

    return fn_quissly_search_setting_block('catalog', 'fn_quissly_search_catalog_block');
}

/** Configuration's catalog block, drawn by fn_quissly_search_catalog_handler(). */
function fn_quissly_search_catalog_block(): string
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
 * A Configuration block, drawn by $draw: one that fails says so in its place, is reported to
 * Quissly (lib/Diagnostics/), and the rest of the page is drawn as usual.
 */
function fn_quissly_search_setting_block(string $name, callable $draw): string
{
    try {
        return (string) $draw();
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, $name . ' settings');

        return fn_quissly_search_info_row('<p class="text-error">' . htmlspecialchars((string) __('quissly_search.block_failed'), ENT_QUOTES, 'UTF-8') . '</p>');
    }
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
    if (\Quissly\Search\Update\Updater::reinstalling()) {
        return ''; // an update rebuilding the settings: drawn for nobody (lib/Update/Updater.php)
    }

    return fn_quissly_search_setting_block('status', 'fn_quissly_search_status_block');
}

/** Configuration's status block, drawn by fn_quissly_search_status_handler(). */
function fn_quissly_search_status_block(): string
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
        . fn_quissly_search_plan_locks()
        . fn_quissly_search_sections_assets();
}

/**
 * The switches the store's plan leaves out (lib/Setup/PlanGate.php), asked afresh so a plan
 * changed in Billing shows at once, for settings-sections.js to draw disabled with the reason
 * and a Billing link. CS-Cart posts nothing for a disabled field, so what was saved stays.
 */
function fn_quissly_search_plan_locks(): string
{
    $store = new DbSyncStore();
    fn_quissly_search_plan_refresh($store, true);
    $locks = [];
    foreach (\Quissly\Search\Setup\PlanGate::current($store, (new \Quissly\Search\Setup\Onboarding($store, true, time()))->services()) as $id => $product) {
        $locks[$id] = (string) __('quissly_search.plan_lacks_' . $product);
    }

    return '<script type="application/json" id="quissly-plan-locks">'
        . json_encode(['locks' => $locks, 'billing' => fn_url('quissly_search.billing'), 'link' => (string) __('quissly_search.plan_add_in_billing')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
        . '</script>';
}

/** Ask Quissly what the store's plans are (PlanGate::remember()): always, or once its last answer is 10 minutes old. */
function fn_quissly_search_plan_refresh(DbSyncStore $store, bool $always): void
{
    $credentials = Credentials::fromRegistry();
    if (!$credentials->isConfigured() || (!$always && !\Quissly\Search\Setup\PlanGate::isStale($store, time()))) {
        return;
    }
    $noPost = static function (): ?array {
        return null;
    };
    $billing = new \Quissly\Search\Setup\StoreBilling($credentials->bearerToken(), fn_quissly_search_http_get($always ? 5 : 15), $noPost);
    \Quissly\Search\Setup\PlanGate::remember($store, $billing->subscriptions(), time());
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
    // A failure puts the query back as it was: CS-Cart's own search answers.
    $before = [$params, $condition, $sortings, $total, $items_per_page];
    try {
        SearchInterceptor::interceptBeforeSelect($params, $condition, $sortings, $total, $items_per_page);
    } catch (\Throwable $e) {
        [$params, $condition, $sortings, $total, $items_per_page] = $before;
        fn_quissly_search_failed($e, 'get_products_before_select');
    }
}

/**
 * Later in the same fn_get_products: keep the ORDER BY FIELD clause the interception set
 * for Quissly's order, which an add-on may replace in this hook (the Bestsellers & on-sale
 * add-on's `on_sale` sorting) - SearchInterceptor::keepOrder().
 *
 * @param array<string, mixed> $params
 * @param array<string, mixed> $sortings
 */
function fn_quissly_search_get_products(&$params, &$fields, &$sortings, &$condition, &$join, &$sorting, &$group_by, &$lang_code, &$having)
{
    try {
        SearchInterceptor::keepOrder((array) $params, $sortings);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'get_products');
    }
}

/**
 * The product filters block of a search page Quissly answered: marked, and part of the block's
 * cache key, so its price slider's bounds are Quissly's (SearchInterceptor::markFilters()).
 * Same arguments on 4.11 (fn_get_filters_products_count) and 4.21
 * (fn_product_filters_get_filters_products_count).
 *
 * @param array<string, mixed> $params
 * @param list<string>         $cache_params
 */
function fn_quissly_search_get_filters_products_count_pre(&$params, &$cache_params, &$cache_tables)
{
    try {
        SearchInterceptor::markFilters($params, $cache_params);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'get_filters_products_count_pre');
    }
}

/**
 * For that marked block: the price slider's min / max from Quissly's results, not CS-Cart's
 * keyword match (SearchInterceptor::sliderBounds()). Same arguments on 4.11 and 4.21.
 */
function fn_quissly_search_get_current_filters_post($params, $filters, $selected_filters, $area, $lang_code, &$variant_values, &$range_values, &$field_variant_values, &$field_range_values)
{
    $before = $field_range_values;
    try {
        SearchInterceptor::sliderBounds((array) $params, (array) $filters, $field_range_values, (string) $lang_code);
    } catch (\Throwable $e) {
        $field_range_values = $before;
        fn_quissly_search_failed($e, 'get_current_filters_post');
    }
}

/**
 * The start of the search controller's gather of the intercepted results: a product matched
 * through option values starts with them chosen (the other options at their defaults, as the
 * chat's resolve chooses them), so its card is priced with their modifiers (VariantHints).
 * Only on the storefront search page, once, and only for a gather of options and pictures (the
 * controller's). The argument list is the same on 4.11 and 4.21.
 *
 * @param array<int|string,array<mixed>> $products
 * @param array<string,mixed>            $params
 */
function fn_quissly_search_gather_additional_products_data_pre(&$products, $params, $lang_code)
{
    try {
        if (!\Quissly\Search\VariantHints::pending() || empty($params['get_options'])
            || (empty($params['get_icon']) && empty($params['get_detailed']))) {
            return;
        }
        \Quissly\Search\VariantHints::presetOptions($products, static function (array $ids) use ($lang_code): array {
            $known = [];
            foreach ((array) fn_get_product_options($ids, $lang_code, true, false, true) as $id => $options) {
                $known[(int) $id] = [
                    'options'  => (array) $options,
                    'defaults' => (array) fn_get_default_product_options((int) $id, false, ['product_options' => (array) $options], true),
                ];
            }

            return $known;
        });
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'gather_additional_products_data_pre');
    }
}

/**
 * The search controller's gather of the intercepted results: a card whose product matched
 * through one of its variants shows that variant's picture (VariantHints) - an option value's
 * picture, or a Variations child's main picture. Only on the storefront search page, once.
 * The argument list is the same on 4.11 and 4.21.
 *
 * @param list<int>                          $product_ids
 * @param array<string,mixed>                $params
 * @param array<int|string,array<mixed>>     $products
 */
function fn_quissly_search_gather_additional_products_data_post($product_ids, $params, &$products, $auth, $lang_code)
{
    try {
        if (!\Quissly\Search\VariantHints::pending() || (empty($params['get_icon']) && empty($params['get_detailed']))) {
            return;
        }
        $children = [];
        $childIds = \Quissly\Search\VariantHints::childIds();
        if ($childIds !== []) {
            $parents = db_get_hash_single_array('SELECT product_id, parent_product_id FROM ?:products WHERE product_id IN (?n) AND status = ?s', ['product_id', 'parent_product_id'], $childIds, 'A');
            $pairs = $parents ? fn_get_image_pairs(array_keys($parents), 'product', 'M', true, true, $lang_code) : [];
            foreach ($parents ?: [] as $id => $parent) {
                $children[(int) $id] = ['parent_product_id' => (int) $parent, 'main_pair' => !empty($pairs[$id]) ? (array) reset($pairs[$id]) : []];
            }
        }
        \Quissly\Search\VariantHints::applyPictures($products, $children);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'gather_additional_products_data_post');
    }
}

/**
 * fn_url's last step: on the storefront search page, a link to a product that matched
 * through one of its variants opens that variant - the product with the matched option values
 * (a colour, a size, or both) selected (`combination`), or the matched Variations child's page
 * (VariantHints). The card's Add to cart dialog (show_quick_view_for_options) opens with the
 * same values chosen, or on the matched child. Runs after
 * the SEO add-on's url_post (priority 2000 > 1800), so it changes the final URL. 4.21 passes
 * $storefront_id too; 4.11 does not.
 */
function fn_quissly_search_url_post(&$_url, $area, $url, $protocol = 'current', $company_id_in_url = false, $lang_code = CART_LANGUAGE, $locations = [], $storefront_id = 0)
{
    try {
        if ($area !== 'C' || !is_string($url)) {
            return;
        }
        $link = \Quissly\Search\VariantHints::linkFor($url) ?? \Quissly\Search\VariantHints::dialogFor($url);
        if (isset($link['combination'])) {
            $_url = fn_link_attach((string) $_url, 'combination=' . $link['combination']);
        } elseif (isset($link['child'])) {
            $_url = fn_url('products.view?product_id=' . (int) $link['child'], $area, $protocol, $lang_code);
        } elseif (isset($link['url'])) {
            $_url = fn_url($link['url'], $area, $protocol, $lang_code);
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'url_post');
    }
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
    try {
        if (empty($simple_mode) && is_array($sorting)) {
            SortMap::registerRelevanceSorting($sorting);
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'products_sorting');
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
    try {
        Sync::productSaved((int) $product_id);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'update_product_post');
    }
}

/**
 * Before a product is deleted: if it is a variation child, its parent is re-sent
 * without it (afterwards the parent can no longer be found).
 *
 * @param int|string $product_id
 */
function fn_quissly_search_delete_product_pre($product_id, $status)
{
    try {
        Sync::productDeleting((int) $product_id);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'delete_product_pre');
    }
}

/** @param int|string $product_id */
function fn_quissly_search_delete_product_post($product_id, $product_deleted)
{
    try {
        if ($product_deleted) {
            Sync::productDeleted((int) $product_id);
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'delete_product_post');
    }
}

/**
 * Stock changed by an order or return (fn_update_product_amount). Only an
 * in-stock / out-of-stock flip is queued.
 */
function fn_quissly_search_update_product_amount(&$new_amount, $product_id, $cart_id, $tracking, $notify, $order_info, $amount_delta, $current_amount, $original_amount, $sign)
{
    try {
        Sync::amountChanged((int) $product_id, $current_amount, $new_amount);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'update_product_amount');
    }
}

/**
 * A status toggle in an admin list (fn_tools_update_status).
 *
 * @param array<string, mixed> $params
 */
function fn_quissly_search_tools_change_status($params, $result)
{
    try {
        if ($result) {
            Sync::statusChanged((array) $params);
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'tools_change_status');
    }
}

/**
 * Multi-Vendor: a vendor's status is about to change (suspend, activate...). Its products
 * are queued now; the worker reads the vendor's status when it sends, so it removes
 * them from Quissly or sends them again accordingly.
 */
function fn_quissly_search_change_company_status_pre($company_id, $status_to, $reason, $status_from, $skip_query, $notify)
{
    try {
        if ($status_to !== $status_from) {
            Sync::vendorChanged((int) $company_id);
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'change_company_status_pre');
    }
}

/**
 * A vendor saved from its edit form, where the status can change too.
 *
 * @param array<string, mixed> $company_data
 */
function fn_quissly_search_update_company($company_data, $company_id, $lang_code, $action)
{
    try {
        if ($action === 'update' && isset($company_data['status'])) {
            Sync::vendorChanged((int) $company_id);
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'update_company');
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
    try {
        $ids = is_object($group) && method_exists($group, 'getProductIds') ? (array) $group->getProductIds() : [];
        foreach ((array) $events as $event) {
            if ($event instanceof \Tygh\Addons\ProductVariations\Product\Group\Events\ProductRemovedEvent) {
                $ids[] = $event->getProduct()->getProductId();
            }
        }
        Sync::productsSaved(array_map('intval', $ids));
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'variation_group_save_group');
    }
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
    try {
        Sync::bundleChanged((int) $bundle_id);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'product_bundle_service_update_bundle');
    }
}

/**
 * @param int|string                        $bundle_id
 * @param array<int|string, array<string>>  $products_data
 * @param array<int, array<string, mixed>>  $data          the new links
 */
function fn_quissly_search_product_bundle_service_update_links($bundle_id, $products_data, $data)
{
    try {
        Sync::bundleChanged(0, array_map('intval', array_column((array) $data, 'product_id')));
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'product_bundle_service_update_links');
    }
}

/** @param int|string $bundle_id */
function fn_quissly_search_product_bundle_service_delete_bundle_pre($bundle_id)
{
    try {
        Sync::bundleChanged((int) $bundle_id);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'product_bundle_service_delete_bundle_pre');
    }
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
    try {
        $result = fn_quissly_search_storefront_build();
    } catch (\Throwable $e) {
        // Nothing of Quissly on this page, which CS-Cart draws as usual.
        $result = [];
        fn_quissly_search_failed($e, 'storefront');
    }

    return $result;
}

/** @return array<string, mixed> fn_quissly_search_storefront()'s answer */
function fn_quissly_search_storefront_build(): array
{
    $credentials = Credentials::fromRegistry();
    $store = new DbSyncStore();
    $configured = $credentials->isConfigured();

    return Storefront::build([
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
            'quick'        => fn_url('quissly_search.quick'),
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
        'overlay_layout' => Features::overlayLayout(),
        'quick_style'    => Features::quickStyle(),
        'i18n'          => [
            'search'             => __('search'),
            'clear'              => __('quissly_search.sf_clear'),
            'seeAll'             => __('quissly_search.sf_see_all'),
            'placeholder'        => __('quissly_search.sf_placeholder'),
            'suggestions'        => trim((string) \Tygh\Registry::get('settings.Company.company_name')) !== ''
                ? __('quissly_search.sf_suggestions', ['[store]' => trim((string) \Tygh\Registry::get('settings.Company.company_name'))])
                : __('quissly_search.sf_suggestions_plain'),
            'mostLoved'          => trim((string) \Tygh\Registry::get('settings.Company.company_name')) !== ''
                ? __('quissly_search.sf_most_loved', ['[store]' => trim((string) \Tygh\Registry::get('settings.Company.company_name'))])
                : __('quissly_search.sf_most_loved_plain'),
            'rating'             => __('quissly_search.sf_rating'),
            'recent'             => __('quissly_search.sf_recent'),
            'removeRecent'       => __('quissly_search.sf_remove_recent'),
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
}

/**
 * Quick Recommendations' rows (lib/Quick/QuickEndpoint.php), in Quissly's order: each from the
 * store's own product in the shopper's language and currency - name, link, image, price (and the
 * list price when it is higher) - as Most loved's cards are; Quissly's Quick answers ids only.
 * A product that is gone, not active, or of a vendor that is not active is left out.
 *
 * @param list<int> $ids
 * @return list<array{id:int, title:string, url:string, image_url:string, price_label:string, original_price_label:string}>
 */
function fn_quissly_search_quick_rows(array $ids): array
{
    $auth = (array) (\Tygh\Tygh::$app['session']['auth'] ?? []);
    $currencyCode = defined('CART_SECONDARY_CURRENCY') ? (string) CART_SECONDARY_CURRENCY : '';
    $currency = (array) (\Tygh\Registry::get('currencies.' . $currencyCode) ?: []);
    $label = static function ($price) use ($currency, $currencyCode): string {
        if (!is_numeric($price) || (float) $price <= 0) {
            return '';
        }
        $price = (float) $price;
        if ($currencyCode !== '' && defined('CART_PRIMARY_CURRENCY') && function_exists('fn_format_price_by_currency')) {
            $price = (float) fn_format_price_by_currency($price, CART_PRIMARY_CURRENCY, $currencyCode);
        }

        return \Quissly\Search\MostLoved::formatPrice($price, $currency);
    };

    $rows = [];
    foreach ($ids as $id) {
        // (id, auth, lang, field list, get_add_pairs, get_main_pair, get_taxes, ...)
        $p = fn_get_product_data((int) $id, $auth, CART_LANGUAGE, '', false, true, false, false, false, false, false);
        if (empty($p['product_id']) || ($p['status'] ?? '') !== 'A' || !\Quissly\Search\Sync\ProductSource::vendorActive((int) ($p['company_id'] ?? 0))) {
            continue;
        }
        $image = '';
        if (!empty($p['main_pair'])) {
            $shown = function_exists('fn_image_to_display') ? (array) fn_image_to_display($p['main_pair'], 160, 160) : [];
            $image = (string) ($shown['image_path'] ?? ($p['main_pair']['detailed']['image_path'] ?? ($p['main_pair']['icon']['image_path'] ?? '')));
        }
        $price = $label($p['price'] ?? null);
        $list = is_numeric($p['list_price'] ?? null) && is_numeric($p['price'] ?? null) && (float) $p['list_price'] > (float) $p['price']
            ? $label($p['list_price'])
            : '';
        $rows[] = [
            'id'                   => (int) $p['product_id'],
            'title'                => (string) ($p['product'] ?? ''),
            'url'                  => (string) fn_url('products.view?product_id=' . (int) $p['product_id']),
            'image_url'            => $image,
            'price_label'          => $price,
            'original_price_label' => $price !== '' ? $list : '',
        ];
    }

    return $rows;
}

/**
 * HTTP for the console (tests pass their own): GET (url, header lines), POST (url, JSON
 * body), PUT (url, JSON body, header lines) -> {status, body}, or null when no answer.
 */
function fn_quissly_search_http_get(int $timeout): callable
{
    return static function (string $url, array $headers) use ($timeout): ?array {
        $response = \Tygh\Http::get($url, [], \Quissly\Search\HttpLog::extra(['headers' => $headers, 'timeout' => $timeout]));
        $status = \Tygh\Http::getStatus();
        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };
}

function fn_quissly_search_http_post(int $timeout): callable
{
    return static function (string $url, string $body) use ($timeout): ?array {
        $response = \Tygh\Http::post($url, $body, \Quissly\Search\HttpLog::extra(['headers' => ['Content-Type: application/json'], 'timeout' => $timeout]));
        $status = \Tygh\Http::getStatus();
        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };
}

function fn_quissly_search_http_put(int $timeout): callable
{
    return static function (string $url, string $body, array $headers) use ($timeout): ?array {
        $response = \Tygh\Http::put($url, $body, \Quissly\Search\HttpLog::extra(['headers' => array_merge(['Content-Type: application/json'], $headers), 'timeout' => $timeout]));
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
    if (\Quissly\Search\Update\Updater::reinstalling()) {
        return ''; // an update rebuilding the settings: drawn for nobody (lib/Update/Updater.php)
    }

    return fn_quissly_search_setting_block('suggestions', 'fn_quissly_search_suggestions_block');
}

/** Configuration's suggestions block, drawn by fn_quissly_search_suggestions_handler(). */
function fn_quissly_search_suggestions_block(): string
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
    if (\Quissly\Search\Update\Updater::reinstalling()) {
        return ''; // an update rebuilding the settings: drawn for nobody (lib/Update/Updater.php)
    }

    return fn_quissly_search_setting_block('buttons', 'fn_quissly_search_buttons_block');
}

/** Configuration's buttons block, drawn by fn_quissly_search_buttons_handler(). */
function fn_quissly_search_buttons_block(): string
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
        'enabled' => \Tygh\Registry::get('addons.quissly_search.most_loved') === 'Y' && !isset(Features::locked()['most_loved']),
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
    if (\Quissly\Search\Update\Updater::reinstalling()) {
        return ''; // an update rebuilding the settings: drawn for nobody (lib/Update/Updater.php)
    }

    return fn_quissly_search_setting_block('most_loved', 'fn_quissly_search_most_loved_block');
}

/** Configuration's most loved block, drawn by fn_quissly_search_most_loved_handler(). */
function fn_quissly_search_most_loved_block(): string
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
 * The picker's search: products whose name (in the admin's language) or code contains $query -
 * under 2 characters, the whole catalog (the picker opens on it, as the Shopify app's) - by name,
 * 20 a page, each as fn_quissly_search_most_loved_item(), and whether a next page exists.
 * Products, not their variations: a variation's code shows its parent's card anyway.
 *
 * @return array{items: list<array{sku:string, name:string, image:string, note:string}>, more: bool}
 */
function fn_quissly_search_most_loved_search(string $query, int $page = 1): array
{
    $per = 20;
    $query = trim($query);
    $like = '%' . $query . '%';
    $filter = mb_strlen($query) >= 2 ? db_quote(' AND (d.product LIKE ?l OR p.product_code LIKE ?l)', $like, $like) : '';
    $codes = db_get_fields(
        "SELECT p.product_code FROM ?:products AS p"
        . " LEFT JOIN ?:product_descriptions AS d ON d.product_id = p.product_id AND d.lang_code = ?s"
        . " WHERE p.product_code != '' AND p.parent_product_id = 0 ?p"
        . " GROUP BY p.product_code ORDER BY MIN(d.product), p.product_code LIMIT ?i, ?i",
        defined('DESCR_SL') ? DESCR_SL : CART_LANGUAGE,
        $filter,
        (max(1, $page) - 1) * $per,
        $per + 1
    );
    $codes = array_map('strval', (array) $codes);

    return [
        'items' => array_map('fn_quissly_search_most_loved_item', array_slice($codes, 0, $per)),
        'more'  => count($codes) > $per,
    ];
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
        $response = \Tygh\Http::post($url, $body, \Quissly\Search\HttpLog::extra(['headers' => ['Content-Type: application/json'], 'timeout' => 10]));
        $status = \Tygh\Http::getStatus();
        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };
    $get = static function (string $url, array $headers): ?array {
        $response = \Tygh\Http::get($url, [], \Quissly\Search\HttpLog::extra(['headers' => $headers, 'timeout' => 10]));
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
 * The cart's amounts before an add to cart (hook pre_add_to_cart), for post_add_to_cart's event.
 *
 * @param array<string, float>|null $set the amounts to keep; null reads them
 * @return array<string, float>
 */
function fn_quissly_search_cart_before(?array $set = null): array
{
    static $amounts = [];
    if ($set !== null) {
        $amounts = $set;
    }

    return $amounts;
}

/** Hook pre_add_to_cart: note the cart's amounts, so post_add_to_cart reports what was really added. */
function fn_quissly_search_pre_add_to_cart($product_data, $cart, $auth, $update)
{
    try {
        fn_quissly_search_cart_before(\Quissly\Search\Events\CartAdds::amounts((array) ($cart['products'] ?? [])));
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'pre_add_to_cart');
    }
}

/**
 * Hook post_add_to_cart: an add_to_cart event per product line that grew, by what it grew (not for a
 * cart update). CS-Cart lowers an amount beyond the stock to what is left: asked for 5000 with 300
 * left, 300 were added (lib/Events/CartAdds).
 */
function fn_quissly_search_post_add_to_cart($product_data, $cart, $auth, $update, $ids)
{
    if ($update || !is_array($ids)) {
        return;
    }
    try {
        foreach (\Quissly\Search\Events\CartAdds::added(fn_quissly_search_cart_before(), (array) ($cart['products'] ?? []), $ids) as $line) {
            [$catalogId, $variantId] = fn_quissly_search_event_ids($line['product_id']);
            fn_quissly_search_event_shopper(EventBody::ADD_TO_CART, EventBody::addToCart($catalogId, $variantId, $line['amount']));
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'post_add_to_cart');
    }
}

/**
 * Hook pre_add_to_wishlist (the wishlist add-on has no "after" hook): an add_to_wishlist event
 * per product.
 */
function fn_quissly_search_pre_add_to_wishlist($product_data, $wishlist, $auth)
{
    try {
        foreach ((array) $product_data as $key => $data) {
            $productId = (int) ($data['product_id'] ?? $key);
            if ($productId > 0) {
                fn_quissly_search_event_shopper(EventBody::WISHLIST, EventBody::wishlist(fn_quissly_search_event_ids($productId)[0]));
            }
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'pre_add_to_wishlist');
    }
}

/**
 * Hook change_order_status: an order becoming paid is a purchase. Paid = CS-Cart's own fn_get_order_paid_statuses() - the statuses that take the stock
 * (by default Processed, Complete and Open, so a cash-on-delivery order counts when placed, as
 * the Magento plugin counts an offline order). Counted when it enters a paid status from an
 * unpaid one, so Processed -> Complete does not count it twice. Its products are queued for
 * the catalog sync then too (their popularity changed); a product view, which also changes
 * it, never queues one.
 */
function fn_quissly_search_change_order_status($status_to, $status_from, $order_info, $force_notification = [], $order_statuses = [], $place_order = false)
{
    try {
        $paid = function_exists('fn_get_order_paid_statuses') ? (array) fn_get_order_paid_statuses() : ['P', 'C'];
        if (in_array($status_to, $paid, true) && !in_array($status_from, $paid, true) && is_array($order_info)) {
            fn_quissly_search_event_order($order_info);
            // The order raised its products' popularity (CS-Cart counts a purchase when the
            // order is placed): queue them, so Quissly's popularity sort follows sales.
            Sync::productsSaved(array_map(static fn ($item): int => (int) ($item['product_id'] ?? 0), array_values((array) ($order_info['products'] ?? []))));
        }
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'change_order_status');
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
            $metadata = \Quissly\Search\Events\VersionReport::metadata($state);
            // A new store's first report also says what it runs on (lib/Diagnostics/InstallReport).
            if (\Quissly\Search\Diagnostics\InstallReport::isFirst($stored)) {
                $metadata += \Quissly\Search\Diagnostics\InstallReport::metadata(
                    fn_quissly_search_install_facts(),
                    (array) ($store->getState(\Quissly\Search\Diagnostics\ErrorReport::STATE) ?? [])
                );
            }
            (new \Quissly\Search\Events\DbEventQueue())->add(EventBody::envelope(\Quissly\Search\Events\VersionReport::EVENT, $metadata, null, [], fn_quissly_search_event_shop(), EventBody::SOURCE_STORE, \Quissly\Search\Shopper::newUuid(), time()), time());
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
 * What this installation runs on, for the first version report (lib/Diagnostics/InstallReport).
 * Facts only - nothing secret or personal. Never throws: what cannot be read is left out.
 *
 * @return array<string, mixed>
 */
function fn_quissly_search_install_facts(): array
{
    $facts = [];
    $read = static function (string $key, callable $fact) use (&$facts): void {
        try {
            $facts[$key] = $fact();
        } catch (\Throwable $e) {
            // Left out.
        }
    };
    $read('platform_version', static fn () => (defined('PRODUCT_VERSION') ? PRODUCT_VERSION : '') . (defined('PRODUCT_EDITION') ? ' ' . PRODUCT_EDITION : ''));
    $read('multivendor', static fn () => function_exists('fn_allowed_for') && fn_allowed_for('MULTIVENDOR'));
    $read('php_version', static fn () => PHP_VERSION);
    $read('php_sapi', static fn () => PHP_SAPI);
    $read('php_extensions', static function (): array {
        $loaded = [];
        foreach (['curl', 'openssl', 'json', 'zip', 'sodium', 'mbstring', 'intl'] as $extension) {
            $loaded[$extension] = extension_loaded($extension);
        }

        return $loaded;
    });
    $read('memory_limit', static fn () => (string) ini_get('memory_limit'));
    $read('max_execution_time', static fn () => (int) ini_get('max_execution_time'));
    $read('addons', static function (): array {
        $on = [];
        foreach (['gdpr', 'wishlist', 'product_variations', 'product_bundles', 'seo', 'master_products'] as $addon) {
            $on[$addon] = \Tygh\Registry::get('addons.' . $addon . '.status') === 'A';
        }

        return $on;
    });
    $read('themes', static fn () => array_values(array_unique(array_map('strval', (array) db_get_fields('SELECT theme_name FROM ?:storefronts')))));
    $read('languages', static fn () => array_map('strval', (array) db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'")));
    $read('main_language', static fn () => fn_quissly_search_main_language());
    $read('currency', static fn () => defined('CART_PRIMARY_CURRENCY') ? (string) CART_PRIMARY_CURRENCY : '');
    $read('products', static fn () => (int) db_get_field("SELECT COUNT(*) FROM ?:products WHERE status = 'A'"));
    $read('updates_can_write', static function (): bool {
        $root = rtrim((string) \Tygh\Registry::get('config.dir.root'), '/');

        return is_writable(__DIR__) && is_writable($root . '/js/addons/quissly_search') && is_writable(rtrim((string) \Tygh\Registry::get('config.dir.var'), '/'));
    });
    $read('server_time', static fn () => gmdate('Y-m-d\TH:i:s\Z'));

    return $facts;
}

/**
 * Right after the store connects (Quissly Setup, Connect): its first version report - with what it
 * runs on - and any problem kept from before, sent now rather than by the next cron run. A few
 * seconds at most; never throws.
 */
function fn_quissly_search_report_now(): void
{
    try {
        $store = new DbSyncStore();
        fn_quissly_search_version_report($store);
        \Quissly\Search\Diagnostics\ErrorCapture::persist();
        fn_quissly_search_error_report($store);
        fn_quissly_search_events_send(4.0);
    } catch (\Throwable $e) {
        fn_quissly_search_failed($e, 'report after connect');
    }
}

/** Quissly's integrations token (lib/Config.php; config.local.php may replace it), '' = none. */
function fn_quissly_search_integrations_token(): string
{
    return trim((string) (\Tygh\Registry::get('config.quissly_integrations_token') ?: \Quissly\Search\Config::INTEGRATIONS_TOKEN));
}

/**
 * One message to Quissly's log: POST /v2beta/integrations/errors with the integrations token (no store
 * key - it works before Connect, and while the add-on is being installed). True when Quissly took it.
 */
function fn_quissly_search_integrations_post(string $error): bool
{
    $token = fn_quissly_search_integrations_token();
    if ($token === '') {
        return false;
    }
    $response = \Tygh\Http::post(
        \Quissly\Search\Config::BASE_URL . \Quissly\Search\Diagnostics\ErrorReport::PATH,
        (string) json_encode(\Quissly\Search\Diagnostics\ErrorReport::body($error), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        \Quissly\Search\HttpLog::extra([
            // X-Platform too: the firewall in front of api.quissly.com refuses a request without it (an HTML 403).
            'headers'            => ['Content-Type: application/json', 'X-Platform: ' . \Quissly\Search\Config::PLATFORM, 'X-Integrations-Token: ' . $token],
            'timeout'            => 5,
            'connection_timeout' => 5,
            'execution_timeout'  => 5,
        ])
    );
    $status = (int) \Tygh\Http::getStatus();

    return $response !== false && $status >= 200 && $status < 300;
}

/**
 * addon.xml's before_install function: CS-Cart calls it once it has read the add-on, before the
 * tables, settings, language files and install functions. It only arranges the check at the end of
 * the installing request (fn_quissly_search_install_outcome). Must not touch the database: CS-Cart
 * counts a database error here as a failed installation.
 */
function fn_quissly_search_before_install()
{
    register_shutdown_function('fn_quissly_search_install_outcome');
}

/**
 * The end of the installing request: an add-on CS-Cart did not install (it removes a failed one) tells
 * Quissly why, as far as CS-Cart said (lib/Diagnostics/InstallReport::failureText). Never throws.
 */
function fn_quissly_search_install_outcome()
{
    try {
        if ((string) db_get_field('SELECT status FROM ?:addons WHERE addon = ?s', 'quissly_search') !== '') {
            return;
        }
        $notices = [];
        foreach ((array) (\Tygh\Tygh::$app['session']['notifications'] ?? []) as $notice) {
            if (in_array($notice['type'] ?? '', ['E', 'W'], true)) {
                $notices[] = strip_tags((string) ($notice['message'] ?? ''));
            }
        }
        fn_quissly_search_integrations_post(\Quissly\Search\Diagnostics\InstallReport::failureText(
            $notices,
            error_get_last(),
            [
                'shop'             => fn_quissly_search_event_shop(),
                'version'          => \Quissly\Search\Update\Release::versionIn((string) @file_get_contents(__DIR__ . '/addon.xml')) ?? '',
                'platform_version' => (defined('PRODUCT_VERSION') ? PRODUCT_VERSION : '') . (defined('PRODUCT_EDITION') ? ' ' . PRODUCT_EDITION : ''),
                'php_version'      => PHP_VERSION,
            ],
            defined('DIR_ROOT') ? (string) DIR_ROOT : ''
        ));
    } catch (\Throwable $e) {
        return;
    }
}

/**
 * Send the add-on's own messages to Quissly's log (POST /v2beta/integrations/errors, with Quissly's
 * integrations token - no store key, so also before the store is connected): once, that it was
 * installed (lib/Diagnostics/InstallReport), then its errors (lib/Diagnostics/ErrorReport), at most
 * ErrorReport::BATCH a run, a few seconds each at most. The first one Quissly does not take stops the
 * run; every error report not taken is counted as unsent again. Never throws.
 */
function fn_quissly_search_error_report(DbSyncStore $store): void
{
    try {
        if (fn_quissly_search_integrations_token() === '') {
            return;
        }
        $post = 'fn_quissly_search_integrations_post';
        $now = time();
        $versions = [
            'shop'             => fn_quissly_search_event_shop(),
            'service'          => Credentials::fromRegistry()->isConfigured() ? QChatDirectory::cachedSearchServiceId($store) : '',
            'version'          => \Quissly\Search\Update\Release::versionIn((string) @file_get_contents(__DIR__ . '/addon.xml')) ?? '',
            'platform_version' => (defined('PRODUCT_VERSION') ? PRODUCT_VERSION : '') . (defined('PRODUCT_EDITION') ? ' ' . PRODUCT_EDITION : ''),
            'php_version'      => PHP_VERSION,
        ];

        // Installed: once, with what the store runs on.
        $ping = (array) ($store->getState(\Quissly\Search\Diagnostics\InstallReport::PING_STATE) ?? []);
        if (\Quissly\Search\Diagnostics\InstallReport::pingDue($ping, $now)) {
            $delivered = $post(\Quissly\Search\Diagnostics\InstallReport::pingText(fn_quissly_search_install_facts(), $versions));
            $store->setState(\Quissly\Search\Diagnostics\InstallReport::PING_STATE, \Quissly\Search\Diagnostics\InstallReport::afterPing($ping, $delivered, $now));
            if (!$delivered) {
                return;
            }
        }

        $stored = (array) ($store->getState(\Quissly\Search\Diagnostics\ErrorReport::STATE) ?? []);
        if ($stored === []) {
            return;
        }
        $result = \Quissly\Search\Diagnostics\ErrorReport::due($stored, $versions, $now);
        $state = $result['state'];
        $stopped = false;
        foreach ($result['reports'] as $i => $metadata) {
            if ($stopped || $i >= \Quissly\Search\Diagnostics\ErrorReport::BATCH) {
                $state = \Quissly\Search\Diagnostics\ErrorReport::unsent($state, $metadata, true);
                continue;
            }
            if (!$post(\Quissly\Search\Diagnostics\ErrorReport::text($metadata))) {
                $stopped = true;
                $state = \Quissly\Search\Diagnostics\ErrorReport::unsent($state, $metadata, false);
            }
        }
        if ($state !== $stored) {
            $store->setState(\Quissly\Search\Diagnostics\ErrorReport::STATE, $state);
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
