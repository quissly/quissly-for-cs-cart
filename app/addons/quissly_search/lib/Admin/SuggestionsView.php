<?php

declare(strict_types=1);

namespace Quissly\Search\Admin;

use Quissly\Search\SearchSuggestions;

/**
 * Configuration's two suggestion blocks, with the Quissly Shopify app's names and layout. Both
 * live in Quissly (SearchSuggestions) and are drawn INSIDE CS-Cart's settings form, so the page's
 * own Save sends them: controllers/backend/addons.pre.php writes what changed back.
 *
 *  - examples(): "Search Examples", the queries the overlay types into its empty bar - "Show
 *    typing suggestions", then one list per storefront language (a Language select when there
 *    is more than one), a row per example with Remove, "Add a suggestion", "N of 20" and "Reset
 *    to generated". Every language's list goes back in one hidden field as JSON.
 *  - buttons(): "Search Suggestions", the buttons under the empty bar - a "Suggestions" select
 *    (Automatic / Manual) with a note; Automatic shows what the shop shows now, read-only, per
 *    language; Manual a field per suggestion per language ("fill one and the next field
 *    appears"), also one hidden JSON field.
 *
 * The editing runs in js/addons/quissly_search/config.js and is styled by
 * design/backend/css/addons/quissly_search/config.css; the handler passes both in ($assets),
 * inlined after the markup, so they also run when the form is loaded into a dialog.
 *
 * Pure: takes what Quissly holds (null = unreadable) and the store's languages, returns escaped HTML.
 */
final class SuggestionsView
{
    /**
     * What the 1.0.2-era settings handler appends after render() (see render()): empty, so an
     * automatic update from such a store does not fail while its old func.php is in memory.
     */
    public const SWITCH_HTML = '';

    /**
     * @param array<string, mixed>|null $current   SearchSuggestions::fromConfig() (null = unreadable)
     * @param array<string, string>     $languages language key => name, the main language first
     * @param list<string>              $generated the main language's generated list (Quissly's, else what it last answered here)
     * @param bool                      $pending   generation still to come
     * @param string                    $assets    the editor's inlined script and style
     */
    public static function examples(?array $current, array $languages, array $generated = [], bool $pending = false, string $assets = ''): string
    {
        $intro = '<p class="muted description" style="max-width:760px">' . self::e(self::t('quissly_search.suggestions_intro')) . '</p>';
        if ($current === null) {
            return $intro . '<p><em>' . self::e(self::t('quissly_search.suggestions_unavailable')) . '</em></p>';
        }
        $codes = self::codes($languages, array_keys((array) $current['by_language']));
        $main = $codes[0];
        $models = [];
        foreach ($codes as $i => $code) {
            $own = $i === 0 ? (array) $current['queries'] : (array) ($current['by_language'][$code] ?? []);
            $models[] = [
                'code'      => $code,
                'label'     => self::label($languages, $code, $i === 0),
                'queries'   => array_values($own),
                'generated' => $i === 0
                    ? array_values($generated)
                    : array_values((array) ($current['generated_by_language'][$code] ?? [])),
                'note'      => $i === 0 && $own === [] && $pending ? self::t('quissly_search.suggestions_generated_pending') : '',
            ];
        }
        $config = [
            'main'      => $main,
            'max'       => SearchSuggestions::MAX_COUNT,
            'maxLength' => SearchSuggestions::MAX_LENGTH,
            'languages' => $models,
            'text'      => [
                'remove'     => self::t('quissly_search.suggestions_remove'),
                'count'      => str_replace(['[count]', '[max]'], ['%1', '%2'], self::t('quissly_search.suggestions_count')),
                'emptyMain'  => self::t('quissly_search.suggestions_empty_main'),
                'emptyOther' => self::t('quissly_search.suggestions_empty_other'),
                'tooLong'    => str_replace('[length]', '%1', self::t('quissly_search.suggestions_too_long')),
            ],
        ];

        return $intro
            . '<input type="hidden" name="quissly_suggestions_present" value="1">'
            . '<label class="checkbox" style="margin:0 0 14px"><input type="checkbox" name="quissly_typing_enabled" value="Y"' . ($current['enabled'] ? ' checked' : '') . '> '
            . self::e(self::t('quissly_search.suggestions_enabled')) . '</label>'
            . '<div class="q-suggestions" data-q-suggestions data-config="' . self::e(self::json($config)) . '">'
            . self::languageSelect('quissly_examples_language', $models)
            . '<input type="hidden" name="quissly_examples" value="" data-q-value>'
            . '<div class="q-rows" data-q-rows aria-live="polite"></div>'
            . '<label class="q-suggestions__add-label" for="quissly_examples_new">' . self::e(self::t('quissly_search.suggestions_add_label')) . '</label>'
            . '<div class="q-suggestions__add">'
            . '<input type="text" class="input-large" maxlength="' . SearchSuggestions::MAX_LENGTH . '" autocomplete="off" data-q-new id="quissly_examples_new" placeholder="'
            . self::e(self::t('quissly_search.suggestions_placeholder')) . '">'
            . '<button type="button" class="btn" data-q-add>' . self::e(self::t('quissly_search.suggestions_add')) . '</button>'
            . '</div>'
            . '<p class="q-suggestions__error" data-q-error hidden></p>'
            . '<p class="note"><span class="q-suggestions__count" data-q-count></span></p>'
            . '<p class="note" data-q-note hidden></p>'
            . '<p><button type="button" class="btn" data-q-reset hidden>' . self::e(self::t('quissly_search.suggestions_reset')) . '</button></p>'
            . '</div>' . $assets;
    }

