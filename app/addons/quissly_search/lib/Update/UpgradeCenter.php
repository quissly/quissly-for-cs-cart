<?php

declare(strict_types=1);

namespace Quissly\Search\Update;

use Quissly\Search\Logger;
use Quissly\Search\Sync\DbSyncStore;
use Tygh\Enum\StorefrontStatuses;
use Tygh\Http;
use Tygh\Registry;

/**
 * Quissly's releases in CS-Cart's own Upgrade center (Add-ons > Upgrades), next to the automatic
 * updates (lib/Update/Updater, unchanged). CS-Cart asks the connector of every installed add-on,
 * `Tygh\UpgradeCenter\Connectors\<Camelized id>\Connector` - ours is
 * Tygh/UpgradeCenter/Connectors/QuisslySearch/Connector.php in the add-on's folder, which CS-Cart
 * autoloads (PSR-0 from the add-on's folder, 4.11 and 4.21 alike). It uses this class:
 *
 *  1. offer(): the latest release, found as the automatic update finds it (GitHub's CDN version,
 *     then GitHub's API or the release's own address), when it is newer than the add-on's files -
 *     downloaded once and checked against Quissly's signature before it is listed;
 *  2. build(): when the merchant clicks Download, the release as an Upgrade center package
 *     (lib/Update/UpgradePackage);
 *  3. before() / after(): the package's pre and post scripts. CS-Cart backs the store up (unless
 *     the merchant skips it), closes the storefront and copies the files; after() checks they
 *     landed, redoes what the automatic update does after its swap (Updater::reinstall: install
 *     queries, templates, language variables, settings kept, the version) and opens the
 *     storefronts it closed again. A failure there puts the previous version back, as an
 *     automatic update does.
 *
 * Both run in the request that installs, with the RUNNING version's code: before() loads its
 * classes (this one and Updater) before the copy. A later release must keep before() and after()
 * with these signatures - a package built by the older connector calls them.
 */
final class UpgradeCenter
{
    private const ADDON = 'quissly_search';

    /** @var resource|null the update lock (the automatic update's), held from before() to after() */
    private static $lock = null;

    /** @var list<int> the storefronts that were open before CS-Cart closed them for the copy */
    private static array $open = [];

    /** The version in the add-on's files (CS-Cart's recorded one can lag behind files copied by hand). */
    public static function installedVersion(): string
    {
        return Release::versionIn((string) @file_get_contents(self::root() . 'app/addons/quissly_search/addon.xml'))
            ?? (string) fn_get_addon_version(self::ADDON);
    }

    /**
     * The release the Upgrade center may offer, or null. $hinted is the version GitHub's CDN copy
     * of addon.xml named (null when it could not be read): one that is not newer than the files
     * asks GitHub nothing more. $problem says (in the merchant's words) why a newer release is not
     * offered, '' when there is none or nothing to say.
     *
     * @return array{version: string, package: string, signature: string, zip: string, size: int, time: int}|null
     */
    public static function offer(?string $hinted, string &$problem): ?array
    {
        $problem = '';
        $installed = self::installedVersion();
        if (($hinted !== null && !UpgradePackage::isOffered($hinted, $installed)) || Updater::isCheckout()) {
            return null;
        }
        $release = Updater::latest($hinted);
        if ($release === null) {
            $problem = Updater::lastError() !== '' ? (string) __('quissly_search.uc_no_check', ['[reason]' => Updater::lastError()]) : '';

            return null;
        }
        if (!UpgradePackage::isOffered($release['version'], $installed)) {
            return null;
        }
        $refusal = self::fetch($release);
        if ($refusal !== '') {
            $problem = self::refusalText($release['version'], $refusal);

            return null;
        }
        $zip = self::cache() . $release['version'] . '.zip';

        return $release + ['zip' => $zip, 'size' => (int) filesize($zip), 'time' => (int) filemtime($zip)];
    }

