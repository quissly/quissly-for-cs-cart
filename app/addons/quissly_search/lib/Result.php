<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * A parsed QSearch result: the ordered list of CS-Cart product IDs and the
 * grand-total match count that drives pagination.
 *
 * IDs are integers here — the string/int boundary is crossed in {@see ResponseParser}.
 */
final class Result
{
    /** @var list<int> ordered, validated, positive product IDs */
    public array $ids;

    /** @var int total matches across all pages */
    public int $numTotalResults;

    /**
     * @param list<int> $ids             ordered, validated, positive product IDs
     * @param int       $numTotalResults total matches across all pages
     */
    public function __construct(array $ids, int $numTotalResults)
    {
        $this->ids = $ids;
        $this->numTotalResults = $numTotalResults;
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    public function count(): int
    {
        return count($this->ids);
    }
}
