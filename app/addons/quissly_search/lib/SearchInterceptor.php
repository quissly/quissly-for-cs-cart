<?php

declare(strict_types=1);

namespace Quissly\Search;

use Quissly\Search\Exception\AuthException;
use Quissly\Search\Exception\ConfigurationException;
use Quissly\Search\Exception\QuisslyException;
use Tygh\Registry;
use Quissly\Search\Media\DbTokenStore;
use Quissly\Search\Media\MediaEndpoint;
use Quissly\Search\Sync\DbSyncStore;
use Quissly\Search\Sync\SyncWorker;
use Tygh\Tygh;

/**
 * Orchestrates the storefront search interception from inside the
 * `get_products_before_select` hook.
 *
 * The guard is the highest-risk item: it
 * anchors on the CONTROLLER DISPATCH (products.search), never merely on the
 * presence of `q`, and a static latch ensures we act on exactly the FIRST
 * qualifying fetch — so a related/block product fetch during the same request is
 * never hijacked.
 *
 * On success it rewrites the query-building pieces (all available by reference at
 * this hook) so fn_get_products returns exactly Quissly's IDs, in Quissly's
 * order, with the pager reflecting the true total. On failure it applies the
 * fallback matrix.
 */
final class SearchInterceptor
{
    /** Latch: we intercept at most once per request (the primary search fetch). */
    private static bool $handled = false;

    /** Test seam: factory producing a {@see QSearchClient}; null = real Client. */
    private static ?\Closure $clientFactory = null;

    /**
     * Hook entry point. All arguments are the by-reference query-building pieces
     * from fn_get_products' `get_products_before_select` hook.
     *
     * @param array<string, mixed> $params
     */
    public static function interceptBeforeSelect(
        array &$params,
        string &$condition,
        array &$sortings,
        &$total,
        &$itemsPerPage
    ): void {
        // $total and $items_per_page are intentionally untyped by-ref: CS-Cart may
        // hand us a numeric string (e.g. the products_per_page setting), and PHP
        // raises a TypeError when binding a mismatched type to a typed reference.
        if (!self::shouldIntercept($params)) {
            return;
        }

        $query = trim((string) $params['q']);
        $credentials = Credentials::fromRegistry();

        // Belt-and-suspenders: never act without credentials (guard already checks).
        if (!$credentials->isConfigured()) {
            SearchSignal::recordSkip('not-configured');

            return;
        }

        // First-sync gate (as in the WooCommerce plugin): until a full catalog sync
        // has been confirmed by Quissly, its index may be empty, and an empty answer is
        // a real "no results" — so native CS-Cart search serves the store instead.
        if (!SyncWorker::isGateOpen(new DbSyncStore())) {
            SearchSignal::recordSkip('gate-closed');

            return;
        }

        // Latch BEFORE the API call so any nested fn_get_products triggered during
        // this request cannot recurse into a second interception.
        self::$handled = true;

        // A voice/image results page: the products were chosen when the shopper spoke or
        // sent the photo; the URL only carries the token (and a placeholder q).
        $token = self::mediaToken();
        if ($token !== '') {
            self::applyMediaResult($params, $condition, $sortings, $token);

            return;
        }

        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = self::resolvePageSize($itemsPerPage);

        // Map the shopper's CS-Cart sort selection to Quissly's codes. Only sorts
        // Quissly honors (relevance, price asc/desc — verified live 2026-06) get a
        // dedicated code; anything else resolves to relevance.
        $sort = SortMap::resolve(
            isset($params['sort_by']) ? (string) $params['sort_by'] : null,
            isset($params['sort_order']) ? (string) $params['sort_order'] : null
        );

        try {
            $client = self::$clientFactory !== null
                ? (self::$clientFactory)($credentials)
                : new Client($credentials);
            $result = $client->qsearch(
                $query,
                self::resolveUserId(),
                $page,
                $pageSize,
                $sort['sort_by'],
                $sort['sort_type'],
                Shopper::deviceAndOs((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))
            );
        } catch (AuthException $e) {
            // 401/402/403: config/billing problem. NO native swap masking — the
            // customer still gets native results (we leave $params untouched), but
            // the merchant must be alerted.
            self::recordAuthAlert($e);
            SearchSignal::recordFallback(SearchSignal::codeFor($e));

            return;
        } catch (QuisslyException $e) {
            SearchSignal::recordFallback(SearchSignal::codeFor($e));
            // Transient (timeout/5xx/transport/429) or our-bug (400/422/404).
            if ($page === 1) {
                // Page 1: silently degrade to native — leave everything untouched.
                return;
            }

            // Page 2+: a native swap would produce an inconsistent result set
            // across pages. Show a graceful notice and an empty page instead.
            self::renderGracefulNotice($params, $condition, $total);

            return;
        }

        // -- Success (including a real 200-empty) --
        $ids = $result->ids;

        // Quissly honors relevance and price (asc/desc); date and popularity are
        // NOT honored (verified live 2026-06). So on Quissly-driven search pages we
        // restrict the storefront "Sort by" dropdown to the honored options
        // (relevance + price) rather than showing controls that silently do
        // nothing. Per-page and layout controls are untouched.
        self::restrictSortControl();

        // Quissly answered: its results (or its real "nothing found") are what renders.
        SearchSignal::recordHit(count($ids), $result->numTotalResults);

        if ($ids === []) {
            // 200 with empty documents is a REAL answer — render the empty state,
            // do NOT fall back to native.
            self::forceEmptyResult($params, $condition, $total);

            return;
        }

        self::applyResult($params, $condition, $sortings, $total, $ids, $result->numTotalResults, $sort);
    }

