<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * What the merchant is told when Quissly refuses the store's searches (401 / 402 / 403):
 * the reason by status, Quissly's own words, and - only for a key in config.local.php - where
 * to check it. It used to be one text for all three that sent every merchant to
 * config.local.php, also a store Setup connected whose plan Quissly did not have (a tester's
 * finding on 1.0.18: Manual invoice -> 403 "No active quota grant for this service").
 */
final class Refusal
{
    /**
     * @param callable(string, array<string, string>): string $t a langvar lookup (CS-Cart's __())
     * @param string $detail Quissly's reason ('' when it gave none)
     * @param string $source where the key comes from (Credentials::source())
     */
    public static function text(int $status, string $detail, string $source, callable $t): string
    {
        $key = in_array($status, [401, 402, 403], true) ? 'quissly_search.refused_' . $status : 'quissly_search.reason_auth_error';
        $parts = [$t($key, [])];
        if ($detail !== '') {
            $parts[] = $t('quissly_search.refused_said', ['[detail]' => $detail]);
        }
        if ($status === 401 && $source === Credentials::SOURCE_CONFIG) {
            $parts[] = $t('quissly_search.refused_check_config', []);
        }

        return implode(' ', $parts);
    }

    private function __construct()
    {
    }
}
