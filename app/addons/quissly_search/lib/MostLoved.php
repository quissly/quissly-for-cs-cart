<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * Most loved products: the merchant's picks, shown in the search overlay when it opens (the
 * Quissly Shopify app's "Most loved products"; the WooCommerce and Magento plugins have the same).
 *
 * The merchant picks 8 products to show and 4 backups by product code (settings most_loved_shown
 * / most_loved_backups, one per line). The overlay shows 8, in order: the shown ones that can be
 * bought, each one that cannot replaced by the first available backup. With fewer than 8 + 4
 * picked, a product in both lists, or fewer than 8 that can be bought between them, nothing is
 * shown (the Shopify app's rules). Configuration says what needs fixing (problems()).
 *
 * Pure: the store's side (loading products, the cards) is in func.php.
 */
final class MostLoved
{
    /** Products shown, and backups for the ones that cannot be bought. */
    public const SHOWN = 8;
    public const BACKUPS = 4;

    /** The storefront's cards are kept this long (state row CACHE). */
    public const CACHE = 'most_loved_cards';
    public const CACHE_SECONDS = 300;

    /**
     * Product codes as typed: one per line (or comma-separated), trimmed, a repeat dropped, at
     * most $max.
     *
     * @return list<string>
     */
    public static function codes(string $raw, int $max): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $code) {
            $code = trim($code);
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return array_slice($out, 0, $max);
    }

    /**
     * The 8 to show, in order, or none.
     *
     * @param list<string>             $shown
     * @param list<string>             $backups
     * @param callable(string): bool   $available can be bought now
     * @return list<string>
     */
    public static function pick(array $shown, array $backups, callable $available): array
    {
        if (count($shown) !== self::SHOWN || count($backups) !== self::BACKUPS || self::duplicates($shown, $backups) !== []) {
            return [];
        }
        $out = [];
        foreach (array_merge($shown, $backups) as $code) {
            if (count($out) < self::SHOWN && !in_array($code, $out, true) && $available($code)) {
                $out[] = $code;
            }
        }

        return count($out) === self::SHOWN ? $out : [];
    }

    /**
     * What the merchant has to fix: picks still to make, products picked in both lists, and
     * picks that cannot be shown, with why. Null when nothing.
     *
     * @param list<string>                                        $shown
     * @param list<string>                                        $backups
     * @param callable(string): array{title:string, reason:string} $state reason '' when it can be shown,
     *                                                                     else not_found | not_visible | out_of_stock
     * @return array{missing_shown:int, missing_backups:int, duplicates:list<string>, problems:list<array{code:string, title:string, backup:bool, reason:string}>, hidden:bool}|null
     */
    public static function problems(array $shown, array $backups, callable $state): ?array
    {
        $problems = [];
        $ok = [];
        foreach ([[$shown, false], [$backups, true]] as [$codes, $backup]) {
            foreach ($codes as $code) {
                $product = $state($code);
                if ($product['reason'] === '') {
                    $ok[$code] = true;
                } else {
                    $problems[] = ['code' => $code, 'title' => $product['title'], 'backup' => $backup, 'reason' => $product['reason']];
                }
            }
        }
        $duplicates = [];
        foreach (self::duplicates($shown, $backups) as $code) {
            $duplicates[] = $state($code)['title'];
        }
        $missingShown = max(0, self::SHOWN - count($shown));
        $missingBackups = max(0, self::BACKUPS - count($backups));
        if ($missingShown === 0 && $missingBackups === 0 && $duplicates === [] && $problems === []) {
            return null;
        }

        return [
            'missing_shown'   => $missingShown,
            'missing_backups' => $missingBackups,
            'duplicates'      => $duplicates,
            'problems'        => $problems,
            'hidden'          => $missingShown > 0 || $missingBackups > 0 || $duplicates !== [] || count($ok) < self::SHOWN,
        ];
    }

    /**
     * The backups that are also picked to show: each product goes in one list only (the Shopify
     * app's rule - nothing is shown until it is fixed).
     *
     * @param list<string> $shown
     * @param list<string> $backups
     * @return list<string>
     */
    public static function duplicates(array $shown, array $backups): array
    {
        return array_values(array_filter($backups, static fn (string $code): bool => in_array($code, $shown, true)));
    }

    /**
     * A price as the store writes it in a currency (CS-Cart's currency row: symbol, after Y/N,
     * decimals, decimals_separator, thousands_separator) - "$1,299.00", "12,50 €".
     *
     * @param array<string, mixed> $currency
     */
    public static function formatPrice(float $value, array $currency): string
    {
        $number = number_format(
            $value,
            max(0, (int) ($currency['decimals'] ?? 2)),
            (string) ($currency['decimals_separator'] ?? '.'),
            (string) ($currency['thousands_separator'] ?? ',')
        );
        $symbol = (string) ($currency['symbol'] ?? '');

        return ($currency['after'] ?? 'N') === 'Y' ? trim($number . ' ' . $symbol) : $symbol . $number;
    }

    /**
     * "3 colors available": how many options a product comes in, named after its first option
     * ("Color" -> "colors"); "3 options available" without one; '' for one.
     *
     * @param string $template the langvar's text with [count] and [option]
     */
    public static function variantsLabel(int $count, string $option, string $template, string $plain): string
    {
        if ($count < 2) {
            return '';
        }
        $option = trim($option);

        return $option !== ''
            ? str_replace(['[count]', '[option]'], [(string) $count, mb_strtolower($option) . 's'], $template)
            : str_replace('[count]', (string) $count, $plain);
    }

    private function __construct()
    {
    }
}
