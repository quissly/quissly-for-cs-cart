<?php

declare(strict_types=1);

namespace Quissly\Search\Quick;

use Quissly\Search\Config;
use Quissly\Search\Exception\QuisslyException;
use Quissly\Search\Media\RateLimiter;

/**
 * The storefront's Quick Recommendations endpoint (the WooCommerce plugin's /quick proxy route):
 * the shopper's typed text is signed and sent to Quissly's /v2beta/quick server-side, and the
 * product ids it ranks come back as rows the dropdown can draw - each filled from the store's
 * own product (name, link, price in the shopper's currency, image), a product that is gone or
 * not visible left out, Quissly's order kept.
 *
 * Public by design (search is public): off unless its switch is on, throttled per visitor.
 * Quissly not answering, or Quick not enabled for the account (a 404), is no suggestions -
 * the shopper still searches as usual.
 */
final class QuickEndpoint
{
    /** As the WooCommerce plugin: a shopper types fast, each pause is one request. */
    public const RATE_MAX = 60;
    public const RATE_WINDOW_SECONDS = 60;
    /** Rows in the dropdown. */
    public const MAX_ROWS = 10;

    private RateLimiter $limiter;

    /** @var callable(string): list<int> query -> Quissly's ids, in order - may throw QuisslyException */
    private $suggest;

    /** @var callable(list<int>): list<array<string, mixed>> ids -> the store's rows, in that order */
    private $hydrate;

    public function __construct(RateLimiter $limiter, callable $suggest, callable $hydrate)
    {
        $this->limiter = $limiter;
        $this->suggest = $suggest;
        $this->hydrate = $hydrate;
    }

    /** @return array{status:int, body:array<string, mixed>} */
    public function handle(string $query, bool $enabled, string $visitor, int $now): array
    {
        if (!$enabled) {
            return ['status' => 404, 'body' => ['error' => 'not_found']];
        }
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (mb_strlen($query) < Config::MIN_QUERY_LENGTH) {
            return ['status' => 200, 'body' => ['suggestions' => []]];
        }
        if (!$this->limiter->allow('quick|' . $visitor, self::RATE_MAX, self::RATE_WINDOW_SECONDS, $now)) {
            return ['status' => 429, 'body' => ['error' => 'rate_limited']];
        }

        try {
            $ids = ($this->suggest)(mb_substr($query, 0, Config::MAX_QUERY_LENGTH));
        } catch (QuisslyException $e) {
            $ids = [];
        }
        $rows = $ids === [] ? [] : array_slice(array_values(($this->hydrate)($ids)), 0, self::MAX_ROWS);

        return ['status' => 200, 'body' => ['suggestions' => $rows]];
    }
}
