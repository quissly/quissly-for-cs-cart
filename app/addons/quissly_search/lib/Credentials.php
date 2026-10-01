<?php

declare(strict_types=1);

namespace Quissly\Search;

use Quissly\Search\Exception\ConfigurationException;
use Quissly\Search\Connect\CredentialStore;
use Quissly\Search\Sync\DbSyncStore;
use Tygh\Registry;

/**
 * The store's Quissly credentials, from one of two sources:
 *
 *  1. config.local.php, when it sets a bearer token — the manual setup, and an
 *     override that always wins:
 *       $config['quissly_bearer_token']     = '...';
 *       $config['quissly_private_key_path'] = '/abs/outside/web/root/quissly_private.pem';
 *       $config['quissly_private_key']      = "-----BEGIN ...";   // inline alternative
 *       $config['quissly_environment']      = 'prod';             // optional, defaults to prod
 *       $config['quissly_project_id'] / $config['quissly_account_email']  // for the panel
 *  2. otherwise, what one-click Connect stored (Connect\CredentialStore: the private
 *     key and API key encrypted with config.local.php's crypt_key).
 *
 * The private key is read into memory only at signing time and never logged.
 */
final class Credentials
{
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_CONNECT = 'connect';
    public const SOURCE_NONE = 'none';
    public const SOURCE_UNDECRYPTABLE = 'undecryptable';

    /** @var array<string, mixed> raw config values */
    private array $config;

    /**
     * @param array<string, mixed> $config raw config values (keys without the
     *                                      'quissly_' prefix: bearer_token,
     *                                      private_key_path, private_key,
     *                                      environment, project_id,
     *                                      account_email)
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** Build from config.local.php, else from what one-click Connect stored. */
    public static function fromRegistry(): self
    {
        $config = [
            'bearer_token'     => (string) Registry::get('config.quissly_bearer_token'),
            'private_key_path' => (string) Registry::get('config.quissly_private_key_path'),
            'private_key'      => (string) Registry::get('config.quissly_private_key'),
            'environment'      => (string) Registry::get('config.quissly_environment'),
            'project_id'       => (string) Registry::get('config.quissly_project_id'),
            'account_email'    => (string) Registry::get('config.quissly_account_email'),
        ];
        if (trim($config['bearer_token']) !== '') {
            return new self($config + ['source' => self::SOURCE_CONFIG]);
        }

        $stored = self::credentialStore()->load();
        if ($stored === null) {
            return new self(['source' => self::SOURCE_NONE, 'environment' => $config['environment']]);
        }
        if (!empty($stored['undecryptable'])) {
            return new self(['source' => self::SOURCE_UNDECRYPTABLE, 'environment' => $config['environment']]);
        }

        return new self($stored + ['source' => self::SOURCE_CONNECT]);
    }

    public static function credentialStore(): CredentialStore
    {
        return new CredentialStore(new DbSyncStore(), (string) Registry::get('config.crypt_key'));
    }

    /** config | connect | none | undecryptable */
    public function source(): string
    {
        return (string) ($this->config['source'] ?? self::SOURCE_CONFIG);
    }

    public function bearerToken(): string
    {
        $token = trim((string) ($this->config['bearer_token'] ?? ''));
        if ($token === '') {
            throw new ConfigurationException('Quissly bearer token is not configured (config.quissly_bearer_token).');
        }

        return $token;
    }

    /** The store's Quissly project id — only the embedded admin panel's sign-in needs it. */
    public function projectId(): string
    {
        return trim((string) ($this->config['project_id'] ?? ''));
    }

    /** The account email Quissly created for this store — only the panel's sign-in needs it. */
    public function accountEmail(): string
    {
        return trim((string) ($this->config['account_email'] ?? ''));
    }

    public function environment(): string
    {
        $env = trim((string) ($this->config['environment'] ?? ''));

        return $env !== '' ? $env : Config::DEFAULT_ENVIRONMENT;
    }

    /**
     * Resolve the RSA private key PEM: prefer a readable path, else the inline
     * string, else fail with a clear (secret-free) error.
     *
     * @throws ConfigurationException
     */
    public function privateKeyPem(): string
    {
        $path = trim((string) ($this->config['private_key_path'] ?? ''));
        if ($path !== '') {
            if (!is_file($path) || !is_readable($path)) {
                throw new ConfigurationException('Quissly private key path is set but the file is missing or unreadable.');
            }
            $pem = file_get_contents($path);
            if ($pem === false || trim($pem) === '') {
                throw new ConfigurationException('Quissly private key file is empty or unreadable.');
            }

            return $pem;
        }

        $inline = (string) ($this->config['private_key'] ?? '');
        if (trim($inline) !== '') {
            return $inline;
        }

        throw new ConfigurationException('Quissly private key is not configured (config.quissly_private_key_path or config.quissly_private_key).');
    }

    /**
     * Lightweight check used by the guard and admin status: are both the bearer
     * token and a private key source present? Does not validate them on the wire.
     */
    public function isConfigured(): bool
    {
        try {
            $this->bearerToken();
            $this->privateKeyPem();

            return true;
        } catch (ConfigurationException $e) {
            return false;
        }
    }
}
