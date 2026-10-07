<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

/**
 * Sends the dirty-product queue to Quissly. The same rules as the WooCommerce plugin's
 * Quissly_Sync_Worker, adapted to CS-Cart (no Action Scheduler: runs from the admin
 * "Sync now" button and from cron, each with a time budget):
 *
 *  - settled on the answer to the send (2026-10-07, the Quissly Shopify app's way): a
 *    2xx is the batch delivered - marked synced, off the queue; the
 *    operation's status is not read, so nothing waits and nothing is re-checked;
 *  - add vs update by whether the product was delivered before;
 *  - a failed item is retried up to MAX_ATTEMPTS times, then dropped and logged;
 *  - an account refusal (401/402/403+JSON), a 429, or no answer stops the run with
 *    the batch still queued and no retry used — nothing is lost, the next run resumes;
 *  - a product that is no longer active (or no longer exists), or on Multi-Vendor one
 *    whose vendor is not active, is deleted from Quissly instead of being sent - the
 *    storefront hides it, and Quissly answering with it gives an empty results page;
 *  - operations recorded by the earlier status-checking version (accepted, never
 *    confirmed) are put back on the queue once, so those products are sent again;
 *  - a variation child is sent inside its parent (ProductSource/ProductMapper): a
 *    queued child re-sends the parent, and a full sync deletes whatever Quissly holds
 *    that is no longer sent (children once synced on their own, removed products),
 *    deletes always ahead of updates;
 *  - a full sync completes when the queue is empty; the first-sync gate (which lets
 *    storefront search use Quissly) opens only if Quissly accepted at least one
 *    product, so a sync that delivered nothing never turns on search against an
 *    empty index.
 */
final class SyncWorker
{
    public const BATCH_SIZE = 50;
    public const MAX_ATTEMPTS = 3;
    /** Operations of the earlier status-checking version put back on the queue per run. */
    public const LEFTOVER_LIMIT = 200;

    public const STATE_PROGRESS = 'progress';
    public const STATE_INITIAL_SYNC = 'initial_sync';
    public const STATE_GATE_BLOCKED = 'gate_blocked';
    public const STATE_REFUSAL = 'refusal';
    public const STATE_LAST_RUN = 'last_run';

    private SyncStore $store;
    private CatalogClient $client;

    /** @var callable(int): ?array<string,mixed> */
    private $load;

    /** @var callable(): int */
    private $clock;

    private bool $stop = false;
    private string $stopReason = '';

    /** @var array{confirmed:int, failed:int, waiting:int} this run */
    private array $run = ['confirmed' => 0, 'failed' => 0, 'waiting' => 0];

