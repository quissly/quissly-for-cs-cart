<?php

declare(strict_types=1);

namespace Quissly\Search\Setup;

/**
 * What Quissly Setup accepts on its first step, as the Shopify app does.
 *
 * The workspace name: letters, numbers, spaces and hyphens (the Shopify app's
 * SLUG_SOURCE_PATTERN), suggested from the store name as its suggestOrganizationName() does.
 * The email: what Quissly's backend accepts (its pydantic EmailStr, mirrored by the Shopify app's
 * email-validation.ts), so a bad address is caught here rather than when the account is created.
 * js/addons/quissly_search/setup.js runs the same checks as the merchant types. Pure; the same
 * rules as the Magento and WooCommerce plugins.
 */
final class SetupInput
{
    /** The names the form accepts. */
    public const NAME_PATTERN = '/^[A-Za-z0-9 -]+$/';

    /** Domains Quissly's backend refuses as not globally routable, with their subdomains. */
    private const SPECIAL_USE_DOMAINS = ['arpa', 'invalid', 'local', 'localhost', 'onion', 'test'];

    /**
     * The store name as a workspace name the form accepts, or '' when too little is left.
     *
     * "&" becomes "and"; anything outside letters, numbers, spaces and hyphens is dropped. A
     * name in a non-Latin script gives '' rather than a mangled suggestion.
     *
     * @param string|null $storeName
     * @return string
     */
    public static function suggestName(?string $storeName): string
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', (string)$storeName));
        $name = str_replace('&', ' and ', $name);
        $name = (string)preg_replace('/[^A-Za-z0-9 -]/', '', $name);
        $name = (string)preg_replace('/\s+/', ' ', $name);
        $name = (string)preg_replace('/-+/', '-', $name);
        $name = (string)preg_replace('/^[\s-]+|[\s-]+$/', '', $name);
        $name = trim(substr($name, 0, 60));
        return strlen((string)preg_replace('/[\s-]/', '', $name)) >= 2 ? $name : '';
    }

    /**
     * Whether a typed workspace name is accepted ('' is: the domain names the workspace).
     *
     * @param string $name
     * @return bool
     */
    public static function isNameValid(string $name): bool
    {
        $name = trim($name);
        return $name === '' || (strlen($name) <= 60 && preg_match(self::NAME_PATTERN, $name) === 1);
    }

    /**
     * Why Quissly would refuse this email: 'required', 'too_long', 'invalid', 'public'; '' = fine.
     *
     * @param string $raw
     * @return string
     */
    public static function emailProblem(string $raw): string
    {
        $email = trim($raw);
        if ($email === '') {
            return 'required';
        }
        if (mb_strlen($email) > 254) {
            return 'too_long';
        }
        $at = strrpos($email, '@');
        if ($at === false || $at < 1 || $at === strlen($email) - 1) {
            return 'invalid';
        }
        $local = substr($email, 0, $at);
        $domain = mb_strtolower(substr($email, $at + 1));
        $atext = '[\p{L}\p{N}_!#$%&\'*+\-\/=?^`{|}~]+';
        if (!preg_match('/^' . $atext . '(?:\.' . $atext . ')*$/u', $local)) {
            return 'invalid';
        }
        $labels = explode('.', $domain);
        if (count($labels) < 2 || mb_strlen($domain) > 253) {
            return 'invalid';
        }
        foreach ($labels as $label) {
            if (!preg_match('/^[\p{L}\p{Nd}](?:[\p{L}\p{Nd}-]*[\p{L}\p{Nd}])?$/u', $label)
                || substr($label, 2, 2) === '--' || mb_strlen($label) > 63) {
                return 'invalid';
            }
        }
        if (!preg_match('/\p{L}$/u', $domain)) {
            return 'invalid';
        }
        foreach (self::SPECIAL_USE_DOMAINS as $special) {
            if ($domain === $special || substr($domain, -strlen($special) - 1) === '.' . $special) {
                return 'public';
            }
        }
        return '';
    }
}
