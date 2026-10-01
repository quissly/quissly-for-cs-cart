<?php

declare(strict_types=1);

namespace Quissly\Search\Media;

/**
 * Voice/image results, held server-side under a short-lived token that the results
 * page URL carries (a photo or a voice clip cannot be re-asked from a URL).
 * Readable repeatedly for TTL_SECONDS — not single-use: a re-sort, page 2, back or
 * reload must still show the same results (the WooCommerce plugin and
 * the Magento plugin made the same change).
 */
interface TokenStore
{
    public const TTL_SECONDS = 600;

    /** @param list<int> $ids ordered product ids */
    public function put(array $ids, int $now): string;

    /** @return ?list<int> null when unknown or expired */
    public function get(string $token, int $now): ?array;
}
