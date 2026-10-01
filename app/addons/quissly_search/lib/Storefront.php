<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * What the storefront pages get from the add-on: the search overlay, the voice /
 * image buttons, and the QChat widget — decided here, rendered by the theme hook
 * (hooks/index/scripts.post.tpl), run by js/addons/quissly_search/*.js.
 *
 * Rules (as in the WooCommerce plugin):
 *  - the overlay and the voice/image buttons exist ONLY where Quissly is actually
 *    answering searches (connected, first sync confirmed, QSearch on): a Quissly
 *    search surface over a storefront Quissly is not running would promise results
 *    the page cannot deliver;
 *  - the overlay switch governs one thing — whether an EXISTING theme search box is
 *    taken over. A theme with no search box gets the overlay anyway (the browser
 *    decides; the server cannot know), since otherwise the shop cannot be searched;
 *  - QChat needs its switch and the account's agent id (the public id — never a key);
 *    with it comes the cart bridge that makes the chat's "Add to Cart" add to the
 *    store's own cart.
 *
 * Pure: takes the facts, returns the template's variables.
 */
final class Storefront
{
    public const QCHAT_SCRIPT = 'https://cdn.quissly.com/scripts/universal_cscart.js';

    /**
     * Theme search inputs, most specific first (CS-Cart responsive / bright_theme;
     * then generic fallbacks).
     */
    public const SEARCH_SELECTORS = [
        'form[name="search_form"] input[name="q"]',
        '#search_input',
        '.ty-search-block__input',
        'input[name="q"][type="text"]',
    ];

    /**
     * @param array{
     *   configured:bool, gate_open:bool, features:list<array{id:string, on:bool}>, agent_id:string,
     *   urls:array{voice:string, image:string, results:string, cart_add?:string, cart_adjust?:string, cart_resolve?:string},
     *   security_hash:string, i18n:array<string,string>, mount_selector?:string, suggestions?:list<string>
     * } $in
     * @return array{overlay:bool, overlay_json:string, media:bool, media_json:string, qchat_agent_id:string, qchat_script:string, cart_json:string}
     */
    public static function build(array $in): array
    {
        $on = array_column($in['features'], 'on', 'id');
        $live = $in['configured'] && $in['gate_open'] && !empty($on['search_interception']);
        $voice = $live && !empty($on['enable_voice']);
        $image = $live && !empty($on['enable_image']);
        $agent = $in['configured'] && !empty($on['enable_qchat']) ? trim($in['agent_id']) : '';

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        return [
            'overlay'        => $live,
            'overlay_json'   => $live ? (string) json_encode([
                'immersive'        => !empty($on['enable_overlay']),
                'resultsUrl'       => $in['urls']['results'],
                'searchSelectors'  => self::SEARCH_SELECTORS,
                'labelTitle'       => $in['i18n']['search'] ?? 'Search',
                'labelPlaceholder' => $in['i18n']['placeholder'] ?? 'Search products…',
                'labelClose'       => $in['i18n']['close'] ?? 'Close',
                // Theme with no search box: the header control the search button sits
                // beside (a CSS selector); '' = auto-detect cart, account, language.
                'mountSelector'    => trim((string) ($in['mount_selector'] ?? '')),
                // Search bar suggestions, typed into the empty bar (SearchSuggestions).
                'suggestions'      => array_values(array_filter((array) ($in['suggestions'] ?? []), 'is_string')),
            ], $flags) : '',
            'media'          => $voice || $image,
            'media_json'     => $voice || $image ? (string) json_encode([
                'enableVoice'     => $voice,
                'enableImage'     => $image,
                'voiceUrl'        => $in['urls']['voice'],
                'imageUrl'        => $in['urls']['image'],
                'resultsUrl'      => $in['urls']['results'],
                'securityHash'    => $in['security_hash'],
                'searchSelectors' => self::SEARCH_SELECTORS,
                'voiceQueryVar'   => Media\MediaEndpoint::QUERY_VAR_VOICE,
                'imageQueryVar'   => Media\MediaEndpoint::QUERY_VAR_IMAGE,
                'voiceMaxMs'      => 5000,
                'imageMaxEdge'    => 1024,
                'i18n'            => $in['i18n'],
            ], $flags) : '',
            'qchat_agent_id' => $agent,
            'qchat_script'   => self::QCHAT_SCRIPT,
            // The chat's "Add to Cart" -> the store's cart (js/addons/quissly_search/cart.js).
            'cart_json'      => $agent !== '' ? (string) json_encode([
                'addUrl'       => $in['urls']['cart_add'] ?? '',
                'adjustUrl'    => $in['urls']['cart_adjust'] ?? '',
                'resolveUrl'   => $in['urls']['cart_resolve'] ?? '',
                'securityHash' => $in['security_hash'],
            ], $flags) : '',
        ];
    }

    private function __construct()
    {
    }
}
