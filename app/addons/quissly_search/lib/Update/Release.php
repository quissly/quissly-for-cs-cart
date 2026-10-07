<?php

declare(strict_types=1);

namespace Quissly\Search\Update;

/**
 * Reading a GitHub release and checking its package, without CS-Cart (lib/Update/Updater
 * does the installing).
 */
final class Release
{
    /**
     * Where the add-on's files live, relative to the store root. A package may hold files
     * under these folders only, and an update replaces each of them whole.
     */
    public const ROOTS = [
        'app/addons/quissly_search',
        'design/backend/css/addons/quissly_search',
        'design/backend/media/images/addons/quissly_search',
        'design/backend/templates/addons/quissly_search',
        'js/addons/quissly_search',
        'var/themes_repository/responsive/css/addons/quissly_search',
        'var/themes_repository/responsive/templates/addons/quissly_search',
    ];

    /** Quissly's release-signing public key (Ed25519, base64): every release zip has a `.sig`. */
    public const PUBLIC_KEY = 'zAXZVzzPPk0LR8MjMrnoTZi96kcvNUvolgQ6fax9c4E=';

    /**
     * A GitHub "latest release" answer: its version (from a `v1.2.3` / `1.2.3` tag), the
     * add-on zip and its signature (`<zip>.sig`), both accepted only from $packagePrefix.
     * Drafts, pre-releases and unsigned releases are never offered.
     *
     * @param mixed $json the decoded answer
     *
     * @return array{version: string, package: string, signature: string}|null
     */
    public static function parse($json, string $asset, string $packagePrefix): ?array
    {
        if (!is_array($json) || !empty($json['draft']) || !empty($json['prerelease'])) {
            return null;
        }
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/', (string) ($json['tag_name'] ?? ''), $m)) {
            return null;
        }
        $urls = [];
        foreach ((array) ($json['assets'] ?? []) as $item) {
            $url = is_array($item) ? (string) ($item['browser_download_url'] ?? '') : '';
            if (strpos($url, $packagePrefix) === 0) {
                $urls[(string) ($item['name'] ?? '')] = $url;
            }
        }
        if (!isset($urls[$asset], $urls[$asset . '.sig'])) {
            return null;
        }

        return ['version' => $m[1], 'package' => $urls[$asset], 'signature' => $urls[$asset . '.sig']];
    }

    /**
     * Whether $signature (base64) is a valid Ed25519 signature of $data by $publicKey
     * (base64). False when PHP has no sodium: an update that cannot be checked is not installed.
     */
    public static function signatureValid(string $data, string $signature, string $publicKey): bool
    {
        $sig = base64_decode(trim($signature), true);
        $key = base64_decode($publicKey, true);
        if ($sig === false || $key === false || strlen($sig) !== 64 || strlen($key) !== 32
            || !function_exists('sodium_crypto_sign_verify_detached')
        ) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($sig, $data, $key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Why a package's file list cannot be installed, or null when it can: every entry sits
     * under one of ROOTS, none climbs out of it, and the add-on's addon.xml is there.
     *
     * @param list<string> $names the zip's entry names
     */
    public static function problem(array $names): ?string
    {
        if ($names === []) {
            return 'empty';
        }
        foreach ($names as $name) {
            if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false || preg_match('#(^|/)\.\.?(/|$)#', $name)) {
                return 'unsafe_path';
            }
            if (!self::owned($name)) {
                return 'foreign_file';
            }
        }
        if (!in_array('app/addons/quissly_search/addon.xml', $names, true)) {
            return 'no_addon_xml';
        }

        return null;
    }

    /** The <version> an addon.xml declares. */
    public static function versionIn(string $addonXml): ?string
    {
        return preg_match('#<version>\s*(\d+\.\d+\.\d+)\s*</version>#', $addonXml, $m) ? $m[1] : null;
    }

    private static function owned(string $name): bool
    {
        foreach (self::ROOTS as $root) {
            if ($name === $root . '/' || strpos($name, $root . '/') === 0) {
                return true;
            }
            // The folders leading to a root ("app/", "app/addons/") as zip directory entries.
            if (substr($name, -1) === '/' && strpos($root . '/', $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
