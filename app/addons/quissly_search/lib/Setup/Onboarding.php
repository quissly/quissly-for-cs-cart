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
 * only once the first sync has opened the gate. It switches on what the chosen plan includes
 * (services()): search, and with a chat plan the assistant. The plan is chosen once: after
 * that its step is closed until Setup is finished (the Shopify app's rule; Billing changes it).
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

    /** The products a plan can include. */
    public const SERVICES = ['qsearch', 'qchat'];

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

    /**
     * The plan step is done: a plan is live, or the merchant was let through without one.
     *
     * @param list<string> $families the plan's products (qsearch, qchat); none = not known
     */
    public function planChosen(array $families = []): void
    {
        $state = $this->state() ?? ['status' => self::IN_PROGRESS];
        $state['plan'] = true;
        $state['services'] = array_values(array_intersect(self::SERVICES, $families));
        $this->save($state);
    }

    /** Whether the plan is chosen and Setup is not finished: the plan step is then closed. */
    public function isPlanLocked(): bool
    {
        $state = $this->state();

        return !empty($state['plan']) && ($state['status'] ?? '') !== self::COMPLETE;
    }

    /**
     * The chosen plan's products; none when Setup never saw the plan (then it counts as search).
     *
     * @return list<string>
     */
    public function services(): array
    {
        return array_values(array_intersect(self::SERVICES, (array) ($this->state()['services'] ?? [])));
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
     * Finish Setup without going live (the Shopify app's "Do this later"): for a merchant not
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
     * Where Setup stands for the Go live step (the Shopify app's account setup card): a phase -
     * building (the services are being built), importing, failed (no products got through) or
     * ready - and the rows: one "Quissly Account" row (store identity, organization and project:
     * one Connect call creates all three), search, the assistant (with a chat plan, or no plan
     * known - QChat finds products through the search service, so search is always listed) and
     * the catalog. A done row has no words. Each detail - and the raw problem behind a failed
     * import, for "Technical details" - is a langvar and its parameters (null for none), for the
     * caller to translate.
     *
     * @param bool $chatFound whether the QChat service's id is known
     * @return array{phase:string, ready:bool, failed:bool, saveable:bool, technical:?array, rows:array<string, array{state:string, detail:?array, hidden:bool}>}
     */
    public function status(bool $chatFound = false): array
    {
        $hold = $this->connected ? $this->holdRemaining() : 0;
        $ready = $this->isReady();
        $failed = !$ready && $this->syncFailed();
        $progress = $this->store->getState(SyncWorker::STATE_PROGRESS);
        $sent = (int) ($progress['ok'] ?? 0);
        $building = !$this->connected || $hold > 0;
        // Finish waits for the account too (the Shopify app's gate), even over a catalog already in Quissly.
        $ready = $ready && !$building;
        $technical = null;

        if ($ready) {
            $catalog = self::row('done', null);
        } elseif ($failed) {
            $refusal = $this->store->getState(SyncWorker::STATE_REFUSAL);
            $technical = $refusal !== null
                ? ['quissly_search.su_sync_refused', ['[status]' => (int) ($refusal['code'] ?? 0)]]
                : ['quissly_search.su_sync_nothing', []];
            $catalog = self::row('failed', ['quissly_search.su_import_failed', []]);
        } elseif (!empty($progress['running'])) {
            $catalog = self::row('running', $sent === 0
                ? ['quissly_search.su_reading_products', []]
                : ['quissly_search.su_importing_products', ['[sent]' => $sent, '[total]' => (int) ($progress['total'] ?? 0)]]);
        } elseif (!$building) {
            $catalog = self::row('running', ['quissly_search.su_starting_shortly', []]);
        } else {
            $catalog = self::row('pending', ['quissly_search.su_starts_when_ready', []]);
        }

        $services = $this->services();
        $connected = $this->connected;
        $service = static function (string $running) use ($connected, $building): array {
            return !$connected ? self::row('pending', ['quissly_search.su_up_next', []])
                : ($building ? self::row('running', [$running, []]) : self::row('done', null));
        };
        if ($services !== [] && !in_array('qchat', $services, true)) {
            $chat = self::row('done', null, true);
        } elseif ($chatFound || $building) {
            $chat = $service('quissly_search.su_setting_up_assistant');
        } else {
            $chat = self::row('pending', ['quissly_search.su_chat_later', []]);
        }

        return [
            'phase'     => $building ? 'building' : ($ready ? 'ready' : ($failed ? 'failed' : 'importing')),
            'ready'     => $ready,
            'failed'    => $failed,
            'saveable'  => $this->canSaveForLater(),
            'technical' => $technical,
            'rows'      => [
                'account' => $this->connected ? self::row('done', null) : self::row('running', ['quissly_search.su_creating_account', []]),
                'qsearch' => $service('quissly_search.su_setting_up_search'),
                'qchat'   => $chat,
                'catalog' => $catalog,
            ],
        ];
    }

    /**
     * @param ?array{0:string, 1:array<string, int>} $detail
     * @return array{state:string, detail:?array{0:string, 1:array<string, int>}, hidden:bool}
     */
    private static function row(string $state, ?array $detail, bool $hidden = false): array
    {
        return ['state' => $state, 'detail' => $detail, 'hidden' => $hidden];
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
