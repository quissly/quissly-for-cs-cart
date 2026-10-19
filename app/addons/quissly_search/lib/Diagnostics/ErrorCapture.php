<?php

declare(strict_types=1);

namespace Quissly\Search\Diagnostics;

/**
 * Catches the add-on's errors in a request and stores them for ErrorReport (started by init.php).
 *
 * - PHP warnings and notices raised in the add-on's own files (an error handler that then hands the
 *   error on to the one it replaced, or to PHP's own handling, so nothing changes for the store);
 * - a fatal error in its files, or in any file while one of its own pages or the cron runs (a shutdown
 *   function). On a storefront page CS-Cart's own fatal handler runs first and ends the request, so a
 *   storefront fatal error is only in CS-Cart's log;
 * - exceptions its hooks and controllers caught (exception()), what it logs with Logger::error
 *   (problem()), Setup / Billing steps that failed and error notices on its pages (refused()), and
 *   its scripts' errors (browser(), from report.js).
 *
 * Occurrences are kept in memory and written to the state row once, when the request ends. Never
 * throws: a failure here is dropped, as is anything after MAX_PER_REQUEST occurrences.
 */
final class ErrorCapture
{
    private const MAX_PER_REQUEST = 20;
    private const WARNINGS = [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_USER_ERROR, E_RECOVERABLE_ERROR];
    private const FATALS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    private static bool $started = false;
    private static bool $busy = false;
    /** @var list<array{entry: array<string, string>, page: string, at: int}> */
    private static array $pending = [];
    /** @var callable|null */
    private static $previous = null;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;
        self::$previous = set_error_handler([self::class, 'onError']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    /**
     * The error handler: records the add-on's own warnings, then lets the previous handler, or PHP,
     * deal with the error as before.
     *
     * @return mixed what the previous handler answered; false = PHP's own handling
     */
    public static function onError(int $type, string $message, string $file = '', int $line = 0)
    {
        if ((error_reporting() & $type) !== 0 && in_array($type, self::WARNINGS, true) && self::ours($file)) {
            self::record(self::kind($type), $message, $file, $line, []);
        }

        return self::$previous !== null ? call_user_func(self::$previous, $type, $message, $file, $line) : false;
    }

    public static function onShutdown(): void
    {
        $last = error_get_last();
        if (is_array($last) && in_array((int) $last['type'], self::FATALS, true)
            && (self::ours((string) $last['file']) || self::onOwnPage())) {
            self::record('php_fatal', (string) $last['message'], (string) $last['file'], (int) $last['line'], []);
        }
        self::persist();
    }

    /** An exception one of the add-on's hooks or controllers caught; $in names which. */
    public static function exception(\Throwable $e, string $in): void
    {
        self::record('exception', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTrace(), ['in' => $in]);
    }

    /**
     * A failure the add-on logged (Logger::error): placed where Logger::error was called.
     *
     * @param array<string, mixed> $context
     */
    public static function problem(string $message, array $context): void
    {
        // [0] is this call, from Logger::error; [1] is Logger::error's call, which is the place.
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $frames[1] ?? [];
        self::record('logged', $message, (string) ($caller['file'] ?? ''), (int) ($caller['line'] ?? 0), [], $context);
    }

    /** A Setup or Billing step that failed, or an error notice on one of the add-on's pages. */
    public static function refused(string $kind, string $message): void
    {
        self::record($kind, $message, '', 0, [], []);
    }

    /**
     * An error in one of the add-on's scripts, sent by report.js.
     *
     * @param array<string, mixed> $report {kind, file, message, at, stack, page}
     */
    public static function browser(array $report, string $device): void
    {
        $file = preg_replace('/[^a-z0-9._-]/', '', strtolower((string) ($report['file'] ?? ''))) ?: 'unknown';
        $stack = mb_substr((string) ($report['stack'] ?? ''), 0, 1500);
        self::record(
            ((string) ($report['kind'] ?? '')) === 'promise' ? 'js_promise' : 'js',
            (string) ($report['message'] ?? ''),
            self::root() . '/js/addons/quissly_search/' . $file,
            0,
            [],
            array_filter([
                'at'     => mb_substr((string) ($report['at'] ?? ''), 0, 40),
                'page'   => mb_substr((string) parse_url((string) ($report['page'] ?? ''), PHP_URL_PATH), 0, 200),
                'device' => $device,
                'stack'  => $stack,
            ], static fn ($value): bool => $value !== '')
        );
    }

    /**
     * Write this request's occurrences to the state row (the shutdown function; also for tests).
     *
     * @param (callable(string): ?array<string, mixed>)|null $get
     * @param (callable(string, array<string, mixed>): void)|null $set
     */
    public static function persist(?callable $get = null, ?callable $set = null): void
    {
        if (self::$pending === [] || self::$busy) {
            return;
        }
        self::$busy = true;
        try {
            if ($get === null || $set === null) {
                if (!function_exists('db_get_field')) {
                    return;
                }
                $store = new \Quissly\Search\Sync\DbSyncStore();
                $get = [$store, 'getState'];
                $set = [$store, 'setState'];
            }
            $state = (array) ($get(ErrorReport::STATE) ?? []);
            foreach (self::$pending as $one) {
                $state = ErrorReport::add($state, $one['entry'], $one['page'], $one['at']);
            }
            self::$pending = [];
            $set(ErrorReport::STATE, $state);
        } catch (\Throwable $e) {
            self::$pending = [];
        } finally {
            self::$busy = false;
        }
    }

    /** @return list<array{entry: array<string, string>, page: string, at: int}> for tests */
    public static function pending(): array
    {
        return self::$pending;
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @param array<string, mixed>       $context
     */
    private static function record(string $kind, string $message, string $file, int $line, array $frames, array $context = []): void
    {
        if (self::$busy || count(self::$pending) >= self::MAX_PER_REQUEST) {
            return;
        }
        self::$busy = true;
        try {
            self::$pending[] = [
                'entry' => ErrorReport::entry($kind, $message, $file, $line, $frames, $context, self::root()),
                'page'  => self::page(),
                'at'    => time(),
            ];
        } catch (\Throwable $e) {
            // Nothing to do: reporting never gets in the store's way.
        } finally {
            self::$busy = false;
        }
    }

    /** Whether a file is one of the add-on's own (its PHP, under app/addons/quissly_search/). */
    private static function ours(string $file): bool
    {
        return $file !== '' && strpos($file, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR) === 0;
    }

    /** Whether this request is one of the add-on's own pages or the cron. */
    private static function onOwnPage(): bool
    {
        return strpos((string) ($_REQUEST['dispatch'] ?? ''), 'quissly_search.') === 0;
    }

    private static function page(): string
    {
        $area = defined('CONSOLE') && CONSOLE ? 'cli' : (defined('AREA') ? (string) AREA : '');
        $dispatch = $_REQUEST['dispatch'] ?? '';

        return trim($area . ' ' . (is_string($dispatch) ? substr($dispatch, 0, 80) : ''));
    }

    private static function root(): string
    {
        return defined('DIR_ROOT') ? (string) DIR_ROOT : dirname(__DIR__, 5);
    }

    private static function kind(int $type): string
    {
        return in_array($type, [E_NOTICE, E_USER_NOTICE], true) ? 'php_notice' : 'php_warning';
    }

    private function __construct()
    {
    }
}
