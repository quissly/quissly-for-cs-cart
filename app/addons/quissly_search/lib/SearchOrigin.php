<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * Which engine answered this search (port of the Magento plugin's SearchDiagnosticHeader
 * + SearchOrigin): the X-Quissly-Search header and a small badge, both ONLY on a request
 * carrying ?quissly_debug=1. The codes can be auth/billing ones, which belong to the
 * merchant, not to a shopper - so it is opt-in per request and a shopper never sees it.
 *
 * The header is sent from the dispatch_before_display hook (after products.search ran,
 * before any output); the badge is drawn at the end of the page (hooks/index/footer.post),
 * fixed bottom-LEFT: the chat launcher owns the bottom-right corner.
 */
final class SearchOrigin
{
    public const DEBUG_PARAM = 'quissly_debug';

    /** @param array<string, mixed> $request */
    public static function asked(array $request): bool
    {
        return isset($request[self::DEBUG_PARAM]);
    }

    /**
     * The badge, or '' when it should not show.
     *
     * @param array{quissly:string, native:string, skipped:string} $labels skipped holds [reason]
     */
    public static function badge(bool $asked, array $labels): string
    {
        if (!$asked || SearchSignal::headerValue() === null) {
            return '';
        }
        $byQuissly = SearchSignal::servedByQuissly();
        $colors = $byQuissly ? 'background:#EEF0FE;border-color:#6366f1;color:#2C2F6B' : 'background:#FDF3E7;border-color:#E8A33D;color:#6B4A16';
        $html = '<div class="quissly-origin quissly-origin--' . ($byQuissly ? 'quissly' : 'cscart') . '" role="status"'
            . ' style="position:fixed;left:16px;bottom:16px;z-index:99999;max-width:360px;padding:8px 14px;border-radius:8px;border-left:4px solid;'
            . 'font:600 13px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.12);' . $colors . '">'
            . self::e($byQuissly ? $labels['quissly'] : $labels['native']);
        $reason = SearchSignal::skipReason();
        if ($reason !== null) {
            $html .= '<span class="quissly-origin__why" style="display:block;margin-top:2px;font-weight:400">'
                . self::e(str_replace('[reason]', $reason, $labels['skipped'])) . '</span>';
        }

        return $html . '</div>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function __construct()
    {
    }
}
