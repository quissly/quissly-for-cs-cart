<?php

declare(strict_types=1);

namespace Quissly\Search\Admin;

use Quissly\Search\Setup\StoreBilling;

/**
 * Quissly Billing - the store's plans, this month's usage and its invoices, laid out as the
 * Shopify app's Settings billing column: a current-plan card per product (search, chat) with
 * its status chip, banners and the inline plan picker, then Usage and Extra requests; Invoices
 * and Payment method on the right. The markup is shared with quissly-for-magento's
 * billing.phtml and quissly-for-woocommerce's views/billing.php (js/addons/quissly_search/
 * billing.js reads it by its data-q-* attributes); keep them in step.
 *
 * Pure: view() builds the model from what the store billing API answered, render() returns
 * escaped HTML. Every word is a langvar (quissly_search.bi_*, plus Setup's su_* for the plan
 * names). The plan cards come from SetupPage::cardModel().
 */
final class BillingPage
{
    /** The products a store can buy, in the order they are shown. */
    public const FAMILIES = ['qsearch', 'qchat'];

    /**
     * Everything the page shows; null when Quissly could not be reached.
     *
     * @param callable $usage fn(string $subscriptionId): ?array - GET /subscriptions/{id}/usage
     */
    public static function view(?array $plans, ?array $subscriptions, callable $usage, ?array $invoices): ?array
    {
        if ($plans === null || $subscriptions === null) {
            return null;
        }
        $currency = (string) ($plans['currency'] ?? 'USD');
        $trialDays = (int) ($plans['trial_days'] ?? 0);
        $open = !empty($plans['billing_open']);
        $eligible = is_array($subscriptions['trial_eligible'] ?? null) ? $subscriptions['trial_eligible'] : [];

        $cards = [];
        $discount = 0;
        foreach ((array) $plans['plans'] as $plan) {
            $card = SetupPage::cardModel($plan, $currency, $trialDays, $open, $eligible);
            $cards[(string) $plan['id']] = $card + self::cardWords($plan, $card, $trialDays, $eligible, $currency) + ['raw' => $plan];
            $discount = max($discount, (int) ($plan['annual_discount_pct'] ?? 0));
        }
        $live = StoreBilling::livePlans($subscriptions);

        $families = [];
        $meters = [];
        $extras = [];
        $windows = [];
        foreach (self::FAMILIES as $family) {
            $familyCards = array_values(array_filter($cards, static fn (array $c): bool => $c['family'] === $family));
            usort($familyCards, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
            $plan = isset($live[$family]) ? self::livePlan($live[$family], $cards, $currency) : null;
            $families[$family] = [
                'label' => self::t($family === 'qchat' ? 'quissly_search.bi_chat' : 'quissly_search.bi_search'),
                'cards' => $familyCards,
                'plan'  => $plan,
            ];
            if ($plan === null) {
                continue;
            }
            $label = $family === 'qchat' ? 'QChat' : 'QSearch';
            $meter = self::meter($plan['id'] === '' ? null : $usage($plan['id']), $label);
            if ($meter !== null) {
                $meters[] = $meter;
                $windows[] = [$meter['from'], $plan['period_end']];
                if ($meter['extra'] > 0 || $plan['extra'] !== null) {
                    $extras[] = $meter + ['plan' => $plan];
                }
            } elseif ($plan['extra'] !== null) {
                $extras[] = ['label' => $label, 'extra' => 0, 'plan' => $plan];
            }
        }

        return [
            'billing_open' => $open,
            'discount_pct' => $discount,
            'families'     => $families,
            'meters'       => $meters,
            'window'       => self::window($windows),
            'extras'       => $extras,
            'invoices'     => $invoices === null ? null : self::invoiceRows($invoices),
        ];
    }

    /** What a full plan card says: the note under the price per cadence, the trial pill, the checklist. */
    private static function cardWords(array $plan, array $card, int $trialDays, array $eligible, string $currency): array
    {
        $family = (string) $plan['family'];
        $free = $card['free'];
        $quota = $plan['quotas'][0] ?? [];
        $requests = (int) ($quota['requests_per_month'] ?? 0);
        $extra = $quota['extra_block'] ?? null;

        $feats = [self::t($family === 'qchat' ? 'quissly_search.bi_includes_qchat' : 'quissly_search.bi_includes_qsearch')];
        if ($family === 'qchat' && $requests === 0) {
            $feats[] = self::t('quissly_search.bi_human_only');
        } else {
            $feats[] = self::t($family === 'qchat' ? 'quissly_search.bi_ai_month' : 'quissly_search.bi_search_month', ['[count]' => number_format($requests)]);
        }
        if (is_array($extra)) {
            $feats[] = self::t('quissly_search.bi_extra_line', ['[price]' => SetupPage::money((float) $extra['price'], $currency), '[count]' => number_format((int) $extra['requests'])]);
            $feats[] = self::t('quissly_search.bi_carry_over');
        } else {
            $feats[] = self::t('quissly_search.bi_no_extra');
        }
        $freeNote = self::t('quissly_search.bi_free_forever');

        return [
            'note_month' => $free ? $freeNote : self::t('quissly_search.bi_billed_monthly'),
            'note_year'  => $free ? $freeNote : ($card['saving'] !== ''
                ? self::t('quissly_search.bi_billed_save', ['[price]' => $card['monthly'], '[saving]' => $card['saving']])
                : self::t('quissly_search.bi_billed_yearly')),
            'trial'      => !$free && $trialDays > 0 && ($eligible[$family] ?? true)
                ? self::t('quissly_search.su_trial', ['[days]' => $trialDays])
                : '',
            'feats'      => $feats,
        ];
    }

    /** One live plan, in words, with the actions it allows. */
    private static function livePlan(array $row, array $cards, string $currency): array
    {
        $sub = $row['subscription'];
        $card = $cards[(string) ($sub['plan_id'] ?? '')] ?? null;
        $status = (string) ($sub['status'] ?? '');
        $paddle = ($sub['channel'] ?? '') === 'paddle';
        $annual = ($sub['billing_cycle'] ?? '') === 'annual';
        $free = $card !== null && $card['free'];
        $periodEnd = (string) ($sub['current_period_end'] ?? '');
        $ends = self::date($periodEnd);
        $cancelling = !empty($sub['cancel_after_period_end']);
        $pending = $cards[(string) ($sub['pending_plan_id'] ?? '')] ?? null;
        $extra = $card['raw']['quotas'][0]['extra_block'] ?? null;
        $id = (string) ($sub['id'] ?? '');

        $meta = [];
        if ($free) {
            $meta[] = self::t('quissly_search.bi_free_meta');
        } elseif ($card !== null) {
            $meta[] = $annual
                ? self::t('quissly_search.bi_per_year', ['[price]' => $card['annual_total']])
                : self::t('quissly_search.bi_per_month', ['[price]' => $card['monthly']]);
        }
        if ($ends !== '' && !$free) {
            $meta[] = self::t($cancelling ? 'quissly_search.bi_ends' : ($status === 'trial' ? 'quissly_search.bi_trial_ends' : 'quissly_search.bi_renews'), ['[date]' => $ends]);
        }

        $banners = [];
        if ($status === 'trial' && !$cancelling) {
            $banners[] = ['tone' => 'info', 'text' => self::t('quissly_search.bi_in_trial', ['[date]' => $ends])];
        }
        if ($status === 'past_due') {
            $banners[] = ['tone' => 'critical', 'text' => self::t('quissly_search.bi_past_due'), 'op' => 'payment_method', 'action' => self::t('quissly_search.bi_update_card')];
        }
        if ($cancelling) {
            $banners[] = ['tone' => 'warning', 'text' => self::t('quissly_search.bi_plan_ends', ['[date]' => $ends]), 'op' => 'resume', 'action' => self::t('quissly_search.bi_keep_plan')];
        }
        if ($pending !== null) {
            $banners[] = ['tone' => 'info', 'text' => self::t('quissly_search.bi_plan_changes', ['[plan]' => $pending['name'], '[date]' => $ends]), 'op' => 'abort', 'action' => self::t('quissly_search.bi_undo_change')];
        }
        $promo = (string) ($sub['promo_code'] ?? '');
        if ($promo !== '' && ($sub['promo_discount_amount'] ?? null) !== null) {
            $banners[] = ['tone' => 'success', 'text' => self::t('quissly_search.bi_promo', ['[code]' => $promo, '[amount]' => SetupPage::money((float) $sub['promo_discount_amount'], $currency)])];
        }

        $labels = ['trial' => 'quissly_search.bi_status_trial', 'active' => 'quissly_search.bi_status_active', 'past_due' => 'quissly_search.bi_status_past_due'];

        return [
            'id'               => $id,
            'name'             => $card['name'] ?? SetupPage::planName((string) ($row['plan']['family'] ?? ''), (string) ($row['plan']['tier'] ?? '')),
            'plan_id'          => (string) ($sub['plan_id'] ?? ''),
            'status_label'     => isset($labels[$status]) ? self::t($labels[$status]) : ucfirst($status),
            'chip'             => ['trial' => 'running', 'active' => 'done'][$status] ?? 'failed',
            'meta'             => implode(' · ', $meta),
            'banners'          => $banners,
            'period_end'       => $periodEnd,
            'can_change'       => $paddle && $pending === null && !$cancelling && $status !== 'past_due',
            'can_upgrade_free' => !$paddle,
            'can_cancel'       => $paddle && !$cancelling,
            'can_card'         => $paddle,
            'can_topup'        => $paddle && is_array($extra) && $status === 'active',
            'extra'            => is_array($extra) ? [
                'price'    => SetupPage::money((float) $extra['price'], $currency),
                'requests' => number_format((int) $extra['requests']),
                'label'    => self::t('quissly_search.bi_buy_more', ['[count]' => number_format((int) $extra['requests']), '[price]' => SetupPage::money((float) $extra['price'], $currency)]),
            ] : null,
        ];
    }

    /** One usage meter, as Shopify's UsageMeter draws it; null when it could not be read. */
    private static function meter(?array $usage, string $label): ?array
    {
        $service = $usage['services'][0] ?? null;
        if (!is_array($service)) {
            return null;
        }
        $limit = (int) ($service['limit'] ?? 0);
        // granted is the plan's requests plus any bought this period.
        $granted = max($limit, (int) ($service['granted'] ?? $limit));
        $used = (int) ($service['used'] ?? 0);
        $remaining = (int) ($service['remaining'] ?? max(0, $granted - $used));
        $pct = $granted > 0 ? min(100, $used / $granted * 100) : 0;
        $over = $granted > 0 && $remaining <= 0;
        $near = !$over && $granted > 0 && $pct >= 80;

        return [
            'label'    => $label,
            'used'     => number_format($used),
            'used_raw' => $used,
            'of'       => $granted > 0 ? number_format($granted) : '',
            'of_raw'   => $granted,
            'left'     => $granted > 0 ? self::t('quissly_search.bi_left', ['[count]' => number_format($remaining)]) : '',
            'out'      => $remaining <= 0,
            'pct'      => round($pct, 1),
            'tone'     => $over ? 'is-over' : ($near ? 'is-near' : ''),
            'note'     => $over ? self::t('quissly_search.bi_used_up') : ($near ? self::t('quissly_search.bi_near') : ''),
            'extra'    => max(0, $granted - $limit),
            'from'     => (string) ($service['period_start'] ?? ''),
        ];
    }

    /** "Current window: 2 Oct → 2 Nov", when the plans agree on one; '' otherwise. */
    private static function window(array $windows): string
    {
        $windows = array_unique(array_map(static fn (array $w): string => implode('|', $w), $windows));
        if (count($windows) !== 1) {
            return '';
        }
        [$from, $to] = explode('|', (string) reset($windows));
        $from = self::date($from, 'j M');
        $to = self::date($to, 'j M');

        return $from !== '' && $to !== '' ? self::t('quissly_search.bi_window', ['[from]' => $from, '[to]' => $to]) : '';
    }

    /** @return list<array> */
    private static function invoiceRows(array $list): array
    {
        $rows = [];
        foreach ((array) ($list['invoices'] ?? []) as $invoice) {
            $rows[] = [
                'id'     => (string) ($invoice['id'] ?? ''),
                'date'   => self::date((string) ($invoice['issued_at'] ?? $invoice['period_start'] ?? '')),
                'period' => self::date((string) ($invoice['period_start'] ?? '')) . ' - ' . self::date((string) ($invoice['period_end'] ?? '')),
                'amount' => SetupPage::money((float) ($invoice['amount_charged'] ?? 0), (string) ($invoice['currency'] ?? 'USD')),
                'status' => ucfirst(str_replace('_', ' ', (string) ($invoice['status'] ?? ''))),
                'pdf'    => ($invoice['channel'] ?? '') === 'paddle',
            ];
        }

        return $rows;
    }

    /** A date as the admin reads it ("2 Nov 2026"), '' when there is none. */
    public static function date(string $iso, string $format = 'j M Y'): string
    {
        $time = $iso === '' ? false : strtotime($iso);

        return $time === false ? '' : gmdate($format, $time);
    }

    // ---------------------------------------------------------------------------------
    // Markup.
    // ---------------------------------------------------------------------------------

    /** @param array $config billing.js's configuration (data-config) */
    public static function render(?array $view, array $config): string
    {
        $html = '<div class="q-billing" data-q-billing data-config="' . self::e((string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '">';
        if ($view === null) {
            $html .= '<div class="q-banner q-banner--critical">' . self::e(self::t('quissly_search.bi_unreachable')) . '</div>';
        } else {
            $left = $view['billing_open'] ? '' : '<div class="q-banner q-banner--warning">' . self::e(self::t('quissly_search.bi_closed')) . '</div>';
            foreach ($view['families'] as $family => $item) {
                $left .= self::planSection((string) $family, $item, (int) $view['discount_pct']);
            }
            $left .= self::usageSection($view) . self::extrasSection($view['extras']);
            $html .= '<div class="q-settings" data-q-settings><div class="q-settings__col">' . $left . '</div>'
                . '<div class="q-settings__col">' . self::invoicesSection($view['invoices']) . self::paymentSection($view['families']) . '</div></div>';
        }

        return $html . self::modal() . '</div>';
    }

    private static function planSection(string $family, array $item, int $discount): string
    {
        $plan = $item['plan'];
        $id = $plan['id'] ?? '';
        $html = '<section class="q-section" data-q-family="' . self::e($family) . '"><div class="q-planhead"><div class="q-planhead__main">'
            . '<p class="q-kicker">' . self::e(self::t('quissly_search.bi_plan_kicker', ['[product]' => $item['label']])) . '</p>'
            . '<span class="q-planhead__name">' . self::e($plan['name'] ?? self::t('quissly_search.bi_no_plan')) . '</span>'
            . '<span class="q-planhead__price">' . self::e($plan !== null ? $plan['meta'] : self::t($family === 'qchat' ? 'quissly_search.bi_no_chat' : 'quissly_search.bi_no_search')) . '</span></div>';
        if ($plan !== null) {
            $html .= '<span class="q-chip is-' . self::e($plan['chip']) . '"><span class="q-chip__dot" aria-hidden="true"></span>' . self::e($plan['status_label']) . '</span>';
        }
        $html .= '</div>';
        foreach ($plan['banners'] ?? [] as $banner) {
            $html .= '<div class="q-banner q-banner--' . self::e($banner['tone']) . '"><span>' . self::e($banner['text']) . '</span>'
                . (!empty($banner['op']) ? '<button type="button" class="q-button" data-q-op="' . self::e($banner['op']) . '" data-id="' . self::e($id) . '">' . self::e($banner['action']) . '</button>' : '')
                . '</div>';
        }

        $html .= '<div class="q-stack" data-q-actions>';
        if ($plan === null || $plan['can_change'] || $plan['can_upgrade_free']) {
            $label = $plan === null ? 'quissly_search.bi_choose_plan' : ($plan['can_upgrade_free'] ? 'quissly_search.bi_upgrade_plan' : 'quissly_search.bi_change_plan');
            $html .= '<button type="button" class="q-button q-button--primary" data-q-open="' . self::e($family) . '">' . self::e(self::t($label)) . '</button>';
        }
        if ($plan !== null && $plan['can_cancel']) {
            $html .= '<button type="button" class="q-button q-button--tertiary q-button--critical" data-q-op="cancel_confirm" data-id="' . self::e($id) . '" data-summary="'
                . self::e(self::t('quissly_search.bi_cancel_summary')) . '">' . self::e(self::t('quissly_search.bi_cancel_subscription')) . '</button>';
        }
        $html .= '</div>';

        // From a paid plan the way to Free is cancelling: change-plan moves between paid plans
        // only, and monthly <-> yearly is refused, so the cadence switch is offered only when a
        // plan is being started.
        $changing = $plan !== null && !$plan['can_upgrade_free'];
        $shown = array_values(array_filter($item['cards'], static fn (array $c): bool => !$c['free'] || !$changing));
        $html .= '<div class="q-stack q-stack--block q-stack--large" data-q-picker data-sub="' . self::e($changing ? $id : '') . '" data-current="' . self::e($plan['plan_id'] ?? '') . '" hidden>';
        if (!$changing) {
            $html .= '<div class="q-stack"><div class="q-seg" role="radiogroup" aria-label="' . self::e(self::t('quissly_search.bi_cadence')) . '">'
                . '<button type="button" class="q-seg__btn" role="radio" aria-checked="true" data-q-cycle="monthly">' . self::e(self::t('quissly_search.su_monthly')) . '</button>'
                . '<button type="button" class="q-seg__btn" role="radio" aria-checked="false" data-q-cycle="annual">' . self::e(self::t('quissly_search.su_annual')) . '</button></div>'
                . ($discount > 0 ? '<span class="q-pill q-pill--accent">' . self::e(self::t('quissly_search.bi_annual_saves', ['[pct]' => $discount])) . '</span>' : '')
                . '</div>';
        }
        $html .= '<div class="q-plans' . (count($shown) === 3 ? ' q-plans--three' : '') . '" role="radiogroup" aria-label="' . self::e(self::t('quissly_search.su_plans')) . '">';
        foreach ($shown as $card) {
            $html .= self::card($card, $changing);
        }
        $html .= '</div><p class="q-text-quiet" data-q-same hidden>' . self::e(self::t('quissly_search.bi_same_plan')) . '</p>'
            . '<div class="q-stack"><button type="button" class="q-button q-button--primary" data-q-confirm disabled>' . self::e(self::t('quissly_search.bi_confirm_plan')) . '</button>'
            . '<button type="button" class="q-button q-button--tertiary" data-q-close>' . self::e(self::t('quissly_search.bi_cancel')) . '</button></div></div>';

        return $html . '</section>';
    }

    private static function card(array $card, bool $changing): string
    {
        $ribbon = '';
        if ($card['disabled']) {
            $ribbon = '<span class="q-pill q-pill--warn q-plan__ribbon">' . self::e(self::t('quissly_search.su_opens_soon')) . '</span>';
        } elseif ($card['popular']) {
            $ribbon = '<span class="q-pill q-pill--accent q-plan__ribbon">' . self::e(self::t('quissly_search.bi_most_popular')) . '</span>';
        }
        $feats = '';
        foreach ($card['feats'] as $i => $feat) {
            $feats .= '<span class="q-plan__feat' . ($i === 0 ? ' is-lead' : '') . '" role="listitem">' . self::e($feat) . '</span>';
        }

        return '<button type="button" role="radio" aria-checked="false" class="q-plan' . ($card['popular'] ? ' is-popular' : '') . '"'
            . ' data-q-pick="' . self::e($card['id']) . '" data-free="' . ($card['free'] ? '1' : '0') . '"' . ($card['disabled'] ? ' aria-disabled="true"' : '') . '>'
            . $ribbon
            . '<span class="q-plan__head"><span class="q-plan__name">' . self::e($card['name']) . '</span><span class="q-plan__radio" aria-hidden="true"></span></span>'
            . '<span class="q-plan__price"><span class="q-plan__amount" data-q-monthly>' . self::e($card['monthly']) . '</span>'
            . '<span class="q-plan__amount" data-q-annual hidden>' . self::e($card['annual_month']) . '</span>'
            . '<span class="q-plan__cadence">' . self::e(self::t('quissly_search.bi_per_mo')) . '</span></span>'
            . '<span class="q-plan__note" data-q-monthly>' . self::e($card['note_month']) . '</span>'
            . '<span class="q-plan__note" data-q-annual hidden>' . self::e($card['note_year']) . '</span>'
            . ($card['trial'] !== '' && !$changing ? '<span><span class="q-pill q-pill--brand">' . self::e($card['trial']) . '</span></span>' : '')
            . '<span class="q-plan__feats" role="list">' . $feats . '</span></button>';
    }

    private static function usageSection(array $view): string
    {
        if ($view['meters'] === []) {
            return '';
        }
        $meters = '';
        foreach ($view['meters'] as $m) {
            $meters .= '<div class="q-usage"><div class="q-usage__head"><span class="q-usage__label">' . self::e($m['label']) . '</span>'
                . '<span class="q-usage__value"><strong>' . self::e($m['used']) . '</strong>'
                . ($m['of'] !== '' ? '<span class="q-usage__limit"> / ' . self::e($m['of']) . '</span><span class="q-usage__left' . ($m['out'] ? ' is-out' : '') . '"> · ' . self::e($m['left']) . '</span>' : '')
                . '</span></div>'
                . '<div class="q-usage__track ' . self::e($m['tone']) . '" role="progressbar" aria-label="' . self::e($m['label']) . '" aria-valuemin="0" aria-valuemax="' . (int) $m['of_raw'] . '" aria-valuenow="' . (int) min($m['used_raw'], $m['of_raw']) . '">'
                . '<div class="q-usage__fill" style="width: ' . (float) $m['pct'] . '%"></div></div>'
                . ($m['note'] !== '' ? '<p class="q-usage__note">' . self::e($m['note']) . '</p>' : '')
                . '</div>';
        }

        return '<section class="q-section"><h2 class="q-section__heading">' . self::e(self::t('quissly_search.bi_usage')) . '</h2>'
            . '<div class="q-usagepanel"><div class="q-usagepanel__meters">' . $meters . '</div>'
            . ($view['window'] !== '' ? '<p class="q-usagepanel__window">' . self::e($view['window']) . '</p>' : '')
            . '</div></section>';
    }

    private static function extrasSection(array $extras): string
    {
        if ($extras === []) {
            return '';
        }
        $tiles = '';
        $buyable = [];
        foreach ($extras as $extra) {
            if ($extra['plan']['extra'] !== null) {
                $tiles .= '<div class="q-metric"><span class="q-metric__value">' . self::e($extra['plan']['extra']['price']) . '</span>'
                    . '<span class="q-metric__label">' . self::e(self::t('quissly_search.bi_block', ['[product]' => $extra['label'], '[count]' => $extra['plan']['extra']['requests']])) . '</span></div>';
            }
            $tiles .= '<div class="q-metric"><span class="q-metric__value">' . self::e(number_format((int) $extra['extra'])) . '</span>'
                . '<span class="q-metric__label">' . self::e(self::t('quissly_search.bi_extra_month', ['[product]' => $extra['label']])) . '</span>'
                . '<span class="q-metric__hint">' . self::e(self::t('quissly_search.bi_carry_hint')) . '</span></div>';
            if ($extra['plan']['can_topup']) {
                $buyable[] = $extra;
            }
        }
        $buttons = '';
        foreach ($buyable as $extra) {
            $label = count($buyable) > 1 ? $extra['label'] . ': ' . $extra['plan']['extra']['label'] : $extra['plan']['extra']['label'];
            $buttons .= '<button type="button" class="q-button" data-q-op="topup_preview" data-id="' . self::e($extra['plan']['id']) . '">' . self::e($label) . '</button>';
        }

        return '<section class="q-section"><h2 class="q-section__heading">' . self::e(self::t('quissly_search.bi_extra_requests')) . '</h2>'
            . '<div class="q-grid">' . $tiles . '</div>'
            . '<p class="q-text-quiet">' . self::e(self::t('quissly_search.bi_extra_text')) . '</p>'
            . ($buttons !== '' ? '<div class="q-divider"></div><div class="q-stack">' . $buttons . '</div>' : '')
            . '</section>';
    }

    private static function invoicesSection(?array $invoices): string
    {
        $html = '<section class="q-section"><h2 class="q-section__heading">' . self::e(self::t('quissly_search.bi_invoices')) . '</h2>';
        if ($invoices === null) {
            return $html . '<p class="q-text-quiet">' . self::e(self::t('quissly_search.bi_invoices_failed')) . '</p></section>';
        }
        if ($invoices === []) {
            return $html . '<p class="q-text-quiet">' . self::e(self::t('quissly_search.bi_no_payments')) . '</p></section>';
        }
        $html .= '<div class="q-list" role="list">';
        foreach ($invoices as $invoice) {
            $html .= '<div class="q-list__row" role="listitem"><div class="q-list__main">'
                . '<span class="q-list__label">' . self::e($invoice['amount'] . ' · ' . $invoice['status']) . '</span>'
                . '<span class="q-list__note">' . self::e(self::t('quissly_search.bi_invoice_for', ['[date]' => $invoice['date'], '[period]' => $invoice['period']])) . '</span></div>'
                . ($invoice['pdf'] ? '<div class="q-list__aside"><button type="button" class="q-button" data-q-op="invoice_pdf" data-id="' . self::e($invoice['id']) . '">' . self::e(self::t('quissly_search.bi_pdf')) . '</button></div>' : '')
                . '</div>';
        }

        return $html . '</div></section>';
    }

    private static function paymentSection(array $families): string
    {
        $rows = '';
        foreach ($families as $item) {
            $plan = $item['plan'];
            if ($plan === null || !$plan['can_card']) {
                continue;
            }
            $rows .= '<div class="q-list__row" role="listitem"><div class="q-list__main"><span class="q-list__label">' . self::e($plan['name']) . '</span>'
                . '<span class="q-list__note">' . self::e(self::t('quissly_search.bi_card_on_file')) . '</span></div>'
                . '<div class="q-list__aside"><button type="button" class="q-button" data-q-op="payment_method" data-id="' . self::e($plan['id']) . '">' . self::e(self::t('quissly_search.bi_update_card')) . '</button></div></div>';
        }

        return '<section class="q-section"><h2 class="q-section__heading">' . self::e(self::t('quissly_search.bi_payment_method')) . '</h2>'
            . '<p class="q-text-quiet">' . self::e(self::t('quissly_search.bi_paddle')) . '</p>'
            . ($rows !== '' ? '<div class="q-list" role="list">' . $rows . '</div>' : '')
            . '</section>';
    }

    private static function modal(): string
    {
        return '<div class="q-modal" data-q-modal hidden><div class="q-modal__box" role="dialog" aria-modal="true" aria-labelledby="q-modal-title">'
            . '<h3 class="q-modal__title" id="q-modal-title" data-q-modal-title></h3>'
            . '<div class="q-modal__body"><p data-q-modal-body></p><div class="q-banner q-banner--critical" data-q-modal-error hidden></div></div>'
            . '<div class="q-modal__actions"><button type="button" class="q-button" data-q-modal-cancel>' . self::e(self::t('quissly_search.bi_cancel')) . '</button>'
            . '<button type="button" class="q-button q-button--primary" data-q-modal-ok></button></div></div></div>';
    }

    /** @param array<string, string|int> $params */
    private static function t(string $key, array $params = []): string
    {
        return function_exists('__') ? (string) __($key, $params) : $key . ($params === [] ? '' : ' ' . implode(' ', $params));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function __construct()
    {
    }
}
