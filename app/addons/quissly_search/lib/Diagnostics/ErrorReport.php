<?php

declare(strict_types=1);

namespace Quissly\Search\Diagnostics;

use Quissly\Search\Events\VersionReport;

/**
 * The add-on's own errors, reported to Quissly's `POST /v2beta/integrations/errors`, which writes them
 * to Quissly's log (lib/Diagnostics/ErrorCapture catches them; func.php fn_quissly_search_error_report
 * sends them). The call carries Quissly's integrations token (X-Integrations-Token, the same for every
 * store) and is not signed, so it works before the store is connected too; the log knows a report's
 * store only by what the report says - its `shop` (host) and, once connected, its QSearch `service`.
 *
 * What is caught: PHP warnings, notices and fatal errors in the add-on's files, exceptions out of its
 * hooks and controllers, everything it logs with Logger::error (Quissly refusing or not answering, a
 * catalog batch refused, an update that could not install), Setup and Billing steps that failed, error
 * notices on its pages, and JavaScript errors in its scripts (js/addons/quissly_search/report.js).
 *
 * The same problem is one entry (its fingerprint: kind, place and message with the numbers taken out),
 * counted; an entry is reported at most once an hour, with how many times it happened since. Entries are
 * kept in a state row (STATE) until Quissly takes them. At most KEEP entries; one not seen for FORGET
 * seconds and already reported is dropped.
 *
 * Nothing secret or personal leaves the store: messages are cut to MESSAGE characters, with the store's
 * folder, query strings, SQL statements, HTML, email and IP addresses and long tokens taken out; paths
 * are relative to the store.
 *
 * Each report is one body {platform: "cs-cart", error} (body()), the error being "plugin_error " and the
 * report as JSON (text()), at most Quissly's 10,000 characters. A report Quissly did not take is counted as unsent again (unsent()) and
 * tried with the next run's reports.
 *
 * Pure (unit-tested without CS-Cart).
 */
final class ErrorReport
{
    public const PATH = '/v2beta/integrations/errors';
    public const PREFIX = 'plugin_error ';
    public const MAX_TEXT = 10000;
    /** Reports sent in one run; the rest wait for the next. */
    public const BATCH = 10;
    public const STATE = 'error_report';
    public const KEEP = 50;
    public const EVERY = 3600;
    public const FORGET = 7 * 86400;

    private const MESSAGE = 500;
    private const CONTEXT = 500;
    private const FRAMES = 8;

    /**
     * One occurrence, cleaned.
     *
     * @param list<array{file?: string, line?: int, function?: string, class?: string}> $frames a stack trace (no arguments)
     * @param array<string, mixed>                                                      $context safe details (Logger's context)
     * @param string                                                                     $root    the store's folder, taken out of paths
     * @return array{fingerprint: string, kind: string, message: string, where: string, trace: string, context: string}
     */
    public static function entry(string $kind, string $message, string $file, int $line, array $frames, array $context, string $root): array
    {
        $kind = preg_replace('/[^a-z0-9_]/', '', strtolower($kind)) ?: 'error';
        $message = self::clean($message, $root, self::MESSAGE);
        $where = $file !== '' ? self::path($file, $root) . ($line > 0 ? ':' . $line : '') : '';
        $trace = [];
        foreach (array_slice($frames, 0, self::FRAMES) as $frame) {
            $call = (isset($frame['class']) ? $frame['class'] . '::' : '') . (string) ($frame['function'] ?? '');
            $at = isset($frame['file']) ? self::path((string) $frame['file'], $root) . ':' . (int) ($frame['line'] ?? 0) : '';
            $trace[] = trim($at . ' ' . $call);
        }
        $encoded = $context !== [] ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '';
        $normal = preg_replace('/\d+/', '#', $message);

        return [
            'fingerprint' => substr(sha1($kind . '|' . preg_replace('/:\d+$/', '', $where) . '|' . $normal), 0, 16),
            'kind'        => $kind,
            'message'     => $message,
            'where'       => $where,
            'trace'       => self::clean(implode("\n", $trace), $root, 2000, false),
            'context'     => self::clean((string) $encoded, $root, self::CONTEXT),
        ];
    }

    /**
     * Count an occurrence in the stored entries.
     *
     * @param array<string, mixed> $state the stored record ([] before the first one)
     * @param array<string, string> $entry from entry()
     * @param string               $page  where it happened: area and dispatch ("A quissly_search.setup")
     * @return array<string, mixed>
     */
    public static function add(array $state, array $entry, string $page, int $now): array
    {
        $problems = is_array($state['problems'] ?? null) ? $state['problems'] : [];
        $key = $entry['fingerprint'];
        $known = is_array($problems[$key] ?? null) ? $problems[$key] : null;
        $problems[$key] = array_merge($entry, [
            'page'    => mb_substr(preg_replace('/[^A-Za-z0-9_. ]/', '', $page) ?? '', 0, 100),
            'count'   => (int) ($known['count'] ?? 0) + 1,
            'pending' => (int) ($known['pending'] ?? 0) + 1,
            'first'   => (int) ($known['first'] ?? $now),
            'last'    => $now,
            'sent_at' => (int) ($known['sent_at'] ?? 0),
        ]);
        // Over the limit: the one seen longest ago goes, an already reported one first.
        while (count($problems) > self::KEEP) {
            $drop = null;
            foreach ($problems as $fingerprint => $problem) {
                $rank = [(int) $problem['pending'] > 0 ? 1 : 0, (int) $problem['last']];
                if ($fingerprint !== $key && ($drop === null || $rank < $drop[1])) {
                    $drop = [$fingerprint, $rank];
                }
            }
            unset($problems[$drop[0]]);
        }
        $state['problems'] = $problems;

        return $state;
    }

