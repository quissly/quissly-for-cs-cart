<?php
/**
 * Quissly for CS-Cart — admin controller.
 *
 * Dispatch: quissly_search.test_connection — fires ONE benign signed QSearch and
 * reports the precise outcome (200 / 401 / 402 / 403 / transport) so the merchant
 * sees misconfiguration here, in admin, rather than mid-search.
 *
 * Dispatch: quissly_search.panel — the embedded Quissly Admin Panel (see
 * lib/Panel/PanelView.php), rendered by views/quissly_search/panel.tpl.
 *
 * Dispatch: quissly_search.dashboard — the Dashboard (lib/Admin/), sync log included.
 * quissly_search.sync_now (POST) — "Sync now": starts a full sync when full=Y, then
 * sends for up to Sync::ADMIN_BUDGET_SECONDS. quissly_search.connect (POST) — one-click
 * Connect (lib/Connect/Connector.php), which then starts the initial sync; its button is
 * on the add-on's settings page (Configuration), drawn by func.php's status handler.
 * quissly_search.setup — Quissly Setup (lib/Setup/, lib/Admin/SetupPage.php), the Shopify
 * app's onboarding, where the menu's three pages lead until it is finished; its steps post
 * to quissly_search.setup_connect / setup_plan / setup_status / setup_finish (JSON).
 * quissly_search.cron — the automatic sync, run from the server's cron:
 *   php /path/to/cart/admin.php --dispatch=quissly_search.cron
 * (CS-Cart runs console dispatches without an admin session; over the web this page
 * needs an admin login like every other admin page.)
 */

use Quissly\Search\Client;
use Quissly\Search\Credentials;
use Quissly\Search\Exception\AuthException;
use Quissly\Search\Exception\QuisslyException;
use Quissly\Search\HealthStore;
use Quissly\Search\Panel\PanelSession;
use Quissly\Search\Panel\PanelView;
use Quissly\Search\Admin\DashboardView;
use Quissly\Search\Admin\BillingPage;
use Quissly\Search\Admin\SetupPage;
use Quissly\Search\Setup\DescriptionDraft;
use Quissly\Search\Setup\Onboarding;
use Quissly\Search\Setup\SetupInput;
use Quissly\Search\Setup\StoreBilling;
use Quissly\Search\Connect\Connector;
use Quissly\Search\Panel\QChatDirectory;
use Quissly\Search\SortMap;
use Quissly\Search\StatusReport;
use Quissly\Search\Sync\DbSyncStore;
use Quissly\Search\Sync\ProductSource;
use Quissly\Search\Sync\Sync;
use Quissly\Search\Sync\SyncWorker;
use Tygh\Registry;
use Tygh\Http;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

/**
 * A run summary in one line, for the notification.
 *
 * @param array{confirmed:int, failed:int, waiting:int, queued:int, stopped:string} $summary
 */
function fn_quissly_search_summary(array $summary): string
{
    $text = __('quissly_search.run_summary', [
        '[confirmed]' => $summary['confirmed'],
        '[waiting]'   => $summary['waiting'],
        '[failed]'    => $summary['failed'],
        '[queued]'    => $summary['queued'],
    ]);

    return $summary['stopped'] !== '' ? $text . ' ' . $summary['stopped'] . '.' : $text;
}

/**
 * A POST to Quissly's console: (url, JSON body) -> {status, body}, null when no answer.
 */
function fn_quissly_search_console_post(int $timeout): callable
{
    return static function (string $url, string $body) use ($timeout): ?array {
        $response = Http::post($url, $body, [
            'headers'            => ['Content-Type: application/json'],
            'timeout'            => $timeout,
            'connection_timeout' => min($timeout, 20),
            'execution_timeout'  => $timeout,
        ]);
        $status = Http::getStatus();
        if ($response === false || !is_int($status) || $status === 0) {
            return null;
        }

        return ['status' => $status, 'body' => (string) $response];
    };
}

/** A GET to Quissly's console with header lines: -> {status, body}, null when no answer. */
function fn_quissly_search_console_get(int $timeout): callable
{
    return static function (string $url, array $headers) use ($timeout): ?array {
        $response = Http::get($url, [], ['headers' => $headers, 'timeout' => $timeout, 'connection_timeout' => $timeout, 'execution_timeout' => $timeout]);
        $status = Http::getStatus();

        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };
}

/** Look the QChat agent id up again and cache it (the storefront only reads the cache). */
function fn_quissly_search_refresh_qchat(DbSyncStore $store, Credentials $credentials): array
{
    return QChatDirectory::refresh($store, $credentials, fn_quissly_search_console_post(10), fn_quissly_search_console_get(10), time());
}

