<?php
/**
 * Quissly for CS-Cart — storefront endpoints for voice and image search
 * (lib/Media/MediaEndpoint.php): POST quissly_search.voice (audio) and
 * POST quissly_search.image (image), each base64, answered with JSON
 * {token, transcription, query_var} for js/addons/quissly_search/media.js.
 *
 * POST quissly_search.cart_adjust (product_id, delta < 0, and options[<option id>]=<value id> per
 * chosen value for a chat item that is an option value or a colour x size combination - or the
 * single option_id + variant_id an earlier cart.js sends): lowers a product's quantity in the shopper's cart,
 * for the QChat cart bridge (js/addons/quissly_search/cart.js), the way checkout.update and
 * checkout.delete do. Increases need no endpoint: the bridge sends CS-Cart's own checkout.add.
 *
 * GET quissly_search.resolve (ids=uuid,uuid,…): the chat widget names products by
 * Quissly's own id (a UUID); this answers {quissly_id: product_id} for the ones the
 * catalog sync has recorded (lib/Sync/SyncWorker.php), so the bridge can add them - and,
 * for an id that names an option value or combination ("Color: Green, Size: Large"), `options`:
 * {quissly_id: {options: {option id: value id}, option_id, variant_id, product_options}},
 * product_options being every option as checkout.add would choose it with those values in place
 * of the defaults (option_id + variant_id: the first value, for an earlier cart.js).
 */

use Quissly\Search\Cart\CartAdjust;
use Quissly\Search\Cart\ChatIds;
use Quissly\Search\Events\EventBody;
use Quissly\Search\Panel\QChatDirectory;
use Quissly\Search\Sync\ProductSource;
use Quissly\Search\Client;
use Quissly\Search\Config;
use Quissly\Search\Credentials;
use Quissly\Search\Features;
use Quissly\Search\Media\DbRateLimiter;
use Quissly\Search\Media\DbTokenStore;
use Quissly\Search\Media\MediaEndpoint;
use Quissly\Search\Quick\QuickEndpoint;
use Quissly\Search\SearchInterceptor;
use Quissly\Search\Sync\DbSyncStore;
use Quissly\Search\Sync\SyncWorker;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

