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
     * under these folders (and these files) only, and an update replaces each of them whole.
     * The language packs sit where CS-Cart reads an add-on's .po (config.dir.lang_packs).
     */
    public const ROOTS = [
        'app/addons/quissly_search',
        'var/langs/en/addons/quissly_search.po',
        'var/langs/ka/addons/quissly_search.po',
        'var/langs/ru/addons/quissly_search.po',
        'design/backend/css/addons/quissly_search',
        'design/backend/media/images/addons/quissly_search',
        'design/backend/templates/addons/quissly_search',
        'js/addons/quissly_search',
        'var/themes_repository/responsive/css/addons/quissly_search',
        'var/themes_repository/responsive/templates/addons/quissly_search',
    ];

    /** Quissly's release-signing public key (Ed25519, base64): every release zip has a `.sig`. */
    public const PUBLIC_KEY = 'zAXZVzzPPk0LR8MjMrnoTZi96kcvNUvolgQ6fax9c4E=';

    /** "Never": no checks and no automatic install. */
    public const NEVER = 0;

    /** How often a store checks, unless the merchant chose otherwise: every 30 minutes. */
    public const DEFAULT_EVERY = 1800;

    /** The same update problem goes to the sync log again after this long. */
    public const PROBLEM_EVERY = 86400;

    /**
     * The "Update check frequency" choices (setting update_check_every) in seconds: 5 minutes
     * (for testing), 30 minutes (the default) to a day, or never.
     */
    public const FREQUENCIES = [
        '5m'    => 300,
        '30m'   => 1800,
        '1h'    => 3600,
        '6h'    => 21600,
        '12h'   => 43200,
        '1d'    => 86400,
        'never' => self::NEVER,
    ];

    /**
     * A setting value in seconds; anything else (nothing chosen included) is the default.
     *
     * @param mixed $value
     */
    public static function interval($value): int
    {
        return is_string($value) && array_key_exists($value, self::FREQUENCIES) ? self::FREQUENCIES[$value] : self::DEFAULT_EVERY;
    }

    /**
     * Whether a version the release repository's addon.xml names is worth asking GitHub about:
     * newer than the installed one and than the release already known ('' = none yet).
     */
    public static function isNews(string $hinted, string $installed, string $known): bool
    {
        return version_compare($hinted, $installed, '>')
            && ($known === '' || version_compare($hinted, $known, '>'));
    }

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
        return self::parseProblem($json, $asset, $packagePrefix) === '' ? self::offered($json, $asset, $packagePrefix) : null;
    }

    /**
     * Why parse() offers nothing for a GitHub "latest release" answer ('' when it offers it), for
     * the sync log: a release that is ignored without a word looks like no release at all.
     *
     * @param mixed $json the decoded answer
     */
    public static function parseProblem($json, string $asset, string $packagePrefix): string
    {
        if (!is_array($json)) {
            return 'GitHub answered something that is not a release';
        }
        if (!empty($json['draft'])) {
            return 'it is a draft';
        }
        if (!empty($json['prerelease'])) {
            return 'it is a pre-release';
        }
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/', (string) ($json['tag_name'] ?? ''))) {
            return 'its tag is not a version number';
        }
        $names = [];
        $elsewhere = false;
        foreach ((array) ($json['assets'] ?? []) as $item) {
            $url = is_array($item) ? (string) ($item['browser_download_url'] ?? '') : '';
            if (strpos($url, $packagePrefix) === 0) {
                $names[(string) ($item['name'] ?? '')] = true;
            } elseif ($url !== '') {
                $elsewhere = true;
            }
        }
        if (!isset($names[$asset])) {
            return $elsewhere ? 'its files are not on Quissly\'s GitHub repository' : 'it has no ' . $asset;
        }
        if (!isset($names[$asset . '.sig'])) {
            return $elsewhere ? 'its signature is not on Quissly\'s GitHub repository' : 'it is not signed (no ' . $asset . '.sig)';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $json a release parseProblem() found nothing wrong with
     * @return array{version: string, package: string, signature: string}|null
     */
    private static function offered($json, string $asset, string $packagePrefix): ?array
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
     * A release named by its version alone, at the addresses the release workflow publishes it
     * to (`v<version>/` + the zip and its `.sig`) - for when GitHub's API refuses the server.
     *
     * @return array{version: string, package: string, signature: string}
     */
    public static function forVersion(string $version, string $asset, string $packagePrefix): array
    {
        $package = $packagePrefix . 'v' . $version . '/' . $asset;

        return ['version' => $version, 'package' => $package, 'signature' => $package . '.sig'];
    }

    /**
     * Whether $signature (base64) is a valid Ed25519 signature of $data by $publicKey
     * (base64). False when it cannot be checked (Signature): an update that cannot be checked is not installed.
     */
    public static function signatureValid(string $data, string $signature, string $publicKey): bool
    {
        $sig = base64_decode(trim($signature), true);
        $key = base64_decode($publicKey, true);
        if ($sig === false || $key === false || strlen($sig) !== 64 || strlen($key) !== 32) {
            return false;
        }

        return Signature::verify($sig, $data, $key);
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

    /**
     * Why a request to GitHub failed, '' when it did not: no answer at all, or a status other
     * than 200 - GitHub answers 403/429 to an IP address past its limit (60 API requests an hour,
     * shared by every site on a shared host).
     */
    public static function requestError(bool $answered, int $status, string $curlError): string
    {
        if (!$answered) {
            return 'no answer' . ($curlError !== '' ? ': ' . $curlError : '');
        }
        if ($status === 200) {
            return '';
        }

        return in_array($status, [403, 429], true) ? 'HTTP ' . $status . ", GitHub's limit for this server's IP address" : 'HTTP ' . $status;
    }

    /**
     * Whether an update problem goes to the sync log: a new one at once, the same one again
     * after a day.
     *
     * @param array{message?: string, at?: int} $last the last one logged ([] = none)
     */
    public static function problemDue(array $last, string $message, int $now): bool
    {
        return ($last['message'] ?? '') !== $message || $now - (int) ($last['at'] ?? 0) >= self::PROBLEM_EVERY;
    }

    private static function owned(string $name): bool
    {
        foreach (self::ROOTS as $root) {
            if ($name === $root || $name === $root . '/' || strpos($name, $root . '/') === 0) {
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
