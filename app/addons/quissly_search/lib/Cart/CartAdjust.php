<?php

declare(strict_types=1);

namespace Quissly\Search\Cart;

/**
 * Lowering a product's quantity in CS-Cart's cart by product id — for the QChat
 * bridge (js/addons/quissly_search/cart.js), which only knows product ids and
 * quantity differences, while CS-Cart's own cart requests are keyed by cart line.
 *
 * Pure: finds the line and the new amount; the controller applies it the way
 * checkout.update does (fn_add_product_to_cart with update, fn_delete_cart_product
 * at zero). A product that is in the cart more than once (different options) is
 * reduced on its most recently added line first, and a line that belongs to a
 * parent product (a bundle or required product: `extra.parent`) is never touched.
 */
final class CartAdjust
{
    /**
     * @param array<string, array<string, mixed>> $cartProducts CS-Cart $cart['products'] (line key => line)
     * @return list<array{key:string, amount:int}> the lines to change, in order; amount 0 = remove the line
     */
    public static function plan(array $cartProducts, int $productId, int $reduceBy): array
    {
        if ($productId <= 0 || $reduceBy <= 0) {
            return [];
        }
        $lines = [];
        foreach ($cartProducts as $key => $line) {
            if ((int) ($line['product_id'] ?? 0) === $productId && empty($line['extra']['parent'])) {
                $lines[(string) $key] = max(0, (int) ($line['amount'] ?? 0));
            }
        }

        $changes = [];
        foreach (array_reverse($lines, true) as $key => $amount) {
            if ($reduceBy <= 0) {
                break;
            }
            $take = min($amount, $reduceBy);
            $changes[] = ['key' => (string) $key, 'amount' => $amount - $take];
            $reduceBy -= $take;
        }

        return $changes;
    }

    private function __construct()
    {
    }
}
