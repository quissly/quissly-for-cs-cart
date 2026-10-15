<?php

declare(strict_types=1);

namespace Quissly\Search\Setup;

use Quissly\Search\Sync\SyncStore;

/**
 * Which feature switches the store's plan leaves out: a store on a Search plan
 * only gets QChat's switch disabled ("not in your plan"), one on a Chat plan the search switches -
 * search and everything that only works with it. A disabled switch counts as off everywhere.
 *
 * What the plan includes, best known first: Quissly's live plans (GET /subscriptions, kept in state
 * row STATE by remember(); refreshed by Configuration, Billing and the cron), plus a plan chosen in
 * Setup for manual invoice (Quissly does not know of that one yet); while Quissly's answer has never
 * been read, the plan chosen in Setup. Nothing known (a store connected before Setup, a Quissly
 * outage at Setup) disables nothing - a store already live is never switched off on a guess.
 */
final class PlanGate
{
    public const STATE = 'plan_families';
    /** How long Quissly's answer is kept before the cron asks again. */
    public const MAX_AGE = 600;

    /** Switches that need a Search plan: search itself and what only works with it. */
    public const SEARCH_SWITCHES = ['search_interception', 'enable_overlay', 'overlay_suggestions', 'overlay_recent', 'enable_quick', 'enable_voice', 'enable_image', 'most_loved'];
    public const CHAT_SWITCHES = ['enable_qchat'];

    /**
     * The disabled switches for what the plan includes.
     *
     * @param list<string> $families the plan's products (qsearch, qchat); none = not known
     * @return array<string, string> switch id => the product it needs
     */
    public static function locked(array $families): array
    {
        $families = array_intersect(Onboarding::SERVICES, $families);
        if ($families === []) {
            return [];
        }
        $locked = [];
        if (!in_array('qsearch', $families, true)) {
            $locked += array_fill_keys(self::SEARCH_SWITCHES, 'qsearch');
        }
        if (!in_array('qchat', $families, true)) {
            $locked += array_fill_keys(self::CHAT_SWITCHES, 'qchat');
        }

        return $locked;
    }

    /**
     * What the plan includes, from what is known.
     *
     * @param ?list<string> $live    Quissly's live plans' products; null = never read
     * @param string        $invoice the product of a plan chosen for manual invoice, or ''
     * @param list<string>  $setup   the products of the plan chosen in Setup
     * @return list<string>
     */
    public static function families(?array $live, string $invoice, array $setup): array
    {
        $known = $live ?? $setup;
        if ($invoice !== '') {
            $known[] = $invoice;
        }

        return array_values(array_intersect(Onboarding::SERVICES, array_unique($known)));
    }

    /** @return array<string, string> the disabled switches, from what the store knows */
    public static function current(SyncStore $store, array $setupServices): array
    {
        $kept = $store->getState(self::STATE);

        return self::locked(self::families(
            is_array($kept['families'] ?? null) ? $kept['families'] : null,
            (string) ($store->getState('setup_invoice')['family'] ?? ''),
            $setupServices
        ));
    }

    /** Keeps Quissly's answer about the store's plans (StoreBilling::subscriptions()); null is no answer. */
    public static function remember(SyncStore $store, ?array $subscriptions, int $now): void
    {
        if ($subscriptions !== null) {
            $store->setState(self::STATE, ['families' => array_keys(StoreBilling::livePlans($subscriptions)), 'at' => $now]);
        }
    }

    /** Whether Quissly's answer is older than MAX_AGE (or was never read). */
    public static function isStale(SyncStore $store, int $now): bool
    {
        return (int) ($store->getState(self::STATE)['at'] ?? 0) + self::MAX_AGE <= $now;
    }

    private function __construct()
    {
    }
}
