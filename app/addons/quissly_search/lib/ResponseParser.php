<?php

declare(strict_types=1);

namespace Quissly\Search;

use Quissly\Search\Exception\ClientErrorException;

/**
 * Turns a raw QSearch 200 body into a {@see Result}.
 *
 * The ID boundary: each documents[].id is
 * validated as a well-formed POSITIVE integer and cast to int. Malformed,
 * missing, non-positive, or non-integer ids are DROPPED (reported via $onDrop),
 * never silently coerced to 0.
 *
 * Pure logic, no CS-Cart dependency — locked by a known-input/known-output test.
 */
final class ResponseParser
{
    /**
     * @param callable(mixed):void|null $onDrop called once per dropped raw id value
     *
     * @throws ClientErrorException if the body is not the expected JSON shape
     */
    public static function parse(string $json, ?callable $onDrop = null): Result
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new ClientErrorException('QSearch response was not valid JSON.');
        }

        if (!array_key_exists('documents', $decoded) || !is_array($decoded['documents'])) {
            throw new ClientErrorException('QSearch response is missing the "documents" array.');
        }

        $ids = [];
        foreach ($decoded['documents'] as $document) {
            $rawId = is_array($document) ? ($document['id'] ?? null) : null;
            $id = self::validateId($rawId);

            if ($id === null) {
                if ($onDrop !== null) {
                    $onDrop($rawId);
                }
                continue;
            }

            $ids[] = $id;
        }

        $numTotal = isset($decoded['num_total_results']) && is_numeric($decoded['num_total_results'])
            ? (int) $decoded['num_total_results']
            : count($ids);

        return new Result($ids, $numTotal);
    }

    /**
     * A well-formed positive integer id, or null if it must be dropped. Accepts
     * ints and integer-valued numeric strings ("1452"); rejects floats with a
     * fractional part, non-numeric strings, zero, and negatives.
     */
    /**
     * @param mixed $rawId
     */
    private static function validateId($rawId): ?int
    {
        if (is_int($rawId)) {
            return $rawId > 0 ? $rawId : null;
        }

        if (is_string($rawId) && preg_match('/^\d+$/', $rawId) === 1) {
            $id = (int) $rawId;

            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function __construct()
    {
    }
}
