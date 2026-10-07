<?php

declare(strict_types=1);

namespace Quissly\Search\Setup;

use Quissly\Search\Config;

/**
 * Quissly's store billing API: the plans a store can buy, buying one, and managing it.
 *
 * chat-backend-middleware's `app/applications/billing/store_router.py` (STORE_BILLING_API.md)
 * on the console host. `GET /plans` is public; every other route takes the store's API key
 * in `X-Store-Api-Key` - the key Connect stored, sent from this server only and never to the
 * browser. Paid plans are sold only while Quissly's billing is open (`billing_open`, else a
 * 403); the Free plan and reading the store's own plans never wait. The same client as
 * quissly-for-magento's Model/Api/StoreBilling.
 *
 * Pure: HTTP is passed in, as everywhere in this add-on - $get(url, headerLines) and
 * $post(url, body, headerLines), each answering {status, body} or null for no answer.
 * Messages come back as langvar names for the caller to translate.
 */
final class StoreBilling
{
    public const PATH = '/api/v1/billing/store';

    /** The subscription states that mean the store has a live plan. */
    public const LIVE_STATUSES = ['trial', 'active', 'past_due'];

    /** @var string */
    private $apiKey;

    /** @var callable */
    private $get;

    /** @var callable */
    private $post;

    public function __construct(string $apiKey, callable $get, callable $post)
    {
        $this->apiKey = $apiKey;
        $this->get = $get;
        $this->post = $post;
    }

    /** Every plan a store can buy, or null when Quissly could not be reached. */
    public function plans(): ?array
    {
        $result = $this->request('GET', '/plans', null, false);

        return $result['ok'] && is_array($result['data']['plans'] ?? null) ? $result['data'] : null;
    }

    /** The store's plans, newest first, or null when they could not be read. */
    public function subscriptions(): ?array
    {
        $result = $this->request('GET', '/subscriptions');

        return $result['ok'] && is_array($result['data']['subscriptions'] ?? null) ? $result['data'] : null;
    }

    /**
     * The live plan of each product the store has, keyed by family.
     *
     * @return array<string, array>
     */
    public static function livePlans(array $subscriptions): array
    {
        $live = [];
        foreach ((array) ($subscriptions['subscriptions'] ?? []) as $row) {
            $status = (string) ($row['subscription']['status'] ?? '');
            $family = (string) ($row['plan']['family'] ?? '');
            // Newest first, so the first live row of a family is its plan.
            if ($family !== '' && !isset($live[$family]) && in_array($status, self::LIVE_STATUSES, true)) {
                $live[$family] = $row;
            }
        }

        return $live;
    }

    /**
     * Start a plan: a payment link to open, or the free plan switched on now.
     *
     * @return array{ok:bool, kind:string, pay_url:string, message:string} message: a langvar, or Quissly's own words
     */
    public function checkout(string $planId, string $billingCycle): array
    {
        $result = $this->request('POST', '/checkout', [
            'plan_id'       => $planId,
            'billing_cycle' => $billingCycle === 'annual' ? 'annual' : 'monthly',
        ]);
        if (!$result['ok']) {
            return ['ok' => false, 'kind' => '', 'pay_url' => '', 'message' => $result['message']];
        }
        $kind = (string) ($result['data']['kind'] ?? '');
        $payUrl = (string) ($result['data']['pay_url'] ?? '');
        // The link opens in the merchant's browser, so it must be a real https page.
        if (!in_array($kind, ['pay', 'free_activated'], true) || ($kind === 'pay' && strpos($payUrl, 'https://') !== 0)) {
            return ['ok' => false, 'kind' => '', 'pay_url' => '', 'message' => self::message(0, '')];
        }

        return ['ok' => true, 'kind' => $kind, 'pay_url' => $payUrl, 'message' => ''];
    }

    /** Used and left this month, per service, for one plan; null when it could not be read. */
    public function usage(string $subscriptionId): ?array
    {
        $result = $this->request('GET', '/subscriptions/' . rawurlencode($subscriptionId) . '/usage');

        return $result['ok'] && is_array($result['data']['services'] ?? null) ? $result['data'] : null;
    }

