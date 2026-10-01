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
