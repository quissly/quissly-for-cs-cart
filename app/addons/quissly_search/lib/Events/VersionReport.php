<?php

declare(strict_types=1);

namespace Quissly\Search\Events;

/**
 * Which version of the add-on a store runs, reported to Quissly.
 *
 * A `quissly_version_update` event tells Quissly which version runs, which one ran before and
 * since when: when the store is first connected, right after each update, and once a day (so a
 * report that was missed is right again within a day). Quissly knows which store sent it from
 * its key; the event carries its own time.
 *
 * Metadata: {platform, version, previous_version, updated_at} ('' previous_version before the
 * first update; updated_at = when the store moved to this version, ISO 8601 UTC).
 *
 * The version is the store's own fact, not a shopper's: it is reported whenever the store is
 * connected, whatever the "Shopping activity" switch says, with no user id. Checked by the cron
 * and at the end of an admin page (func.php fn_quissly_search_version_report); state row STATE.
 *
 * Pure (unit-tested without CS-Cart).
 */
final class VersionReport
{
    public const EVENT = 'quissly_version_update';
    public const PLATFORM = 'cs-cart';
    /** {version, previous, updated_at, reported_at} */
    public const STATE = 'version_report';
    public const EVERY = 86400;

    /**
     * What to keep and whether to report: a new version at once, the same one once a day.
     *
     * @param array<string, mixed> $state the stored record ([] before the first one)
     * @return array{state: array<string, mixed>, report: bool}
     */
    public static function track(array $state, string $version, int $now): array
    {
        $known = (string) ($state['version'] ?? '');
        if ($known !== $version) {
            $state = ['version' => $version, 'previous' => $known, 'updated_at' => $now, 'reported_at' => (int) ($state['reported_at'] ?? 0)];

            return ['state' => $state, 'report' => true];
        }
        $at = (int) ($state['reported_at'] ?? 0);

        return ['state' => $state, 'report' => $at <= 0 || $now - $at >= self::EVERY];
    }

    /**
     * @param array<string, mixed> $state the stored record
     * @return array{platform: string, version: string, previous_version: string, updated_at: string}
     */
    public static function metadata(array $state): array
    {
        return [
            'platform'         => self::PLATFORM,
            'version'          => (string) ($state['version'] ?? ''),
            'previous_version' => (string) ($state['previous'] ?? ''),
            'updated_at'       => gmdate('Y-m-d\TH:i:s\Z', (int) ($state['updated_at'] ?? 0)),
        ];
    }

    private function __construct()
    {
    }
}
