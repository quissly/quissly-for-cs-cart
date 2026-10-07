<?php

declare(strict_types=1);

namespace Quissly\Search\Update;

use Quissly\Search\Logger;
use Quissly\Search\Sync\DbSyncStore;
use Tygh\Addons\SchemesManager;
use Tygh\Http;
use Tygh\Registry;

/**
 * Automatic updates from the add-on's public GitHub repository.
 *
 * Once a day (from the cron dispatch, or at the end of an admin page when the cron is not
 * set up) the add-on asks GitHub for the latest release. When it is newer than the
 * installed version it is installed straight away:
 *
 *  1. the release zip is downloaded and checked: signed with Quissly's release key
 *     (Release::PUBLIC_KEY - an unsigned or wrongly signed zip is never installed, even
 *     if someone could publish to the repository), only the add-on's own folders, its
 *     addon.xml readable and naming the release's version;
 *  2. each of the add-on's folders (Release::ROOTS) is moved to a backup and the new one
 *     put in its place - any failure moves the old ones back;
 *  3. what CS-Cart does only at install time is redone: the install queries (every one is
 *     CREATE TABLE IF NOT EXISTS), the storefront templates copied into the themes, the
 *     language variables and the settings (values kept) - fn_reinstall_addon_files -
 *     and the add-on's recorded version.
 *
 * Settings, credentials and the sync tables are never touched. A version that failed to
 * install is not tried again; the next release is. A store (or add-on folder) that is a git
 * checkout is never written to: whoever deploys it updates it.
 */
final class Updater
{
    public const REPOSITORY = 'quissly/quissly-for-cs-cart';
    public const ASSET = 'quissly-for-cs-cart.zip';
    public const STATE = 'update';
    public const CHECK_EVERY = 86400;
    public const RETRY_AFTER = 3600;

    private const ADDON = 'quissly_search';

    /** Check (and install) when the day is up. */
    public static function runIfDue(DbSyncStore $store, int $now): void
    {
        $state = $store->getState(self::STATE) ?? [];
        if ((int) ($state['next_at'] ?? 0) > $now) {
            return;
        }
        // Claimed before the network: a second request in the meantime finds it not due.
        $state['next_at'] = $now + self::RETRY_AFTER;
        $store->setState(self::STATE, $state);

        $release = self::latest();
        if ($release === null) {
            return; // tried again in an hour
        }
        $state['next_at'] = $now + self::CHECK_EVERY;
        $state['checked_at'] = $now;
        $state['latest'] = $release['version'];
        // The files' own version: what is really running (CS-Cart's recorded version can lag
        // behind files copied in by hand).
        $installed = Release::versionIn((string) @file_get_contents(self::root() . 'app/addons/quissly_search/addon.xml'))
            ?? (string) fn_get_addon_version(self::ADDON);
        if (self::isCheckout()) {
            $state['skipped'] = 'git_checkout';
        } elseif (version_compare($release['version'], $installed, '>') && ($state['failed'] ?? '') !== $release['version']) {
            $error = self::install($release);
            $state['last'] = ['from' => $installed, 'to' => $release['version'], 'at' => $now, 'error' => $error];
            if ($error === null) {
                unset($state['failed']);
                Logger::notice('Quissly update installed', ['from' => $installed, 'to' => $release['version']]);
                $store->log(sprintf('Quissly updated from %s to %s.', $installed, $release['version']), $now);
            } else {
                $state['failed'] = $release['version'];
                Logger::error('Quissly update failed', ['version' => $release['version'], 'reason' => $error]);
                $store->log(sprintf('Quissly %s could not be installed (%s); %s is still running.', $release['version'], $error, $installed), $now);
            }
        }
        $store->setState(self::STATE, $state);
    }

    /**
     * Whether the store or the add-on's folder is a git checkout: its files are managed by
     * whoever deploys it (and a developer's copy may be the repository itself), so it is never
     * written to - as WordPress skips automatic updates on a git-managed site.
     */
    private static function isCheckout(): bool
    {
        return file_exists(self::root() . '.git') || file_exists(self::root() . 'app/addons/quissly_search/.git');
    }

    /** @return array{version: string, package: string, signature: string}|null */
    private static function latest(): ?array
    {
        $body = Http::get(self::releaseUrl(), [], [
            'headers'            => ['Accept: application/vnd.github+json', 'User-Agent: quissly-for-cs-cart'],
            'timeout'            => 10,
            'connection_timeout' => 10,
            'execution_timeout'  => 15,
        ]);
        if ($body === false || Http::getStatus() !== 200) {
            return null;
        }

        return Release::parse(json_decode((string) $body, true), self::ASSET, self::packagePrefix());
    }