    /**
     * The release $schema names (the Upgrade center's schema.json, written from offer()) as an
     * Upgrade center package at $packagePath. '' on success, else the reason in the merchant's words.
     *
     * @param array<string, mixed> $schema
     */
    public static function build(array $schema, string $packagePath): string
    {
        $from = (string) ($schema['from_version'] ?? '');
        $to = (string) ($schema['to_version'] ?? '');
        if (self::installedVersion() !== $from) {
            return (string) __('quissly_search.uc_changed', ['[version]' => $from]);
        }
        $release = ['version' => $to, 'package' => (string) ($schema['package_url'] ?? ''), 'signature' => (string) ($schema['signature_url'] ?? '')];
        $refusal = self::fetch($release);
        if ($refusal !== '') {
            return self::refusalText($to, $refusal);
        }
        $unpacked = self::cache() . uniqid('release-', true) . '/';
        $zip = new \ZipArchive();
        if ($zip->open(self::cache() . $to . '.zip') !== true || !$zip->extractTo($unpacked)) {
            fn_rm($unpacked);

            return self::refusalText($to, 'bad_zip');
        }
        $zip->close();
        try {
            $releaseFiles = UpgradePackage::filesIn($unpacked);
            $files = UpgradePackage::files($releaseFiles, UpgradePackage::filesIn(self::root()));
            fn_rm($packagePath);
            fn_mkdir(dirname($packagePath));
            $package = new \ZipArchive();
            if ($package->open($packagePath, \ZipArchive::CREATE) !== true) {
                return self::refusalText($to, 'write_failed');
            }
            $package->addFromString('package.json', (string) json_encode(UpgradePackage::contentSchema($files), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            foreach (UpgradePackage::scripts($from, $to) as $name => $source) {
                $package->addFromString('scripts/' . $name, $source);
            }
            foreach (UpgradePackage::copied($releaseFiles, $files) as $path) {
                $package->addFile($unpacked . $path, 'package/' . $path);
            }

            return $package->close() ? '' : self::refusalText($to, 'write_failed');
        } finally {
            fn_rm($unpacked);
        }
    }

    /**
     * The package's pre script: still the version the package was built for, not being updated by
     * the automatic update right now (its lock is taken until after()), a copy of the add-on's
     * files to put back on failure, and which storefronts are open. Throws to stop the install
     * (CS-Cart 4.11 ignores a pre script's return value).
     */
    public static function before(string $from, string $to): void
    {
        if (self::installedVersion() !== $from) {
            throw new \Exception((string) __('quissly_search.uc_changed', ['[version]' => $from]));
        }
        fn_mkdir(self::work());
        $lock = fopen(self::work() . 'lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \Exception((string) __('quissly_search.uc_busy'));
        }
        self::$lock = $lock;
        // Every class after() reaches, loaded now from the running version: the copy may change
        // or delete their files (1.0.26 has no UpgradePackage; its Updater::reinstall is private).
        foreach ([Updater::class, UpgradePackage::class, Release::class, Logger::class, DbSyncStore::class] as $class) {
            class_exists($class);
        }

        $backup = self::work() . 'backup/';
        fn_rm($backup);
        foreach (Release::ROOTS as $path) {
            if (file_exists(self::root() . $path)) {
                fn_mkdir(dirname($backup . $path));
                if (!fn_copy(self::root() . $path, $backup . $path)) {
                    throw new \Exception(self::refusalText($to, 'backup_failed'));
                }
            }
        }

        self::$open = [];
        [$storefronts] = \Tygh\Tygh::$app['storefront.repository']->find(['status' => StorefrontStatuses::OPEN]);
        foreach ($storefronts as $storefront) {
            self::$open[] = (int) $storefront->storefront_id;
        }
    }

    /**
     * The package's post script, once CS-Cart copied $packageDir over the store: every file
     * landed, then what CS-Cart does only at install time, redone (Updater::reinstall). Any
     * failure puts the previous version back and throws an Exception (CS-Cart's install catches
     * only those; an Error would end the request half done). The storefronts CS-Cart closed for
     * the copy are opened again either way - the store runs a whole version.
     */
    public static function after(string $to, string $packageDir): void
    {
        $from = (string) Release::versionIn((string) @file_get_contents(self::work() . 'backup/app/addons/quissly_search/addon.xml'));
        try {
            try {
                $missing = UpgradePackage::notCopied($packageDir, self::root());
                $error = $missing !== [] ? 'not_written:' . implode(', ', array_slice($missing, 0, 3))
                    : (Updater::reinstall($to) ? '' : 'reinstall_failed');
            } catch (\Throwable $e) {
                $error = get_class($e) . ': ' . substr($e->getMessage(), 0, 200) . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
            if ($error !== '') {
                Updater::restore(self::work() . 'backup/');
                Updater::reinstall($from);
                Logger::error('Quissly update in the Upgrade center failed', ['version' => $to, 'reason' => $error]);
                (new DbSyncStore())->log(sprintf('Quissly %s could not be installed in Add-ons > Upgrades (%s); %s was put back.', $to, $error, $from), time());

                throw new \Exception(self::failedText($to, $from, $error));
            }
            $schema = json_decode((string) @file_get_contents(dirname($packageDir) . '/package.json'), true);
            foreach (UpgradePackage::emptied((array) ($schema['files'] ?? [])) as $dir) {
                @rmdir(self::root() . $dir); // only when empty
            }
            fn_rm(self::cache());
            Logger::notice('Quissly update installed', ['from' => $from, 'to' => $to, 'by' => 'upgrade_center']);
            (new DbSyncStore())->log(sprintf('Quissly updated from %s to %s in Add-ons > Upgrades.', $from, $to), time());
        } finally {
            self::reopen();
            if (self::$lock !== null) {
                flock(self::$lock, LOCK_UN);
                fclose(self::$lock);
                self::$lock = null;
            }
        }
    }

    /** The storefronts before() found open, open again (CS-Cart closed them for the copy). */
    private static function reopen(): void
    {
        $repository = \Tygh\Tygh::$app['storefront.repository'];
        foreach (self::$open as $id) {
            $storefront = $repository->findById($id);
            if ($storefront && $storefront->status !== StorefrontStatuses::OPEN) {
                $storefront->status = StorefrontStatuses::OPEN;
                $repository->save($storefront);
            }
        }
        self::$open = [];
    }

    /**
     * The release's zip and signature in the cache folder - downloaded once per version - checked
     * as an automatic update checks it. '' when it may be installed, else UpgradePackage::refusal's
     * reason (or download_failed, no_signature, no_zip, bad_zip). Two admin pages can check at
     * once (the dashboard asks the Upgrade center too): each downloads to its own files and only
     * a checked pair is moved into place.
     *
     * @param array{version: string, package: string, signature: string} $release
     */
    private static function fetch(array $release): string
    {
        if (!class_exists('ZipArchive')) {
            return 'no_zip';
        }
        $zipPath = self::cache() . $release['version'] . '.zip';
        if (is_file($zipPath) && is_file($zipPath . '.sig')) {
            $refusal = self::check($zipPath, $release['version']);
            if ($refusal === '') {
                return '';
            }
            @unlink($zipPath);
            @unlink($zipPath . '.sig');
        }

        fn_mkdir(self::cache());
        $own = self::cache() . uniqid('download-', true) . '.zip';
        try {
            $result = Http::get($release['package'], [], [
                'headers'           => ['User-Agent: quissly-for-cs-cart'],
                'timeout'           => 30,
                'execution_timeout' => 120,
                'write_to_file'     => $own,
            ]);
            // The file, not the status: with write_to_file Tygh\Http keeps the previous request's.
            if ($result === false || !is_file($own) || (string) file_get_contents($own, false, null, 0, 4) !== "PK\x03\x04") {
                return 'download_failed';
            }
            $signature = Http::get($release['signature'], [], [
                'headers' => ['User-Agent: quissly-for-cs-cart'], 'timeout' => 15, 'execution_timeout' => 20,
            ]);
            if ($signature === false || Http::getStatus() !== 200) {
                return 'no_signature';
            }
            fn_put_contents($own . '.sig', (string) $signature);
            $refusal = self::check($own, $release['version']);
            if ($refusal !== '') {
                return $refusal; // never kept, never offered
            }
            foreach (glob(self::cache() . '*.zip*') ?: [] as $old) {
                if (strpos(basename($old), 'download-') !== 0) {
                    @unlink($old); // another version's
                }
            }
            rename($own . '.sig', $zipPath . '.sig');
            rename($own, $zipPath);

            return '';
        } finally {
            @unlink($own);
            @unlink($own . '.sig');
        }
    }

    /** UpgradePackage::refusal for a zip and its .sig beside it ('bad_zip' when it cannot be opened). */
    private static function check(string $zipPath, string $version): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return 'bad_zip';
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $addonXml = (string) $zip->getFromName('app/addons/quissly_search/addon.xml');
        $zip->close();

        return UpgradePackage::refusal((string) file_get_contents($zipPath), (string) @file_get_contents($zipPath . '.sig'), Release::PUBLIC_KEY, $names, $addonXml, $version);
    }

    /** Why a release cannot be offered or installed, in the merchant's words. */
    private static function refusalText(string $version, string $refusal): string
    {
        $reasons = [
            'no_sodium'       => 'uc_reason_no_sodium',
            'bad_signature'   => 'uc_reason_signature',
            'download_failed' => 'uc_reason_download',
            'no_signature'    => 'uc_reason_signature',
            'no_zip'          => 'uc_reason_no_zip',
            'backup_failed'   => 'uc_reason_backup',
            'write_failed'    => 'uc_reason_write',
        ];
        $reason = (string) __('quissly_search.' . ($reasons[$refusal] ?? 'uc_reason_package'), ['[detail]' => $refusal]);

        return (string) __('quissly_search.uc_refused', ['[version]' => $version, '[reason]' => $reason]);
    }

    private static function failedText(string $to, string $from, string $error): string
    {
        return (string) __('quissly_search.uc_failed', ['[version]' => $to, '[previous]' => $from, '[reason]' => $error]);
    }

    /** The automatic update's work folder (removed with the add-on). */
    private static function work(): string
    {
        return rtrim((string) Registry::get('config.dir.var'), '/') . '/quissly_search_update/';
    }

    /** The Upgrade center's downloads: one release's zip and signature. */
    private static function cache(): string
    {
        return self::work() . 'upgrade_center/';
    }

    private static function root(): string
    {
        return rtrim((string) Registry::get('config.dir.root'), '/') . '/';
    }
}
