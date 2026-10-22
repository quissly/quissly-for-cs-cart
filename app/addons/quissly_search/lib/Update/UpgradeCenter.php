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
 *     (lib/Update/UpgradePackage), with a validator CS-Cart runs before anything else
 *     (validate(): can this server send the administrator's email and write the restore
 *     script - lib/Update/UpgradeCheck), so the install stops while the storefront is open;
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

    /** Whether after() ran for the install before() prepared (else stopped() may reopen the store). */
    private static bool $finished = false;

    /**
     * State row: the server checks' network answers, kept for the Dashboard - a day when the
     * server answered, an hour when it did not (a fixed problem should not be shown for long).
     * Every Install asks afresh and refreshes them.
     */
    private const STATE_CHECKS = 'upgrade_center_checks';

    private const CHECKS_TTL = 86400;

    private const CHECKS_FAILED_TTL = 3600;

    /** @var array<string, ?bool> this request's network answers, by what was asked */
    private static array $asked = [];

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
            $package->addFromString('validators/' . UpgradePackage::VALIDATOR . '.php', UpgradePackage::validator($from, $to));
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
     * (CS-Cart 4.11 ignores a pre script's return value) - also when the administrator's email
     * cannot be sent: CS-Cart closes the storefronts right after this script. Should CS-Cart still
     * stop before the copy, stopped() opens them again at the end of the request.
     */
    public static function before(string $from, string $to): void
    {
        if (self::installedVersion() !== $from) {
            throw new \Exception((string) __('quissly_search.uc_changed', ['[version]' => $from]));
        }
        // Again here, as the package's validator checked it: CS-Cart closes the storefront right
        // after this script and stops with it closed when its email cannot be sent. Answers are
        // kept for the request, so the SMTP server is not asked twice.
        foreach (self::problems(false, null, null) as [$kind, $reason]) {
            throw new \Exception(htmlspecialchars(self::problemText($kind, $reason, false), ENT_QUOTES, 'UTF-8'));
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
        self::$finished = false;
        register_shutdown_function([self::class, 'stopped'], $to);
    }

    /**
     * The end of the install request (registered by before()). CS-Cart closes the storefronts
     * after the pre script and returns without opening them again when a later step fails before
     * the copy - its backup, its email to the administrator. Then the storefronts before() found
     * open are opened again: the store still runs the version it ran. Not when after() ran (it
     * opens them itself), nor when any of the add-on's files differ from before()'s copy (a copy
     * cut short: left as CS-Cart left it). Public for register_shutdown_function only.
     */
    public static function stopped(string $to): void
    {
        if (self::$finished || self::$open === []) {
            return;
        }
        try {
            $repository = \Tygh\Tygh::$app['storefront.repository'];
            $closed = false;
            foreach (self::$open as $id) {
                $storefront = $repository->findById($id);
                $closed = $closed || ($storefront && $storefront->status !== StorefrontStatuses::OPEN);
            }
            if (!$closed) {
                self::$open = [];

                return;
            }
            if (UpgradePackage::filesIn(self::root()) !== UpgradePackage::filesIn(self::work() . 'backup/')) {
                Logger::error('Quissly update in the Upgrade center stopped part way; the storefront is left as CS-Cart left it', ['version' => $to]);

                return;
            }
            self::reopen();
            Logger::notice('Quissly update in the Upgrade center stopped before the copy; storefront opened again', ['version' => $to]);
            (new DbSyncStore())->log(sprintf('CS-Cart stopped installing Quissly %s in Add-ons > Upgrades before copying any file (see its upgrade log); the storefront it had closed was opened again.', $to), time());
        } catch (\Throwable $e) {
            // The request is ending; CS-Cart's own state stands.
        }
    }

    /**
     * The package's validator (UpgradePackage::validator), first of CS-Cart's install steps:
     * [true, ''] when this server can finish installing $from -> $to, else [false, why - as the
     * HTML CS-Cart's "Validation issue" dialog prints]. The backup is checked only when the
     * merchant backs up: CS-Cart's install request carries skip_backup=Y when "Skip files and
     * database backup" is ticked (its controller sets App::$perform_backup from that field and
     * hands the same request to the validators).
     *
     * @param array<string, mixed> $request
     *
     * @return array{0: bool, 1: string}
     */
    public static function validate(array $request, string $from, string $to): array
    {
        $id = (string) ($request['id'] ?? '');
        $restoreDir = (preg_match('/^[A-Za-z0-9_.\-]+$/', $id) ? $id : self::ADDON) . '_' . $from . '-' . $to;
        $texts = [];
        $found = [];
        foreach (self::problems(($request['skip_backup'] ?? '') !== 'Y', $restoreDir, new DbSyncStore(), true) as [$kind, $reason]) {
            $texts[] = htmlspecialchars(self::problemText($kind, $reason, false), ENT_QUOTES, 'UTF-8');
            $found[] = $kind . ': ' . $reason;
        }
        if ($found !== []) {
            // The merchant sees the message; Quissly hears of it too (lib/Diagnostics/), so a store whose
            // server stops the Upgrade center is known before its merchant writes in. The same problem
            // is one report, sent at most once an hour (ErrorReport).
            \Quissly\Search\Diagnostics\ErrorCapture::refused('upgrade_center_blocked', sprintf(
                'Upgrade center install of Quissly %s (from %s) stopped by the server check: %s',
                $to,
                $from,
                implode('; ', $found)
            ));
        }

        return $texts === [] ? [true, ''] : [false, implode('<br><br>', $texts)];
    }

    /**
     * The Dashboard's warnings: what would stop CS-Cart's Upgrade center on this server. File
     * and php.ini facts are read each time; the network answers (the SMTP server, the store's
     * own address) are kept (STATE_CHECKS).
     *
     * @return list<string>
     */
    public static function dashboardProblems(DbSyncStore $store): array
    {
        $texts = [];
        foreach (self::problems(true, null, $store) as [$kind, $reason]) {
            $texts[] = self::problemText($kind, $reason, true);
        }

        return $texts;
    }

    /**
     * What would stop CS-Cart's install here: the administrator's email (always) and, when
     * $backup, the restore script CS-Cart writes into upgrades/$restoreDir (or upgrades/) and
     * fetches over HTTP before it backs up. Network answers are asked once per request; with
     * $cache they are also kept (STATE_CHECKS) - and taken from it unless $fresh.
     *
     * @return list<array{0: string, 1: string}> [kind (mail, restore), the reason in the merchant's words]
     */
    private static function problems(bool $backup, ?string $restoreDir, ?DbSyncStore $cache, bool $fresh = false): array
    {
        $emails = self::emailSettings();
        $method = (string) ($emails['mailer_send_method'] ?? '');
        $disabled = (string) ini_get('disable_functions');
        $php = [
            'custom_transport' => isset(\Tygh\Tygh::$app['mailer.transport.' . $method]),
            'windows'          => DIRECTORY_SEPARATOR === '\\',
            'mail_function'    => function_exists('mail') && !UpgradeCheck::disabled('mail', $disabled),
            'sendmail_path'    => (string) ini_get('sendmail_path'),
        ];
        $runnable = static function (string $program): ?bool {
            return UpgradeCheck::runnable($program, (string) getenv('PATH'), (string) ini_get('open_basedir'));
        };
        $reachable = static function (string $host, int $port) use ($cache, $fresh, $disabled): ?bool {
            return self::asked('smtp:' . $host . ':' . $port, $cache, $fresh, static function () use ($host, $port, $disabled): ?bool {
                if (!function_exists('fsockopen') || UpgradeCheck::disabled('fsockopen', $disabled)) {
                    return null;
                }
                $socket = @fsockopen($host, $port, $errno, $error, 5.0);
                if ($socket === false) {
                    return false;
                }
                fclose($socket);

                return true;
            });
        };

        $problems = [];
        $mail = UpgradeCheck::mail($emails, $php, $runnable, $reachable);
        if ($mail !== null) {
            $problems[] = ['mail', self::reasonText($mail)];
        }
        if ($backup) {
            $restore = UpgradeCheck::backup(self::restoreFacts($restoreDir, $cache, $fresh));
            if ($restore !== null) {
                $problems[] = ['restore', self::reasonText($restore)];
            }
        }

        return $problems;
    }

    /**
     * Settings > E-mails as CS-Cart's mailer reads them for the upgrade's email (no company in
     * the message: the default transport, MailerProvider - on Ultimate's "All stores" the
     * default company's values). Only the method, the SMTP host and the sendmail path are read.
     *
     * @return array<string, mixed>
     */
    private static function emailSettings(): array
    {
        if (fn_allowed_for('ULTIMATE') && !Registry::get('runtime.company_id') && !Registry::get('runtime.simple_ultimate')) {
            return (array) \Tygh\Settings::instance(fn_get_default_company_id())->getValues('Emails');
        }

        return (array) Registry::get('settings.Emails');
    }

    /**
     * What CS-Cart's prepareRestore() needs: upgrades/ with its source_restore.php, a folder it
     * can write the restore script to, and that script answering over HTTP at the store's own
     * address - asked with a script of ours that answers as CS-Cart's does ("Access denied"),
     * removed right after.
     *
     * @return array{dir: bool, restore: bool, writable: bool, fixable: bool, reachable: ?bool, url: string}
     */
    private static function restoreFacts(?string $restoreDir, ?DbSyncStore $cache, bool $fresh): array
    {
        $upgrades = self::root() . 'upgrades';
        $target = $restoreDir !== null && is_dir($upgrades . '/' . $restoreDir) ? $upgrades . '/' . $restoreDir : null;
        $writable = is_dir($upgrades) && fn_is_writable($upgrades) ? $upgrades
            : ($target !== null && fn_is_writable($target) ? $target : null);
        $url = rtrim((string) Registry::get('config.current_location'), '/') . '/upgrades/'
            . ($writable !== null && $writable === $target ? $restoreDir . '/' : '');
        $facts = [
            'dir'       => is_dir($upgrades),
            'restore'   => is_file($upgrades . '/source_restore.php') && is_readable($upgrades . '/source_restore.php'),
            'writable'  => $writable !== null,
            // CS-Cart's Permissions validator makes it writable itself: chmod (PHP's own folder) or FTP.
            'fixable'   => (function_exists('posix_geteuid') && @fileowner($upgrades) === posix_geteuid())
                || (function_exists('ftp_connect') && (string) Registry::get('settings.Upgrade_center.ftp_hostname') !== ''),
            'reachable' => null,
            'url'       => $url,
        ];
        if ($facts['dir'] && $facts['restore'] && $writable !== null) {
            $facts['reachable'] = self::asked('http:' . $url, $cache, $fresh, static function () use ($writable, $url): ?bool {
                $name = 'quissly_check_' . bin2hex(random_bytes(8)) . '.php';
                if (@file_put_contents($writable . '/' . $name, "<?php\necho 'Access denied';\n") === false) {
                    return null;
                }
                try {
                    return Http::get($url . $name, [], ['timeout' => 10, 'execution_timeout' => 20]) === 'Access denied';
                } finally {
                    @unlink($writable . '/' . $name);
                }
            });
        }

        return $facts;
    }

    /**
     * A network answer: once per request; with $cache also kept, and taken from it while fresh
     * enough unless $fresh (an Install asks afresh).
     *
     * @param callable(): ?bool $ask
     */
    private static function asked(string $key, ?DbSyncStore $cache, bool $fresh, callable $ask): ?bool
    {
        if (array_key_exists($key, self::$asked)) {
            return self::$asked[$key];
        }
        $kept = $cache !== null ? (array) $cache->getState(self::STATE_CHECKS) : [];
        $current = static function ($answer): bool {
            return isset($answer['at']) && time() - (int) $answer['at'] < (($answer['ok'] ?? null) === true ? self::CHECKS_TTL : self::CHECKS_FAILED_TTL);
        };
        if (!$fresh && isset($kept[$key]) && $current($kept[$key])) {
            return self::$asked[$key] = $kept[$key]['ok'] ?? null;
        }
        self::$asked[$key] = $ask();
        if ($cache !== null) {
            $kept = array_filter($kept, $current);
            $kept[$key] = ['at' => time(), 'ok' => self::$asked[$key]];
            $cache->setState(self::STATE_CHECKS, $kept);
        }

        return self::$asked[$key];
    }

    /** @param array{0: string, 1: string} $problem from UpgradeCheck */
    private static function reasonText(array $problem): string
    {
        [$reason, $detail] = $problem;

        return (string) __('quissly_search.uc_reason_' . $reason, ['[program]' => $detail, '[server]' => $detail, '[url]' => $detail]);
    }

    /** The whole message: for the Upgrade center's dialog, or the Dashboard's warning. */
    private static function problemText(string $kind, string $reason, bool $dashboard): string
    {
        return (string) __($dashboard ? 'quissly_search.dash_uc_' . $kind : 'quissly_search.uc_' . $kind . '_blocked', ['[reason]' => $reason]);
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
        self::$finished = true;
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
