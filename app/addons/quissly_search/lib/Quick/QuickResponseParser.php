<?php

declare(strict_types=1);

namespace Quissly\Search\Quick;

/**
 * The ordered product ids in a /v2beta/quick answer - and nothing else (the Magento plugin's
 * QuickResponseParser). Quick answers a bare list, `[{"id": 41, "score": 30.7, "metadata": {}}, ...]`:
 * since 2026-09-01 its metadata is empty, so a row's name, link, price and image come from the
 * store (QuickEndpoint's hydrator), never from Quissly.
 *
 * The id is the top-level integer, or metadata.q_external_id where a service's quick config
 * lists it; anything that is not a positive integer (Quissly's own uuid) is dropped, never
 * guessed at. Order is the payload: ids come back as Quissly ranked them, each once.
 */
final class QuickResponseParser
{
    /** Nothing sensible renders past this; a runaway answer is a bug upstream. */
    public const MAX_IDS = 12;

    /** @return list<int> */
    public static function ids(string $body): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && !array_key_exists(0, $decoded)) {
            // The WooCommerce plugin also reads an object wrapper; a bare list is the contract.
            $decoded = $decoded['documents'] ?? ($decoded['results'] ?? ($decoded['suggestions'] ?? null));
        }
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $id = self::positiveInt($metadata['q_external_id'] ?? null) ?? self::positiveInt($row['id'] ?? null);
            if ($id !== null && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= self::MAX_IDS) {
                break;
            }
        }

        return $ids;
    }

    /** @param mixed $value */
    private static function positiveInt($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9]\d{0,18}$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    private function __construct()
    {
    }
}
