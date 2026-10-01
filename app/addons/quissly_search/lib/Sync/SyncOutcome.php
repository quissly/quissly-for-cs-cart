<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

/**
 * What one catalog batch came to. Ids are the string ids that were sent.
 *
 * state:
 *  - done:         Quissly finished; `ok` / `failed` / `alreadyExists` say per item
 *                  (an id the status did not mention is in none of them);
 *  - unconfirmed:  accepted, but the operation did not finish within the poll bound;
 *  - refused:      the account is refused (401/402/403+JSON) — stop, alert, keep queued;
 *  - rate_limited: 429 — back off, keep queued;
 *  - transport:    no answer (or an edge 403) — keep queued;
 *  - rejected:     the batch itself was rejected (4xx/5xx) — counts as a failed attempt.
 */
final class SyncOutcome
{
    public string $state;
    public int $httpStatus = 0;
    /** @var list<string> */
    public array $ok = [];
    /** @var list<string> */
    public array $failed = [];
    /** @var list<string> */
    public array $alreadyExists = [];
    /** @var list<string> */
    public array $pending = [];
    public string $operationId = '';
    /** @var array<string, string> Quissly's id => our product id, for the items it confirmed */
    public array $quisslyIds = [];
    public string $detail = '';

    private function __construct(string $state)
    {
        $this->state = $state;
    }

    /**
     * @param list<string> $ok
     * @param list<string> $failed
     * @param list<string> $alreadyExists
     */
    public static function done(array $ok, array $failed, array $alreadyExists): self
    {
        $o = new self('done');
        $o->ok = array_values($ok);
        $o->failed = array_values($failed);
        $o->alreadyExists = array_values($alreadyExists);

        return $o;
    }

    /** @param list<string> $ids */
    public static function unconfirmed(array $ids, string $operationId): self
    {
        $o = new self('unconfirmed');
        $o->pending = $ids;
        $o->operationId = $operationId;

        return $o;
    }

    /** @param list<string> $ids */
    public static function refused(int $status, array $ids): self
    {
        $o = new self('refused');
        $o->httpStatus = $status;
        $o->pending = $ids;

        return $o;
    }

    /** @param list<string> $ids */
    public static function rateLimited(array $ids): self
    {
        $o = new self('rate_limited');
        $o->httpStatus = 429;
        $o->pending = $ids;

        return $o;
    }

    /** @param list<string> $ids */
    public static function transport(array $ids): self
    {
        $o = new self('transport');
        $o->pending = $ids;

        return $o;
    }

    /** @param list<string> $ids */
    public static function rejected(int $status, array $ids, string $detail): self
    {
        $o = new self('rejected');
        $o->httpStatus = $status;
        $o->failed = $ids;
        $o->detail = $detail;

        return $o;
    }

    public function withOperationId(string $operationId): self
    {
        $this->operationId = $operationId;

        return $this;
    }
}
