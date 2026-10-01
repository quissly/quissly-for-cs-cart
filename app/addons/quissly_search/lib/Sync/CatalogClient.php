<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

use Quissly\Search\Config;
use Quissly\Search\Credentials;
use Quissly\Search\Signer;

/**
 * v1-signed catalog sender: POST add / PUT update / DELETE against /v1beta/catalog,
 * then poll the operation to a terminal state. Contract live-verified by
 * the WooCommerce plugin and the Magento plugin against the same backend:
 *
 *  - body {data, service: 'search', timestamp}; add/update carry data as a map
 *    id => record, delete as a list of {id}; ids are STRINGS;
 *  - the signed string is "{first id}.{timestamp}" with the SAME timestamp the body
 *    carries (Signer::catalogTimestamp — space separator);
 *  - the mutation answers 200 + operation_id; the real outcome comes from
 *    GET /v1beta/catalog?operation_id&timestamp&service=search, signed
 *    "{operation_id}.{timestamp}" with a FRESH ISO timestamp (T separator) per poll.
 *    The body reports `started` before `completed` / `partially completed`, with a
 *    per-item `data` map ({q_external_id, status, reason});
 *  - /add is NOT idempotent: an existing id comes back failed with a reason containing
 *    "already exists" — reported separately so the caller re-sends it as an update.
 *
 * Refusal classification matches the other two plugins: 401, 402, and a 403 WITH a
 * JSON body are the ACCOUNT refusing (retrying cannot help, and marking ids failed
 * would drain the catalog by attrition); a 403 without JSON is an edge block
 * (transient). 429 is the concurrency limit (back off).
 *
 * Transport is injected so the classification is unit-tested without the network;
 * {@see LiveTransport} is the real one.
 */
final class CatalogClient
{
    public const PATH = '/v1beta/catalog';

    public const MAX_STATUS_POLLS = 10;
    public const STATUS_POLL_DELAY_SECONDS = 3;

    /** Quissly's own product id: a UUID (the key of a status body's `data` map). */
    public const QUISSLY_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const PENDING = ['pending', 'processing', 'in progress', 'in_progress', 'queued', 'running', 'started'];

    /** @var callable(string, string, list<string>, ?string): ?array{status:int, body:string} */
    private $transport;

    private Credentials $credentials;

    /** @var callable(int): void */
    private $sleep;

    private int $maxPolls;

