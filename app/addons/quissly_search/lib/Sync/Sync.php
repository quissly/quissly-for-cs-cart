<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

use Quissly\Search\Credentials;

/**
 * CS-Cart entry points into the sync: the worker wired to the live pieces, and the
 * product-change hooks (func.php calls these). The hooks only QUEUE — they never
 * call Quissly inside a product save; "Sync now" and cron send.
 */
final class Sync
{

    /** "Sync now" in the admin: an admin waiting on a page. */
    public const ADMIN_BUDGET_SECONDS = 25;

    /** One cron run (cron is expected every 5 minutes). */
    public const CRON_BUDGET_SECONDS = 240;

    public static function worker(?Credentials $credentials = null): SyncWorker
    {
        $credentials = $credentials ?? Credentials::fromRegistry();
        $source = new ProductSource();

        return new SyncWorker(
            new DbSyncStore(),
            new CatalogClient($credentials, new LiveTransport()),
            [$source, 'load']
        );
    }

    /** A product was created or saved (status included: the worker deletes inactive ones). */
    public static function productSaved(int $productId): void
    {
        self::queue($productId, SyncStore::OP_UPSERT);
    }

    /**
     * Several products changed together (a variation group was created or changed).
     *
     * @param list<int> $productIds
     */
    public static function productsSaved(array $productIds): void
    {
        foreach (array_unique(array_map('intval', $productIds)) as $productId) {
            self::queue($productId, SyncStore::OP_UPSERT);
        }
    }

    /** Before a product is deleted: a variation child's parent must be re-sent without it. */
    public static function productDeleting(int $productId): void
    {
        $parentId = (new ProductSource())->parentOf($productId);
        if ($parentId > 0) {
            self::queue($parentId, SyncStore::OP_UPSERT);
        }
    }

    public static function productDeleted(int $productId): void
    {
        self::queue($productId, SyncStore::OP_DELETE);
    }

    /** Stock moved (orders, returns): only an in/out-of-stock flip changes what Quissly holds. */
    public static function amountChanged(int $productId, $currentAmount, $newAmount): void
    {
        if (((float) $currentAmount > 0) !== ((float) $newAmount > 0)) {
            self::queue($productId, SyncStore::OP_UPSERT);
        }
    }

    /**
     * A status toggle in an admin list (Active / Hidden / Disabled).
     *
     * @param array<string, mixed> $params fn_tools_update_status params (table, id_name, id, status)
     */
    public static function statusChanged(array $params): void
    {
        if (($params['table'] ?? '') === 'products' && !empty($params['id'])) {
            self::queue((int) $params['id'], SyncStore::OP_UPSERT);
        }
        if (($params['table'] ?? '') === 'product_bundles' && !empty($params['id'])) {
            self::bundleChanged((int) $params['id']);
        }
        if (($params['table'] ?? '') === 'companies' && !empty($params['id'])) {
            self::vendorChanged((int) $params['id']);
        }
    }

    /**
     * A Multi-Vendor vendor's status changes (suspended, reactivated...): its products
     * are queued, and the worker removes them from Quissly or sends them again by the
     * vendor's status when it runs.
     */
    public static function vendorChanged(int $companyId): void
    {
        if ($companyId > 0) {
            self::productsSaved((new ProductSource())->vendorProductIds($companyId));
        }
    }

    /**
     * A product bundle is saved, re-linked, switched on/off or deleted: its members
     * carry it in their metadata (ProductMapper), so they are re-sent. Called before
     * the change too, so a product taken out of a bundle is re-sent without it.
     *
     * @param list<int> $productIds members known to the caller, on top of the stored links
     */
    public static function bundleChanged(int $bundleId, array $productIds = []): void
    {
        $ids = $bundleId > 0 ? (new ProductSource())->bundleMemberIds($bundleId) : [];
        self::productsSaved(array_merge($ids, $productIds));
    }

    private static function queue(int $productId, string $operation): void
    {
        // Nothing to send before the store is connected; connecting starts a full sync.
        if ($productId > 0 && Credentials::fromRegistry()->isConfigured()) {
            (new DbSyncStore())->enqueue($productId, $operation, time());
        }
    }

    private function __construct()
    {
    }
}
