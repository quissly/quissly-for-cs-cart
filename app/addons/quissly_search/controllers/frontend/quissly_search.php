<?php
/**
 * Quissly for CS-Cart — storefront endpoints for voice and image search
 * (lib/Media/MediaEndpoint.php): POST quissly_search.voice (audio) and
 * POST quissly_search.image (image), each base64, answered with JSON
 * {token, transcription, query_var} for js/addons/quissly_search/media.js.
 *
 * POST quissly_search.cart_adjust (product_id, delta < 0): lowers a product's quantity
 * in the shopper's cart, for the QChat cart bridge (js/addons/quissly_search/cart.js),
 * the way checkout.update and checkout.delete do. Increases need no endpoint: the
 * bridge sends CS-Cart's own checkout.add.
 *
 * GET quissly_search.resolve (ids=uuid,uuid,…): the chat widget names products by
 * Quissly's own id (a UUID); this answers {quissly_id: product_id} for the ones the
 * catalog sync has recorded (lib/Sync/SyncWorker.php), so the bridge can add them.
 */

use Quissly\Search\Cart\CartAdjust;
use Quissly\Search\Cart\ChatIds;
use Quissly\Search\Panel\QChatDirectory;
use Quissly\Search\Sync\ProductSource;
use Quissly\Search\Client;
use Quissly\Search\Config;
use Quissly\Search\Credentials;
use Quissly\Search\Features;
use Quissly\Search\Media\DbRateLimiter;
use Quissly\Search\Media\DbTokenStore;
use Quissly\Search\Media\MediaEndpoint;
use Quissly\Search\SearchInterceptor;
use Quissly\Search\Sync\DbSyncStore;
use Quissly\Search\Sync\SyncWorker;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

/** @var string $mode */

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
    // uuid5 over every active product, children included (the chat names either);
    // pairs recorded by the sync cover a store whose namespace cannot be looked up.
    $found = $namespace !== '' ? ChatIds::resolve($namespace, $ids, (new ProductSource())->allActiveIds()) : [];
    $found += $store->productIdsFor(array_values(array_diff($ids, array_keys($found))));

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    echo json_encode((object) $found);
    exit;
}

if ($mode === 'cart_adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cart = &Tygh::$app['session']['cart'];
    $auth = &Tygh::$app['session']['auth'];
    $changes = CartAdjust::plan((array) ($cart['products'] ?? []), (int) ($_REQUEST['product_id'] ?? 0), -(int) ($_REQUEST['delta'] ?? 0));
    foreach ($changes as $change) {
        if ($change['amount'] === 0) {
            fn_delete_cart_product($cart, $change['key']);
        } else {
            fn_add_product_to_cart([$change['key'] => [
                'product_id' => (int) $cart['products'][$change['key']]['product_id'],
                'amount'     => $change['amount'],
            ]], $cart, $auth, true);
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

return [CONTROLLER_STATUS_NO_PAGE];
