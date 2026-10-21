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

    /** @var array<int, array{option:int,variant:int}|array{child:int}> product id => the variant that matched (VariantHints) */
    public array $variants;

    /**
     * @param list<int> $ids             ordered, validated, positive product IDs
     * @param int       $numTotalResults total matches across all pages
     * @param array<int, array{option:int,variant:int}|array{child:int}> $variants matched variants, by product id
     */
    public function __construct(array $ids, int $numTotalResults, array $variants = [])
    {
        $this->ids = $ids;
        $this->numTotalResults = $numTotalResults;
        $this->variants = $variants;
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
