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

    /**
     * The product picker over each code box (js/addons/quissly_search/most-loved-picker.js, the
     * Magento plugin's file): search by name or code, choose, drag to reorder. The textarea stays
     * what the form saves; $script (the picker, inlined) is printed once, after both lists.
     *
     * @param array<string, list<array{sku:string, name:string, image:string, note:string}>> $items 'shown' / 'backups', in order
     * @param array<string, string> $text the words the picker writes
     */
    public static function pickers(array $items, string $searchUrl, array $text, string $script): string
    {
        $html = '';
        foreach (['shown' => \Quissly\Search\MostLoved::SHOWN, 'backups' => \Quissly\Search\MostLoved::BACKUPS] as $list => $max) {
            $config = [
                // CS-Cart's field ids end in the setting's object id: container_<prefix>_most_loved_shown_<id>.
                'field'     => '[id^="container_"][id*="_most_loved_' . $list . '_"] textarea',
                'list'      => $list,
                'max'       => $max,
                'searchUrl' => $searchUrl,
                'items'     => $items[$list] ?? [],
                'text'      => $text + ['inOther' => $list === 'shown' ? ($text['inBackups'] ?? '') : ($text['inShown'] ?? '')],
            ];
            $html .= '<div class="q-picker" data-q-picker data-config="' . self::e((string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"></div>';
        }
        // Each picker goes under its own box, and the plain list of picks gives way to it.
        $place = '(function(){document.querySelectorAll(".q-picker[data-q-picker]:not([data-q-placed])").forEach(function(root){'
            . 'root.setAttribute("data-q-placed","1");var c=JSON.parse(root.getAttribute("data-config")||"{}");'
            . 'var f=document.querySelector(c.field);if(f){f.parentNode.insertBefore(root,f.nextSibling);}});'
            . 'document.querySelectorAll(".q-most-loved__list").forEach(function(l){l.hidden=true;});})();';

        return $html . '<script>' . $place . str_replace('</script', '<\/script', $script) . '</script>';
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
