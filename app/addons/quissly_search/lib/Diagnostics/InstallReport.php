<?php

declare(strict_types=1);

namespace Quissly\Search\Diagnostics;

/**
 * What a new store runs on, told to Quissly once: with the store's first version report, which
 * leaves the moment the store connects (Quissly Setup's first step, or Connect) - the add-on has
 * no key to sign anything with before that. Its metadata gains `report: install` and `environment`:
 * CS-Cart's version and edition, PHP's version, limits and extensions, the add-ons the add-on works
 * with, the theme, the languages and currency, how many products, whether automatic updates can
 * write their files, and how many problems ErrorReport recorded before the store was connected.
 *
 * A store that reported its version before (one updated to this version) sends no install report.
 * Nothing secret or personal: facts about the installation only.
 *
 * Before that, as soon as the add-on is installed (its first admin page or cron run), the same facts
 * go to Quissly's log once through `/v2beta/integrations/errors` (pingText(): "plugin_installed {json}",
 * with the store's host and versions) - that call needs no key, so it does not wait for Connect. One
 * Quissly does not take is tried again an hour later (pingDue / afterPing, state row PING_STATE).
 *
 * An installation CS-Cart could not finish (a table, a setting, a language file, an install function
 * failing - it then removes the add-on again, so none of its code runs afterwards) is told from the
 * installing request itself: addon.xml's before_install function registers a check for the end of
 * that request (func.php fn_quissly_search_install_outcome), which sends failureText() -
 * "plugin_install_failed {json}" with CS-Cart's error notices and PHP's last error - when the add-on is
 * not installed by then. What CS-Cart refuses before before_install (an add-on file it cannot read,
 * the PHP version, a missing dependency) runs none of the add-on's code and stays unseen.
 *
 * Pure (unit-tested without CS-Cart); func.php fn_quissly_search_install_facts gathers the facts.
 */
final class InstallReport
{
    public const REPORT = 'install';
    public const PING_STATE = 'install_ping';
    public const PING_PREFIX = 'plugin_installed ';
    public const PING_RETRY = 3600;
    public const FAILED_PREFIX = 'plugin_install_failed ';

    /**
     * Whether this version report is the store's first one.
     *
     * @param array<string, mixed> $versionState VersionReport's stored record before this report
     */
    public static function isFirst(array $versionState): bool
    {
        return (int) ($versionState['reported_at'] ?? 0) <= 0;
    }

    /**
     * The keys added to the first version report's metadata.
     *
     * @param array<string, mixed> $facts what fn_quissly_search_install_facts found
     * @param array<string, mixed> $errors ErrorReport's stored record
     * @return array{report: string, environment: array<string, mixed>}
     */
    public static function metadata(array $facts, array $errors): array
    {
        $environment = self::tidy($facts, 2);
        $environment['problems_before_connect'] = is_array($errors['problems'] ?? null) ? count($errors['problems']) : 0;

        return ['report' => self::REPORT, 'environment' => $environment];
    }

    /**
     * Whether the "installed" message is still to be sent now.
     *
     * @param array<string, mixed> $ping the stored record: {sent: bool, tried_at: int}
     */
    public static function pingDue(array $ping, int $now): bool
    {
        return empty($ping['sent']) && $now - (int) ($ping['tried_at'] ?? 0) >= self::PING_RETRY;
    }

    /**
     * @param array<string, mixed> $ping
     * @return array<string, mixed>
     */
    public static function afterPing(array $ping, bool $delivered, int $now): array
    {
        return ['sent' => !empty($ping['sent']) || $delivered, 'tried_at' => $now];
    }

    /**
     * The "installed" message: the prefix, then the store, the versions and the facts as JSON.
     *
     * @param array<string, mixed>  $facts    what fn_quissly_search_install_facts found
     * @param array<string, string> $versions {shop, service, version, platform_version, php_version}
     */
    public static function pingText(array $facts, array $versions): string
    {
        $body = ['platform' => \Quissly\Search\Events\VersionReport::PLATFORM]
            + array_map('strval', $versions)
            + ['environment' => self::tidy($facts, 2)];
        $text = self::PING_PREFIX . (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_substr($text, 0, ErrorReport::MAX_TEXT);
    }

    /**
     * What a failed installation tells Quissly.
     *
     * @param list<string>              $notices   CS-Cart's error and warning notices of the request
     * @param array<string, mixed>|null $lastError PHP's error_get_last()
     * @param array<string, string>     $versions  {shop, version, platform_version, php_version}
     * @param string                    $root      the store's folder, taken out of messages
     */
    public static function failureText(array $notices, ?array $lastError, array $versions, string $root): string
    {
        $body = ['platform' => \Quissly\Search\Events\VersionReport::PLATFORM] + array_map('strval', $versions);
        $body['notices'] = array_values(array_filter(array_map(
            static fn ($notice): string => ErrorReport::clean((string) $notice, $root, 1000),
            array_slice($notices, 0, 10)
        ), static fn (string $notice): bool => $notice !== ''));
        if (is_array($lastError) && isset($lastError['message'])) {
            $body['php_error'] = ErrorReport::clean((string) $lastError['message'], $root, 1000)
                . ' @ ' . ErrorReport::clean((string) ($lastError['file'] ?? ''), $root, 300) . ':' . (int) ($lastError['line'] ?? 0);
        }
        $text = self::FAILED_PREFIX . (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_substr($text, 0, ErrorReport::MAX_TEXT);
    }

    /**
     * Scalars and small lists of them only, strings cut short: the report stays small and plain.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function tidy(array $value, int $depth): array
    {
        $out = [];
        foreach (array_slice($value, 0, 60, true) as $key => $item) {
            if (is_array($item) && $depth > 0) {
                $out[$key] = self::tidy($item, $depth - 1);
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $out[$key] = $item;
            } elseif (is_scalar($item)) {
                $out[$key] = ErrorReport::clean((string) $item, '', 100);
            }
        }

        return $out;
    }

    private function __construct()
    {
    }
}
