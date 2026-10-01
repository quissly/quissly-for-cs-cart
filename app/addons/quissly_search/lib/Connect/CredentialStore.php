<?php

declare(strict_types=1);

namespace Quissly\Search\Connect;

use Quissly\Search\Sync\SyncStore;

/**
 * The credentials one-click Connect obtained, in the add-on's state table: the
 * private key and API key encrypted ({@see KeyCrypto}), the public key and account
 * ids in the clear (none of them secret).
 *
 * The keypair is saved BEFORE Quissly is called: nothing has been sent yet, so a
 * failed connect strands nothing, and a retry reuses the same key.
 */
final class CredentialStore
{
    public const STATE = 'credentials';

    private SyncStore $store;
    private string $secret;

    public function __construct(SyncStore $store, string $secret)
    {
        $this->store = $store;
        $this->secret = $secret;
    }

    /** @return array{private_pem:string, public_pem:string} the stored keypair, generating it first if needed */
    public function keypair(): array
    {
        $saved = $this->raw();
        if (isset($saved['private_key'], $saved['public_key'])) {
            $private = KeyCrypto::decrypt((string) $saved['private_key'], $this->secret);
            if ($private !== null) {
                return ['private_pem' => $private, 'public_pem' => (string) $saved['public_key']];
            }
        }

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false || !openssl_pkey_export($res, $private)) {
            throw new \RuntimeException('Could not generate an RSA key pair (OpenSSL).');
        }
        $public = (string) openssl_pkey_get_details($res)['key'];
        $this->store->setState(self::STATE, [
            'private_key' => KeyCrypto::encrypt($private, $this->secret),
            'public_key'  => $public,
        ]);

        return ['private_pem' => $private, 'public_pem' => $public];
    }

    public function saveAccount(string $apiKey, string $projectId, string $storeId, string $email, string $environment): void
    {
        $this->store->setState(self::STATE, array_merge($this->raw() ?? [], [
            'api_key'       => KeyCrypto::encrypt($apiKey, $this->secret),
            'project_id'    => $projectId,
            'store_id'      => $storeId,
            'account_email' => $email,
            'environment'   => $environment,
            'connected_at'  => time(),
        ]));
    }

    public function hasAccount(): bool
    {
        return !empty($this->raw()['api_key']);
    }

    /**
     * The decrypted account, as Credentials config keys; null when not connected.
     * 'undecryptable' is set when the values are there but crypt_key no longer opens
     * them (it was changed) — reconnecting is then the way out.
     *
     * @return ?array<string, string|bool>
     */
    public function load(): ?array
    {
        $saved = $this->raw();
        if (empty($saved['api_key'])) {
            return null;
        }
        $token = KeyCrypto::decrypt((string) $saved['api_key'], $this->secret);
        $private = KeyCrypto::decrypt((string) ($saved['private_key'] ?? ''), $this->secret);
        if ($token === null || $private === null) {
            return ['undecryptable' => true];
        }

        return [
            'bearer_token'  => $token,
            'private_key'   => $private,
            'environment'   => (string) ($saved['environment'] ?? ''),
            'project_id'    => (string) ($saved['project_id'] ?? ''),
            'account_email' => (string) ($saved['account_email'] ?? ''),
        ];
    }

    /** @return ?array<string, mixed> */
    private function raw(): ?array
    {
        return $this->store->getState(self::STATE);
    }
}
