<?php

declare(strict_types=1);

namespace Quissly\Search\Panel;

use Quissly\Search\Config;
use Quissly\Search\Credentials;
use Quissly\Search\Sync\SyncStore;

/**
 * The store's QChat agent id: the PUBLIC id the chat widget embeds (never the API
 * key). Provisioning creates the account's qchat service but does not return its
 * id, so it is looked up the way the WooCommerce plugin's
 * Quissly_Live_Service_Directory_Client does: open a panel session
 * ({@see PanelSession}), then GET console.quissly.com/api/v1/services/project/{id}
 * with that session's token and take the qchat service's id. Live 2026-09-21: the
 * test store's list is [qchat, qsearch].
 *
 * Cached in the add-on's state, so storefront pages never wait on the console: the
 * admin (Dashboard, Connect) refreshes it.
 */
final class QChatDirectory
{
    public const STATE = 'qchat';
    public const PATH_PROJECT_SERVICES = '/api/v1/services/project/';

    /** Cached agent id ('' when none is known). */
    public static function cached(SyncStore $store): string
    {
        return (string) ($store->getState(self::STATE)['agent_id'] ?? '');
    }

    /**
     * Look the agent id up now and cache the answer (an unsuccessful lookup keeps a
     * previously found id: a console hiccup must not switch chat off).
     *
     * @param callable(string, string): ?array{status:int, body:string}       $post (url, JSON body)
     * @param callable(string, list<string>): ?array{status:int, body:string} $get  (url, header lines)
     * @return array{agent_id:string, namespace:string, error:string}
     */
    public static function refresh(SyncStore $store, Credentials $credentials, callable $post, callable $get, int $now): array
    {
        $found = self::lookup($credentials, $post, $get);
        $agentId = $found['agent_id'] !== '' ? $found['agent_id'] : self::cached($store);
        $namespace = $found['namespace'] !== '' ? $found['namespace'] : self::cachedNamespace($store);
        $searchId = $found['search_service_id'] !== '' ? $found['search_service_id'] : self::cachedSearchServiceId($store);
        $store->setState(self::STATE, [
            'agent_id' => $agentId, 'namespace' => $namespace, 'search_service_id' => $searchId,
            'checked_at' => $now, 'error' => $found['error'],
        ]);

        return ['agent_id' => $agentId, 'namespace' => $namespace, 'error' => $found['error']];
    }

    /**
     * @return array{agent_id:string, namespace:string, search_service_id:string, error:string} error: not_configured | session_<code> | services_unavailable | no_qchat_service
     *         (namespace: the qsearch service's quissly_service_link - the namespace of the
     *         product ids the chat widget uses, uuid5(namespace, product id); live-confirmed;
     *         search_service_id: the qsearch service's id - where its widget_config, the
     *         search bar suggestions, lives)
     */
    public static function lookup(Credentials $credentials, callable $post, callable $get): array
    {
        if (!$credentials->isConfigured()) {
            return ['agent_id' => '', 'namespace' => '', 'search_service_id' => '', 'error' => 'not_configured'];
        }
        $session = PanelSession::open($credentials->projectId(), $credentials->accountEmail(), $credentials->bearerToken(), $post);
        if (!$session['ok']) {
            return ['agent_id' => '', 'namespace' => '', 'search_service_id' => '', 'error' => 'session_' . $session['error']];
        }
        $response = $get(
            Config::CONSOLE_URL . self::PATH_PROJECT_SERVICES . rawurlencode($credentials->projectId()),
            ['Authorization: Bearer ' . $session['access_token']]
        );
        $list = $response !== null && $response['status'] === 200 ? json_decode($response['body'], true) : null;
        if (!is_array($list)) {
            return ['agent_id' => '', 'namespace' => '', 'search_service_id' => '', 'error' => 'services_unavailable'];
        }
        $agentId = $namespace = $searchId = '';
        foreach ($list as $service) {
            if (!is_array($service)) {
                continue;
            }
            if (($service['service_type_slug'] ?? null) === 'qchat' && !empty($service['id']) && $agentId === '') {
                $agentId = (string) $service['id'];
            }
            if (($service['service_type_slug'] ?? null) === 'qsearch' && !empty($service['quissly_service_link']) && $namespace === '') {
                $namespace = strtolower((string) $service['quissly_service_link']);
            }
            if (($service['service_type_slug'] ?? null) === 'qsearch' && !empty($service['id']) && $searchId === '') {
                $searchId = (string) $service['id'];
            }
        }

        return ['agent_id' => $agentId, 'namespace' => $namespace, 'search_service_id' => $searchId, 'error' => $agentId === '' ? 'no_qchat_service' : ''];
    }

    /** The cached qsearch service id ('' when not known). */
    public static function cachedSearchServiceId(SyncStore $store): string
    {
        return (string) ($store->getState(self::STATE)['search_service_id'] ?? '');
    }

    /** The cached qsearch namespace ('' when not known). */
    public static function cachedNamespace(SyncStore $store): string
    {
        return (string) ($store->getState(self::STATE)['namespace'] ?? '');
    }

    private function __construct()
    {
    }
}
