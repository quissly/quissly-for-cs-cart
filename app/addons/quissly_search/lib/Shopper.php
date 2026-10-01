<?php

declare(strict_types=1);

namespace Quissly\Search;

/**
 * Who is searching - the shopper details sent with a search, in the Shopify app's scheme
 * so every platform reports shoppers
 * alike:
 *
 *   user_id  customer:<id>  a signed-in customer - stable across devices and sessions;
 *            guest:<uuid>   an anonymous browser - a UUID kept in a first-party cookie for a
 *                           year. One device, not one person.
 *            null           a guest without consent to performance cookies on a store running
 *                           CS-Cart's GDPR add-on (its Klaro banner lists this add-on as a
 *                           service): no cookie is read or set - as the Shopify app withholds its
 *                           visitor id when analytics consent is refused.
 *   device / os             from the browser's User-Agent (text search only, as Shopify).
 *
 * No session-length id: the session hash used before 2026-09-30 changed with every session,
 * so one returning shopper counted as many "users" - the reason the Shopify app dropped
 * Shopify's session cookie too. Pure - the cookie itself is read and set by
 * SearchInterceptor::resolveUserId().
 */
final class Shopper
{
    public const COOKIE = 'quissly_uid';
    public const CUSTOMER_PREFIX = 'customer:';
    public const GUEST_PREFIX = 'guest:';
    /** This add-on's service in the GDPR add-on's Klaro banner (schemas/gdpr/klaro_config.post.php). */
    public const KLARO_SERVICE = 'quissly_search';

    /** The id of a signed-in customer. */
    public static function customerId(int $userId): string
    {
        return self::CUSTOMER_PREFIX . $userId;
    }

    /** The id of an anonymous browser, from its cookie; null when the cookie is not ours. */
    public static function guestId(string $cookie): ?string
    {
        return self::isUuid($cookie) ? self::GUEST_PREFIX . strtolower($cookie) : null;
    }

    /**
     * Only a UUID v4 - the shape newUuid() mints - is trusted: the cookie is client-supplied,
     * and a free-form value would let a visitor pick (or replay) an identity.
     */
    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * May a guest be identified? The GDPR add-on's banner (Klaro) keeps each service's answer
     * in its `klaro` cookie as JSON ({"quissly_search": true, ...}). Without an answer it
     * follows the store's policy: "explicit" consent = not until accepted, "implicit" = until
     * declined; no GDPR add-on (or policy "none") = yes.
     *
     * @param string|null $policy      addons.gdpr.gdpr_cookie_consent, null when the add-on is off
     * @param string      $klaroCookie the `klaro` cookie, '' when absent
     */
    public static function guestAllowed(?string $policy, string $klaroCookie): bool
    {
        if ($policy === null || $policy === 'none') {
            return true;
        }
        $consents = json_decode($klaroCookie, true);
        if (is_array($consents) && array_key_exists(self::KLARO_SERVICE, $consents)) {
            return $consents[self::KLARO_SERVICE] === true;
        }

        return $policy !== 'explicit';
    }

    /** A fresh UUID v4. */
    public static function newUuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * The shopper's device and OS (QueryRequestV2 `device` / `os`). `os` is '' when unknown
     * (then not sent); anything unrecognised counts as a desktop.
     *
     * @return array{device: string, os: string}
     */
    public static function deviceAndOs(string $userAgent): array
    {
        $ua = strtolower($userAgent);
        if (strpos($ua, 'ipad') !== false) {
            return ['device' => 'tablet', 'os' => 'iOS'];
        }
        if (strpos($ua, 'iphone') !== false) {
            return ['device' => 'mobile', 'os' => 'iOS'];
        }
        if (strpos($ua, 'android') !== false) {
            return ['device' => strpos($ua, 'mobile') !== false ? 'mobile' : 'tablet', 'os' => 'Android'];
        }
        if (strpos($ua, 'mac os x') !== false) {
            return ['device' => 'desktop', 'os' => 'macOS'];
        }
        if (strpos($ua, 'windows nt') !== false) {
            return ['device' => 'desktop', 'os' => 'Windows'];
        }
        if (strpos($ua, 'linux') !== false) {
            return ['device' => 'desktop', 'os' => 'Linux'];
        }

        return ['device' => 'desktop', 'os' => ''];
    }

    private function __construct()
    {
    }
}