/**
 * Feature rows with what a shopper will actually see: storefront features appear only
 * once Quissly search is live; chat needs the account's agent (looked up here when chat
 * is on and the last lookup is an hour old or found nothing).
 *
 * @param list<array{id:string, label:string, on:bool, built:bool}> $features
 */
function fn_quissly_search_feature_notes(array $features, DbSyncStore $store, Credentials $credentials): array
{
    $gateOpen = SyncWorker::isGateOpen($store);
    foreach ($features as &$feature) {
        if (!$feature['on'] || !$feature['built']) {
            continue;
        }
        if (in_array($feature['id'], ['enable_overlay', 'enable_voice', 'enable_image'], true) && !$gateOpen) {
            $feature['note'] = __('quissly_search.feature_waits_for_search');
        }
        if ($feature['id'] === 'enable_qchat' && $credentials->isConfigured()) {
            $cache = $store->getState(QChatDirectory::STATE);
            if (empty($cache['agent_id']) || (int) ($cache['checked_at'] ?? 0) < time() - 3600) {
                $cache = fn_quissly_search_refresh_qchat($store, $credentials);
            }
            $feature['note'] = !empty($cache['agent_id'])
                ? __('quissly_search.qchat_agent_ok')
                : __('quissly_search.qchat_agent_missing', ['[error]' => (string) ($cache['error'] ?? '')]);
        }
    }

    return $features;
}

/** Quissly Setup's state for this request. */
function fn_quissly_search_onboarding(): Onboarding
{
    return new Onboarding(new DbSyncStore(), Credentials::fromRegistry()->isConfigured(), time());
}

/** The store billing API client, with the store's key (never sent to the browser). */
function fn_quissly_search_store_billing(): StoreBilling
{
    $post = static function (string $url, string $body, array $headers): ?array {
        $response = Http::post($url, $body, ['headers' => array_merge(['Content-Type: application/json'], $headers), 'timeout' => 15]);
        $status = Http::getStatus();

        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    };

    return new StoreBilling(Credentials::fromRegistry()->bearerToken(), fn_quissly_search_http_get(15), $post);
}

/** Answer a Setup step with JSON and stop: no page, no notification queue. */
function fn_quissly_search_setup_json(array $data): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** A Setup message: a langvar name, or Quissly's own words passed through. */
function fn_quissly_search_setup_text(string $message): string
{
    return strpos($message, 'quissly_search.') === 0 ? (string) __($message) : $message;
}

/** Whether QSearch answers the storefront (the add-on's search_interception setting). */
function fn_quissly_search_set_search(bool $on): void
{
    \Tygh\Settings::instance()->updateValue('search_interception', $on ? 'Y' : 'N', 'quissly_search');
}

/**
 * Start the first sync once the new service can take it, holding search back for Go live.
 * Then send for a few seconds: this add-on has no background worker of its own besides the
 * server's cron, so while Setup is open its polling keeps the first sync moving.
 */
function fn_quissly_search_setup_advance(Onboarding $onboarding): void
{
    if ($onboarding->claimFirstSync()) {
        fn_quissly_search_set_search(false);
        Sync::worker()->startFullSync((new ProductSource())->activeIds());
        // The chat service is built with the search one: look it up again if Connect could not.
        $store = new DbSyncStore();
        if (empty($store->getState(QChatDirectory::STATE)['agent_id'])) {
            fn_quissly_search_refresh_qchat($store, Credentials::fromRegistry());
        }
    }
    $progress = (new DbSyncStore())->getState(SyncWorker::STATE_PROGRESS);
    if (!empty($progress['running'])) {
        @set_time_limit(60);
        Sync::worker()->run(8, 'setup');
    }
}

/**
 * The workspace description, drafted from the store's own facts (the Shopify app's draft,
 * lib/Setup/DescriptionDraft.php): the active products and their categories, the company's
 * city and country, the price range and the vendors. Any failure gives no draft.
 */
