<?php

declare(strict_types=1);

namespace Quissly\Search;

use Quissly\Search\Exception\AuthException;
use Quissly\Search\Exception\ClientErrorException;
use Quissly\Search\Exception\QuisslyException;
use Quissly\Search\Exception\RateLimitException;
use Quissly\Search\Exception\ServerException;
use Quissly\Search\Exception\TransportException;
use Tygh\Http;

/**
 * Server-side QSearch caller.
 *
 * Builds the verified v2 request (signed string + headers + JSON body), sends it
 * via CS-Cart's Tygh\Http (no Guzzle), and maps the
 * outcome to the typed exception hierarchy so the interceptor can apply the
 * fallback matrix. On 200 it returns a parsed {@see Result} (IDs only).
 *
 * Signing happens here, server-side only, so visitors can never forge a call.
 */
final class Client implements QSearchClient
{
    private Credentials $credentials;

    public function __construct(Credentials $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * @throws QuisslyException on any non-200 outcome or transport failure
     */
    public function qsearch(string $query, ?string $userId, int $pageNumber, int $pageSize, int $sortBy, int $sortType, array $shopper = [], array $filters = []): Result
    {
        $body = $this->buildBody($query, $userId, $pageNumber, $pageSize, $sortBy, $sortType, $shopper, $filters);
        $headers = $this->buildHeaders($body);

        // Whole-second granularity is all Tygh\Http exposes; the 2.5s budget is
        // enforced as a 3s hard ceiling (ceil) — the closest we can get without
        // replacing the mandated HTTP client.
        $timeout = (int) ceil(Config::TIMEOUT_SECONDS);

        $response = Http::post(Config::url(), $body, HttpLog::extra([
            'headers'            => $headers,
            'timeout'            => $timeout,
            'connection_timeout' => $timeout,
            'execution_timeout'  => $timeout,
        ]));

        $status = Http::getStatus();

        // No HTTP status reached -> transport error or timeout. Falls back on page 1.
        if ($response === false || !is_int($status) || $status === 0) {
            Logger::error('QSearch transport failure', ['transport_error' => Http::getError() ?: 'unknown']);
            throw new TransportException('QSearch transport failure: ' . (Http::getError() ?: 'no response'));
        }

        if ($status === 200) {
            return ResponseParser::parse((string) $response, static function ($rawId): void {
                Logger::error('Dropped malformed product id from QSearch response', [
                    'raw_id_type' => gettype($rawId),
                ]);
            });
        }

        $this->throwForStatus($status, (string) $response);
    }

    /**
     * Voice or image search (the WooCommerce plugin's voice()/visual(), same
     * shapes): voice rides /v2beta/qsearch with `audio` (base64 16 kHz mono
     * WAV) instead of `query`; image is /v2beta/qimage with `image` (base64 JPEG). Same
     * v2 header signing as text search, NO body timestamp (qimage rejects one: 422).
     * Returns the ids and, for voice, the transcription Quissly echoes in `query`.
     *
     * @param array<string, mixed> $media ['audio' => b64] or ['image' => b64]
     * @return array{result: Result, transcription: string}
     *
     * @throws QuisslyException on any non-200 outcome or transport failure
     */
    public function media(string $path, array $media, ?string $userId, int $pageSize): array
    {
        $json = json_encode($media + [
            'user_id'          => $userId,
            'include_metadata' => false,
            'channel'          => Config::CHANNEL,
            'page_number'      => 1,
            'page_size'        => $pageSize,
        ], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ClientErrorException('Failed to encode the media search body.');
        }

        $response = Http::post(Config::BASE_URL . $path, $json, HttpLog::extra([
            'headers'            => $this->buildHeaders($json, $path),
            'timeout'            => Config::MEDIA_TIMEOUT_SECONDS,
            'connection_timeout' => Config::MEDIA_TIMEOUT_SECONDS,
            'execution_timeout'  => Config::MEDIA_TIMEOUT_SECONDS,
        ]));
        $status = Http::getStatus();
        if ($response === false || !is_int($status) || $status === 0) {
            throw new TransportException('Media search transport failure: ' . (Http::getError() ?: 'no response'));
        }
        if ($status !== 200) {
            $this->throwForStatus($status, (string) $response);
        }
        $decoded = json_decode((string) $response, true);

        return [
            'result'        => ResponseParser::parse((string) $response),
            'transcription' => is_array($decoded) && is_string($decoded['query'] ?? null) ? $decoded['query'] : '',
        ];
    }

    /**
     * Quick Recommendations for the typed text: the product ids Quissly ranks, in its order
     * (Quick\QuickResponseParser) - none when Quissly does not answer 200 (a 404 is Quick not
     * enabled for the account). Quick takes no page fields (a 422 "Extra inputs").
     *
     * @return list<int>
     *
     * @throws TransportException when nothing answered
     */
    public function quick(string $query, ?string $userId): array
    {
        $answer = $this->post(Config::QUICK_PATH, [
            'query'            => $query,
            'user_id'          => $userId,
            'include_metadata' => true,
            'channel'          => Config::CHANNEL,
        ], (int) ceil(Config::TIMEOUT_SECONDS));
        if ($answer === null) {
            throw new TransportException('Quick transport failure: ' . (Http::getError() ?: 'no response'));
        }

        return $answer['status'] === 200 ? Quick\QuickResponseParser::ids($answer['body']) : [];
    }

    /**
     * @return non-empty-string JSON body
     */
    /**
     * @param array{device?: string, os?: string} $shopper
     * @param array<string, mixed>                $filters
     */
    private function buildBody(string $query, ?string $userId, int $pageNumber, int $pageSize, int $sortBy, int $sortType, array $shopper, array $filters = []): string
    {
        // Verified contract: query, user_id, page_size, channel, page_number,
        // sort_by/sort_type - plus device / os (QueryRequestV2, 2026-09-30, as the Shopify
        // app sends them). NO body timestamp, NO include_metadata.
        // sort_by/sort_type use Quissly's authoritative codes (see SortMap);
        // sort_by=0 (relevance) is equivalent to omitting them (verified live).
        $payload = [
            'query'       => $query,
            'user_id'     => $userId,
            'page_size'   => $pageSize,
            'channel'     => Config::CHANNEL,
            'page_number' => $pageNumber,
            'sort_by'     => $sortBy,
            'sort_type'   => $sortType,
        ];
        // Who is searching, as the Shopify app sends it (QueryRequestV2 device / os).
        if (($shopper['device'] ?? '') !== '') {
            $payload['device'] = $shopper['device'];
        }
        if (($shopper['os'] ?? '') !== '') {
            $payload['os'] = $shopper['os'];
        }
        // The shopper's price range (PriceFilter): applied by Quissly to each result row before
        // variants collapse, the sort and the page, so num_total_results counts what matches.
        if ($filters !== []) {
            $payload['filters'] = $filters;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new ClientErrorException('Failed to encode QSearch request body.');
        }

        return $json;
    }

    /**
     * A signed v2 POST to another route (Quissly's suggestion generator): the status and the raw
     * body, or null when nothing answered. Never throws for an HTTP status - the caller reads it.
     *
     * @return array{status:int, body:string}|null
     */
    public function post(string $path, array $body, int $timeoutSeconds): ?array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }
        $response = Http::post(Config::BASE_URL . $path, $json, HttpLog::extra([
            'headers'            => $this->buildHeaders($json, $path),
            'timeout'            => $timeoutSeconds,
            'connection_timeout' => min(10, $timeoutSeconds),
            'execution_timeout'  => $timeoutSeconds,
        ]));
        $status = Http::getStatus();

