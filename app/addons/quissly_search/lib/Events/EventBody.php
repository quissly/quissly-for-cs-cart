<?php

declare(strict_types=1);

namespace Quissly\Search\Events;

/**
 * The shopping activity sent to Quissly's analytics, `POST /v2beta/qsearch/events`.
 *
 * Five events, the ones the Quissly Shopify app sends, in its shape (the Magento and
 * WooCommerce plugins send the same): a product page view, a search, an add to cart, an add
 * to the wishlist and a paid order. Quissly stores each one, and the Quissly Admin Panel's
 * e-commerce and conversion tabs count them by type and day. Everything that is not a body field goes in
 * `metadata`, which the engine keeps as it is.
 *
 * Ids are strings (the catalog's own: a variation's parent is the product, the variation the
 * variant); prices are strings with two decimals, as Shopify's order webhook sends them. Nothing
 * about a shopper but the user_id (customer:/guest:, as a search sends it) - no name, email,
 * address or IP.
 *
 * Pure: builds bodies, nothing else.
 */
final class EventBody
{
    public const VIEW = 'view_PDP';
    public const SEARCH = 'search';
    public const ADD_TO_CART = 'add_to_cart';
    public const WISHLIST = 'add_to_wishlist';
    public const PURCHASE = 'purchase_successful';

    /** A shopper's own action on the storefront. */
    public const SOURCE_STOREFRONT = 'storefront';

    /** A paid order - recorded by the store, not the shopper's browser (Shopify: "webhook"). */
    public const SOURCE_ORDER = 'order';

    public const MODALITIES = ['text', 'voice', 'image'];

    /** The storefront search's own limit (Config::MAX_QUERY_LENGTH). */
    public const MAX_QUERY_LENGTH = 150;

    /** Order lines sent at most; a larger order still counts, with its first lines. */
    public const MAX_ORDER_LINES = 100;

    private const NAME_LENGTH = 255;

    /** @return array<string, string> metadata */
    public static function view(int $productId): array
    {
        return ['product_id' => (string) $productId];
    }

    /**
     * A search (Shopify: {query, modality, source}). A voice or image search carries no words
     * of its own; a text search needs 1-150 characters. Null = nothing to record.
     *
     * @return array<string, string>|null metadata
     */
    public static function search(string $query, string $modality): ?array
    {
        if (!in_array($modality, self::MODALITIES, true)) {
            return null;
        }
        $query = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query));
        if ($modality === 'text' && ($query === '' || mb_strlen($query) > self::MAX_QUERY_LENGTH)) {
            return null;
        }
        $metadata = ['modality' => $modality];
        if ($modality === 'text') {
            $metadata['query'] = $query;
        }

        return $metadata;
    }

    /**
     * @param int      $productId the catalog product (a variation's parent)
     * @param int|null $variantId the variation, when one was chosen
     * @return array<string, int|string> metadata
     */
    public static function addToCart(int $productId, ?int $variantId, float $quantity): array
    {
        $metadata = ['product_id' => (string) $productId, 'quantity' => max(1, (int) round($quantity))];
        if ($variantId !== null && $variantId > 0 && $variantId !== $productId) {
            $metadata['variant_id'] = (string) $variantId;
        }

        return $metadata;
    }

    /** @return array<string, string> metadata */
    public static function wishlist(int $productId): array
    {
        return ['product_id' => (string) $productId];
    }

    /**
     * A paid order (Shopify's {order_id, total_price, currency, products[]}).
     *
     * @param list<array{product_id?: int, variant_id?: int|null, name?: string, quantity?: float, price?: float}> $lines
     * @return array<string, mixed> metadata
     */
    public static function purchase(string $orderId, float $total, string $currency, array $lines): array
    {
        $products = [];
        foreach (array_slice($lines, 0, self::MAX_ORDER_LINES) as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $variantId = (int) ($line['variant_id'] ?? 0);
            $products[] = [
                'product_id' => (string) $productId,
                'variant_id' => $variantId > 0 && $variantId !== $productId ? (string) $variantId : null,
                'name'       => mb_substr(trim((string) ($line['name'] ?? '')), 0, self::NAME_LENGTH),
                'quantity'   => max(1, (int) round((float) ($line['quantity'] ?? 1))),
                'price'      => self::money((float) ($line['price'] ?? 0)),
            ];
        }

        return [
            'order_id'    => $orderId,
            'total_price' => self::money($total),
            'currency'    => strtoupper(trim($currency)),
            'products'    => $products,
        ];
    }

    /**
     * The request body. `event_id` is chosen here, once: a send that times out after Quissly
     * stored the row is sent again with the same id, which the engine's insert de-duplicates on.
     *
     * @param array<string, mixed>  $metadata from one of the methods above
     * @param array<string, string> $shopper  {device?, os?} - '' or missing = not sent
     * @return array<string, mixed>
     */
    public static function envelope(string $type, array $metadata, ?string $userId, array $shopper, string $shop, string $source, string $eventId, int $time): array
    {
        $body = ['event_type' => $type, 'event_id' => $eventId, 'channel' => 'web'];
        if ($userId !== null && $userId !== '') {
            $body['user_id'] = $userId;
        }
        if (in_array($shopper['device'] ?? '', ['mobile', 'tablet', 'desktop'], true)) {
            $body['device'] = $shopper['device'];
        }
        if (($shopper['os'] ?? '') !== '') {
            $body['os'] = (string) $shopper['os'];
        }
        // Set last, as the Shopify app does, so an event's own keys can never replace them.
        $body['metadata'] = array_merge($metadata, [
            'shop'       => $shop,
            'source'     => $source,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z', $time),
        ]);

        return $body;
    }

    /** A price as Shopify's webhook writes one: "19.90". */
    private static function money(float $amount): string
    {
        return number_format(max(0.0, $amount), 2, '.', '');
    }

    private function __construct()
    {
    }
}
