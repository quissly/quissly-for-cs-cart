<?php

declare(strict_types=1);

namespace Quissly\Search\Sync;

use Quissly\Search\Config;
use Quissly\Search\Credentials;
use Quissly\Search\Signer;

/**
 * v1-signed catalog sender: POST add / PUT update / DELETE against /v1beta/catalog,
 * settled on the answer to the send (2026-10-07, the Quissly Shopify app's way): a 2xx is
 * the batch delivered and the operation's status is not polled.
 * Contract live-verified by the WooCommerce plugin and the Magento plugin against the
 * same backend:
 *
 *  - body {data, service: 'search', timestamp}; add/update carry data as a map
 *    id => record, delete as a list of {id}; ids are STRINGS;
 *  - the signed string is "{first id}.{timestamp}" with the SAME timestamp the body
 *    carries (Signer::catalogTimestamp — space separator);
 *  - the mutation answers 200 + operation_id (Quissly then works through it; its per-item
 *    outcome - GET /v1beta/catalog?operation_id&timestamp&service=search - is not read,
 *    so an add for a product Quissly already holds, or an item it rejects, is not seen).
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

    /** @var callable(string, string, list<string>, ?string): ?array{status:int, body:string} */
    private $transport;

    private Credentials $credentials;

    public function __construct(Credentials $credentials, callable $transport)
    {
        $this->credentials = $credentials;
        $this->transport = $transport;
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

        // Accepted: the batch is delivered.
        $decoded = json_decode($response['body'], true);
        $operationId = is_array($decoded) && isset($decoded['operation_id']) ? (string) $decoded['operation_id'] : '';

        return SyncOutcome::done($ids, [], [])->withOperationId($operationId);
    }

    /**
     * The immediate answer to a mutation: null when accepted (delivered), else the outcome.
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