        return $response === false || !is_int($status) || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
    }

    /**
     * @return list<string> "Key: Value" header lines for cURL
     */
    private function buildHeaders(string $body, string $path = Config::QSEARCH_PATH): array
    {
        $timestampMs = Signer::timestampMs();
        $nonce = Signer::nonce();

        // The SAME timestamp and nonce that are signed are sent as headers.
        $signature = Signer::sign(
            Config::METHOD,
            $path,
            $timestampMs,
            $nonce,
            $this->credentials->privateKeyPem()
        );

        // Verified contract header set. X-Platform carries the platform enum value
        // (Config::PLATFORM): 'cs-cart' -> 200, 'cscart' -> 422.
        return [
            'Authorization: Bearer ' . $this->credentials->bearerToken(),
            'X-Signature: ' . $signature,
            'X-Environment: ' . $this->credentials->environment(),
            'X-Timestamp: ' . $timestampMs,
            'X-Nonce: ' . $nonce,
            'X-Platform: ' . Config::PLATFORM,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ];
    }

    /**
     * @return never
     *
     * @throws QuisslyException
     */
    private function throwForStatus(int $status, string $body = ''): void
    {
        // 401/402/403 -> merchant config/billing problem, NO fallback swap. Quissly's own reason
        // ("No active quota grant for this service") is kept for the merchant's message.
        if ($status === 401 || $status === 402 || $status === 403) {
            Logger::error('QSearch authorization/plan failure', ['status' => $status]);
            throw new AuthException('QSearch returned ' . $status . ' (auth/payment/plan).', $status, null, self::detail($body));
        }

        if ($status === 429) {
            Logger::error('QSearch rate limited', ['status' => $status]);
            throw new RateLimitException('QSearch returned 429 (rate limited).', $status);
        }

        // 400/404/422 -> our bug. Log loudly, fall back.
        if ($status === 400 || $status === 404 || $status === 422) {
            Logger::error('QSearch client error (our bug)', ['status' => $status]);
            throw new ClientErrorException('QSearch returned ' . $status . ' (client error).', $status);
        }

        if ($status >= 500) {
            Logger::error('QSearch server error', ['status' => $status]);
            throw new ServerException('QSearch returned ' . $status . ' (server error).', $status);
        }

        // Any other unexpected status -> treat as our bug and fall back.
        Logger::error('QSearch unexpected status', ['status' => $status]);
        throw new ClientErrorException('QSearch returned unexpected status ' . $status . '.', $status);
    }

    /** Quissly's reason for refusing (the `detail` of its error answer), as plain text, or ''. */
    public static function detail(string $body): string
    {
        $decoded = json_decode($body, true);
        $detail = is_array($decoded) && is_string($decoded['detail'] ?? null) ? $decoded['detail'] : '';
        $detail = trim((string) preg_replace('/\s+/', ' ', strip_tags($detail)));

        return function_exists('mb_substr') ? mb_substr($detail, 0, 200) : substr($detail, 0, 200);
    }
}