    /**
     * The guard. Fire ONLY when all hold.
     *
     * @param array<string, mixed> $params
     */
    private static function shouldIntercept(array $params): bool
    {
        if (self::$handled) {
            return false; // already intercepted the primary fetch this request
        }

        // Storefront only, never admin.
        if (!defined('AREA') || AREA !== 'C') {
            return false;
        }

        // Anchor on the controller dispatch — the real search controller path.
        if (Registry::get('runtime.controller') !== 'products' || Registry::get('runtime.mode') !== 'search') {
            return false;
        }

        // From here on this is the storefront search page: a guard that fails says why
        // (X-Quissly-Search / the ?quissly_debug=1 badge, via SearchSignal).

        // Master on/off toggle (stored add-on setting; default Y when unset).
        if (Registry::get('addons.quissly_search.search_interception') === 'N') {
            SearchSignal::recordSkip('search-off');

            return false;
        }

        // A real keyword of >= 2 chars (trimmed, multibyte-aware).
        if (!isset($params['q'])) {
            SearchSignal::recordSkip('no-term');

            return false;
        }
        $query = trim((string) $params['q']);
        $length = mb_strlen($query);
        if ($length < Config::MIN_QUERY_LENGTH || $length > Config::MAX_QUERY_LENGTH) {
            SearchSignal::recordSkip('no-term');

            return false;
        }

        // A shopper refinement (a sidebar filter, a category, a price range...): CS-Cart's own
        // search answers. The interception replaces the whole WHERE condition, so it would
        // silently drop the refinement while the sidebar still showed it as active.
        $refinement = self::refinement($_REQUEST);
        if ($refinement !== null) {
            SearchSignal::recordSkip('filter:' . $refinement);

            return false;
        }

        return true;
    }