    /**
     * What to report now: each entry with new occurrences, once an hour.
     *
     * @param array<string, mixed>  $state    the stored record
     * @param array<string, string> $versions {shop, service, version, platform_version, php_version}
     * @return array{state: array<string, mixed>, reports: list<array<string, mixed>>}
     */
    public static function due(array $state, array $versions, int $now): array
    {
        $problems = is_array($state['problems'] ?? null) ? $state['problems'] : [];
        $reports = [];
        foreach ($problems as $fingerprint => $problem) {
            $pending = (int) ($problem['pending'] ?? 0);
            if ($pending <= 0) {
                if ((int) ($problem['last'] ?? 0) < $now - self::FORGET) {
                    unset($problems[$fingerprint]);
                }
                continue;
            }
            if ($now - (int) ($problem['sent_at'] ?? 0) < self::EVERY) {
                continue;
            }
            $reports[] = self::metadata($problem, $versions);
            $problems[$fingerprint]['pending'] = 0;
            $problems[$fingerprint]['sent_at'] = $now;
        }
        $state['problems'] = $problems;

        return ['state' => $state, 'reports' => $reports];
    }

    /**
     * An event's metadata.
     *
     * @param array<string, mixed>  $problem  a stored entry
     * @param array<string, string> $versions {version, platform_version, php_version}
     * @return array<string, mixed>
     */
    public static function metadata(array $problem, array $versions): array
    {
        return [
            'platform'         => VersionReport::PLATFORM,
            'shop'             => (string) ($versions['shop'] ?? ''),
            'service'          => (string) ($versions['service'] ?? ''),
            'version'          => (string) ($versions['version'] ?? ''),
            'platform_version' => (string) ($versions['platform_version'] ?? ''),
            'php_version'      => (string) ($versions['php_version'] ?? ''),
            'fingerprint'      => (string) ($problem['fingerprint'] ?? ''),
            'kind'             => (string) ($problem['kind'] ?? ''),
            'message'          => (string) ($problem['message'] ?? ''),
            'where'            => (string) ($problem['where'] ?? ''),
            'trace'            => (string) ($problem['trace'] ?? ''),
            'context'          => (string) ($problem['context'] ?? ''),
            'page'             => (string) ($problem['page'] ?? ''),
            'count'            => (int) ($problem['pending'] ?? 0),
            'total'            => (int) ($problem['count'] ?? 0),
            'first_seen'       => gmdate('Y-m-d\TH:i:s\Z', (int) ($problem['first'] ?? 0)),
            'last_seen'        => gmdate('Y-m-d\TH:i:s\Z', (int) ($problem['last'] ?? 0)),
        ];
    }

    /**
     * The request body for one `error` string.
     *
     * @return array{platform: string, error: string}
     */
    public static function body(string $error): array
    {
        return ['platform' => VersionReport::PLATFORM, 'error' => $error];
    }

    /**
     * The endpoint's `error` string for one report: the prefix and the report as JSON, the trace
     * and then the context left out when it would be longer than Quissly takes.
     *
     * @param array<string, mixed> $metadata metadata()
     */
    public static function text(array $metadata): string
    {
        foreach ([[], ['trace'], ['trace', 'context']] as $drop) {
            $text = self::PREFIX . (string) json_encode(array_diff_key($metadata, array_flip($drop)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (mb_strlen($text) <= self::MAX_TEXT) {
                return $text;
            }
        }

        return mb_substr($text, 0, self::MAX_TEXT);
    }

    /**
     * A report Quissly did not take: its occurrences count as unsent again. Tried in the next run
     * when $soon (it was never sent: the run's batch was full, or an earlier send had failed),
     * else an hour after this try.
     *
     * @param array<string, mixed> $state    the stored record, after due()
     * @param array<string, mixed> $metadata the report, from due()
     * @return array<string, mixed>
     */
    public static function unsent(array $state, array $metadata, bool $soon): array
    {
        $fingerprint = (string) ($metadata['fingerprint'] ?? '');
        if (!isset($state['problems'][$fingerprint])) {
            return $state;
        }
        $state['problems'][$fingerprint]['pending'] = (int) $state['problems'][$fingerprint]['pending'] + (int) ($metadata['count'] ?? 0);
        if ($soon) {
            $state['problems'][$fingerprint]['sent_at'] = 0;
        }

        return $state;
    }

    /** A message as it may leave the store: no folder, query string, email address or token. */
    public static function clean(string $text, string $root, int $max, bool $oneLine = true): string
    {
        $root = rtrim($root, '/');
        if ($root !== '') {
            $text = str_replace($root . '/', '', $text);
        }
        // CS-Cart's database errors carry the statement, with its values, as "<p>SQL</p>": not sent.
        $text = (string) preg_replace('~<p>.*?(</p>|$)~is', '', $text);
        $text = strip_tags($text);
        $text = (string) preg_replace('~(https?://[^\s?#"\'<>()]+)\?[^\s"\'<>()]*~i', '$1?…', $text);
        $text = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text);
        $text = (string) preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[ip]', $text);
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+\S+/i', '$1 [hidden]', $text);
        // A long run of letters AND digits is a key, token or id; long function names have no digits.
        $text = (string) preg_replace('~(?=[A-Za-z0-9+_=-]*\d)(?=[A-Za-z0-9+_=-]*[A-Za-z])[A-Za-z0-9+_=-]{32,}~', '[hidden]', $text);
        $text = $oneLine ? (string) preg_replace('/\s+/u', ' ', $text) : (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = trim($text);

        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    private static function path(string $file, string $root): string
    {
        $root = rtrim($root, '/');

        return $root !== '' && strpos($file, $root . '/') === 0 ? substr($file, strlen($root) + 1) : basename($file);
    }

    private function __construct()
    {
    }
}