    /**
     * @param array<string, mixed>|null      $current   SearchSuggestions::fromConfig() (null = unreadable)
     * @param array<string, string>          $languages language key => name, the main language first
     * @param array{mode:string, lists:array<string, list<string>>} $local the add-on's copy, used while Quissly does not hold the choice
     * @param string                         $assets    the editor's inlined script and style
     */
    public static function buttons(?array $current, array $languages, array $local, string $assets = ''): string
    {
        $inQuissly = $current !== null && ($current['chips_mode'] ?? '') !== '';
        $mode = $inQuissly ? (string) $current['chips_mode'] : $local['mode'];
        $lists = $inQuissly ? (array) $current['manual_chips'] : $local['lists'];
        $codes = self::codes($languages, array_keys($lists));

        $automatic = self::t('quissly_search.buttons_note_automatic');
        $manual = self::t('quissly_search.buttons_note_manual');
        $options = '';
        foreach (['automatic', 'manual'] as $value) {
            $options .= '<option value="' . $value . '"' . ($mode === $value ? ' selected' : '') . '>'
                . self::e(self::t('quissly_search.buttons_mode_' . $value)) . '</option>';
        }
        $models = [];
        foreach ($codes as $i => $code) {
            $models[] = ['code' => $code, 'label' => self::label($languages, $code, $i === 0), 'queries' => array_values((array) ($lists[$code] ?? []))];
        }
        $config = [
            'max'       => SearchSuggestions::MAX_CHIPS,
            'maxLength' => SearchSuggestions::MAX_LENGTH,
            'languages' => $models,
            'text'      => [
                'field'       => str_replace('[n]', '%1', self::t('quissly_search.buttons_field')),
                'placeholder' => self::t('quissly_search.buttons_placeholder'),
            ],
        ];

        return '<input type="hidden" name="quissly_buttons_present" value="1">'
            . '<div data-q-mode>'
            . '<label for="quissly_chips_mode" style="font-weight:600;display:block;margin-bottom:6px">' . self::e(self::t('quissly_search.buttons_mode')) . '</label>'
            . '<select id="quissly_chips_mode" name="quissly_chips_mode">' . $options . '</select>'
            . '<p class="muted description" data-q-mode-note data-automatic="' . self::e($automatic) . '" data-manual="' . self::e($manual) . '">'
            . self::e($mode === 'manual' ? $manual : $automatic) . '</p>'
            . self::automaticButtons($current, $codes, $languages, $mode)
            . '<div class="q-suggestions" data-q-manual' . ($mode === 'manual' ? '' : ' hidden') . '>'
            . '<div data-q-fields data-config="' . self::e(self::json($config)) . '">'
            . self::languageSelect('quissly_buttons_language', $models)
            . '<input type="hidden" id="quissly_chips_manual" name="quissly_chips_manual" value="" data-q-value>'
            . '<div class="q-fields" data-q-list></div>'
            . '</div></div>'
            . '</div>' . $assets;
    }