    /**
     * @param callable(int): ?array<string,mixed> $load  product id -> normalized product (ProductSource::load)
     * @param callable(): int                     $clock unix time
     */
    public function __construct(SyncStore $store, CatalogClient $client, callable $load, ?callable $clock = null)
    {
        $this->store = $store;
        $this->client = $client;
        $this->load = $load;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Whether storefront search may use Quissly: a full sync has delivered products. */
    public static function isGateOpen(SyncStore $store): bool
    {
        return !empty($store->getState(self::STATE_INITIAL_SYNC)['complete']);
    }

    /**
     * Queue every active product and start tracking progress. Does not send anything:
     * {@see run()} does.
     *
     * @param list<int> $productIds
     */
    public function startFullSync(array $productIds): int
    {
        $now = $this->now();
        // Reconcile: what Quissly holds but is no longer a product we send (a deleted or
        // disabled product, or a variation child synced on its own before variations
        // were sent inside their parent) is deleted - queued a second EARLIER so those
        // deletes reach Quissly before the parents' updates. Quissly processes
        // operations in order, and a late delete would remove a parent's new variant.
        $stale = array_values(array_diff($this->store->syncedIds(), array_map('intval', $productIds)));
        foreach ($stale as $id) {
            $this->store->enqueue($id, SyncStore::OP_DELETE, $now - 1);
        }
        foreach ($productIds as $id) {
            $this->store->enqueue((int) $id, SyncStore::OP_UPSERT, $now);
        }
        $this->store->setState(self::STATE_PROGRESS, [
            'running' => true, 'total' => count($productIds), 'ok' => 0, 'failed' => 0,
            'started_at' => $now, 'finished_at' => null,
        ]);
        $this->store->setState(self::STATE_GATE_BLOCKED, null);
        $this->store->log('Full sync started: ' . count($productIds) . ' products queued' . ($stale !== [] ? ', ' . count($stale) . ' no longer sent queued for removal' : '') . '.', $now);

        return count($productIds);
    }

    /**
     * Send queued batches until the queue is empty, a stop condition, or the time
     * budget runs out.
     *
     * @return array{confirmed:int, failed:int, waiting:int, queued:int, stopped:string} waiting and queued: totals after the run
     */
    public function run(int $budgetSeconds, string $source): array
    {
        $deadline = $this->now() + $budgetSeconds;
        $this->stop = false;
        $this->stopReason = '';
        $this->run = ['confirmed' => 0, 'failed' => 0, 'waiting' => 0];

        $this->requeueLeftovers();

        while (!$this->stop && $this->now() < $deadline && $this->store->countQueued() > 0) {
            $this->flushBatch();
        }

        $this->finishIfDone();

        // waiting: leftover operations of the earlier version not yet put back (normally 0).
        $summary = [
            'confirmed' => $this->run['confirmed'],
            'failed'    => $this->run['failed'],
            'waiting'   => $this->store->waiting()['products'],
            'queued'    => $this->store->countQueued(),
            'stopped'   => $this->stopReason,
        ];
        $this->store->setState(self::STATE_LAST_RUN, ['at' => $this->now(), 'source' => $source] + $summary);

        return $summary;
    }

    private function flushBatch(): void
    {
        $rows = $this->store->claim(self::BATCH_SIZE);
        $attempts = [];
        $new = $existing = $deletes = [];
        $dropped = [];

        foreach ($rows as $row) {
            $id = $row['product_id'];
            $attempts[$id] = $row['attempts'];
            $product = $row['operation'] === SyncStore::OP_DELETE ? null : ($this->load)($id);
            $parentId = (int) ($product['parent_product_id'] ?? 0);
            if ($parentId > 0) {
                // A variation child travels inside its parent: send the parent (and
                // remove a copy of the child that was once synced on its own).
                $this->store->enqueue($parentId, SyncStore::OP_UPSERT, $this->now());
                if ($this->store->isSynced($id)) {
                    $deletes[] = $id;
                } else {
                    $dropped[] = $id;
                }
                continue;
            }
            // A disabled product, or (Multi-Vendor) one whose vendor is not active: the
            // storefront hides it, so Quissly must not answer with it.
            $active = $product !== null && ($product['status'] ?? 'A') === 'A' && ($product['vendor_active'] ?? true);

            if (!$active) {
                if ($this->store->isSynced($id)) {
                    $deletes[] = $id;
                } else {
                    $dropped[] = $id; // never reached Quissly: nothing to delete
                }
                continue;
            }
            if ($this->store->isSynced($id)) {
                $existing[$id] = ProductMapper::map($product);
            } else {
                $new[$id] = ProductMapper::map($product);
            }
        }

        if ($dropped !== []) {
            $this->store->remove($dropped);
        }
        // Deletes first: Quissly works through operations in order, and a delete that
        // lands after an update could remove a product's freshly sent variant.
        if ($deletes !== []) {
            $this->apply('delete', $this->client->delete($deletes), $deletes, $attempts);
        }
        if ($new !== [] && !$this->stop) {
            $this->apply('add', $this->client->add($new), array_keys($new), $attempts);
        }
        if ($existing !== [] && !$this->stop) {
            $this->apply('update', $this->client->update($existing), array_keys($existing), $attempts);
        }
    }

    /**
     * @param list<int>       $ids      the batch's product ids
     * @param array<int, int> $attempts attempts so far, by id
     */
    private function apply(string $kind, SyncOutcome $outcome, array $ids, array $attempts): void
    {
        switch ($outcome->state) {
            case 'done':
                // A 2xx: the whole batch is delivered.
                $this->confirm($kind, $ids);
                $this->store->remove($ids);
                $this->store->log(sprintf('catalog %s: %d products accepted by Quissly%s.', $kind, count($ids), $outcome->operationId !== '' ? ' (operation ' . $outcome->operationId . ')' : ''), $this->now());
                break;
            case 'rejected':
                $this->fail($ids, $attempts, $kind);
                $this->store->log(sprintf('catalog %s rejected (HTTP %d) for %d products: %s', $kind, $outcome->httpStatus, count($ids), $outcome->detail), $this->now());
                break;
            case 'refused':
                $this->store->setState(self::STATE_REFUSAL, ['code' => $outcome->httpStatus, 'at' => $this->now()]);
                $this->halt(sprintf('Quissly refused catalog updates (HTTP %d)', $outcome->httpStatus));
                break;
            case 'rate_limited':
                $this->halt('Quissly asked to slow down (HTTP 429)');
                break;
            default:
                $this->halt('Quissly could not be reached');
        }
    }

    /**
     * Operations the earlier status-checking version recorded as accepted-but-unconfirmed:
     * their products had left the queue to wait for a status that is no longer read, so
     * they go back on it and are sent again.
     */
    private function requeueLeftovers(): void
    {
        $count = 0;
        foreach ($this->store->operations(self::LEFTOVER_LIMIT) as $operation) {
            foreach ($operation['product_ids'] as $id) {
                $this->store->enqueue((int) $id, SyncStore::OP_UPSERT, $this->now());
                $count++;
            }
            $this->store->removeOperation($operation['operation_id']);
        }
        if ($count > 0) {
            $this->store->log(sprintf('%d products from unconfirmed earlier operations queued to be sent again.', $count), $this->now());
        }
    }

    /** @param list<int> $ids delivered */
    private function confirm(string $kind, array $ids): void
    {
        if ($kind === 'delete') {
            $this->store->clearSynced($ids);
        } else {
            $this->store->markSynced($ids, $this->now());
        }
        $confirmed = count($ids);
        if ($confirmed > 0) {
            $this->store->setState(self::STATE_REFUSAL, null);
            $this->run['confirmed'] += $confirmed;
            $this->bumpProgress('ok', $kind === 'delete' ? 0 : $confirmed);
        }
    }

    /**
     * @param list<int>       $ids
     * @param array<int, int> $attempts
     */
    private function fail(array $ids, array $attempts, string $kind): void
    {
        if ($ids === []) {
            return;
        }
        $this->store->bumpAttempts($ids);
        $permanent = array_values(array_filter($ids, static fn (int $id): bool => ($attempts[$id] ?? 0) + 1 >= self::MAX_ATTEMPTS));
        if ($permanent !== []) {
            $this->store->remove($permanent);
            $this->countFailed($permanent, $kind);
        }
    }

    /** @param list<int> $ids */
    private function countFailed(array $ids, string $kind): void
    {
        $this->run['failed'] += count($ids);
        $this->bumpProgress('failed', $kind === 'delete' ? 0 : count($ids));
        $this->store->log(sprintf('catalog %s: gave up on %d products: %s', $kind, count($ids), implode(', ', $ids)), $this->now());
    }

    private function halt(string $reason): void
    {
        $this->stop = true;
        $this->stopReason = $reason;
        $this->store->log($reason . '; the queue is kept and the next run resumes.', $this->now());
    }

    private function bumpProgress(string $key, int $by): void
    {
        $progress = $this->store->getState(self::STATE_PROGRESS);
        if ($by <= 0 || empty($progress['running'])) {
            return;
        }
        $progress[$key] = (int) ($progress[$key] ?? 0) + $by;
        $this->store->setState(self::STATE_PROGRESS, $progress);
    }

    private function finishIfDone(): void
    {
        $progress = $this->store->getState(self::STATE_PROGRESS);
        if (empty($progress['running']) || $this->store->countQueued() > 0 || $this->store->waiting()['products'] > 0) {
            return;
        }
        $progress['running'] = false;
        $progress['finished_at'] = $this->now();
        $this->store->setState(self::STATE_PROGRESS, $progress);

        if ((int) $progress['ok'] > 0 || (int) $progress['total'] === 0) {
            $this->store->setState(self::STATE_INITIAL_SYNC, ['complete' => true, 'at' => $this->now()]);
            $this->store->setState(self::STATE_GATE_BLOCKED, null);
            $this->store->log('Full sync complete; storefront search can now use Quissly.', $this->now());
        } else {
            $this->store->setState(self::STATE_GATE_BLOCKED, ['at' => $this->now(), 'failed' => (int) $progress['failed']]);
            $this->store->log('Full sync finished WITHOUT Quissly accepting any product (' . (int) $progress['failed'] . ' failed); search stays on native CS-Cart search. Fix the cause and sync again.', $this->now());
        }
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
