<?php

declare(strict_types=1);

namespace Quissly\Search\Admin;

use Quissly\Search\SearchSuggestions;

/**
 * Configuration's "Search bar suggestions" block (the Shopify app's): the queries the search
 * overlay types into its empty bar. They live in Quissly (SearchSuggestions); this shows what
 * Quissly holds now. Drawn INSIDE CS-Cart's settings form, so the page's own Save sends them:
 * controllers/backend/addons.pre.php writes a changed list back.
 *
 * Pure: render() takes the list read from Quissly (null = unreadable), returns escaped HTML.
 */
final class SuggestionsView
{
    private const COUNT_SCRIPT = '(function(){var t=document.getElementById("quissly_suggestions"),c=document.getElementById("quissly-suggestions-count");if(!t||!c){return;}function n(){var s={},k=0;t.value.split(/\r\n|\r|\n/).forEach(function(l){l=l.trim().toLowerCase();if(l&&!s[l]){s[l]=1;k++;}});c.textContent=k;c.parentNode.style.color=k>__MAX__?"#b32d2e":"";}t.addEventListener("input",n);n();})();';

    /**
     * @param array{enabled:bool, queries:list<string>}|null $current   what Quissly holds (null = unreadable)
     * @param list<string>                                   $generated the list generated from the catalog
     * @param bool                                           $pending   generation still to come
     */
    public static function render(?array $current, array $generated = [], bool $pending = false): string
    {
        $intro = '<p class="muted description" style="max-width:760px">' . self::e(self::t('quissly_search.suggestions_intro')) . '</p>';
        if ($current === null) {
            return $intro . '<p><em>' . self::e(self::t('quissly_search.suggestions_unavailable')) . '</em></p>';
        }

        return $intro
            . '<input type="hidden" name="quissly_suggestions_present" value="1">'
            . '<label class="checkbox" style="margin:0 0 10px"><input type="checkbox" name="quissly_typing_enabled" value="Y"' . ($current['enabled'] ? ' checked' : '') . '> '
            . self::e(self::t('quissly_search.suggestions_enabled')) . '</label>'
            . '<textarea id="quissly_suggestions" name="quissly_suggestions" rows="6" class="input-large" style="width:100%;max-width:520px" placeholder="'
            . self::e(self::t('quissly_search.suggestions_placeholder')) . '">' . self::e(implode("\n", $current['queries'])) . '</textarea>'
            . '<p class="muted description">' . self::e(str_replace('[length]', (string) SearchSuggestions::MAX_LENGTH, self::t('quissly_search.suggestions_hint'))) . '</p>'
            . self::countHtml(count($current['queries']))
            . self::generatedHtml($current['queries'], $generated, $pending);
    }

    /**
     * "N of 20" under the list (the Shopify app's), kept current while the merchant types:
     * distinct, non-empty lines - the save cleans the list the same way.
     */
    private static function countHtml(int $count): string
    {
        $text = str_replace(
            ['[count]', '[max]'],
            ['<span id="quissly-suggestions-count">' . $count . '</span>', (string) SearchSuggestions::MAX_COUNT],
            self::e(self::t('quissly_search.suggestions_count'))
        );

        return '<p class="muted description">' . $text . '</p>'
            . '<script>' . str_replace('__MAX__', (string) SearchSuggestions::MAX_COUNT, self::COUNT_SCRIPT) . '</script>';
    }

    /**
     * "Use the generated suggestions" (the Shopify app's Reset), or a note that they are coming.
     *
     * @param list<string> $current
     * @param list<string> $generated
     */
    private static function generatedHtml(array $current, array $generated, bool $pending): string
    {
        if ($generated !== [] && $generated !== $current) {
            return '<label class="checkbox" style="margin-top:10px"><input type="checkbox" name="quissly_suggestions_use_generated" value="Y"> '
                . self::e(self::t('quissly_search.suggestions_use_generated')) . '</label>'
                . '<p class="muted description">' . self::e(implode(' · ', $generated)) . '</p>';
        }
        if ($current === [] && $pending) {
            return '<p class="muted description">' . self::e(self::t('quissly_search.suggestions_generated_pending')) . '</p>';
        }

        return '';
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
