<?php

declare(strict_types=1);

namespace Quissly\Search\Update;

/**
 * A Quissly release as a package of CS-Cart's Upgrade center (Add-ons > Upgrades), without
 * CS-Cart (lib/Update/UpgradeCenter and the add-on's Tygh/UpgradeCenter/Connectors/QuisslySearch
 * connector do the downloading and installing).
 *
 * The Upgrade center unpacks a package into var/upgrade/packages/quissly_search/content/:
 * package.json (the content schema: every file with its status - new, changed or deleted - and
 * the hash its Collisions check compares the store's file with), package/ (the files, copied
 * over the store root as they are) and scripts/ (a pre and a post script it includes before and
 * after the copy, inside its own install method).
 */
final class UpgradePackage
{
    public const PRE_SCRIPT = 'quissly_search_pre.php';
    public const POST_SCRIPT = 'quissly_search_post.php';

    /**
     * The add-on's language packs. Never listed in package.json: for a listed var/langs file the
     * Upgrade center reinstalls that language's WHOLE pack (core.po and every add-on's texts -
     * a merchant's own translations lost - and "installed with errors" where the store has no
     * core.po for it). They are still in package/, which is copied whole, and the post step
     * reloads the add-on's texts as an automatic update does.
     */
    private const LANG_PACKS = 'var/langs/';

    /** Whether the Upgrade center offers $latest to a store whose files are $installed: newer only. */
    public static function isOffered(string $latest, string $installed): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+$/', $latest) && version_compare($latest, $installed, '>');
    }

    /**
     * Why a downloaded release must not become a package ('' when it may): the same rules as
     * an automatic update - signed with Quissly's release key (PHP without sodium cannot check,
     * so nothing is offered), only the add-on's own files, its addon.xml naming the release.
     *
     * @param list<string> $names the zip's entry names
     */
    public static function refusal(string $zip, string $signature, string $publicKey, array $names, string $addonXml, string $version): string
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return 'no_sodium';
        }
        if (!Release::signatureValid($zip, $signature, $publicKey)) {
            return 'bad_signature';
        }
        $problem = Release::problem($names);
        if ($problem !== null) {
            return 'bad_package:' . $problem;
        }
        if (Release::versionIn($addonXml) !== $version) {
            return 'version_mismatch';
        }

        return '';
    }

    /**
     * Every file of the add-on in a store (or an unpacked release): path from $root => md5.
     *
     * @return array<string, string>
     */
    public static function filesIn(string $root): array
    {
        $root = rtrim($root, '/') . '/';
        $files = [];
        foreach (Release::ROOTS as $path) {
            if (is_file($root . $path)) {
                $files[$path] = (string) md5_file($root . $path);
            } elseif (is_dir($root . $path)) {
                $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $path, \FilesystemIterator::SKIP_DOTS));
                foreach ($items as $item) {
                    if ($item->isFile()) {
                        $files[substr((string) $item->getPathname(), strlen($root))] = (string) md5_file((string) $item->getPathname());
                    }
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * package.json's "files": what the release adds, changes and deletes in the store. A file
     * the store already has as released is left out. "hash" is the store's own copy (for a new
     * file, the release's): the Upgrade center reports a collision only for a file that differs
     * from it, and the add-on's files are always replaced whole - as an automatic update does.
     *
     * @param array<string, string> $release path => md5 of the release's files
     * @param array<string, string> $installed path => md5 of the store's
     *
     * @return array<string, array{status: string, hash: string}>
     */
    public static function files(array $release, array $installed): array
    {
        $files = [];
        foreach ($release as $path => $hash) {
            if (strpos($path, self::LANG_PACKS) === 0 || ($installed[$path] ?? null) === $hash) {
                continue;
            }
            $files[$path] = isset($installed[$path])
                ? ['status' => 'changed', 'hash' => $installed[$path]]
                : ['status' => 'new', 'hash' => $hash];
        }
        foreach ($installed as $path => $hash) {
            if (!isset($release[$path]) && strpos($path, self::LANG_PACKS) !== 0) {
                $files[$path] = ['status' => 'deleted', 'hash' => $hash];
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * The files that go into the package's package/ folder: the new and changed ones, and the
     * language packs (see LANG_PACKS).
     *
     * @param array<string, string>                              $release
     * @param array<string, array{status: string, hash: string}> $files   from files()
     *
     * @return list<string>
     */
    public static function copied(array $release, array $files): array
    {
        $copied = [];
        foreach (array_keys($release) as $path) {
            $path = (string) $path;
            if (strpos($path, self::LANG_PACKS) === 0 || in_array($files[$path]['status'] ?? '', ['new', 'changed'], true)) {
                $copied[] = $path;
            }
        }

        return $copied;
    }

    /**
     * The folders a release's deleted files leave behind, deepest first (the Upgrade center
     * deletes files, never folders), for removal when empty - an automatic update replaces each
     * of Release::ROOTS whole and leaves none. Never a root itself.
     *
     * @param array<string, array{status: string, hash: string}> $files from files()
     *
     * @return list<string>
     */
    public static function emptied(array $files): array
    {
        $dirs = [];
        foreach ($files as $path => $file) {
            if ($file['status'] !== 'deleted') {
                continue;
            }
            for ($dir = dirname((string) $path); self::underRoot($dir); $dir = dirname($dir)) {
                $dirs[$dir] = substr_count($dir, '/');
            }
        }
        arsort($dirs);

        return array_keys($dirs);
    }

    /**
     * package.json.
     *
     * @param array<string, array{status: string, hash: string}> $files from files()
     *
     * @return array<string, mixed>
     */
    public static function contentSchema(array $files): array
    {
        return [
            'files'   => $files,
            'scripts' => ['pre' => self::PRE_SCRIPT, 'post' => self::POST_SCRIPT],
        ];
    }

    /**
     * The pre and post scripts. The Upgrade center includes them inside its install method (on
     * 4.11 a pre script's return value is ignored, so a refusal is an exception, which both
     * versions report); the post script reads that method's $content_path.
     *
     * @return array<string, string> file name => PHP source
     */
    public static function scripts(string $from, string $to): array
    {
        $head = "<?php\n// Quissly Search %s -> %s, CS-Cart's Upgrade center: %s (lib/Update/UpgradeCenter.php).\n"
            . "defined('BOOTSTRAP') or die('Access denied');\n";

        return [
            self::PRE_SCRIPT => sprintf($head, $from, $to, 'before the files are copied')
                . sprintf("\\Quissly\\Search\\Update\\UpgradeCenter::before(%s, %s);\n", var_export($from, true), var_export($to, true)),
            self::POST_SCRIPT => sprintf($head, $from, $to, 'after the files are copied')
                . sprintf("\\Quissly\\Search\\Update\\UpgradeCenter::after(%s, \$content_path . 'package/');\n", var_export($to, true)),
        ];
    }

    /** Whether $dir is a folder inside one of Release::ROOTS (not the root itself). */
    private static function underRoot(string $dir): bool
    {
        foreach (Release::ROOTS as $root) {
            if (strpos($dir, $root . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The files under $packageDir that the store root does not hold as packaged (after the
     * Upgrade center's copy: none, unless a folder could not be written).
     *
     * @return list<string>
     */
    public static function notCopied(string $packageDir, string $root): array
    {
        $packageDir = rtrim($packageDir, '/') . '/';
        $root = rtrim($root, '/') . '/';
        $missing = [];
        foreach (self::filesIn($packageDir) as $path => $hash) {
            if (!is_file($root . $path) || md5_file($root . $path) !== $hash) {
                $missing[] = $path;
            }
        }

        return $missing;
    }
}
