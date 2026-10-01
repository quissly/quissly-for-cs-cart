<?php

declare(strict_types=1);

namespace Quissly\Search;

use Quissly\Search\Exception\ConfigurationException;

/**
 * Produces the X-Signature for a QSearch request.
 *
 * Scheme (verified live against prod, HTTP 200):
 *   sign = base64( RSA-SHA256-PKCS#1v1.5( "{METHOD}\n{PATH}\n{TIMESTAMP_MS}\n{NONCE}" ) )
 *
 * PKCS#1 v1.5 is OpenSSL's default for openssl_sign — do NOT switch to PSS.
 * It is deterministic, so for a fixed (method, path, ts, nonce, key) the output
 * is reproducible; that property is what the signer unit test locks against the
 * known-good signature captured from qsearch_test.sh.
 *
 * Pure: no CS-Cart dependencies, so it is unit-testable in isolation.
 */
final class Signer
{
    /**
     * Build the exact string that gets signed. Four components, newline-joined,
     * no trailing newline.
     */
    public static function canonicalString(string $method, string $path, int $timestampMs, string $nonce): string
    {
        return $method . "\n" . $path . "\n" . $timestampMs . "\n" . $nonce;
    }

    /**
     * @return string base64-encoded signature
     *
     * @throws ConfigurationException if the private key cannot be loaded or signing fails
     */
    public static function sign(string $method, string $path, int $timestampMs, string $nonce, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            // Never include the key material in the message.
            throw new ConfigurationException('Quissly private key is invalid or could not be parsed.');
        }

        $data = self::canonicalString($method, $path, $timestampMs, $nonce);
        $signature = '';
        $ok = openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        if ($ok === false || $signature === '') {
            throw new ConfigurationException('Quissly request signing failed.');
        }

        return base64_encode($signature);
    }

    /**
     * The v1 (catalog) signed string: "{payload_param}.{timestamp}". payload_param is
     * the batch's first product id (add/update/delete) or the operation_id (status).
     */
    public static function catalogCanonicalString(string $payloadParam, string $timestamp): string
    {
        return $payloadParam . '.' . $timestamp;
    }

    /**
     * v1 (catalog) signature - a different scheme from v2 search; never mix them.
     *
     * @return string base64-encoded signature
     *
     * @throws ConfigurationException if the private key cannot be loaded or signing fails
     */
    public static function signCatalog(string $payloadParam, string $timestamp, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new ConfigurationException('Quissly private key is invalid or could not be parsed.');
        }

        $signature = '';
        $ok = openssl_sign(self::catalogCanonicalString($payloadParam, $timestamp), $signature, $key, OPENSSL_ALGO_SHA256);
        if ($ok === false || $signature === '') {
            throw new ConfigurationException('Quissly catalog request signing failed.');
        }

        return base64_encode($signature);
    }

    /**
     * v1 body timestamp: Python-style, SPACE separator, 6-digit microseconds, UTC offset
     * (2025-01-09 08:44:14.318804+00:00). Also the signed timestamp for add/update/delete.
     */
    public static function catalogTimestamp(?float $unixTime = null): string
    {
        return self::formatUtc($unixTime, 'Y-m-d H:i:s.uP');
    }

    /**
     * v1 status-poll timestamp: ISO 8601 with a 'T' (2026-05-31T12:24:49.249639+00:00).
     * It is both the query parameter and the signed "{operation_id}.{ts}" payload.
     */
    public static function statusTimestamp(?float $unixTime = null): string
    {
        return self::formatUtc($unixTime, 'Y-m-d\TH:i:s.uP');
    }

    private static function formatUtc(?float $unixTime, string $format): string
    {
        $dt = \DateTime::createFromFormat('U.u', sprintf('%.6f', $unixTime ?? microtime(true)), new \DateTimeZone('UTC'));

        return $dt->format($format);
    }

    /** Current Unix time in milliseconds. */
    public static function timestampMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /** Fresh RFC-4122 v4 UUID, lowercase. */
    public static function nonce(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function __construct()
    {
    }
}