    /**
     * What Automatic shows on the shop now, read-only: Quissly's generated buttons per language.
     *
     * @param array<string, mixed>|null $current
     * @param list<string>              $codes
     * @param array<string, string>     $languages
     */
    private static function automaticButtons(?array $current, array $codes, array $languages, string $mode): string
    {
        $html = '<div class="q-auto-buttons" data-q-auto' . ($mode === 'manual' ? ' hidden' : '') . '>';
        if ($current === null) {
            return $html . '<p class="muted description">' . self::e(self::t('quissly_search.buttons_not_connected')) . '</p></div>';
        }
        $rows = '';
        foreach ($codes as $code) {
            $list = SearchSuggestions::pickGenerated($current, $code, $codes[0]);
            if ($list === []) {
                continue;
            }
            $rows .= '<div class="q-auto-buttons__row">';
            if (count($codes) > 1) {
                $rows .= '<span class="q-auto-buttons__language">' . self::e($languages[$code] ?? $code) . '</span>';
            }
            foreach ($list as $text) {
                $rows .= '<span class="q-auto-buttons__button">' . self::e($text) . '</span>';
            }
            $rows .= '</div>';
        }
        if ($rows === '') {
            return $html . '<p class="muted description">' . self::e(self::t('quissly_search.buttons_none_yet')) . '</p></div>';
        }

        return $html . '<p class="q-auto-buttons__title">' . self::e(self::t('quissly_search.buttons_shown_now')) . '</p>' . $rows . '</div>';
    }

    /**
     * The Language select, when there is more than one language.
     *
     * @param list<array{code:string, label:string}> $models
     */
    private static function languageSelect(string $id, array $models): string
    {
        if (count($models) < 2) {
            return '';
        }
        $options = '';
        foreach ($models as $model) {
            $options .= '<option value="' . self::e($model['code']) . '">' . self::e($model['label']) . '</option>';
        }

        return '<div class="q-suggestions__language">'
            . '<label for="' . self::e($id) . '">' . self::e(self::t('quissly_search.suggestions_language')) . '</label>'
            . '<select id="' . self::e($id) . '" data-q-language>' . $options . '</select>'
            . '<p class="note">' . self::e(self::t('quissly_search.suggestions_language_note')) . '</p></div>';
    }

    /**
     * What 1.0.2's settings handler calls. An automatic update from 1.0.2 runs CS-Cart's
     * reinstall in the same request as the old func.php, whose "Search bar suggestions" handler
     * still calls this while the settings are rebuilt; what it returns is thrown away, so it
     * only has to exist (without it the update fails and rolls back). Remove once no store
     * runs 1.0.2.
     *
     * @param array<string, mixed>|null $current
     * @param list<string>              $generated
     */
    public static function render(?array $current = null, array $generated = [], bool $pending = false): string
    {
        return '';
    }

    /**
     * The store's languages, main first, plus any Quissly already holds a list for.
     *
     * @param array<string, string> $languages
     * @param list<string|int>      $extra
     * @return list<string>
     */
    private static function codes(array $languages, array $extra): array
    {
        $codes = array_map('strval', array_keys($languages));
        foreach ($extra as $code) {
            $codes[] = (string) $code;
        }
        $codes = array_values(array_unique(array_filter($codes, 'strlen')));

        return $codes !== [] ? $codes : ['en'];
    }

    /** @param array<string, string> $languages */
    private static function label(array $languages, string $code, bool $main): string
    {
        $name = $languages[$code] ?? $code;

        return $main ? str_replace('[language]', $name, self::t('quissly_search.suggestions_language_default')) : $name;
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
