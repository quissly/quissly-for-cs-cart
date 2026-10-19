<?php

declare(strict_types=1);

namespace Quissly\Search\Connect;

use Quissly\Search\Config;

/**
 * One-click Connect: creates the store's Quissly account and keeps the credentials,
 * so there is no key to copy and no token to paste. Same endpoint, payload and
 * guard as the WooCommerce plugin's wizard and the Magento plugin's Provisioner:
 * POST console.quissly.com/api/v1/services/external/open-source with the store's
 * domain, the owner's email and a public key generated here; Quissly answers with
 * the API key and account ids.
 *
 * Creating an account is NOT idempotent: a second call makes a second account and
 * strands the first with everything synced to it — so connecting a store that is
 * already connected is refused.
 *
 * Since 2026-10-19 the sign-up takes the email only with a code Quissly mailed there: Setup first
 * asks for one (sendCode(): POST .../open-source/send-code {email, domain}), the merchant types it,
 * and connect() sends it as `verification_code`. Six digits, ten minutes, five tries, a minute
 * before a resend - Quissly's rules and words, passed through.
 *
 * `platform` is 'cs-cart' (Config::CONSOLE_PLATFORM): the console stores it with the
 * key, and its sign-in (the embedded panel) refuses a key whose stored platform
 * differs — live 2026-09-21, a store registered as 'cscart' could not sign in.
 */
final class Connector
{
    public const PATH = '/api/v1/services/external/open-source';
    public const SEND_CODE_PATH = '/api/v1/services/external/open-source/send-code';

    /** Mailing a code is quick upstream; the merchant waits on this one. */
    public const SEND_CODE_TIMEOUT_SECONDS = 30;

    /** Account creation does real work upstream (Magento found 60 s was not enough). */
    public const TIMEOUT_SECONDS = 300;

    private CredentialStore $credentials;

    /** @var callable(string, string): ?array{status:int, body:string} (url, JSON body) */
    private $post;

    public function __construct(CredentialStore $credentials, callable $post)
    {
        $this->credentials = $credentials;
        $this->post = $post;
    }