    /**
     * The first refinement the shopper's request carries, null when there is none. Read from
     * the request (what the shopper sent), not from fn_get_products' params, which CS-Cart
     * fills itself. Empty values - '', '0', 'N', [] - are no refinement: the theme's search
     * form sends cid=0.
     *
     * @param array<string, mixed> $request
     */
    public static function refinement(array $request): ?string
    {
        foreach (self::REFINEMENTS as $name) {
            $value = $request[$name] ?? null;
            if (is_array($value)) {
                $value = array_filter($value, static fn ($v): bool => $v !== '' && $v !== null);
                if ($value !== []) {
                    return $name;
                }
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && $value !== '0' && $value !== 'N') {
                return $name;
            }
        }

        return null;
    }

    /** Request parameters that narrow fn_get_products' results - the shopper's refinements. */
    private const REFINEMENTS = [
        'features_hash', 'filter_variants', 'feature_variants', 'variant_id',
        'cid', 'category_id',
        'price_from', 'price_to', 'weight_from', 'weight_to', 'amount_from', 'amount_to',
        'popularity_from', 'popularity_to',
        'pcode', 'company_id', 'free_shipping',
    ];

    /** The voice/image token on this request, '' when it is a typed search. */
    private static function mediaToken(): string
    {
        foreach ([MediaEndpoint::QUERY_VAR_VOICE, MediaEndpoint::QUERY_VAR_IMAGE] as $var) {
            $value = isset($_REQUEST[$var]) ? trim((string) $_REQUEST[$var]) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Serve a voice/image result: exactly the products the token holds. Unlike a typed
     * search there is nothing to re-ask Quissly, so CS-Cart paginates and sorts this
     * fixed set itself: relevance keeps Quissly's order (ORDER BY FIELD over all the
     * ids), a price sort is plain SQL over the same set (the WooCommerce plugin sorts
     * its snapshot locally for the same reason). An expired token shows an empty result
     * with a notice rather than a text search for the placeholder words.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $sortings
     */
    private static function applyMediaResult(array &$params, string &$condition, array &$sortings, string $token): void
    {
        self::restrictSortControl();
        $ids = (new DbTokenStore())->get($token, time());
        $condition = '';
        if ($ids === null || $ids === []) {
            SearchSignal::recordFallback('token_expired');
            $params['pid'] = [0];
            if ($ids === null && function_exists('fn_set_notification')) {
                fn_set_notification('W', __('warning'), __('quissly_search.media_expired'));
            }

            return;
        }
        $params['pid'] = $ids;
        SearchSignal::recordHit(count($ids), count($ids));
        $sort = SortMap::resolve(
            isset($params['sort_by']) ? (string) $params['sort_by'] : null,
            isset($params['sort_order']) ? (string) $params['sort_order'] : null
        );
        if ($sort['key'] === SortMap::RELEVANCE_KEY) {
            $orderIds = $sort['order'] === 'desc' ? array_reverse($ids) : $ids;
            $sortings[$sort['key']] = 'FIELD(products.product_id, ' . implode(',', array_map('intval', $orderIds)) . ')';
        }
        $params['sort_by'] = $sort['key'];
        $params['sort_order'] = $sort['order'];
    }

    /**
     * Rewrite the query-building pieces to return exactly $ids, in order, with the
     * pager total overridden. Called only with a non-empty $ids of positive ints.
     *
     * @param array<string, mixed>                                  $params
     * @param array<string, mixed>                                  $sortings
     * @param list<int>                                             $ids
     * @param array{key:string,order:string,sort_by:int,sort_type:int} $sort resolved sort
     */
    private static function applyResult(
        array &$params,
        string &$condition,
        array &$sortings,
        int &$total,
        array $ids,
        int $numTotalResults,
        array $sort
    ): void {
        // 1. Blank the native keyword LIKE SQL so it can't AND-collapse against our
        //    ID filter. At this hook, for a primary keyword search, $condition holds
        //    only that keyword clause.
        $condition = '';

        // 2. Constrain the fetch to our IDs (builds `products.product_id IN (...)`).
        $params['pid'] = $ids;

        // 3. Preserve Quissly's returned order (relevance OR the honored price sort
        //    it just applied). CS-Cart has no native order-by-id-sequence, so inject
        //    ORDER BY FIELD(...) via the sortings map (db_sort runs after this hook).
        //    db_sort appends the sort_order to our clause, so for a 'desc' selection
        //    we reverse the ID list — FIELD(reversed) DESC then renders Quissly's
        //    exact order. We keep $params['sort_by']/['sort_order'] as the shopper's
        //    effective choice so the storefront dropdown shows the correct active
        //    option/label (fn_get_products returns these as $search to the template).
        $orderIds = $sort['order'] === 'desc' ? array_reverse($ids) : $ids;
        $idList = implode(',', array_map('intval', $orderIds));
        $sortings[$sort['key']] = 'FIELD(products.product_id, ' . $idList . ')';
        $params['sort_by'] = $sort['key'];
        $params['sort_order'] = $sort['order'];

        // 4. Pager: override the total to Quissly's grand total (not this page's
        //    ID count). Setting $total here also disables SQL_CALC_FOUND_ROWS.
        $total = $numTotalResults;

        // 5. Force `LIMIT 0, count` so paginating into a page's ID set never offsets
        //    into emptiness — Quissly already returned just this page's IDs.
        $params['limit'] = count($ids);

        // Catalog/index drift check: how many of the returned IDs actually exist
        // in this catalog. parsed == pid always (we pass the parsed IDs straight
        // through). If exist_local < pid, Quissly returned IDs the store doesn't
        // have — those silently don't render. That is a catalog/index sync issue,
        // NOT an interception bug, so surface it for the merchant. Stays silent on
        // a healthy store (all IDs present) to avoid per-search log noise.
        self::warnOnCatalogDrift($ids);
    }

    /**
     * @param list<int> $ids parsed QSearch IDs (== the pid set we pass in)
     */
    private static function warnOnCatalogDrift(array $ids): void
    {
        if ($ids === [] || !function_exists('db_get_field')) {
            return;
        }

        $existLocal = (int) db_get_field('SELECT COUNT(*) FROM ?:products WHERE product_id IN (?n)', $ids);

        if ($existLocal < count($ids)) {
            Logger::notice('QSearch returned product IDs not present in the catalog (index/catalog drift)', [
                'parsed'      => count($ids),   // IDs parsed from the QSearch response
                'pid'         => count($ids),   // IDs handed to fn_get_products as pid
                'exist_local' => $existLocal,   // of those, rows present in ?:products
                'missing'     => count($ids) - $existLocal,
            ]);
        }
    }

    /**
     * Restrict the storefront "Sort by" dropdown to the Quissly-honored options
     * (relevance + price asc/desc) for this request, in-memory (request-scoped;
     * not persisted).
     *
     * The dropdown reads settings.Appearance.available_product_list_sortings (a
     * `'<key>-<order>' => 'Y'` map) in views/products/components/sorting.tpl, gated
     * by `{if $avail_sorting}`. Setting it to only the honored options removes the
     * unhonored sorts (date, popularity) and name — the per-page selector and the
     * grid/list layout toggle, which sit outside that gate, stay visible. The
     * synthetic `quissly_relevance` option is registered for rendering via the
     * `products_sorting` hook ({@see SortMap::registerRelevanceSorting()}).
     * Storefront templates read this setting live at render, so it takes effect for
     * the page about to render.
     */
    private static function restrictSortControl(): void
    {
        Registry::set('settings.Appearance.available_product_list_sortings', SortMap::availableSortings());
    }

    /**
     * A real 200-empty result: zero products, no native fallback.
     *
     * @param array<string, mixed> $params
     */
    private static function forceEmptyResult(array &$params, string &$condition, int &$total): void
    {
        $condition = '';
        $params['pid'] = [0]; // matches no product_id -> empty set
        $total = 0;
        unset($params['limit']);
    }

    /**
     * Page 2+ transient failure: graceful empty page + a shopper-facing notice,
     * rather than an inconsistent native swap mid-pagination.
     *
     * @param array<string, mixed> $params
     */
    private static function renderGracefulNotice(array &$params, string &$condition, int &$total): void
    {
        self::forceEmptyResult($params, $condition, $total);

        if (function_exists('fn_set_notification')) {
            fn_set_notification(
                'W',
                function_exists('__') ? __('warning') : 'Warning',
                function_exists('__') ? __('quissly_search.search_temporarily_unavailable') : 'Search is temporarily unavailable. Please try again.'
            );
        }
    }

    /**
     * 401/402/403 alert: persist a flag the admin status page reads and
     * log it. Never blocks the storefront.
     */
    private static function recordAuthAlert(AuthException $e): void
    {
        Logger::error('QSearch auth/plan failure surfaced to merchant', ['status' => $e->getHttpStatus()]);
        // Persist so the admin status page can alert the merchant — the failure
        // happened in the shopper's session, which the admin never sees.
        HealthStore::recordFailure($e->getHttpStatus(), $e->getMessage());
    }

    /**
     * The shopper id sent to Quissly (Shopper): customer:<id> when signed in, else
     * guest:<uuid> from the year-long quissly_uid cookie - minted on the first search - or
     * null for a guest who has not consented to it in the GDPR add-on's banner.
     */
    public static function resolveUserId(): ?string
    {
        $auth = Tygh::$app['session']['auth'] ?? [];
        if (is_array($auth) && !empty($auth['user_id']) && (int) $auth['user_id'] > 0) {
            return Shopper::customerId((int) $auth['user_id']);
        }
        $gdprOn = Registry::get('addons.gdpr.status') === 'A';
        if (!Shopper::guestAllowed(
            $gdprOn ? (string) Registry::get('addons.gdpr.gdpr_cookie_consent') : null,
            (string) ($_COOKIE['klaro'] ?? '')
        )) {
            return null;
        }

        $guest = Shopper::guestId((string) ($_COOKIE[Shopper::COOKIE] ?? ''));
        if ($guest !== null) {
            return $guest;
        }

        $uuid = Shopper::newUuid();
        if (!headers_sent()) {
            setcookie(Shopper::COOKIE, $uuid, [
                'expires'  => time() + 365 * 86400,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true, // nothing client-side reads it
                'samesite' => 'Lax',
            ]);
            $_COOKIE[Shopper::COOKIE] = $uuid; // the same id for the rest of this request
        }

        return Shopper::GUEST_PREFIX . $uuid;
    }

    /**
     * The QSearch page_size, in priority order:
     *   1. the items-per-page CS-Cart is using for THIS fetch (the search controller
     *      passes settings.Appearance.products_per_page),
     *   2. the store's configured products_per_page setting (covers a 0 from CS-Cart),
     *   3. a final hard default.
     *
     * This keeps the page we ask Quissly for exactly aligned with the page the theme
     * renders, so pagination math stays consistent.
     *
     * @param mixed $itemsPerPage may arrive as int or numeric string from CS-Cart
     */
    private static function resolvePageSize($itemsPerPage): int
    {
        if ((int) $itemsPerPage > 0) {
            return (int) $itemsPerPage;
        }

        $storeSetting = (int) Registry::get('settings.Appearance.products_per_page');
        if ($storeSetting > 0) {
            return $storeSetting;
        }

        return Config::DEFAULT_PAGE_SIZE;
    }

    /** Test seam: reset the per-request latch. */
    public static function resetLatch(): void
    {
        self::$handled = false;
    }

    /**
     * Test seam: inject a {@see QSearchClient} factory (called with Credentials),
     * or pass null to restore the real Client. Production code never calls this.
     */
    public static function setClientFactory(?callable $factory): void
    {
        self::$clientFactory = $factory === null ? null : \Closure::fromCallable($factory);
    }

    private function __construct()
    {
    }
}
