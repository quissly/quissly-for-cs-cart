<?php

declare(strict_types=1);

namespace Quissly\Search;

use Quissly\Search\Exception\QuisslyException;

/**
 * Contract for a QSearch caller. {@see Client} is the real implementation; tests
 * inject a fake via {@see SearchInterceptor::setClientFactory()} to return
 * controlled local IDs without touching the live API.
 */
interface QSearchClient
{
    /**
     * @param int $sortBy   Quissly sort_by code (see {@see SortMap})
     * @param int $sortType Quissly sort_type code (1 asc, 2 desc)
     * @param array{device?: string, os?: string} $shopper the shopper's device / OS (Shopper::deviceAndOs())
     *
     * @throws QuisslyException on any non-200 outcome or transport failure
     */
    public function qsearch(string $query, ?string $userId, int $pageNumber, int $pageSize, int $sortBy, int $sortType, array $shopper = []): Result;
}
