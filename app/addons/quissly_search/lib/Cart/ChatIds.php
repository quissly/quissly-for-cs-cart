<?php

declare(strict_types=1);

namespace Quissly\Search\Cart;

/**
 * Quissly's own product ids, as the chat widget uses them: uuid5 of the qsearch
 * service's quissly_service_link and our product id (live-confirmed 2026-09-24 on
 * this store: uuid5(link, "247") was the chat's id for product 247). Pure; the same
 * derivation the WooCommerce plugin and the Magento plugin use.
 */
final class ChatIds
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function uuid5(string $namespace, string $name): string
    {
        $hash = sha1((string) hex2bin(str_replace('-', '', strtolower($namespace))) . $name);

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }

    /**
     * The given Quissly ids that belong to one of $productIds, translated.
     *
     * @param list<string> $quisslyIds lower-case UUIDs
     * @param list<int>    $productIds
     * @return array<string, int>
     */
    public static function resolve(string $namespace, array $quisslyIds, array $productIds): array
    {
        $wanted = array_flip($quisslyIds);
        $out = [];
        foreach ($productIds as $id) {
            $uuid = self::uuid5($namespace, (string) (int) $id);
            if (isset($wanted[$uuid])) {
                $out[$uuid] = (int) $id;
            }
        }

        return $out;
    }

    private function __construct()
    {
    }
}