    /**
     * Download, check and put the release in place. Null on success, else why not.
     *
     * @param array{version: string, package: string, signature: string} $release
     */
    private static function install(array $release): ?string
    {
        $work = rtrim((string) Registry::get('config.dir.var'), '/') . '/quissly_search_update/';
        fn_mkdir($work);
        $lock = fopen($work . 'lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 'busy';
        }
        try {
            fn_rm($work . 'new');
            $zipPath = $work . 'package.zip';
            $result = Http::get($release['package'], [], [
                'headers'           => ['User-Agent: quissly-for-cs-cart'],
                'timeout'           => 30,
                'execution_timeout' => 120,
                'write_to_file'     => $zipPath,
            ]);
            if ($result === false || Http::getStatus() !== 200 || !is_file($zipPath) || filesize($zipPath) === 0) {
                return 'download_failed';
            }
            $signature = Http::get($release['signature'], [], [
                'headers' => ['User-Agent: quissly-for-cs-cart'], 'timeout' => 15, 'execution_timeout' => 20,
            ]);
            if ($signature === false || Http::getStatus() !== 200) {
                return 'no_signature';
            }
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                return 'no_sodium';
            }
            if (!Release::signatureValid((string) file_get_contents($zipPath), (string) $signature, Release::PUBLIC_KEY)) {
                return 'bad_signature';
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return 'bad_zip';
            }
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $problem = Release::problem($names);
            if ($problem !== null) {
                $zip->close();

                return 'bad_package:' . $problem;
            }
            $extracted = $zip->extractTo($work . 'new');
            $zip->close();
            @unlink($zipPath);
            if (!$extracted) {
                return 'extract_failed';
            }
            $addonXml = (string) file_get_contents($work . 'new/app/addons/quissly_search/addon.xml');
            if (Release::versionIn($addonXml) !== $release['version']) {
                return 'version_mismatch';
            }
            // A scheme CS-Cart could not read must never replace the running one.
            $previous = libxml_use_internal_errors(true);
            $parsed = simplexml_load_string($addonXml);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($parsed === false) {
                return 'bad_package:addon_xml';
            }

            $swap = self::swap($work . 'new/', $work . 'backup/');
            if ($swap !== null) {
                return $swap;
            }
            if (!self::reinstall($release['version'])) {
                self::restore($work . 'backup/');
                self::reinstall((string) Release::versionIn((string) file_get_contents(self::root() . 'app/addons/quissly_search/addon.xml')));

                return 'reinstall_failed';
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return null;
        } finally {
            fn_rm($work . 'new');
            @unlink($work . 'package.zip');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Old folders to the backup, new ones in their place; on any failure, the old ones back. */
    private static function swap(string $new, string $backup): ?string
    {
        fn_rm($backup);
        $root = self::root();
        foreach (Release::ROOTS as $path) {
            if (file_exists($root . $path) && !self::move($root . $path, $backup . $path)) {
                self::restore($backup);

                return 'swap_failed';
            }
            if (file_exists($new . $path) && !self::move($new . $path, $root . $path)) {
                self::restore($backup);

                return 'swap_failed';
            }
        }

        return null;
    }

    /** Put the backed-up folders back (removing whatever replaced them). */
    private static function restore(string $backup): void
    {
        $root = self::root();
        foreach (Release::ROOTS as $path) {
            if (file_exists($backup . $path)) {
                fn_rm($root . $path);
                self::move($backup . $path, $root . $path);
            } elseif (file_exists($root . $path)) {
                fn_rm($root . $path); // new in this release, nothing to put back
            }
        }
    }

    /**
     * Move a folder: a rename, or - across filesystems (var/ can be its own mount, where
     * rename() fails) - a copy, then the original removed.
     */
    private static function move(string $from, string $to): bool
    {
        fn_mkdir(dirname($to));
        if (@rename($from, $to)) {
            return true;
        }
        fn_rm($to); // whatever a failed rename left
        if (!fn_copy($from, $to)) {
            fn_rm($to);

            return false;
        }

        return fn_rm($from);
    }

    /** What CS-Cart does only at install time, redone for the files now in place. */
    private static function reinstall(string $version): bool
    {
        try {
            SchemesManager::clearInternalCache(self::ADDON);
            $scheme = SchemesManager::getScheme(self::ADDON);
            if (empty($scheme)) {
                return false;
            }
            if ($scheme->processQueries('install', Registry::get('config.dir.addons') . self::ADDON) === false) {
                return false;
            }
            if (function_exists('fn_reinstall_addon_files')) {
                fn_reinstall_addon_files(self::ADDON);
            } else {
                fn_install_addon_templates(self::ADDON);
                fn_update_addon_language_variables($scheme);
                fn_clear_cache();
            }
            fn_update_addon_version(self::ADDON, $version);

            return true;
        } catch (\Throwable $e) {
            Logger::error('Quissly update: reinstall step failed', ['exception' => get_class($e)]);

            return false;
        }
    }

    private static function root(): string
    {
        return rtrim((string) Registry::get('config.dir.root'), '/') . '/';
    }

    /** GitHub's "latest release" address; $config['quissly_update_url'] replaces it on a dev store. */
    private static function releaseUrl(): string
    {
        $override = (string) Registry::get('config.quissly_update_url');

        return $override !== '' ? $override : 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest';
    }

    /** Where a package may come from: this repository's release downloads (or the dev override's host). */
    private static function packagePrefix(): string
    {
        $override = (string) Registry::get('config.quissly_update_url');
        if ($override !== '') {
            $parts = parse_url($override);

            return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
        }

        return 'https://github.com/' . self::REPOSITORY . '/releases/download/';
    }
}
