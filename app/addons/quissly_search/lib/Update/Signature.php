<?php

declare(strict_types=1);

namespace Quissly\Search\Update;

/**
 * The Ed25519 check of a release's signature: PHP's sodium extension when it has one, else the
 * bundled pure-PHP copy of paragonie/sodium_compat v1.24.2 (third_party/sodium_compat, ISC licence:
 * only the files its verify uses, unchanged), so a host without sodium updates too (~50 ms for a
 * release zip). The copy needs 64-bit PHP.
 *
 * A class of its own, not methods on Release: an update runs in a request that has already loaded
 * the previous version's Release, which lacks anything added to it here.
 */
final class Signature
{
    /** The copy's files, in load order (each file skips itself when its class exists). */
    private const COPY = [
        'SodiumException.php',
        'Compat.php',
        'Core/Util.php',
        'Core/Curve25519/Fe.php',
        'Core/Curve25519/H.php',
        'Core/Curve25519/Ge/P1p1.php',
        'Core/Curve25519/Ge/P2.php',
        'Core/Curve25519/Ge/P3.php',
        'Core/Curve25519/Ge/Cached.php',
        'Core/Curve25519/Ge/Precomp.php',
        'Core/Curve25519.php',
        'Core/Ed25519.php',
    ];

    /** Whether this PHP can check a release's signature. */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached') || (PHP_INT_SIZE === 8 && is_file(self::dir() . 'Core/Ed25519.php'));
    }

    /** Whether $sig (64 bytes) is $key's (32 bytes) signature of $data; false when it cannot be checked. */
    public static function verify(string $sig, string $data, string $key): bool
    {
        if (function_exists('sodium_crypto_sign_verify_detached')) {
            try {
                return sodium_crypto_sign_verify_detached($sig, $data, $key);
            } catch (\Throwable $e) {
                return false;
            }
        }

        return self::verifyInPhp($sig, $data, $key);
    }

    /** The bundled copy's check (public for the tests, whose PHP has sodium). */
    public static function verifyInPhp(string $sig, string $data, string $key): bool
    {
        if (PHP_INT_SIZE !== 8 || strlen($sig) !== 64 || strlen($key) !== 32) {
            return false;
        }
        try {
            foreach (self::COPY as $file) {
                // A missing file would be a fatal error, which no catch stops.
                if (!is_file(self::dir() . $file)) {
                    return false;
                }
                require_once self::dir() . $file;
            }

            return \ParagonIE_Sodium_Core_Ed25519::verify_detached($sig, $data, $key) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/third_party/sodium_compat/src/';
    }
}
