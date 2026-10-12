<?php

declare(strict_types=1);

namespace Quissly\Search\Admin;

/**
 * Configuration's Most Loved Products status: what each picked product code is, and what needs
 * fixing before the overlay can show them (MostLoved::problems()).
 *
 * Pure: takes the picks' states and the problems, returns escaped HTML.
 */
final class MostLovedView
{
    /**
     * @param bool                                                     $enabled
     * @param list<array{code:string, title:string, backup:bool, reason:string}> $picks every pick, in order
     * @param array{missing_shown:int, missing_backups:int, duplicates:list<string>, problems:list<array>, hidden:bool}|null $status
     */
    public static function render(bool $enabled, array $picks, ?array $status): string
    {
        $html = '<div class="q-most-loved"><p class="muted description">' . self::e(self::t('quissly_search.most_loved_intro')) . '</p>';
        if (!$enabled) {
            return $html . '</div>';
        }
        if ($picks !== []) {
            $html .= '<ol class="q-most-loved__list">';
            foreach ($picks as $pick) {
                $html .= '<li>' . self::e($pick['title'])
                    . ($pick['title'] !== $pick['code'] ? ' <span class="q-most-loved__sku">' . self::e($pick['code']) . '</span>' : '')
                    . ($pick['backup'] ? ' <span class="q-most-loved__sku">(' . self::e(self::t('quissly_search.most_loved_backup')) . ')</span>' : '')
                    . ($pick['reason'] !== '' ? ' <span class="q-most-loved__problem">' . self::e(self::t('quissly_search.most_loved_reason_' . $pick['reason'])) . '</span>' : '')
                    . '</li>';
            }
            $html .= '</ol>';
        }
        if ($status === null) {
            return $html . '<p class="q-most-loved__status">' . self::e(self::t('quissly_search.most_loved_ready')) . '</p></div>';
        }
        $items = '';
        if ($status['missing_shown'] > 0) {
            $items .= '<li>' . self::e(str_replace('[count]', (string) $status['missing_shown'], self::t('quissly_search.most_loved_missing_shown'))) . '</li>';
        }
        if ($status['missing_backups'] > 0) {
            $items .= '<li>' . self::e(str_replace('[count]', (string) $status['missing_backups'], self::t('quissly_search.most_loved_missing_backups'))) . '</li>';
        }
        foreach ($status['problems'] as $problem) {
            $items .= '<li>' . self::e($problem['title'] . ': ' . self::t('quissly_search.most_loved_reason_' . $problem['reason'])) . '</li>';
        }
        $duplicates = '';
        if ($status['duplicates'] !== []) {
            $duplicates = '<p>' . self::e(self::t('quissly_search.most_loved_duplicates')) . '</p><ul>';
            foreach ($status['duplicates'] as $title) {
                $duplicates .= '<li>' . self::e($title) . '</li>';
            }
            $duplicates .= '</ul>';
        }

        return $html . '<div class="q-most-loved__status"><strong class="q-most-loved__problem">'
            . self::e(self::t($status['hidden'] ? 'quissly_search.most_loved_hidden' : 'quissly_search.most_loved_backups_used'))
            . '</strong>' . $duplicates . ($items !== '' ? '<ul>' . $items . '</ul>' : '') . '</div></div>';
    }

    private static function t(string $key): string
    {
        return function_exists('__') ? (string) __($key) : $key;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function __construct()
    {
    }
}
