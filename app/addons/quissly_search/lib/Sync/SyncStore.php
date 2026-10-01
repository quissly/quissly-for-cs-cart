<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

/**
 * Everything the sync keeps between runs: the dirty-product queue, which products
 * Quissly already has (drives add vs update), operations Quissly accepted but has
 * not confirmed yet, small state values (progress, refusal, first-sync gate, last
 * run) and the sync log.
 *
 * {@see DbSyncStore} is the CS-Cart implementation; the unit tests use an in-memory
 * one, so the worker's rules are tested without a database.
 */
interface SyncStore
{
    public const OP_UPSERT = 'upsert';
    public const OP_DELETE = 'delete';

    /** Queue a product (one row per product; the latest operation wins, retries reset). */
    public function enqueue(int $productId, string $operation, int $now): void;

    /** @return list<array{product_id:int, operation:string, attempts:int}> oldest first */
    public function claim(int $limit): array;

    /** @param list<int> $productIds */
    public function remove(array $productIds): void;

    /** @param list<int> $productIds */
    public function bumpAttempts(array $productIds): void;

    public function countQueued(): int;

    public function isSynced(int $productId): bool;

    /** @param list<int> $productIds */
    public function markSynced(array $productIds, int $now): void;

    /** @param list<int> $productIds */
    public function clearSynced(array $productIds): void;

    public function countSynced(): int;

    /** @return list<int> every product Quissly is known to hold */
    public function syncedIds(): array;

    /** @param array<string, string|int> $map Quissly's product id (a UUID) => our product id */
    public function saveQuisslyIds(array $map): void;

    /**
     * @param list<string> $quisslyIds
     * @return array<string, int> Quissly's product id => our product id, for the known ones
     */
    public function productIdsFor(array $quisslyIds): array;

    /** @param list<int> $productIds */
    public function addOperation(string $operationId, string $kind, array $productIds, int $now): void;

    /** @return list<array{operation_id:string, kind:string, product_ids:list<int>, sent_at:int}> oldest first */
    public function operations(int $limit): array;

    public function removeOperation(string $operationId): void;

    /** @return array{products:int, oldest_sent_at:?int} products in unconfirmed operations */
    public function waiting(): array;

    /** @return ?array<string, mixed> */
    public function getState(string $name): ?array;

    /** @param ?array<string, mixed> $value null deletes */
    public function setState(string $name, ?array $value): void;

    public function log(string $message, int $now): void;

    /** @return list<array{at:int, message:string}> newest first */
    public function recentLog(int $limit): array;
}
