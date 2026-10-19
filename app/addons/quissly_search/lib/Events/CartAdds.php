<?php

declare(strict_types=1);

namespace Quissly\Search\Events;

/**
 * What an add to cart really added, for its add_to_cart event: CS-Cart lowers an amount beyond the
 * stock to what is left (fn_check_amount_in_stock, "only N available") and adds to a line the cart
 * already has, so the amount asked for is not the amount added. The cart's amounts are noted before
 * (hook pre_add_to_cart) and compared after (post_add_to_cart); a line that did not grow added nothing.
 *
 * Pure (unit-tested without CS-Cart).
 */
final class CartAdds
{
    /**
     * The cart's amounts by cart id.
     *
     * @param array<string|int, mixed> $cartProducts $cart['products']
     * @return array<string, float>
     */
    public static function amounts(array $cartProducts): array
    {
        $amounts = [];
        foreach ($cartProducts as $cartId => $line) {
            $amounts[(string) $cartId] = is_array($line) ? (float) ($line['amount'] ?? 0) : 0.0;
        }

        return $amounts;
    }

    /**
     * Each product line that grew, and by how much.
     *
     * @param array<string, float>     $before       amounts() before the add
     * @param array<string|int, mixed> $cartProducts $cart['products'] after it
     * @param array<string|int, mixed> $ids          post_add_to_cart's [cart id => product id]
     * @return list<array{product_id: int, amount: float}>
     */
    public static function added(array $before, array $cartProducts, array $ids): array
    {
        $after = self::amounts($cartProducts);
        $added = [];
        foreach ($ids as $cartId => $productId) {
            $grew = ($after[(string) $cartId] ?? 0.0) - ($before[(string) $cartId] ?? 0.0);
            if ((int) $productId > 0 && $grew > 0) {
                $added[] = ['product_id' => (int) $productId, 'amount' => $grew];
            }
        }

        return $added;
    }

    private function __construct()
    {
    }
}
