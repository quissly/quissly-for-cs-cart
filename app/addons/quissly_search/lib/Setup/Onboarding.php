<?php

declare(strict_types=1);

namespace Quissly\Search\Setup;

use Quissly\Search\Sync\SyncStore;
use Quissly\Search\Sync\SyncWorker;

/**
 * Quissly Setup: the one-click onboarding, as the Shopify app does it - Your details
 * (Connect), Choose a plan, Go live - and the same screen as quissly-for-magento's and
 * quissly-for-woocommerce's. Until it is finished the Quissly menu's three entries open it.
 *
 * The first catalog sync starts on its own once the service Connect created has settled
 * (HOLD_SECONDS, the Magento plugin's ConnectHold); going live is the merchant's click, and
 * only once the first sync has opened the gate.
 *
 * State is one row, `setup`, in quissly_search_state. A store connected before Setup existed
 * has no row and counts as finished, so nobody already live is sent back through it.
 *
 * Pure: the caller passes the store, whether credentials resolve, and the time; the caller
 * starts the sync and writes the search setting when this says so.
 */
final class Onboarding
{
    public const STATE = 'setup';
    public const HOLD_SECONDS = 90;

    public const STEP_DETAILS = 'details';
    public const STEP_PLAN = 'plan';
    public const STEP_GOLIVE = 'golive';

    private const IN_PROGRESS = 'in_progress';
    private const COMPLETE = 'complete';

    /** @var SyncStore */
    private $store;

    /** @var bool */
    private $connected;

    /** @var int */
    private $now;

    public function __construct(SyncStore $store, bool $connected, int $now)
    {
        $this->store = $store;
        $this->connected = $connected;
        $this->now = $now;
    }

    public function isComplete(): bool
    {
        $state = $this->state();

        return $state === null ? $this->connected : ($state['status'] ?? '') === self::COMPLETE;
    }

    public function step(): string
    {
        if (!$this->connected) {
            return self::STEP_DETAILS;
        }

        return empty($this->state()['plan']) ? self::STEP_PLAN : self::STEP_GOLIVE;
    }

    /** Connect succeeded for a store that had not finished Setup. */
    public function connected(): void
    {
        $state = $this->state() ?? [];
        $state['status'] = self::IN_PROGRESS;
        $state['connected_at'] = $this->now;
        unset($state['sync']);
        $this->save($state);
    }

    /** The plan step is done: a plan is live, or the merchant was let through without one. */
    public function planChosen(): void
    {
        $state = $this->state() ?? ['status' => self::IN_PROGRESS];
        $state['plan'] = true;
        $this->save($state);
    }

    public function holdRemaining(): int
    {
        $at = (int) ($this->state()['connected_at'] ?? 0);

        return $at > 0 ? max(0, $at + self::HOLD_SECONDS - $this->now) : 0;
    }

    /**
     * Whether the first sync should start now - true once, and never over a sync that
     * already ran. The caller starts it (and holds search back for Go live).
     */
    public function claimFirstSync(): bool
    {
        $state = $this->state();
        if ($state === null || ($state['status'] ?? '') !== self::IN_PROGRESS || !empty($state['sync'])
            || !$this->connected || $this->holdRemaining() > 0) {
            return false;
        }
        $state['sync'] = true;
        $this->save($state);

        return $this->store->getState(SyncWorker::STATE_PROGRESS) === null && !SyncWorker::isGateOpen($this->store);
    }

    /** Whether the first sync ended without the catalog getting through. */
    public function syncFailed(): bool
    {
        return $this->store->getState(SyncWorker::STATE_GATE_BLOCKED) !== null
            || $this->store->getState(SyncWorker::STATE_REFUSAL) !== null;
    }

    /** The catalog is in Quissly: the gate is open and no sync is running. */
    public function isReady(): bool
    {
        return $this->connected
            && SyncWorker::isGateOpen($this->store)
            && empty($this->store->getState(SyncWorker::STATE_PROGRESS)['running']);
    }

    /**
     * Finish Setup without going live (the Shopify app's "Save changes"): for a merchant not
     * ready to switch search on yet, so nobody is held on this screen. Needs the services built
     * (the hold over); the caller starts the first sync if it has not started, and search stays
     * off until the merchant switches it on.
     */
    public function canSaveForLater(): bool
    {
        return $this->connected && $this->holdRemaining() === 0;
    }

    /** Go live is done (or saved for later): Setup is behind the store. */
    public function complete(): void
    {
        $state = $this->state() ?? [];
        $state['status'] = self::COMPLETE;
        $this->save($state);
    }

    /**
     * The Go live step's progress list: the Shopify app's rows - store identity, organization,
     * project, QSearch service, QChat service, catalog import. One Connect call creates the
     * first three at once; the services are built during the hold. Each row's detail is a
     * langvar and its parameters (null for none), for the caller to translate.
     *
     * @param bool $chatFound whether the QChat service's id is known
     * @return array{ready:bool, failed:bool, saveable:bool, rows:array<string, array{state:string, detail:?array{0:string, 1:array<string, int>}}>}
     */
    public function status(bool $chatFound = false): array
    {
        $hold = $this->connected ? $this->holdRemaining() : 0;
        $ready = $this->isReady();
        $failed = !$ready && $this->syncFailed();
        $progress = $this->store->getState(SyncWorker::STATE_PROGRESS);
        $sent = (int) ($progress['ok'] ?? 0);

        if ($ready) {
            $catalog = self::row('done', $sent > 0 ? ['quissly_search.su_products_sent', ['[count]' => $sent]] : null);
        } elseif ($failed) {
            $refusal = $this->store->getState(SyncWorker::STATE_REFUSAL);
            $catalog = self::row('failed', $refusal !== null
                ? ['quissly_search.su_sync_refused', ['[status]' => (int) ($refusal['code'] ?? 0)]]
                : ['quissly_search.su_sync_nothing', []]);
        } elseif (!empty($progress['running'])) {
            $catalog = self::row('running', ['quissly_search.su_products_progress', ['[sent]' => $sent, '[total]' => (int) ($progress['total'] ?? 0)]]);
        } elseif ($this->connected && $hold === 0) {
            $catalog = self::row('running', ['quissly_search.su_starting', []]);
        } else {
            $catalog = self::row('pending', ['quissly_search.su_sync_waits', []]);
        }

        $created = self::row($this->connected ? 'done' : 'pending', null);
        $building = !$this->connected ? 'pending' : ($hold > 0 ? 'running' : 'done');

        return [
            'ready'    => $ready,
            'failed'   => $failed,
            'saveable' => $this->canSaveForLater(),
            'rows'     => [
                'store'        => $created,
                'organization' => $created,
                'project'      => $created,
                'qsearch'      => self::row($building, $hold > 0 ? ['quissly_search.su_service_building', []] : null),
                'qchat'        => $chatFound || $building !== 'done'
                    ? self::row($building, null)
                    : self::row('pending', ['quissly_search.su_chat_not_found', []]),
                'catalog'      => $catalog,
            ],
        ];
    }

    /**
     * @param ?array{0:string, 1:array<string, int>} $detail
     * @return array{state:string, detail:?array{0:string, 1:array<string, int>}}
     */
    private static function row(string $state, ?array $detail): array
    {
        return ['state' => $state, 'detail' => $detail];
    }

    private function state(): ?array
    {
        return $this->store->getState(self::STATE);
    }

    private function save(array $state): void
    {
        $this->store->setState(self::STATE, $state);
    }
}
