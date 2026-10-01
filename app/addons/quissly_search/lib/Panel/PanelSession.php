<?php

declare(strict_types=1);

namespace Quissly\Search\Panel;

use Quissly\Search\Config;

/**
 * Signs the store in to the Quissly admin panel, SERVER-SIDE: the store's API key
 * is exchanged for short-lived panel tokens, so the key never reaches the browser —
 * only the tokens do, in the panel iframe's URL. Ported from the WooCommerce plugin
 * (Quissly_Panel_Session) and the Magento plugin (PanelSession):
 * POST {project_id, api_key, email, platform} to the console's
 * /api/v1/auth/service-login/store, which answers {access_token, refresh_token}.
 *
 * Errors: not_configured (nothing to exchange — nothing is sent), rejected (401:
 * usually an email / project id from another Quissly environment),
 * platform_unsupported (422 on the platform field: Quissly's sign-in does not know
 * CS-Cart stores yet), transport_error (no answer), unexpected (anything else).
 */
final class PanelSession
{
    public const PATH_SERVICE_LOGIN = '/api/v1/auth/service-login/store';

    /** An admin waiting on a page, not a shopper waiting on results. */
    public const TIMEOUT_SECONDS = 10;

    /**
     * @param callable(string, string): ?array{status:int, body:string} $post (url, JSON body) -> answer, null when none
     * @return array{ok:bool, access_token:string, refresh_token:string, error:string}
     */
    public static function open(string $projectId, string $email, string $apiKey, callable $post): array
    {
        if ($projectId === '' || $email === '' || $apiKey === '') {
            return self::failure('not_configured');
        }

        $response = $post(Config::CONSOLE_URL . self::PATH_SERVICE_LOGIN, (string) json_encode([
            'project_id' => $projectId,
            'api_key'    => $apiKey,
            'email'      => $email,
            'platform'   => Config::CONSOLE_PLATFORM,
        ], JSON_UNESCAPED_SLASHES));

        if ($response === null) {
            return self::failure('transport_error');
        }

        return self::interpretLogin($response['status'], json_decode($response['body'], true));
    }

    /**
     * Only a 200 carrying BOTH tokens opens a session — the panel needs the refresh
     * token to stay signed in.
     *
     * @param mixed $body decoded response body
     * @return array{ok:bool, access_token:string, refresh_token:string, error:string}
     */
    public static function interpretLogin(int $status, $body): array
    {
        if ($status === 401) {
            return self::failure('rejected');
        }
        if ($status === 422 && stripos((string) json_encode($body['detail'] ?? ''), 'platform') !== false) {
            return self::failure('platform_unsupported');
        }
        $access = is_array($body) && isset($body['access_token']) ? (string) $body['access_token'] : '';
        $refresh = is_array($body) && isset($body['refresh_token']) ? (string) $body['refresh_token'] : '';
        if ($status !== 200 || $access === '' || $refresh === '') {
            return self::failure('unexpected');
        }

        return ['ok' => true, 'access_token' => $access, 'refresh_token' => $refresh, 'error' => ''];
    }

    /** The iframe URL: the panel, bootstrapped with the session. */
    public static function embedUrl(string $panelUrl, string $accessToken, string $refreshToken): string
    {
        return rtrim($panelUrl, '/') . '/?' . http_build_query(
            ['access_token' => $accessToken, 'refresh_token' => $refreshToken],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    /** @return array{ok:bool, access_token:string, refresh_token:string, error:string} */
    private static function failure(string $error): array
    {
        return ['ok' => false, 'access_token' => '', 'refresh_token' => '', 'error' => $error];
    }

    private function __construct()
    {
    }
}
