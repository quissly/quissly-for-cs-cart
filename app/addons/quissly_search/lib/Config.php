<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * Static configuration for the Quissly QSearch integration.
 *
 * The endpoint PATH lives here in exactly one place. It is part of the signed
 * string, so it MUST match byte-for-byte between the request URL and the
 * signature — a mismatch returns 401 with no other symptom.
 *
 * These are non-secret transport constants. Secrets (bearer token, private key,
 * environment) are read from config.local.php via {@see Credentials}.
 */
final class Config
{
    /** API base URL (no trailing slash). */
    public const BASE_URL = 'https://api.quissly.com';

    /** Endpoint path — part of the signed string. One source of truth. */
    public const QSEARCH_PATH = '/v2beta/qsearch';

    /** HTTP method, also part of the signed string. */
    public const METHOD = 'POST';

    /** Default environment the credential is registered under. */
    public const DEFAULT_ENVIRONMENT = 'prod';

    /** Hard timeout budget for a QSearch call, in seconds. */
    public const TIMEOUT_SECONDS = 2.5;

    /** Minimum trimmed query length before we intercept. */
    public const MIN_QUERY_LENGTH = 2;

    /** Maximum query length we will send (Quissly 400s on overflow). */
    public const MAX_QUERY_LENGTH = 150;

    /** Channel sent in the request body. */
    public const CHANNEL = 'web';

    /**
     * X-Platform (header only, NOT signed), in ONE constant.
     *
     * LIVE-CONFIRMED 2026-09-21 against api.quissly.com: the value is 'cs-cart'
     * WITH a hyphen. The enum is 'shopify', 'woocommerce', 'magento', 'internal',
     * 'cs-cart'; the unhyphenated 'cscart' this repo's docs used to mandate returns
     * 422 on EVERY search. (The console's provisioning endpoint accepts 'cscart' in
     * its body - the two services disagree; raised with Quissly.)
     */
    public const PLATFORM = 'cs-cart';

    /** Fallback page size when CS-Cart hands us a zero/empty items-per-page. */
    public const DEFAULT_PAGE_SIZE = 20;

    /** Voice (qsearch + audio) and image (qimage) paths; both v2-signed like text search. */
    public const QIMAGE_PATH = '/v2beta/qimage';

    /** Voice/image: Quissly transcribes or embeds first, so far slower than a text search. */
    public const MEDIA_TIMEOUT_SECONDS = 15;

    /** Quissly's console (account services: the admin panel's sign-in). No signature. */
    public const CONSOLE_URL = 'https://console.quissly.com';

    /** The Quissly admin panel embedded on the add-on's "Quissly Admin Panel" page. */
    public const PANEL_URL = 'https://admin.quissly.com';

    /**
     * The platform sent to the CONSOLE's store sign-in. LIVE 2026-09-21: it first
     * accepted only shopify | magento | woocommerce; Quissly then added 'cs-cart'
     * (hyphenated, like the search API's X-Platform) and now rejects 'cscart' with 422
     * — although the open-source provisioning call registered this store with
     * 'cscart'. One constant to change if the console spelling moves again.
     */
    public const CONSOLE_PLATFORM = 'cs-cart';

    /** Full request URL. */
    public static function url(): string
    {
        return self::BASE_URL . self::QSEARCH_PATH;
    }

    private function __construct()
    {
    }
}