function fn_quissly_search_setup_draft(string $shopName): string
{
    try {
        $count = (new ProductSource())->countActive();
        $collections = [];
        $vendors = [];
        $prices = null;
        if ($count > 0) {
            $rows = db_get_array(
                'SELECT cd.category AS title, COUNT(DISTINCT pc.product_id) AS product_count'
                . ' FROM ?:categories c'
                . ' JOIN ?:category_descriptions cd ON cd.category_id = c.category_id AND cd.lang_code = ?s'
                . ' JOIN ?:products_categories pc ON pc.category_id = c.category_id'
                . " JOIN ?:products p ON p.product_id = pc.product_id AND p.status = 'A'"
                . " WHERE c.status = 'A' GROUP BY c.category_id",
                CART_LANGUAGE
            );
            foreach ($rows as $row) {
                $title = (string) $row['title'];
                $collections[] = ['title' => $title, 'handle' => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'), 'product_count' => (int) $row['product_count']];
            }
            $range = db_get_row(
                'SELECT MIN(pp.price) AS lo, MAX(pp.price) AS hi FROM ?:product_prices pp'
                . " JOIN ?:products p ON p.product_id = pp.product_id AND p.status = 'A'"
                . ' WHERE pp.lower_limit = 1 AND pp.usergroup_id = 0'
            );
            if (!empty($range['hi'])) {
                $prices = ['min' => (float) $range['lo'], 'max' => (float) $range['hi'], 'currency' => CART_PRIMARY_CURRENCY];
            }
            // Multi-Vendor: the vendors behind the products, one entry per product (ranked by count).
            foreach (db_get_array("SELECT co.company AS name, COUNT(*) AS n FROM ?:products p JOIN ?:companies co ON co.company_id = p.company_id WHERE p.status = 'A' GROUP BY p.company_id") as $row) {
                $vendors = array_merge($vendors, array_fill(0, min(50, max(1, (int) $row['n'])), (string) $row['name']));
            }
        }
        $country = (string) Registry::get('settings.Company.company_country');

        return (string) DescriptionDraft::compose([
            'shop_name'        => $shopName,
            'meta_description' => null,
            'product_count'    => $count,
            'product_kinds'    => [],
            'collections'      => $collections,
            'location'         => [
                'city'    => (string) Registry::get('settings.Company.company_city'),
                'country' => $country !== '' ? (string) fn_get_country_name($country) : '',
            ],
            'prices'           => $prices,
            'vendors'          => $vendors,
        ]);
    } catch (\Throwable $e) {
        return '';
    }
}

if (in_array($mode, ['dashboard', 'panel', 'billing', 'billing_manage'], true) && !fn_quissly_search_onboarding()->isComplete()) {
    return [CONTROLLER_STATUS_REDIRECT, 'quissly_search.setup'];
}

if ($mode === 'setup') {
    $onboarding = fn_quissly_search_onboarding();
    if ($onboarding->isComplete()) {
        return [CONTROLLER_STATUS_REDIRECT, 'quissly_search.dashboard'];
    }
    $step = $onboarding->step();
    $credentials = Credentials::fromRegistry();
    $billing = $step === Onboarding::STEP_DETAILS ? null : fn_quissly_search_store_billing();
    $admin = fn_quissly_search_admin_user();
    $text = static function (string $key): string {
        return (string) __('quissly_search.' . $key);
    };
    $endpoint = static function (string $mode): array {
        return ['url' => fn_url('quissly_search.' . $mode), 'params' => new \stdClass()];
    };
    Tygh::$app['view']->assign('quissly_setup_html', SetupPage::render([
        'step'          => $step,
        'admin_email'   => (string) ($admin['email'] ?? ''),
        'account_email' => $credentials->accountEmail(),
        'store_name'    => SetupInput::suggestName((string) Registry::get('settings.Company.company_name')),
        'description'   => $step === Onboarding::STEP_DETAILS ? fn_quissly_search_setup_draft(trim((string) Registry::get('settings.Company.company_name'))) : '',
        'domain'        => (string) parse_url((string) fn_url('', 'C', 'https'), PHP_URL_HOST),
        'plans'         => $billing !== null ? $billing->plans() : null,
        'subscriptions' => $billing !== null ? $billing->subscriptions() : null,
        'logo_url'      => Registry::get('config.current_location') . '/design/backend/media/images/addons/quissly_search/quissly-wordmark.svg',
        'config'        => [
            'reload'    => fn_url('quissly_search.setup'),
            'csrf'      => ['security_hash' => fn_generate_security_hash()],
            'endpoints' => [
                'connect' => $endpoint('setup_connect'),
                'plan'    => $endpoint('setup_plan'),
                'status'  => $endpoint('setup_status'),
                'finish'  => $endpoint('setup_finish'),
            ],
            'text'      => [
                'connecting'  => $text('su_connecting'),
                'saving'      => $text('su_saving'),
                'goingLive'   => $text('su_going_live'),
                'pickPlan'    => $text('su_pick_plan'),
                'saveYear'    => $text('su_save_year'),
                'payTimeout'  => $text('su_pay_timeout'),
                'payNotYet'   => $text('su_pay_not_yet'),
                'error'       => $text('su_error'),
                'compare'       => $text('su_compare'),
                'compareBack'   => $text('su_compare_back'),
                'emailRequired' => $text('su_email_required'),
                'emailInvalid'  => $text('su_email_invalid'),
                'emailTooLong'  => $text('su_email_too_long'),
                'emailPublic'   => $text('su_email_public'),
                'nameInvalid'   => $text('su_name_invalid'),
                'chipWorking' => $text('su_setting_up'),
                'chipReady'   => $text('su_ready'),
                'chipFailed'  => $text('su_needs_attention'),
                'states'      => [
                    'done'    => $text('su_done'),
                    'running' => $text('su_running'),
                    'pending' => $text('su_waiting'),
                    'failed'  => $text('su_failed'),
                ],
            ],
        ],
    ]));

    return [CONTROLLER_STATUS_OK];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'setup_connect') {
    // What Quissly's backend accepts (the Shopify app's checks), refused here rather than
    // half-way through creating the account.
    $problem = SetupInput::emailProblem((string) ($_REQUEST['email'] ?? ''));
    if ($problem !== '') {
        fn_quissly_search_setup_json(['ok' => false, 'message' => (string) __('quissly_search.su_email_' . $problem)]);
    }
    if (!SetupInput::isNameValid((string) ($_REQUEST['store_name'] ?? ''))) {
        fn_quissly_search_setup_json(['ok' => false, 'message' => (string) __('quissly_search.su_name_invalid')]);
    }
    @set_time_limit(Connector::TIMEOUT_SECONDS + 60);
    $onboarding = fn_quissly_search_onboarding();
    $inSetup = !$onboarding->isComplete();
    $admin = fn_quissly_search_admin_user();
    $typed = trim((string) ($_REQUEST['store_name'] ?? ''));
    $companyName = $typed !== '' ? mb_substr($typed, 0, 100) : trim((string) Registry::get('settings.Company.company_name'));
    $environment = trim((string) Registry::get('config.quissly_environment'));
    $result = (new Connector(Credentials::credentialStore(), fn_quissly_search_console_post(Connector::TIMEOUT_SECONDS)))->connect(
        (string) ($_REQUEST['email'] ?? ''),
        [
            'domain'            => (string) parse_url((string) fn_url('', 'C', 'https'), PHP_URL_HOST),
            'name'              => $companyName !== '' ? $companyName : null,
            'first_name'        => isset($admin['firstname']) ? (string) $admin['firstname'] : null,
            'last_name'         => isset($admin['lastname']) ? (string) $admin['lastname'] : null,
            'environment'       => $environment !== '' ? $environment : \Quissly\Search\Config::DEFAULT_ENVIRONMENT,
            'already_connected' => Credentials::fromRegistry()->source() === Credentials::SOURCE_CONFIG,
            'description'       => trim((string) ($_REQUEST['description'] ?? '')),
        ]
    );
    if (!$result['ok']) {
        fn_quissly_search_setup_json(['ok' => false, 'message' => fn_quissly_search_setup_text($result['message'])]);
    }
    fn_quissly_search_refresh_qchat(new DbSyncStore(), Credentials::fromRegistry());
    if ($inSetup) {
        // The first sync starts once the new service has settled (Onboarding::HOLD_SECONDS).
        fn_quissly_search_onboarding()->connected();
    }
    fn_quissly_search_setup_json(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'setup_plan') {
    if (!Credentials::fromRegistry()->isConfigured()) {
        fn_quissly_search_setup_json(['ok' => false, 'message' => (string) __('quissly_search.su_connect_first')]);
    }
    $onboarding = fn_quissly_search_onboarding();
    $billing = fn_quissly_search_store_billing();
    $op = (string) ($_REQUEST['op'] ?? '');

    if ($op === 'checkout') {
        $checkout = $billing->checkout((string) ($_REQUEST['plan_id'] ?? ''), (string) ($_REQUEST['billing_cycle'] ?? 'monthly'));
        if (!$checkout['ok']) {
            fn_quissly_search_setup_json(['ok' => false, 'message' => fn_quissly_search_setup_text($checkout['message'])]);
        }
        if ($checkout['kind'] === 'free_activated') {
            $onboarding->planChosen();
            fn_quissly_search_setup_json(['ok' => true, 'kind' => 'active']);
        }
        fn_quissly_search_setup_json(['ok' => true, 'kind' => 'pay', 'pay_url' => $checkout['pay_url']]);
    }
    if ($op === 'check' || $op === 'keep') {
        $subscriptions = $billing->subscriptions();
        if ($subscriptions === null) {
            fn_quissly_search_setup_json(['ok' => false, 'message' => fn_quissly_search_setup_text(StoreBilling::message(0, ''))]);
        }
        $live = StoreBilling::livePlans($subscriptions);
        $family = (string) ($_REQUEST['family'] ?? '');
        $active = $family !== '' ? isset($live[$family]) : $live !== [];
        if ($active) {
            $onboarding->planChosen();
        }
        fn_quissly_search_setup_json(['ok' => true, 'active' => $active, 'message' => $active ? '' : (string) __('quissly_search.su_pay_not_yet')]);
    }
    // Only while the plans cannot be loaded, so a Quissly outage never strands a merchant.
    if ($op === 'later' && $billing->plans() === null) {
        $onboarding->planChosen();
        fn_quissly_search_setup_json(['ok' => true]);
    }
    fn_quissly_search_setup_json(['ok' => false, 'message' => (string) __('quissly_search.su_pick_plan')]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'setup_status') {
    $onboarding = fn_quissly_search_onboarding();
    if (($_REQUEST['retry'] ?? '') === '1' && $onboarding->syncFailed()) {
        $store = new DbSyncStore();
        $store->setState(SyncWorker::STATE_REFUSAL, null);
        Sync::worker()->startFullSync((new ProductSource())->activeIds());
    }
    if (!$onboarding->isComplete()) {
        fn_quissly_search_setup_advance($onboarding);
    }
    $status = fn_quissly_search_onboarding()->status(!empty((new DbSyncStore())->getState(QChatDirectory::STATE)['agent_id']));
    foreach ($status['rows'] as $key => $row) {
        $status['rows'][$key]['detail'] = $row['detail'] === null ? '' : (string) __($row['detail'][0], $row['detail'][1]);
    }
    fn_quissly_search_setup_json(['ok' => true] + $status);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'setup_finish') {
    $onboarding = fn_quissly_search_onboarding();
    // "Save changes" (the Shopify app's): Setup is done, search stays off for now.
    if (($_REQUEST['later'] ?? '') === '1') {
        if (!$onboarding->canSaveForLater()) {
            fn_quissly_search_setup_json(['ok' => false, 'message' => (string) __('quissly_search.su_still_building')]);
        }
        fn_quissly_search_setup_advance($onboarding);
        $onboarding->complete();
        (new DbSyncStore())->log('Quissly Setup saved without going live; QSearch stays off until it is switched on.', time());
        fn_set_notification('N', __('notice'), __('quissly_search.su_saved_later'));
        fn_quissly_search_setup_json(['ok' => true, 'redirect' => fn_url('quissly_search.dashboard')]);
    }
    if (!$onboarding->isReady()) {
        fn_quissly_search_setup_json(['ok' => false, 'message' => (string) __('quissly_search.su_not_ready')]);
    }
    fn_quissly_search_set_search(true);
    $onboarding->complete();
    (new DbSyncStore())->log('Quissly Setup finished; QSearch is live.', time());
    fn_set_notification('N', __('notice'), __('quissly_search.su_live_now'));
    fn_quissly_search_setup_json(['ok' => true, 'redirect' => fn_url('quissly_search.dashboard')]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'connect') {
    @set_time_limit(Connector::TIMEOUT_SECONDS + Sync::ADMIN_BUDGET_SECONDS + 60);
    $admin = fn_quissly_search_admin_user();
    $companyName = trim((string) Registry::get('settings.Company.company_name'));
    $environment = trim((string) Registry::get('config.quissly_environment'));
    $result = (new Connector(Credentials::credentialStore(), fn_quissly_search_console_post(Connector::TIMEOUT_SECONDS)))->connect(
        (string) ($_REQUEST['email'] ?? ''),
        [
            'domain'            => (string) parse_url((string) fn_url('', 'C', 'https'), PHP_URL_HOST),
            'name'              => $companyName !== '' ? $companyName : null,
            'first_name'        => isset($admin['firstname']) ? (string) $admin['firstname'] : null,
            'last_name'         => isset($admin['lastname']) ? (string) $admin['lastname'] : null,
            'environment'       => $environment !== '' ? $environment : \Quissly\Search\Config::DEFAULT_ENVIRONMENT,
            'already_connected' => Credentials::fromRegistry()->source() === Credentials::SOURCE_CONFIG,
        ]
    );
    if (!$result['ok']) {
        fn_set_notification('E', __('error'), $result['message']);

        return [CONTROLLER_STATUS_REDIRECT, 'addons.update?addon=quissly_search'];
    }

    // Provisioning creates the chat agent but does not return its id: look it up now, so
    // turning QChat on later needs nothing else (a miss is retried from the Dashboard).
    fn_quissly_search_refresh_qchat(new DbSyncStore(), Credentials::fromRegistry());

    // Connected: the initial sync starts now (the WooCommerce plugin's wizard does the same).
    $worker = Sync::worker();
    $worker->startFullSync((new ProductSource())->activeIds());
    $summary = $worker->run(Sync::ADMIN_BUDGET_SECONDS, 'connect');
    fn_set_notification('N', __('notice'), __('quissly_search.connect_ok') . ' ' . fn_quissly_search_summary($summary));

    return [CONTROLLER_STATUS_REDIRECT, 'quissly_search.dashboard'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'sync_now') {
    if (!Credentials::fromRegistry()->isConfigured()) {
        fn_set_notification('E', __('error'), __('quissly_search.not_configured'));

        return [CONTROLLER_STATUS_REDIRECT, 'quissly_search.dashboard'];
    }
    @set_time_limit(Sync::ADMIN_BUDGET_SECONDS + 60);
    $worker = Sync::worker();
    if (($_REQUEST['full'] ?? '') === 'Y') {
        $worker->startFullSync((new ProductSource())->activeIds());
    }
    $summary = $worker->run(Sync::ADMIN_BUDGET_SECONDS, 'admin');
    fn_set_notification($summary['stopped'] !== '' ? 'W' : 'N', __('notice'), fn_quissly_search_summary($summary));

    return [CONTROLLER_STATUS_REDIRECT, 'quissly_search.dashboard'];
}

if ($mode === 'cron') {
    $store = new DbSyncStore();
    $store->setState('last_cron', ['at' => time()]);
    if (Credentials::fromRegistry()->isConfigured()) {
        @set_time_limit(Sync::CRON_BUDGET_SECONDS + 60);
        $summary = Sync::worker()->run(Sync::CRON_BUDGET_SECONDS, 'cron');
        fn_set_notification($summary['stopped'] !== '' ? 'W' : 'N', 'Quissly', fn_quissly_search_summary($summary));
        // Search bar suggestions from the catalog: once per store, when due (lib/Showcase/).
        fn_quissly_search_showcase_tick($store);
    }
    // A newer release from GitHub, checked once a day and installed (lib/Update/).
    \Quissly\Search\Update\Updater::runIfDue($store, time());

    return defined('CONSOLE') ? [CONTROLLER_STATUS_OK] : [CONTROLLER_STATUS_REDIRECT, 'quissly_search.dashboard'];
}

if ($mode === 'dashboard') {
    $store = new DbSyncStore();
    $credentials = Credentials::fromRegistry();
    $connected = $credentials->isConfigured();
    Tygh::$app['view']->assign('quissly_dashboard_html', DashboardView::render([
        'connected'       => $connected,
        'environment'     => $connected ? $credentials->environment() : '',
        'status'          => StatusReport::build($credentials, $store),
        'features'        => fn_quissly_search_feature_notes(\Quissly\Search\Features::all(), $store, $credentials),
        'active_products' => (new ProductSource())->countActive(),
        'synced'          => $store->countSynced(),
        'queued'          => $store->countQueued(),
        'waiting'         => $store->waiting(),
        'progress'        => $store->getState(SyncWorker::STATE_PROGRESS),
        'initial_sync'    => SyncWorker::isGateOpen($store),
        'gate_blocked'    => $store->getState(SyncWorker::STATE_GATE_BLOCKED),
        'refusal'         => $store->getState(SyncWorker::STATE_REFUSAL),
        'last_run'        => $store->getState(SyncWorker::STATE_LAST_RUN),
        'last_cron_at'    => isset($store->getState('last_cron')['at']) ? (int) $store->getState('last_cron')['at'] : null,
        'cron_command'    => 'php ' . rtrim(DIR_ROOT, '/') . '/' . Registry::get('config.admin_index') . ' --dispatch=quissly_search.cron',
        'log'             => $store->recentLog(DashboardView::LOG_LINES),
        'now'             => time(),
        'urls'            => [
            'sync_now'        => fn_url('quissly_search.sync_now'),
            'test_connection' => fn_url('quissly_search.test_connection?redirect_to=dashboard'),
            'settings'        => fn_url('addons.update?addon=quissly_search'),
            'panel'           => fn_url('quissly_search.panel'),
        ],
        'security_hash'   => fn_generate_security_hash(),
    ]));

    return [CONTROLLER_STATUS_OK];
}

if ($mode === 'test_connection') {
    $return_url = ($_REQUEST['redirect_to'] ?? '') === 'dashboard' ? 'quissly_search.dashboard' : 'addons.update?addon=quissly_search';

    $credentials = Credentials::fromRegistry();
    if (!$credentials->isConfigured()) {
        fn_set_notification('E', __('error'), __('quissly_search.not_configured'));

        return [CONTROLLER_STATUS_REDIRECT, $return_url];
    }

    try {
        $result = (new Client($credentials))->qsearch('test', 'admin_test_connection', 1, 1, SortMap::Q_RELEVANCE, SortMap::T_DESC);
        HealthStore::recordSuccess();
        fn_set_notification(
            'N',
            __('notice'),
            __('quissly_search.test_connection_ok', ['[count]' => $result->numTotalResults])
        );
    } catch (AuthException $e) {
        HealthStore::recordFailure($e->getHttpStatus(), $e->getMessage());
        fn_set_notification(
            'E',
            __('error'),
            __('quissly_search.test_connection_auth_fail', ['[status]' => $e->getHttpStatus()])
        );
    } catch (QuisslyException $e) {
        fn_set_notification(
            'W',
            __('warning'),
            __('quissly_search.test_connection_fail', ['[status]' => $e->getHttpStatus()])
        );
    }

    return [CONTROLLER_STATUS_REDIRECT, $return_url];
}

if ($mode === 'billing') {
    $billing = fn_quissly_search_store_billing();
    $text = static function (string $key): string {
        return (string) __('quissly_search.' . $key);
    };
    Tygh::$app['view']->assign('quissly_billing_html', BillingPage::render(
        BillingPage::view(
            $billing->plans(),
            $billing->subscriptions(),
            static function (string $id) use ($billing): ?array {
                return $billing->usage($id);
            },
            $billing->invoices()
        ),
        [
            'endpoint' => fn_url('quissly_search.billing_manage'),
            'reload'   => fn_url('quissly_search.billing'),
            'csrf'     => ['security_hash' => fn_generate_security_hash()],
            'text'     => [
                'working'       => $text('bi_working'),
                'error'         => $text('su_error'),
                'payWaiting'    => $text('bi_pay_waiting'),
                'payTimeout'    => $text('bi_pay_timeout'),
                'close'         => $text('bi_close'),
                'titleChange'   => $text('bi_title_change'),
                'titleTopup'    => $text('bi_title_topup'),
                'titleCancel'   => $text('bi_title_cancel'),
                'titlePay'      => $text('bi_title_pay'),
                'titleError'    => $text('bi_title_error'),
                'confirmCancel' => $text('bi_cancel_plan'),
            ],
        ]
    ));

    return [CONTROLLER_STATUS_OK];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'billing_manage') {
    $billing = fn_quissly_search_store_billing();
    $op = (string) ($_REQUEST['op'] ?? '');
    $id = (string) ($_REQUEST['id'] ?? '');
    $planId = (string) ($_REQUEST['plan_id'] ?? '');
    $money = static function ($value, string $currency): string {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $number = number_format((float) $value, 2);

        return isset($symbols[$currency]) ? $symbols[$currency] . $number : $currency . ' ' . $number;
    };
    $done = static function (array $result, string $notice): void {
        if ($result['ok']) {
            fn_set_notification('N', __('notice'), $notice);
            fn_quissly_search_setup_json(['ok' => true, 'done' => true]);
        }
        fn_quissly_search_setup_json(['ok' => false, 'message' => fn_quissly_search_setup_text($result['message'])]);
    };

    switch ($op) {
        case 'checkout':
            $checkout = $billing->checkout($planId, (string) ($_REQUEST['billing_cycle'] ?? ''));
            if ($checkout['ok'] && $checkout['kind'] === 'free_activated') {
                fn_set_notification('N', __('notice'), __('quissly_search.bi_free_on'));
                fn_quissly_search_setup_json(['ok' => true, 'done' => true]);
            }
            fn_quissly_search_setup_json($checkout['ok']
                ? ['ok' => true, 'pay_url' => $checkout['pay_url']]
                : ['ok' => false, 'message' => fn_quissly_search_setup_text($checkout['message'])]);
            // no break: answered
        case 'check':
            $subscriptions = $billing->subscriptions();
            $active = $subscriptions !== null && isset(StoreBilling::livePlans($subscriptions)[(string) ($_REQUEST['family'] ?? '')]);
            if ($active) {
                fn_set_notification('N', __('notice'), __('quissly_search.bi_paid_on'));
            }
            fn_quissly_search_setup_json(['ok' => true, 'active' => $active]);
            // no break: answered
        case 'change_preview':
            $result = $billing->manage($op, $id, ['plan_id' => $planId]);
            if (!$result['ok']) {
                fn_quissly_search_setup_json(['ok' => false, 'message' => fn_quissly_search_setup_text($result['message'])]);
            }
            $preview = $result['data'];
            $kind = (string) ($preview['kind'] ?? '');
            if ($kind === 'upgrade') {
                $summary = __('quissly_search.bi_preview_upgrade', ['[amount]' => $money($preview['charge_now'] ?? 0, (string) ($preview['currency'] ?? 'USD'))]);
            } elseif ($kind === 'downgrade') {
                $summary = __('quissly_search.bi_preview_downgrade', ['[date]' => BillingPage::date((string) ($preview['effective_at'] ?? ''))]);
            } elseif ($kind === 'trial_swap') {
                $summary = __('quissly_search.bi_preview_trial');
            } else {
                $summary = __('quissly_search.bi_preview_other');
            }
            fn_quissly_search_setup_json(['ok' => true, 'summary' => (string) $summary, 'confirm' => (string) __('quissly_search.bi_change_plan')]);
            // no break: answered
        case 'change':
            $result = $billing->manage($op, $id, ['plan_id' => $planId]);
            $kind = (string) ($result['data']['kind'] ?? '');
            $done($result, (string) ($kind === 'scheduled'
                ? __('quissly_search.bi_done_scheduled', ['[date]' => BillingPage::date((string) ($result['data']['subscription']['current_period_end'] ?? ''))])
                : ($kind === 'processing' ? __('quissly_search.bi_done_processing') : __('quissly_search.bi_done_changed'))));
            // no break: answered
        case 'topup_preview':
            $result = $billing->manage($op, $id);
            if (!$result['ok']) {
                fn_quissly_search_setup_json(['ok' => false, 'message' => fn_quissly_search_setup_text($result['message'])]);
            }
            fn_quissly_search_setup_json(['ok' => true, 'confirm' => (string) __('quissly_search.bi_buy_now'), 'summary' => (string) __('quissly_search.bi_preview_topup', [
                '[count]'  => number_format((int) ($result['data']['requests'] ?? 0)),
                '[amount]' => $money($result['data']['charge_now'] ?? 0, (string) ($result['data']['currency'] ?? 'USD')),
            ])]);
            // no break: answered
        case 'topup':
            $result = $billing->manage($op, $id, ['idempotency_key' => (string) ($_REQUEST['idempotency_key'] ?? '')]);
            $done($result, (string) (($result['data']['kind'] ?? '') === 'processing'
                ? __('quissly_search.bi_topup_processing')
                : __('quissly_search.bi_topup_added', ['[count]' => number_format((int) ($result['data']['requests'] ?? 0))])));
            // no break: answered
        case 'cancel':
        case 'resume':
        case 'abort':
            $done($billing->manage($op, $id), (string) __(['cancel' => 'quissly_search.bi_done_cancel', 'resume' => 'quissly_search.bi_done_resume', 'abort' => 'quissly_search.bi_done_abort'][$op]));
            // no break: answered
        case 'payment_method':
        case 'invoice_pdf':
            $result = $billing->manage($op, $id);
            fn_quissly_search_setup_json($result['ok']
                ? ['ok' => true, 'url' => (string) ($result['data']['url'] ?? $result['data']['pay_url'] ?? '')]
                : ['ok' => false, 'message' => fn_quissly_search_setup_text($result['message'])]);
    }
    fn_quissly_search_setup_json(['ok' => false, 'message' => (string) __('quissly_search.bi_unknown')]);
}

if ($mode === 'panel') {
    $post = static function (string $url, string $body): ?array {
        $response = Http::post($url, $body, [
            'headers'            => ['Content-Type: application/json'],
            'timeout'            => PanelSession::TIMEOUT_SECONDS,
            'connection_timeout' => PanelSession::TIMEOUT_SECONDS,
            'execution_timeout'  => PanelSession::TIMEOUT_SECONDS,
        ]);
        $status = Http::getStatus();
        if ($response === false || !is_int($status) || $status === 0) {
            return null;
        }

        return ['status' => $status, 'body' => (string) $response];
    };

    Tygh::$app['view']->assign('quissly_panel_html', PanelView::render(Credentials::fromRegistry(), $post));

    return [CONTROLLER_STATUS_OK];
}

return [CONTROLLER_STATUS_NO_PAGE];