    /** The store's payments, newest first; null when they could not be read. */
    public function invoices(int $limit = 12): ?array
    {
        $result = $this->request('GET', '/invoices?limit=' . max(1, min(100, $limit)) . '&offset=0');

        return $result['ok'] && is_array($result['data']['invoices'] ?? null) ? $result['data'] : null;
    }

    /**
     * One plan-management call on a subscription.
     *
     * Ops: invoice_pdf (GET, id is the invoice), change_preview / change {plan_id},
     * topup_preview / topup {idempotency_key}, cancel, resume, abort, payment_method.
     *
     * @return array{ok:bool, data:array, message:string}
     */
    public function manage(string $op, string $id, array $body = []): array
    {
        $routes = [
            'invoice_pdf'    => ['GET', '/invoices/%s/pdf'],
            'change_preview' => ['POST', '/subscriptions/%s/change-plan/preview'],
            'change'         => ['POST', '/subscriptions/%s/change-plan'],
            'topup_preview'  => ['POST', '/subscriptions/%s/topup/preview'],
            'topup'          => ['POST', '/subscriptions/%s/topup'],
            'cancel'         => ['POST', '/subscriptions/%s/cancel'],
            'resume'         => ['POST', '/subscriptions/%s/resume'],
            'abort'          => ['POST', '/subscriptions/%s/abort-scheduled-change'],
            'payment_method' => ['POST', '/subscriptions/%s/payment-method'],
        ];
        if (!isset($routes[$op]) || !preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return ['ok' => false, 'data' => [], 'message' => self::message(0, '')];
        }
        [$method, $path] = $routes[$op];
        $result = $this->request($method, sprintf($path, $id), $method === 'POST' ? $body : null);
        $url = (string) ($result['data']['url'] ?? $result['data']['pay_url'] ?? '');
        // A link opened in the merchant's browser must be a real https page.
        if ($result['ok'] && in_array($op, ['invoice_pdf', 'payment_method'], true) && strpos($url, 'https://') !== 0) {
            return ['ok' => false, 'data' => [], 'message' => self::message(0, '')];
        }

        return $result;
    }

    /**
     * What the merchant is told, per the contract's error table: Quissly's own wording where
     * it is meant for the merchant, else a langvar name.
     */
    public static function message(int $status, string $detail): string
    {
        switch ($status) {
            case 400:
            case 402:
            case 409:
                if ($detail !== '') {
                    return $detail;
                }
                break;
            case 401:
                return 'quissly_search.su_billing_401';
            case 403:
                return 'quissly_search.su_billing_403';
            case 502:
                return 'quissly_search.su_billing_502';
            case 503:
                return 'quissly_search.su_billing_503';
        }

        return 'quissly_search.su_billing_unreachable';
    }

    /**
     * One call; never throws.
     *
     * @return array{ok:bool, data:array, message:string}
     */
    private function request(string $method, string $path, ?array $body = null, bool $authenticated = true): array
    {
        $headers = ['Accept: application/json'];
        if ($authenticated) {
            if ($this->apiKey === '') {
                return ['ok' => false, 'data' => [], 'message' => self::message(401, '')];
            }
            $headers[] = 'X-Store-Api-Key: ' . $this->apiKey;
        }
        $url = Config::CONSOLE_URL . self::PATH . $path;
        $response = $method === 'POST'
            ? ($this->post)($url, (string) json_encode($body ?? []), $headers)
            : ($this->get)($url, $headers);
        if ($response === null) {
            return ['ok' => false, 'data' => [], 'message' => self::message(0, '')];
        }
        $status = (int) $response['status'];
        $decoded = json_decode((string) $response['body'], true);
        $data = is_array($decoded) ? $decoded : [];
        if ($status >= 200 && $status < 300 && is_array($decoded)) {
            return ['ok' => true, 'data' => $data, 'message' => ''];
        }

        return ['ok' => false, 'data' => $data, 'message' => self::message($status, is_string($data['detail'] ?? null) ? $data['detail'] : '')];
    }
}