    /**
     * Ask Quissly to mail a sign-up code to $email for this store's domain.
     *
     * @return array{ok:bool, message:string}
     */
    public function sendCode(string $email, string $domain, bool $alreadyConnected): array
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::fail('quissly_search.connect_email_invalid');
        }
        if ($alreadyConnected || $this->credentials->hasAccount()) {
            return self::fail('quissly_search.connect_already');
        }
        if ($domain === '') {
            return self::fail('quissly_search.connect_no_domain');
        }
        $response = ($this->post)(Config::CONSOLE_URL . self::SEND_CODE_PATH, (string) json_encode(['email' => $email, 'domain' => $domain], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::interpretSendCode($response);
    }

    /**
     * @param ?array{status:int, body:string} $response
     * @return array{ok:bool, message:string}
     */
    public static function interpretSendCode(?array $response): array
    {
        if ($response === null) {
            return ['ok' => false, 'message' => self::t('quissly_search.connect_unreachable', ['[host]' => Config::CONSOLE_URL])];
        }
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return ['ok' => true, 'message' => ''];
        }
        $body = json_decode($response['body'], true);
        // Quissly's own words ("Please wait a minute before requesting another code.") are what help.
        $detail = is_array($body) && isset($body['detail']) && is_string($body['detail']) && trim($body['detail']) !== '' ? $body['detail'] : '';

        return ['ok' => false, 'message' => $detail !== '' ? $detail : self::t('quissly_search.connect_failed', ['[detail]' => 'HTTP ' . $response['status']])];
    }

    /**
     * @param array{domain:string, name:?string, first_name:?string, last_name:?string, environment:string, already_connected:bool, verification_code?:string} $store
     * @return array{ok:bool, message:string}
     */
    public function connect(string $email, array $store): array
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::fail('quissly_search.connect_email_invalid');
        }
        if ($store['already_connected'] || $this->credentials->hasAccount()) {
            return self::fail('quissly_search.connect_already');
        }
        if ($store['domain'] === '') {
            return self::fail('quissly_search.connect_no_domain');
        }

        $keys = $this->credentials->keypair();
        $payload = self::payload($email, $keys['public_pem'], $store);
        $response = ($this->post)(Config::CONSOLE_URL . self::PATH, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = self::interpret($response);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['error']];
        }
        $this->credentials->saveAccount($result['api_key'], $result['project_id'], $result['store_id'], $email, $store['environment']);

        return ['ok' => true, 'message' => ''];
    }

    /**
     * @param array{domain:string, name:?string, first_name:?string, last_name:?string, environment:string} $store
     * @return array<string, string>
     */
    public static function payload(string $email, string $publicPem, array $store): array
    {
        $payload = [
            'domain'            => $store['domain'],
            'public_key'        => $publicPem,
            'email'             => $email,
            'platform'          => Config::CONSOLE_PLATFORM,
            'service_type_slug' => 'qsearch',
            // Quissly Setup's drafted, editable description; else the add-on's own line.
            'description'       => isset($store['description']) && trim((string) $store['description']) !== ''
                ? mb_substr(trim((string) $store['description']), 0, \Quissly\Search\Setup\DescriptionDraft::MAX_LENGTH)
                : 'CS-Cart store connected via the quissly_search add-on.',
            'environment'       => $store['environment'],
        ];
        // Sent only when known: Quissly names the account from the domain otherwise.
        foreach (['name' => $store['name'], 'first_name' => $store['first_name'], 'last_name' => $store['last_name']] as $field => $value) {
            if ($value !== null && trim($value) !== '') {
                $payload[$field] = trim($value);
            }
        }
        $display = trim(($store['first_name'] ?? '') . ' ' . ($store['last_name'] ?? ''));
        if ($display !== '') {
            $payload['display_name'] = $display;
        }
        // The code Quissly mailed to the email (sendCode()); the sign-up refuses without it.
        $code = trim((string) ($store['verification_code'] ?? ''));
        if ($code !== '') {
            $payload['verification_code'] = $code;
        }

        return $payload;
    }

    /**
     * @param ?array{status:int, body:string} $response
     * @return array{ok:bool, api_key:string, project_id:string, store_id:string, error:string}
     */
    public static function interpret(?array $response): array
    {
        $fail = static fn (string $error): array => ['ok' => false, 'api_key' => '', 'project_id' => '', 'store_id' => '', 'error' => $error];
        if ($response === null) {
            return $fail(self::t('quissly_search.connect_unreachable', ['[host]' => Config::CONSOLE_URL]));
        }
        $body = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($body)) {
            // Quissly's own wording (e.g. "Email already registered") is what helps.
            $detail = is_array($body) && isset($body['detail']) && is_string($body['detail']) ? $body['detail'] : 'HTTP ' . $response['status'];

            return $fail(self::t('quissly_search.connect_failed', ['[detail]' => $detail]));
        }
        $apiKey = (string) ($body['api_key'] ?? '');
        $projectId = (string) ($body['project_id'] ?? '');
        if ($apiKey === '' || $projectId === '') {
            return $fail(self::t('quissly_search.connect_failed', ['[detail]' => 'no credentials in the answer']));
        }

        return [
            'ok'         => true,
            'api_key'    => $apiKey,
            'project_id' => $projectId,
            'store_id'   => (string) ($body['store_id'] ?? '') !== '' ? (string) $body['store_id'] : $projectId,
            'error'      => '',
        ];
    }

    /** @return array{ok:bool, message:string} */
    private static function fail(string $key): array
    {
        return ['ok' => false, 'message' => self::t($key)];
    }

    /** @param array<string, string> $params */
    private static function t(string $key, array $params = []): string
    {
        return function_exists('__') ? (string) __($key, $params) : $key . ($params === [] ? '' : ' ' . implode(' ', $params));
    }
}
