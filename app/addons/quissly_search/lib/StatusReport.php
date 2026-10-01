<?php

declare(strict_types=1);

namespace Quissly\Search;

use Quissly\Search\Sync\DbSyncStore;
use Quissly\Search\Sync\SyncStore;
use Quissly\Search\Sync\SyncWorker;
use Tygh\Registry;

/**
 * Computes the merchant-facing status model: is search interception ACTIVE, and
 * if not, WHY (never leave active-vs-inactive invisible).
 *
 * Pure-ish: reads credentials, the master toggle, and the persisted health row,
 * and returns a plain array the admin status block renders. Returns a structured
 * model rather than HTML so it can be asserted in tests.
 */
final class StatusReport
{
    public const STATE_ACTIVE = 'active';
    public const STATE_INACTIVE = 'inactive';

    /**
     * @return array{
     *   state:string,
     *   configured:bool,
     *   toggle_on:bool,
     *   environment:string,
     *   reasons:list<string>,
     *   auth_alert:?array{status:int,message:string,at:int},
     *   last_success_at:int
     * }
     */
    public static function build(?Credentials $credentials = null, ?SyncStore $store = null): array
    {
        $credentials ??= Credentials::fromRegistry();
        $store ??= new DbSyncStore();

        $configured = $credentials->isConfigured();
        $toggleOn = Registry::get('addons.quissly_search.search_interception') !== 'N';
        $health = HealthStore::get();

        $authAlert = null;
        if ($health !== null && in_array($health['last_status'], [401, 402, 403], true)) {
            // Only treat an auth failure as current if it is the most recent event.
            if ($health['last_failure_at'] >= $health['last_success_at']) {
                $authAlert = [
                    'status'  => $health['last_status'],
                    'message' => $health['last_message'],
                    'at'      => $health['last_failure_at'],
                ];
            }
        }

        $reasons = [];
        if (!$toggleOn) {
            $reasons[] = 'master_toggle_off';
        }
        if (!$configured) {
            $reasons[] = 'credentials_not_configured';
        }
        if ($authAlert !== null) {
            $reasons[] = 'auth_error';
        }
        if ($configured && !SyncWorker::isGateOpen($store)) {
            $reasons[] = 'initial_sync_pending';
        }

        return [
            'state'           => $reasons === [] ? self::STATE_ACTIVE : self::STATE_INACTIVE,
            'configured'      => $configured,
            'toggle_on'       => $toggleOn,
            'environment'     => $configured ? $credentials->environment() : '',
            'reasons'         => $reasons,
            'auth_alert'      => $authAlert,
            'last_success_at' => $health['last_success_at'] ?? 0,
        ];
    }

    private function __construct()
    {
    }
}
