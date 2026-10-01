<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

use Tygh\Http;

/**
 * The real catalog transport: (method, url, header lines, body) -> {status, body},
 * or null when no HTTP answer arrived.
 *
 * POST, PUT and GET go through Tygh\Http. DELETE cannot:
 * Http::delete() takes no body and always sends an empty one, while Quissly's delete
 * carries the ids in a JSON body — so DELETE uses the same cURL call Tygh\Http makes
 * internally for PUT (CUSTOMREQUEST + POSTFIELDS).
 */
final class LiveTransport
{
    public const TIMEOUT_SECONDS = 60;

    /**
     * @param list<string> $headers
     * @return ?array{status:int, body:string}
     */
    public function __invoke(string $method, string $url, array $headers, ?string $body): ?array
    {
        if ($method === 'DELETE') {
            return self::curl($method, $url, $headers, (string) $body);
        }

        $extra = [
            'headers'            => $headers,
            'timeout'            => self::TIMEOUT_SECONDS,
            'connection_timeout' => 10,
            'execution_timeout'  => self::TIMEOUT_SECONDS,
        ];
        if ($method === 'GET') {
            $response = Http::get($url, [], $extra);
        } elseif ($method === 'PUT') {
            $response = Http::put($url, (string) $body, $extra);
        } else {
            $response = Http::post($url, (string) $body, $extra);
        }

        $status = Http::getStatus();
        if ($response === false || !is_int($status) || $status === 0) {
            return null;
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /**
     * @param list<string> $headers
     * @return ?array{status:int, body:string}
     */
    private static function curl(string $method, string $url, array $headers, string $body): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $status === 0) {
            return null;
        }

        return ['status' => $status, 'body' => $response];
    }
}