    /**
     * @param int $maxPolls status polls per operation before giving up for now (the
     *                      operation is then "unconfirmed", and {@see checkStatus()} can
     *                      look again on a later run)
     */
    public function __construct(Credentials $credentials, callable $transport, ?callable $sleep = null, int $maxPolls = self::MAX_STATUS_POLLS)
    {
        $this->credentials = $credentials;
        $this->transport = $transport;
        $this->maxPolls = max(1, $maxPolls);
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<int|string, array<string, mixed>> $records mapped records keyed by product id
     */
    public function add(array $records): SyncOutcome
    {
        return $this->mutate('POST', $records);
    }

    /**
     * @param array<int|string, array<string, mixed>> $records mapped records keyed by product id
     */
    public function update(array $records): SyncOutcome
    {
        return $this->mutate('PUT', $records);
    }

    /**
     * @param list<int|string> $ids
     */
    public function delete(array $ids): SyncOutcome
    {
        $ids = array_map('strval', array_values($ids));
        if ($ids === []) {
            return SyncOutcome::done([], [], []);
        }
        $data = array_map(static fn (string $id): array => ['id' => $id], $ids);

        return $this->send('DELETE', $data, $ids);
    }

    /**
     * @param array<int|string, array<string, mixed>> $records
     */
    private function mutate(string $method, array $records): SyncOutcome
    {
        if ($records === []) {
            return SyncOutcome::done([], [], []);
        }
        // PHP keys "7" as int 7; as an object, json_encode writes every key as a string.
        return $this->send($method, (object) $records, array_map('strval', array_keys($records)));
    }

    /**
     * @param array<mixed>|object $data
     * @param list<string>        $ids
     */
    private function send(string $method, $data, array $ids): SyncOutcome
    {
        $ts = Signer::catalogTimestamp();
        $body = json_encode(
            ['data' => $data, 'service' => 'search', 'timestamp' => $ts],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($body === false) {
            return SyncOutcome::rejected(0, $ids, 'could not encode the batch as JSON');
        }

        $response = ($this->transport)(
            $method,
            Config::BASE_URL . self::PATH,
            $this->headers($ids[0], $ts),
            $body
        );

        $refusal = self::classifyRefusal($response, $ids);
        if ($refusal !== null) {
            return $refusal;
        }

        $decoded = json_decode($response['body'], true);
        $operationId = is_array($decoded) && isset($decoded['operation_id']) ? (string) $decoded['operation_id'] : '';
        if ($operationId === '') {
            // Accepted without an operation to follow: nothing more can be learned.
            return SyncOutcome::done($ids, [], []);
        }

        return $this->poll($operationId, $ids);
    }

    /**
     * @param list<string> $ids
     */
    private function poll(string $operationId, array $ids): SyncOutcome
    {
        for ($i = 0; $i < $this->maxPolls; $i++) {
            if ($i > 0) {
                ($this->sleep)(self::STATUS_POLL_DELAY_SECONDS);
            }
            $outcome = $this->checkStatus($operationId, $ids);
            if ($outcome !== null) {
                return $outcome;
            }
        }

        return SyncOutcome::unconfirmed($ids, $operationId);
    }

    /**
     * One status look at an operation: its terminal outcome, or null while Quissly is
     * still working on it (or the look itself got no usable answer).
     *
     * @param list<string> $ids the ids that were sent in it
     */
    public function checkStatus(string $operationId, array $ids): ?SyncOutcome
    {
        $ts = Signer::statusTimestamp();
        $url = Config::BASE_URL . self::PATH
            . '?operation_id=' . rawurlencode($operationId)
            . '&timestamp=' . rawurlencode($ts)
            . '&service=search';
        $response = ($this->transport)('GET', $url, $this->headers($operationId, $ts), null);
        if ($response === null || $response['status'] !== 200) {
            return null;
        }
        $outcome = self::interpretStatus(json_decode($response['body'], true), $ids);

        return $outcome === null ? null : $outcome->withOperationId($operationId);
    }

    /**
     * The immediate answer to a mutation: null when accepted (go poll), else the outcome.
     *
     * @param ?array{status:int, body:string} $response
     * @param list<string> $ids
     */
    public static function classifyRefusal(?array $response, array $ids): ?SyncOutcome
    {
        if ($response === null) {
            return SyncOutcome::transport($ids);
        }
        $status = $response['status'];
        if ($status >= 200 && $status < 300) {
            return null;
        }
        if ($status === 429) {
            return SyncOutcome::rateLimited($ids);
        }
        $json = is_array(json_decode($response['body'], true));
        if ($status === 401 || $status === 402 || ($status === 403 && $json)) {
            return SyncOutcome::refused($status, $ids);
        }
        if ($status === 403) {
            return SyncOutcome::transport($ids); // edge/infrastructure block: transient
        }

        return SyncOutcome::rejected($status, $ids, substr($response['body'], 0, 300));
    }

    /**
     * A status body -> the terminal outcome, or null while still in progress.
     *
     * @param mixed        $body decoded status body
     * @param list<string> $ids  the ids that were sent
     */
    public static function interpretStatus($body, array $ids): ?SyncOutcome
    {
        $top = is_array($body) && isset($body['status']) ? strtolower((string) $body['status']) : '';
        if ($top === '' || in_array($top, self::PENDING, true)) {
            return null;
        }

        if (!is_array($body['data'] ?? null) || $body['data'] === []) {
            return in_array($top, ['failed', 'error', 'canceled', 'cancelled'], true)
                ? SyncOutcome::done([], $ids, [])
                : SyncOutcome::done($ids, [], []);
        }

        $ok = $failed = $already = [];
        $quisslyIds = [];
        foreach ($body['data'] as $key => $item) {
            $id = is_array($item) && isset($item['q_external_id']) ? (string) $item['q_external_id'] : '';
            if (!in_array($id, $ids, true)) {
                continue;
            }
            $reason = strtolower((string) ($item['reason'] ?? ''));
            $status = strtolower((string) ($item['status'] ?? ''));
            if (strpos($reason, 'already exists') !== false) {
                $already[] = $id;
            } elseif (in_array($status, ['successful', 'success', 'succeeded', 'completed', 'ok'], true) || strpos($reason, 'no changes') !== false) {
                // "no changes detected" comes back as failed, but means the product (or
                // variant) already holds exactly this data (the Magento plugin, live).
                $ok[] = $id;
            } else {
                $failed[] = $id;
                continue;
            }
            // The item's key is Quissly's own id for the product (uuid5 of the
            // service and our id) - the id the chat widget uses. Kept so the
            // storefront can translate it back (the chat's Add to Cart).
            if (is_string($key) && preg_match(self::QUISSLY_ID, $key)) {
                $quisslyIds[strtolower($key)] = $id;
            }
        }

        $outcome = SyncOutcome::done(array_unique($ok), array_unique($failed), array_unique($already));
        $outcome->quisslyIds = $quisslyIds;

        return $outcome;
    }

    /**
     * @return list<string>
     */
    private function headers(string $payloadParam, string $ts): array
    {
        return [
            'Authorization: Bearer ' . $this->credentials->bearerToken(),
            'X-Signature: ' . Signer::signCatalog($payloadParam, $ts, $this->credentials->privateKeyPem()),
            'X-Environment: ' . $this->credentials->environment(),
            'X-Platform: ' . Config::PLATFORM,
            'Content-Type: application/json',
        ];
    }
}
