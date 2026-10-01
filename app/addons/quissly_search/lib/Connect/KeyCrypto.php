<?php

declare(strict_types=1);

namespace Quissly\Search\Connect;

/**
 * AES-256-GCM for the credentials the add-on stores itself (the private key and the
 * API key), ported from the WooCommerce plugin's Quissly_Key_Crypto. The secret is
 * CS-Cart's own `$config['crypt_key']` (config.local.php), so the database alone
 * does not reveal them. GCM detects tampering: a wrong secret or a modified value
 * fails closed (null).
 */
final class KeyCrypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    /** @return string base64(iv | tag | ciphertext) */
    public static function encrypt(string $plaintext, string $secret): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key($secret), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);

        return base64_encode($iv . $tag . (string) $ciphertext);
    }

    public static function decrypt(string $payload, string $secret): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            return null;
        }
        $plain = openssl_decrypt(
            substr($raw, self::IV_LEN + self::TAG_LEN),
            self::CIPHER,
            self::key($secret),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LEN),
            substr($raw, self::IV_LEN, self::TAG_LEN)
        );

        return $plain === false ? null : $plain;
    }

    private static function key(string $secret): string
    {
        return hash('sha256', $secret, true);
    }

    private function __construct()
    {
    }
}
