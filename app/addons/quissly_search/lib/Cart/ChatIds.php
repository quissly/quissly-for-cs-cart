<?php

declare(strict_types=1);

namespace Quissly\Search\Cart;

/**
 * Quissly's own product ids, as the chat widget uses them: uuid5 of the qsearch
 * service's quissly_service_link and our product id (uuid5(link, "247") is the chat's id
 * for product 247). Pure; the same
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
     * The given Quissly ids that belong to one of $productIds - or to one of the option values
     * a product is sent with as variants (the chat names the variant: uuid5(link,
     * "11-o1-11")), which translates to its product - translated.
     *
     * @param list<string>       $quisslyIds lower-case UUIDs
     * @param list<int>          $productIds
     * @param array<string, int> $variantIds variant id (ProductMapper::optionVariantId) => product id
     * @return array<string, int>
     */
    public static function resolve(string $namespace, array $quisslyIds, array $productIds, array $variantIds = []): array
    {
        $wanted = array_flip($quisslyIds);
        $out = [];
        foreach ($productIds as $id) {
            $uuid = self::uuid5($namespace, (string) (int) $id);
            if (isset($wanted[$uuid])) {
                $out[$uuid] = (int) $id;
            }
        }
        foreach ($variantIds as $variantId => $productId) {
            $uuid = self::uuid5($namespace, (string) $variantId);
            if (isset($wanted[$uuid]) && !isset($out[$uuid])) {
                $out[$uuid] = (int) $productId;
            }
        }

        return $out;
    }

    /**
     * The option value each given Quissly id names, for the ids that name one of the option values
     * a product is sent with as variants: the chat's "Collegiate 72 Tee - Color: Red" is product 11
     * with option 1 set to Red - resolve() alone gives product 11, which CS-Cart adds in its default
     * colour.
     *
     * @param list<string>       $quisslyIds lower-case UUIDs
     * @param array<string, int> $variantIds as resolve()
     * @return array<string, array{product_id:int, option_id:int, variant_id:int}>
     */
    public static function optionValues(string $namespace, array $quisslyIds, array $variantIds): array
    {
        $wanted = array_flip($quisslyIds);
        $out = [];
        foreach ($variantIds as $variantId => $productId) {
            // ProductMapper::optionVariantId: "<product>-o<option>-<value>"
            if (!preg_match('/^(\d+)-o(\d+)-(\d+)$/', (string) $variantId, $m) || (int) $m[1] !== (int) $productId) {
                continue;
            }
            $uuid = self::uuid5($namespace, (string) $variantId);
            if (isset($wanted[$uuid])) {
                $out[$uuid] = ['product_id' => (int) $productId, 'option_id' => (int) $m[2], 'variant_id' => (int) $m[3]];
            }
        }

        return $out;
    }

    private function __construct()
    {
    }
}