// A failure is reported to Quissly (lib/Diagnostics/), then CS-Cart shows it as before.
try {
    // POST quissly_search.client_error: an error in one of the add-on's scripts (func.php).
    if ($mode === 'client_error' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        fn_quissly_search_client_error();
    }

    if ($mode === 'voice' || $mode === 'image') {
        $credentials = Credentials::fromRegistry();
        $on = array_column(Features::all(), 'on', 'id');
        $enabled = $_SERVER['REQUEST_METHOD'] === 'POST'
            && $credentials->isConfigured()
            && !empty($on['search_interception'])
            && !empty($on[$mode === 'voice' ? 'enable_voice' : 'enable_image'])
            && SyncWorker::isGateOpen(new DbSyncStore());

        $endpoint = new MediaEndpoint(new DbTokenStore(), new DbRateLimiter(), static function (string $kind, string $base64) use ($credentials): array {
            $client = new Client($credentials);
            $answer = $kind === 'voice'
                ? $client->media(Config::QSEARCH_PATH, ['audio' => $base64], SearchInterceptor::resolveUserId(), MediaEndpoint::PAGE_SIZE)
                : $client->media(Config::QIMAGE_PATH, ['image' => $base64], SearchInterceptor::resolveUserId(), MediaEndpoint::PAGE_SIZE);

            return ['ids' => $answer['result']->ids, 'transcription' => $answer['transcription']];
        });

        // REMOTE_ADDR only: X-Forwarded-For is client-spoofable (a fresh bucket per header value).
        $answer = $endpoint->handle($mode, (string) ($_REQUEST[$mode === 'voice' ? 'audio' : 'image'] ?? ''), $enabled, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), time());

        http_response_code($answer['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($answer['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
     * POST quissly_search.quick (q): Quick Recommendations for the typed text, answered with JSON
     * {suggestions: [{id, title, url, image_url, price_label, original_price_label}]} for
     * js/addons/quissly_search/quick.js (lib/Quick/QuickEndpoint.php; rows from the store,
     * fn_quissly_search_quick_rows).
     */
    if ($mode === 'quick') {
        $credentials = Credentials::fromRegistry();
        $on = array_column(Features::all(), 'on', 'id');
        $enabled = $_SERVER['REQUEST_METHOD'] === 'POST'
            && $credentials->isConfigured()
            && !empty($on['search_interception'])
            && !empty($on['enable_quick'])
            && SyncWorker::isGateOpen(new DbSyncStore());

        $endpoint = new QuickEndpoint(
            new DbRateLimiter(),
            static fn (string $query): array => (new Client($credentials))->quick($query, SearchInterceptor::resolveUserId()),
            'fn_quissly_search_quick_rows'
        );
        // REMOTE_ADDR only, as the media endpoints.
        $answer = $endpoint->handle((string) ($_REQUEST['q'] ?? ''), $enabled, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), time());

        http_response_code($answer['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($answer['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
     * GET quissly_search.tick (token): the cron's work for a store without a server cron, asked by a
     * storefront visit in the background (lib/VisitTick.php). It does not count as the cron and does
     * nothing while the cron runs; 403 without the right token.
     */
    if ($mode === 'tick') {
        if (!\Quissly\Search\VisitTick::valid((string) ($_REQUEST['token'] ?? ''), (string) \Tygh\Registry::get('config.crypt_key'))) {
            http_response_code(403);
            exit;
        }
        ignore_user_abort(true);
        $store = new DbSyncStore();
        $skipped = (int) ($store->getState('last_cron')['at'] ?? 0) >= time() - \Quissly\Search\VisitTick::CRON_FRESH_SECONDS;
        if (!$skipped) {
            $store->setState('last_visit_run', ['at' => time()]);
            fn_quissly_search_cron_work($store);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => true, 'skipped' => $skipped ? 'cron_running' : '']);
        exit;
    }

    if ($mode === 'resolve') {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => strtolower(trim($id)), explode(',', (string) ($_REQUEST['ids'] ?? ''))),
            static fn (string $id): bool => (bool) preg_match(ChatIds::UUID, $id)
        ))), 0, 50);

        $store = new DbSyncStore();
        $namespace = QChatDirectory::cachedNamespace($store);
        if ($namespace === '' && $ids !== [] && empty($store->getState('namespace_lookup_failed')['at'] ?? null)) {
            // Stores connected before the namespace was recorded: look it up once.
            $namespace = fn_quissly_search_lookup_namespace($store);
        }
        // uuid5 over every active product, children included (the chat names either), and the
        // option values sent as variants; pairs recorded by the sync cover a store whose
        // namespace cannot be looked up.
        $source = new ProductSource();
        $variantIds = $namespace !== '' && $ids !== [] ? $source->optionVariantIds() : [];
        $found = $namespace !== '' && $ids !== [] ? ChatIds::resolve($namespace, $ids, $source->allActiveIds(), $variantIds) : [];
        $found += $store->productIdsFor(array_values(array_diff($ids, array_keys($found))));
        // The chosen values go into the add, the other options as CS-Cart would default them
        // (its checkout.add sets either all of a product's options or the defaults).
        $options = [];
        foreach (ChatIds::optionValues($namespace, $ids, $variantIds) as $uuid => $value) {
            $chosen = (array) fn_get_default_product_options($value['product_id'], false, [], true);
            foreach ($value['options'] as $optionId => $variantId) {
                $chosen[$optionId] = $variantId;
            }
            $options[$uuid] = [
                'options'         => (object) $value['options'],
                'option_id'       => (int) array_key_first($value['options']),
                'variant_id'      => (int) reset($value['options']),
                'product_options' => (object) $chosen,
            ];
        }
        if ($options !== []) {
            // A key no UUID can be: a bridge that reads only {quissly_id: product_id} skips it.
            $found['options'] = $options;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, max-age=300');
        echo json_encode((object) $found);
        exit;
    }

    if ($mode === 'cart_adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $productId = (int) ($_REQUEST['product_id'] ?? 0);
        // The chat item's values: options[<option id>]=<value id> each, or the one option_id +
        // variant_id an earlier cart.js sends.
        $raw = $_REQUEST['options'] ?? [];
        $valid = is_array($raw) && count($raw) <= 10;
        $chosen = [];
        foreach ($valid ? $raw : [] as $optionId => $variantId) {
            $chosen[(int) $optionId] = is_scalar($variantId) && preg_match('/^[1-9]\d{0,9}$/', (string) $variantId) ? (int) $variantId : 0;
        }
        if ((int) ($_REQUEST['option_id'] ?? 0) > 0 || (int) ($_REQUEST['variant_id'] ?? 0) > 0) {
            $chosen[(int) ($_REQUEST['option_id'] ?? 0)] = (int) ($_REQUEST['variant_id'] ?? 0);
        }
        // Only values of this product's own options (or its linked global ones), every one of
        // them: never the browser's word.
        $source = new ProductSource();
        foreach ($chosen as $optionId => $variantId) {
            $valid = $valid && $optionId > 0 && $variantId > 0 && $source->hasOptionValue($productId, $optionId, $variantId);
        }
        if (!$valid) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['changed' => 0]);
            exit;
        }
        $cart = &Tygh::$app['session']['cart'];
        $auth = &Tygh::$app['session']['auth'];
        $changes = CartAdjust::plan((array) ($cart['products'] ?? []), $productId, -(int) ($_REQUEST['delta'] ?? 0), $chosen);
        foreach ($changes as $change) {
            if ($change['amount'] === 0) {
                fn_delete_cart_product($cart, $change['key']);
            } else {
                $line = $cart['products'][$change['key']];
                $data = ['product_id' => (int) $line['product_id'], 'amount' => $change['amount']];
                if (isset($line['product_options'])) {
                    // The line's own options, as checkout.update sends them: without them CS-Cart
                    // takes the defaults and moves the amount to the default colour's line.
                    $data['product_options'] = (array) $line['product_options'];
                }
                fn_add_product_to_cart([$change['key'] => $data], $cart, $auth, true);
            }
        }
        if ($changes !== []) {
            if (fn_cart_is_empty($cart)) {
                fn_clear_cart($cart);
            }
            $cart['recalculate'] = true;
            fn_calculate_cart_content($cart, $auth, 'A', true, 'F', true);
            fn_save_cart_content($cart, $auth['user_id']);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['changed' => count($changes)]);
        exit;
    }

    /*
     * POST quissly_search.event (a JSON body): a product page view or a search, from
     * js/addons/quissly_search/events.js. Anonymous like the media endpoints, with their
     * protections: 404 while "Shopping activity" is off or the store is not connected, a body cap,
     * a per-visitor rate limit (higher - an event costs the merchant nothing). The browser says only
     * what was viewed or searched; a product that is not active is refused. Writes a queue row.
     */
    if ($mode === 'event' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $status = 204;
        $raw = (string) file_get_contents('php://input');
        $event = json_decode($raw, true);
        $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
        if (!fn_quissly_search_events_enabled()) {
            $status = 404;
        } elseif (!(new DbRateLimiter())->allow('events|' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 60, time())) {
            $status = 429;
        } elseif (strlen($raw) > 1024) {
            $status = 413;
        } elseif ($type === EventBody::VIEW) {
            $id = is_scalar($event['product_id'] ?? null) && preg_match('/^[1-9]\d{0,9}$/', (string) $event['product_id']) ? (int) $event['product_id'] : 0;
            if ($id > 0 && db_get_field('SELECT status FROM ?:products WHERE product_id = ?i', $id) === 'A') {
                fn_quissly_search_event_shopper(EventBody::VIEW, EventBody::view(fn_quissly_search_event_ids($id)[0]));
            } else {
                $status = 400;
            }
        } elseif ($type === EventBody::SEARCH) {
            $metadata = EventBody::search(
                is_string($event['query'] ?? null) ? $event['query'] : '',
                is_string($event['modality'] ?? null) ? $event['modality'] : ''
            );
            if ($metadata !== null) {
                fn_quissly_search_event_shopper(EventBody::SEARCH, $metadata);
            } else {
                $status = 400;
            }
        } else {
            $status = 400;
        }

        http_response_code($status);
        header('Cache-Control: no-store');
        exit;
    }

    return [CONTROLLER_STATUS_NO_PAGE];
} catch (\Throwable $e) {
    \Quissly\Search\Diagnostics\ErrorCapture::exception($e, 'storefront ' . $mode);
    throw $e;
}
