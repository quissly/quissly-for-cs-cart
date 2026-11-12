<?php

declare(strict_types=1);

namespace Quissly\Search\Orders;

use Quissly\Search\Update\Signature;

/**
 * The rules of QChat's order lookup, with no CS-Cart in them (OrderLookup applies them):
 *
 *  - verify(): only Quissly's signed request is read - the raw body signed with Quissly's order lookup
 *    key (Ed25519; the public halves are KEYS, by key id; checked by Signature, so a host without the
 *    sodium extension checks it too), naming this store's project, at most CLOCK_SKEW old, with a nonce
 *    (used once - the controller keeps it);
 *  - sameNumber() / contactMatches(): the shopper's order number and email or phone - the middleware's
 *    rules for Shopify orders, so every platform answers alike;
 *  - statuses() / combined(): a CS-Cart order status in the engine's (Shopify's) words.
 */
final class LookupRules
{
    /** Quissly's order lookup public keys (Ed25519, base64), by key id. */
    public const KEYS = [
        'k1' => '4oqUKgUeo6tWq0wECjgNycyb8E9LRWPEijAkJ/xyYfU=',
    ];

    public const MAX_BODY = 2048;
    public const CLOCK_SKEW = 300;
    public const MIN_PHONE = 7;

    /** CS-Cart's own status for an order that was never placed (a checkout left half-way). */
    public const NOT_PLACED = ['N'];

    /** CS-Cart's default order statuses. A status a merchant added is "being handled". */
    private const FULFILLMENT = [
        'O' => 'UNFULFILLED', // Open
        'Y' => 'UNFULFILLED', // Awaiting call
        'P' => 'IN_PROGRESS', // Processed
        'B' => 'ON_HOLD',     // Backordered
        'C' => 'FULFILLED',   // Complete
    ];
    private const PAID = ['P', 'C'];
    private const CANCELLED = ['I', 'D', 'F']; // Canceled, Declined, Failed

    /**
     * The signed request, or null when it is not Quissly's for this store, or too old.
     *
     * @param array<string, string> $keys public keys by id
     * @return ?array{order_number:string, contact:string, nonce:string}
     */
    public static function verify(string $body, string $signature, string $keyId, string $projectId, int $now, array $keys = self::KEYS): ?array
    {
        if ($body === '' || strlen($body) > self::MAX_BODY || $projectId === '' || !isset($keys[$keyId]) || !Signature::available()) {
            return null;
        }
        $sig = base64_decode($signature, true);
        $key = base64_decode($keys[$keyId], true);
        if ($sig === false || $key === false || strlen($sig) !== 64 || strlen($key) !== 32 || !Signature::verify($sig, $body, $key)) {
            return null;
        }
        $ask = json_decode($body, true);
        if (!is_array($ask)) {
            return null;
        }
        $project = is_string($ask['project_id'] ?? null) ? $ask['project_id'] : '';
        $number = is_string($ask['order_number'] ?? null) ? trim($ask['order_number']) : '';
        $contact = is_string($ask['contact'] ?? null) ? trim($ask['contact']) : '';
        $nonce = is_string($ask['nonce'] ?? null) ? $ask['nonce'] : '';
        $at = is_int($ask['timestamp'] ?? null) ? $ask['timestamp'] : 0;
        if (!hash_equals(strtolower($projectId), strtolower($project)) || abs($now - $at) > self::CLOCK_SKEW
            || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $nonce)
            || $number === '' || strlen($number) > 64 || $contact === '' || strlen($contact) > 254) {
            return null;
        }

        return ['order_number' => $number, 'contact' => $contact, 'nonce' => $nonce];
    }

    /** The order id a shopper's number names ("#1024", "1024"), or 0. */
    public static function orderId(string $given): int
    {
        $digits = ltrim((string) preg_replace('/[^0-9A-Za-z]/', '', $given), '0');

        return $digits !== '' && ctype_digit($digits) && strlen($digits) <= 10 && (int) $digits <= 2147483647 ? (int) $digits : 0;
    }

    /**
     * Whether the shopper's email or phone is the order's own: an email equal in any case; a phone whose
     * digits (at least MIN_PHONE) are the end of the other's ("+995 555 12 34 56" = "555123456").
     *
     * @param string[] $emails
     * @param string[] $phones
     */
    public static function contactMatches(string $contact, array $emails, array $phones): bool
    {
        $contact = strtolower(trim($contact));
        if (strpos($contact, '@') !== false) {
            foreach ($emails as $email) {
                $email = strtolower(trim((string) $email));
                if ($email !== '' && $email === $contact) {
                    return true;
                }
            }

            return false;
        }
        $given = (string) preg_replace('/\D/', '', $contact);
        if (strlen($given) < self::MIN_PHONE) {
            return false;
        }
        foreach ($phones as $phone) {
            $known = (string) preg_replace('/\D/', '', (string) $phone);
            if (strlen($known) >= self::MIN_PHONE && (self::endsWith($known, $given) || self::endsWith($given, $known))) {
                return true;
            }
        }

        return false;
    }

    /**
     * An order's status in the engine's words. `$paymentReceived` is the status's own "payment received"
     * flag (CS-Cart 4.12+; null on 4.11, which has none: Processed and Complete count as paid). A shipment
     * with a tracking number makes an order still being handled fulfilled.
     *
     * @return array{fulfillment_status:string, financial_status:string, cancelled:bool}
     */
    public static function statuses(string $status, ?bool $paymentReceived, bool $shipped): array
    {
        $fulfillment = self::FULFILLMENT[$status] ?? 'IN_PROGRESS';
        if ($shipped && $fulfillment !== 'FULFILLED') {
            $fulfillment = 'FULFILLED';
        }
        $paid = $paymentReceived ?? in_array($status, self::PAID, true);

        return [
            'fulfillment_status' => $fulfillment,
            'financial_status'   => $paid ? 'PAID' : 'PENDING',
            'cancelled'          => in_array($status, self::CANCELLED, true),
        ];
    }

    /**
     * A marketplace order split by vendor, as one: cancelled when every part is, fulfilled or paid when
     * every part still on is, unfulfilled when none of them moved, else in progress.
     *
     * @param list<array{fulfillment_status:string, financial_status:string, cancelled:bool}> $parts
     * @return array{fulfillment_status:string, financial_status:string, cancelled:bool}
     */
    public static function combined(array $parts): array
    {
        $on = array_values(array_filter($parts, static fn (array $part): bool => !$part['cancelled']));
        if ($on === []) {
            return $parts === []
                ? ['fulfillment_status' => 'UNFULFILLED', 'financial_status' => 'PENDING', 'cancelled' => false]
                : ['fulfillment_status' => $parts[0]['fulfillment_status'], 'financial_status' => $parts[0]['financial_status'], 'cancelled' => true];
        }
        $fulfillment = array_values(array_unique(array_column($on, 'fulfillment_status')));
        $financial = array_unique(array_column($on, 'financial_status'));

        return [
            'fulfillment_status' => count($fulfillment) === 1 ? $fulfillment[0] : 'IN_PROGRESS',
            'financial_status'   => $financial === ['PAID'] ? 'PAID' : 'PENDING',
            'cancelled'          => false,
        ];
    }

    /** A carrier's tracking address, http(s) only. */
    public static function trackingUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    private static function endsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }

    private function __construct()
    {
    }
}
