<?php

declare(strict_types=1);

namespace Quissly\Search\Update;

/**
 * Whether CS-Cart's Upgrade center can finish installing on this server, decided without CS-Cart
 * (lib/Update/UpgradeCenter gathers the facts and words the answer).
 *
 * CS-Cart's install (UpgradeCenter\App::installUpgradePackage, 4.11 and 4.21 alike) runs the
 * validators, writes a restore script into upgrades/ when the merchant backs up, runs the pre
 * script, CLOSES every open storefront, backs up, and then emails the administrator - and stops
 * right there, storefront left closed, when that email cannot be sent. These checks run first
 * (the package's validator, again in the pre script), so the install stops with the store open.
 *
 * Conservative: only what would certainly fail is a problem; anything that cannot be told
 * (an add-on's own mail transport, an unknown send method, a program behind open_basedir)
 * passes. Each problem is [reason, detail]: the detail is a program, an address or a URL -
 * never a password.
 */
final class UpgradeCheck
{
    /** CS-Cart's Settings > E-mails > Send method: PhpMailerTransport uses PHP's mail() for these. */
    private const PHP_MAIL = ['', 'mail'];

    /** PHPMailer's port when the SMTP host names none (CS-Cart never sets one). */
    private const SMTP_PORT = 25;

    /**
     * Why the administrator's email cannot be sent the way CS-Cart sends it, or null.
     *
     * @param array<string, mixed> $emails  Settings > E-mails (mailer_send_method, mailer_smtp_host, mailer_sendmail_path)
     * @param array{custom_transport?: bool, windows?: bool, mail_function?: bool, sendmail_path?: string} $php
     *        custom_transport: an add-on registered its own transport for the method;
     *        mail_function: mail() exists and is not disabled; sendmail_path: php.ini's
     * @param callable(string): ?bool      $runnable  whether a program can run (null: cannot tell)
     * @param callable(string, int): ?bool $reachable whether host:port accepts a connection (null: cannot tell)
     *
     * @return array{0: string, 1: string}|null
     */
    public static function mail(array $emails, array $php, callable $runnable, callable $reachable): ?array
    {
        if (!empty($php['custom_transport'])) {
            return null;
        }
        $method = (string) ($emails['mailer_send_method'] ?? '');
        if ($method === 'smtp') {
            $servers = self::smtpServers((string) ($emails['mailer_smtp_host'] ?? ''));
            if ($servers === []) {
                return ['smtp_host', ''];
            }
            foreach ($servers as [$host, $port]) {
                if ($reachable($host, $port) !== false) {
                    return null; // PHPMailer tries each in turn: one answering (or unknown) is enough
                }
            }

            return ['smtp', implode(', ', array_map(static function (array $server): string {
                return $server[0] . ':' . $server[1];
            }, $servers))];
        }
        if ($method === 'sendmail') {
            $program = self::program((string) ($emails['mailer_sendmail_path'] ?? ''));

            return $program !== '' && $runnable($program) === false ? ['sendmail', $program] : null;
        }
        if (!in_array($method, self::PHP_MAIL, true)) {
            return null;
        }
        if (array_key_exists('mail_function', $php) && $php['mail_function'] === false) {
            return ['mail_disabled', ''];
        }
        if (!empty($php['windows'])) {
            return null; // mail() talks SMTP there (php.ini's SMTP), not a program
        }
        // "Could not instantiate mail function": mail() pipes into php.ini's sendmail_path.
        $program = self::program((string) ($php['sendmail_path'] ?? ''));

        return $program !== '' && $runnable($program) === false ? ['mail_program', $program] : null;
    }

    /**
     * Why CS-Cart cannot write and check its restore script before backing up, or null. Asked
     * only when the merchant backs up ("Skip files and database backup" not ticked).
     *
     * @param array{dir: bool, restore: bool, writable: bool, fixable: bool, reachable: ?bool, url: string} $facts
     *        dir: upgrades/ exists; restore: upgrades/source_restore.php is readable; writable: the
     *        restore script's folder can be written; fixable: CS-Cart can make it writable itself
     *        (FTP access in the Upgrade center's settings, or the folder is PHP's own); reachable:
     *        the store answered a request for a script there (null: not asked); url: that address
     *
     * @return array{0: string, 1: string}|null
     */
    public static function backup(array $facts): ?array
    {
        if (!$facts['dir'] || !$facts['restore']) {
            return ['restore_missing', ''];
        }
        if (!$facts['writable'] && !$facts['fixable']) {
            return ['restore_writable', ''];
        }
        if ($facts['reachable'] === false) {
            return ['restore_http', $facts['url']];
        }

        return null;
    }

    /**
     * The SMTP servers PHPMailer tries for CS-Cart's "SMTP host" (6.x and 5.2 alike): ';'-separated
     * entries, each "[ssl:// or tls://]host[:port]", port 25 unless given. Invalid entries are
     * skipped, as PHPMailer skips them.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function smtpServers(string $setting): array
    {
        $servers = [];
        foreach (explode(';', $setting) as $entry) {
            if (!preg_match('/^(?:(?:ssl|tls):\/\/)?(.+?)(?::(\d+))?$/', trim($entry), $match)) {
                continue;
            }
            $host = $match[1];
            if (!preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])$/', $host)) {
                continue;
            }
            $port = isset($match[2]) && (int) $match[2] > 0 && (int) $match[2] < 65536 ? (int) $match[2] : self::SMTP_PORT;
            $servers[] = [$host, $port];
        }

        return $servers;
    }

    /**
     * The program a command line (php.ini's sendmail_path, CS-Cart's sendmail path) runs: its
     * first word, unquoted. '' when that cannot be told (empty, a variable, an assignment).
     */
    public static function program(string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            return '';
        }
        if ($command[0] === '"' || $command[0] === "'") {
            $end = strpos($command, $command[0], 1);
            $program = $end === false ? '' : substr($command, 1, $end - 1);
        } else {
            $program = (string) preg_split('/\s+/', $command)[0];
        }

        return $program === '' || strpbrk($program, '=$`;|&<>(){}*?') !== false ? '' : $program;
    }

    /**
     * Whether $program can run: an absolute path is an executable file; a bare name is found on
     * $path (the web server's PATH, which the shell PHP starts it with searches). null when it
     * cannot be told: a relative path, no PATH, or open_basedir hiding the file from PHP.
     */
    public static function runnable(string $program, string $path, string $openBasedir): ?bool
    {
        if ($program === '' || $openBasedir !== '') {
            return null;
        }
        if (strpos($program, '/') !== false) {
            return $program[0] === '/' ? is_file($program) && is_executable($program) : null;
        }
        if ($path === '') {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $program) && is_executable($dir . '/' . $program)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $function is among php.ini's disable_functions. */
    public static function disabled(string $function, string $disableFunctions): bool
    {
        return in_array(strtolower($function), array_map('trim', explode(',', strtolower($disableFunctions))), true);
    }

    private function __construct()
    {
    }
}
