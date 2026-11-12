<?php

declare(strict_types=1);

namespace Quissly\Search\Orders;

/**
 * QChat's order lookup for one verified request (controllers/frontend/quissly_search.php, mode
 * order_lookup, checked the signature): the order with that number, and - only when the email or phone
 * is the order's own - its status, items and tracking in the shape the engine reads for Shopify orders.
 * Anything else is `not_found`, never saying which part did not match.
 *
 * A marketplace order split by vendor (Multi-Vendor's parent order) is answered as one: its parts'
 * statuses combined (LookupRules::combined), their items and tracking together.
 */
final class OrderLookup
{
    public const MAX_ITEMS = 50;

    /** @return array<string, mixed> */
    public static function answer(string $number, string $contact): array
    {
        $id = LookupRules::orderId($number);
        $order = $id > 0
            ? db_get_row('SELECT order_id, is_parent_order, user_id, email, phone, b_phone, s_phone, status, timestamp FROM ?:orders WHERE order_id = ?i', $id)
            : [];
        if (empty($order) || in_array((string) $order['status'], LookupRules::NOT_PLACED, true)) {
            return ['status' => 'not_found'];
        }
        $emails = [(string) $order['email']];
        if ((int) $order['user_id'] > 0) {
            $emails[] = (string) db_get_field('SELECT email FROM ?:users WHERE user_id = ?i', (int) $order['user_id']);
        }
        if (!LookupRules::contactMatches($contact, $emails, [(string) $order['phone'], (string) $order['b_phone'], (string) $order['s_phone']])) {
            return ['status' => 'not_found'];
        }

        $parts = ($order['is_parent_order'] ?? 'N') === 'Y'
            ? array_map('intval', (array) db_get_fields('SELECT order_id FROM ?:orders WHERE parent_order_id = ?i', $id))
            : [$id];
        $statuses = [];
        $items = [];
        $tracking = [];
        foreach ($parts as $partId) {
            $info = fn_get_order_info($partId, false, true, false, true);
            if (empty($info) || in_array((string) $info['status'], LookupRules::NOT_PLACED, true)) {
                continue;
            }
            foreach ((array) ($info['products'] ?? []) as $line) {
                if (count($items) < self::MAX_ITEMS) {
                    $items[] = ['title' => strip_tags((string) ($line['product'] ?? '')), 'quantity' => (int) ($line['amount'] ?? 0)];
                }
            }
            $partTracking = self::tracking($partId);
            $tracking = array_merge($tracking, $partTracking);
            $params = fn_get_status_params((string) $info['status'], STATUSES_ORDER);
            $statuses[] = LookupRules::statuses(
                (string) $info['status'],
                isset($params['payment_received']) ? $params['payment_received'] === 'Y' : null,
                $partTracking !== []
            );
        }
        $status = count($statuses) === 1 ? $statuses[0] : LookupRules::combined($statuses);

        return [
            'status' => 'found',
            'order'  => [
                'order_number'       => (string) $id,
                'created_at'         => gmdate('Y-m-d\TH:i:s\Z', (int) $order['timestamp']),
                'cancelled'          => $status['cancelled'],
                'fulfillment_status' => $status['fulfillment_status'],
                'financial_status'   => $status['financial_status'],
                'tracking'           => $tracking,
                'items'              => $items,
                // The customer's own order page (they sign in); a guest's order has none to link.
                'status_page_url'    => (int) $order['user_id'] > 0 ? (string) fn_url('orders.details?order_id=' . $id, 'C') : '',
            ],
        ];
    }

    /** @return list<array{company:string, number:string, url:string}> the order's shipments with a tracking number */
    private static function tracking(int $orderId): array
    {
        $out = [];
        [$shipments] = fn_get_shipments_info(['order_id' => $orderId, 'advanced_info' => true]);
        foreach ((array) $shipments as $shipment) {
            $number = trim((string) ($shipment['tracking_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $carrier = (array) ($shipment['carrier_info'] ?? []);
            $out[] = [
                'company' => (string) ($carrier['name'] ?? ($shipment['carrier'] ?? '')),
                'number'  => $number,
                'url'     => LookupRules::trackingUrl((string) ($carrier['tracking_url'] ?? '')),
            ];
        }

        return $out;
    }

    private function __construct()
    {
    }
}
