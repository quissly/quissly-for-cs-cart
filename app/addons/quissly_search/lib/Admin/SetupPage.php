<?php

declare(strict_types=1);

namespace Quissly\Search\Admin;

use Quissly\Search\Setup\Onboarding;
use Quissly\Search\Setup\StoreBilling;

/**
 * Quissly Setup - the onboarding screen, in the Shopify app's design. The markup is shared
 * with quissly-for-magento and quissly-for-woocommerce (js/addons/quissly_search/setup.js
 * reads it by its data-q-* attributes); keep them in step.
 *
 * Pure: render() takes the model the controller builds, returns escaped HTML. Every word is a
 * langvar (quissly_search.su_*).
 */
final class SetupPage
{
    /**
     * @param array{step:string, admin_email:string, account_email:string, store_name:string, description:string,
     *     domain:string, plans:?array, subscriptions:?array, config:array, logo_url:string} $m
     */
    public static function render(array $m): string
    {
        $step = $m['step'];
        $connected = $step !== Onboarding::STEP_DETAILS;
        $plans = $connected ? self::planView($m['plans'], $m['subscriptions']) : null;
        $steps = [
            Onboarding::STEP_DETAILS => self::t('quissly_search.su_step_details'),
            Onboarding::STEP_PLAN    => self::t('quissly_search.su_step_plan'),
            Onboarding::STEP_GOLIVE  => self::t('quissly_search.su_step_golive'),
        ];
        $done = (int) array_search($step, array_keys($steps), true);
        $config = $m['config'];
        $config['step'] = $step;
        $config['reached'] = $step;
        $config['text']['saveDefault'] = $plans !== null && $plans['discount_pct'] > 0
            ? self::t('quissly_search.su_save_pct', ['[pct]' => $plans['discount_pct']])
            : '';

        $html = '<div class="q-setup" data-q-setup data-config="' . self::e((string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '">'
            . self::band($steps, $done, $m['logo_url'])
            . self::details($m, $connected);
        if ($connected) {
            $html .= self::plan($plans) . self::golive();
        }

        return $html . '</div>';
    }

    /** @param array<string, string> $steps */
    private static function band(array $steps, int $done, string $logoUrl): string
    {
        $stepper = '';
        $index = 0;
        foreach ($steps as $key => $label) {
            if ($index > 0) {
                $stepper .= '<li class="q-stepper__line" aria-hidden="true"></li>';
            }
            $stepper .= '<li class="q-stepper__item"><button type="button" data-q-goto="' . self::e($key) . '">'
                . '<span class="q-stepper__dot">' . ($index < $done ? '&#10003;' : (string) ($index + 1)) . '</span>'
                . '<span>' . self::e($label) . '</span></button></li>';
            $index++;
        }
        $arcs = '';
        foreach ([[86, -14, 30], [86, -14, 42], [86, -14, 54], [8, 112, 26], [8, 112, 38], [118, 66, 34], [118, 66, 46]] as [$cx, $cy, $r]) {
            $arcs .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '"/>';
        }
        $total = count($steps);

        return '<div class="q-band">'
            . '<svg class="q-band__pattern" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<g fill="none" stroke="#b8d432" stroke-opacity="0.24" stroke-width="0.4">' . $arcs . '</g></svg>'
            . '<div class="q-band__row">'
            . '<img class="q-band__logo" src="' . self::e($logoUrl) . '" alt="Quissly" height="20">'
            . '<div class="q-band__titles"><div class="q-band__title">' . self::e(self::t('quissly_search.su_band_title')) . '</div>'
            . '<div class="q-band__sub">' . self::e(self::t('quissly_search.su_band_sub')) . '</div></div>'
            . '<div class="q-band__progress"><div class="q-band__progress-label"><span>' . self::e(self::t('quissly_search.su_progress')) . '</span>'
            . '<span>' . self::e(self::t('quissly_search.su_progress_count', ['[done]' => $done, '[total]' => $total])) . '</span></div>'
            . '<div class="q-meter" role="progressbar" aria-valuemin="0" aria-valuemax="' . $total . '" aria-valuenow="' . $done . '">'
            . '<span style="width: ' . (int) round($done / $total * 100) . '%"></span></div></div>'
            . '</div><ol class="q-stepper">' . $stepper . '</ol></div>';
    }

    private static function details(array $m, bool $connected): string
    {
        if ($connected) {
            $fields = self::field('q-email', self::t('quissly_search.su_email'), '<input id="q-email" type="email" readonly value="' . self::e($m['account_email']) . '">', self::t('quissly_search.su_connected_help'));
            $action = '<button type="button" class="q-btn" data-q-forward="plan">' . self::e(self::t('quissly_search.su_continue')) . '</button>';
        } else {
            $fields = self::field('q-email', self::t('quissly_search.su_email'), '<input id="q-email" name="email" type="email" required autocomplete="email" value="' . self::e($m['admin_email']) . '">', self::t('quissly_search.su_email_help'), 'email')
                . self::field('q-store-name', self::t('quissly_search.su_store_name'), '<input id="q-store-name" name="store_name" type="text" maxlength="100" value="' . self::e($m['store_name']) . '" placeholder="' . self::e($m['domain']) . '">', self::t('quissly_search.su_store_name_help', ['[domain]' => $m['domain']]), 'store_name')
                . '<div class="q-field"><label for="q-description">' . self::e(self::t('quissly_search.su_description'))
                . ' <span class="q-optional">' . self::e(self::t('quissly_search.su_optional')) . '</span></label>'
                . '<textarea id="q-description" name="description" rows="3" maxlength="500">' . self::e($m['description']) . '</textarea>'
                . '<p class="q-help">' . self::e(self::t('quissly_search.su_description_help')) . '</p></div>';
            $action = '<button type="submit" class="q-btn" data-q-connect-submit>' . self::e(self::t('quissly_search.su_connect')) . '</button>';
        }
        if ($connected) {
            $next = '';
            foreach ([['su_next_plan', 'su_next_plan_text'], ['su_next_sync', 'su_next_sync_text'], ['su_step_golive', 'su_next_golive_text']] as [$title, $text]) {
                $next .= '<li><strong>' . self::e(self::t('quissly_search.' . $title)) . '</strong><span>' . self::e(self::t('quissly_search.' . $text)) . '</span></li>';
            }
            $side = '<div class="q-kicker">' . self::e(self::t('quissly_search.su_next')) . '</div><ul class="q-next">' . $next . '</ul>';
        } else {
            // The workspace preview, following what is typed (setup.js), as on Shopify.
            $noDescription = self::t('quissly_search.su_no_description');
            $side = '<div class="q-kicker">' . self::e(self::t('quissly_search.su_preview')) . '</div><div class="q-preview">'
                . '<div class="q-preview__kicker">' . self::e(self::t('quissly_search.su_workspace')) . '</div>'
                . '<div class="q-preview__name" data-q-preview="store_name" data-fallback="' . self::e($m['domain']) . '">' . self::e($m['store_name'] !== '' ? $m['store_name'] : $m['domain']) . '</div>'
                . '<p class="q-preview__desc" data-q-preview="description" data-fallback="' . self::e($noDescription) . '">' . self::e($m['description'] !== '' ? $m['description'] : $noDescription) . '</p></div>';
        }

        return '<section data-q-step="details" hidden><form data-q-connect novalidate><div class="q-grid"><div class="q-card">'
            . self::kicker(1) . '<h2>' . self::e(self::t('quissly_search.su_step_details')) . '</h2>'
            . '<p class="q-desc">' . self::e(self::t('quissly_search.su_details_desc')) . '</p>'
            . $fields . '<div class="q-notice q-notice--error" data-q-error hidden></div></div>'
            . '<aside class="q-card q-card--side">' . $side . '<p class="q-side-foot">' . self::e(self::t('quissly_search.su_side_foot')) . '</p></aside></div>'
            . '<div class="q-actionbar"><span class="q-actionbar__note">' . self::e(self::t($connected ? 'quissly_search.su_connected_note' : 'quissly_search.su_connect_note')) . '</span>'
            . $action . '</div></form></section>';
    }

    private static function plan(?array $plans): string
    {
        $body = '';
        $cadence = '';
        if ($plans === null) {
            $body = '<div class="q-notice q-notice--warn">' . self::e(self::t('quissly_search.su_plans_unavailable')) . '</div>';
            $bar = '<span class="q-actionbar__note"></span><button type="button" class="q-btn" data-q-plan-op="later">' . self::e(self::t('quissly_search.su_no_plan')) . '</button>';
        } elseif ($plans['current'] !== null) {
            $body = '<div class="q-notice">' . self::e(self::t('quissly_search.su_current_plan', ['[plan]' => $plans['current']])) . '</div>';
            $bar = '<span class="q-actionbar__note">' . self::e($plans['current']) . '</span><button type="button" class="q-btn" data-q-plan-op="keep">' . self::e(self::t('quissly_search.su_continue')) . '</button>';
        } else {
            $cadence = '<div class="q-cadence" role="radiogroup" aria-label="' . self::e(self::t('quissly_search.su_billing')) . '">'
                . '<button type="button" role="radio" data-q-cadence="monthly" aria-checked="true"><strong>' . self::e(self::t('quissly_search.su_monthly')) . '</strong><small>' . self::e(self::t('quissly_search.su_monthly_sub')) . '</small></button>'
                . '<button type="button" role="radio" data-q-cadence="annual" aria-checked="false"><strong>' . self::e(self::t('quissly_search.su_annual')) . '</strong><small data-q-saving></small></button></div>';
            if (!$plans['billing_open']) {
                $body .= '<div class="q-notice q-notice--warn">' . self::e(self::t('quissly_search.su_billing_closed')) . '</div>';
            }
            $body .= '<div class="q-tabs" role="tablist">'
                . '<button type="button" role="tab" data-q-tab="qsearch" aria-selected="true">' . self::e(self::t('quissly_search.su_tab_search')) . '</button>'
                . '<button type="button" role="tab" data-q-tab="qchat" aria-selected="false">' . self::e(self::t('quissly_search.su_tab_chat')) . '</button></div>'
                . '<div data-q-plan-picker>';
            foreach ($plans['families'] as $family => $cards) {
                $body .= '<div data-q-plans="' . self::e($family) . '">'
                    . '<div class="q-plans" role="radiogroup" data-q-view="cards" aria-label="' . self::e(self::t('quissly_search.su_plans')) . '">';
                foreach ($cards as $card) {
                    $body .= self::card($card);
                }
                $body .= '</div>' . self::compareTable($family, $cards) . '</div>';
            }
            $body .= '</div><div class="q-compare-row"><button type="button" class="q-btn q-btn--quiet q-btn--small" data-q-compare>'
                . self::e(self::t('quissly_search.su_compare')) . '</button></div>'
                . '<div class="q-notice" data-q-pay hidden>' . self::e(self::t('quissly_search.su_pay_waiting'))
                . ' <a href="#" target="_blank" rel="noopener noreferrer" data-q-pay-open>' . self::e(self::t('quissly_search.su_pay_open')) . '</a> &middot; '
                . '<button type="button" class="q-link" data-q-pay-check>' . self::e(self::t('quissly_search.su_paid')) . '</button></div>';
            $bar = '<span class="q-actionbar__note" data-q-plan-note></span><button type="button" class="q-btn" data-q-plan-submit disabled></button>';
        }

        return '<section data-q-step="plan" hidden><div class="q-card q-card--plan"><div class="q-plan-head"><div>'
            . self::kicker(2) . '<h2>' . self::e(self::t('quissly_search.su_step_plan')) . '</h2></div>' . $cadence . '</div>'
            . $body . '<div class="q-notice q-notice--error" data-q-error hidden></div></div>'
            . '<div class="q-actionbar"><button type="button" class="q-btn q-btn--quiet" data-q-back="details">' . self::e(self::t('quissly_search.su_back')) . '</button>'
            . $bar . '</div></section>';
    }

    private static function card(array $card): string
    {
        $ribbon = $card['disabled']
            ? '<span class="q-plan__ribbon q-plan__ribbon--soon">' . self::e(self::t('quissly_search.su_opens_soon')) . '</span>'
            : ($card['popular'] ? '<span class="q-plan__ribbon">' . self::e(self::t('quissly_search.su_popular')) . '</span>' : '');
        $figures = '';
        foreach ($card['figures'] as [$value, $label]) {
            $figures .= '<div><strong>' . self::e($value) . '</strong><span>' . self::e($label) . '</span></div>';
        }
        $perMonth = ' <small>' . self::e(self::t('quissly_search.su_per_month')) . '</small>';

        return '<div class="q-plan' . ($card['popular'] ? ' is-popular' : '') . '" ' . self::planAttributes($card) . '>'
            . $ribbon
            . '<div class="q-plan__head"><span>' . self::e($card['name']) . '</span><span class="q-plan__radio" aria-hidden="true"></span></div>'
            . '<div class="q-plan__price" data-q-monthly>' . self::e($card['monthly']) . $perMonth . '</div>'
            . '<div class="q-plan__price" data-q-annual>' . self::e($card['annual_month']) . $perMonth . '</div>'
            . '<div class="q-plan__trial" data-q-monthly>' . self::e($card['monthly_note']) . '</div>'
            . '<div class="q-plan__trial" data-q-annual>' . self::e($card['annual_note']) . '</div>'
            . '<div class="q-plan__figures">' . $figures . '</div></div>';
    }

    /** The data-* attributes setup.js reads off a plan, for a card and its comparison column alike. */
    private static function planAttributes(array $card): string
    {
        return 'data-q-plan role="radio" tabindex="-1" aria-checked="false" aria-disabled="' . ($card['disabled'] ? 'true' : 'false') . '"'
            . ' data-plan-id="' . self::e($card['id']) . '" data-family="' . self::e($card['family']) . '" data-free="' . ($card['free'] ? '1' : '0') . '"'
            . ' data-saving="' . self::e($card['saving']) . '" data-cta="' . self::e($card['cta']) . '"'
            . ' data-note-monthly="' . self::e($card['note_monthly']) . '" data-note-annual="' . self::e($card['note_annual']) . '"';
    }

    /** "Compare all features": the family's plans as a table, swapped in for the cards (direction 3a). */
    private static function compareTable(string $family, array $cards): string
    {
        $perMonth = ' <small>' . self::e(self::t('quissly_search.su_per_month')) . '</small>';
        $head = '<th scope="col" class="q-compare__corner">' . self::e(self::t('quissly_search.su_compare_plans')) . '</th>';
        foreach ($cards as $card) {
            $head .= '<th scope="col"><button type="button" class="q-compare__plan" ' . self::planAttributes($card) . '>'
                . '<span class="q-compare__name"><span class="q-plan__radio" aria-hidden="true"></span>' . self::e($card['name']) . '</span>'
                . '<span class="q-compare__price" data-q-monthly>' . self::e($card['monthly']) . $perMonth . '</span>'
                . '<span class="q-compare__price" data-q-annual>' . self::e($card['annual_month']) . $perMonth . '</span>'
                . '<span class="q-compare__note">' . self::e($card['disabled'] ? self::t('quissly_search.su_opens_soon') : $card['monthly_note']) . '</span>'
                . '</button></th>';
        }
        $rows = [
            [self::t($family === 'qchat' ? 'quissly_search.su_ai_messages_month' : 'quissly_search.su_searches_month'), static fn (array $c): string => $c['figures'][0][0]],
            [self::t('quissly_search.su_extra_requests'), static fn (array $c): string => $c['figures'][1][0]],
            [self::t('quissly_search.su_billed_yearly_row'), static fn (array $c): string => $c['annual_total']],
            [self::t('quissly_search.su_free_trial_row'), static fn (array $c): string => $c['trial_label']],
        ];
        $body = '';
        foreach ($rows as [$label, $value]) {
            $body .= '<tr><th scope="row">' . self::e($label) . '</th>';
            foreach ($cards as $card) {
                $body .= '<td>' . self::e($value($card)) . '</td>';
            }
            $body .= '</tr>';
        }

        return '<div class="q-compare-wrap" data-q-view="table" hidden><table class="q-compare"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    private static function golive(): string
    {
        $list = '';
        foreach ([['su_live_search', 'su_live_search_text'], ['su_live_safety', 'su_live_safety_text'], ['su_live_config', 'su_live_config_text']] as [$title, $text]) {
            $list .= '<li><span class="q-disc" aria-hidden="true"></span><div>' . self::e(self::t('quissly_search.' . $title))
                . '<span>' . self::e(self::t('quissly_search.' . $text)) . '</span></div></li>';
        }
        $rows = '';
        // The Shopify app's provisioning rows, in its order.
        foreach (['store' => 'su_row_store', 'organization' => 'su_row_organization', 'project' => 'su_row_project', 'qsearch' => 'su_row_qsearch', 'qchat' => 'su_row_qchat', 'catalog' => 'su_row_import'] as $key => $label) {
            $rows .= '<li class="q-row" data-q-row="' . $key . '" data-state="pending"><span class="q-disc" aria-hidden="true"></span>'
                . '<span class="q-row__label">' . self::e(self::t('quissly_search.' . $label)) . ' <small data-q-row-detail hidden></small></span>'
                . '<span class="q-row__state" data-q-row-state>' . self::e(self::t('quissly_search.su_waiting')) . '</span></li>';
        }

        return '<section data-q-step="golive" hidden><div class="q-grid q-grid--golive"><div class="q-card q-card--golive">'
            . self::kicker(3) . '<h2>' . self::e(self::t('quissly_search.su_step_golive')) . '</h2>'
            . '<p class="q-desc">' . self::e(self::t('quissly_search.su_golive_desc')) . '</p>'
            . '<ul class="q-golive-list">' . $list . '</ul><div class="q-notice q-notice--error" data-q-error hidden></div></div>'
            . '<div class="q-card q-card--warm"><div class="q-status-head"><div class="q-kicker q-kicker--quiet">' . self::e(self::t('quissly_search.su_account_setup')) . '</div>'
            . '<span class="q-chip" data-q-chip>' . self::e(self::t('quissly_search.su_setting_up')) . '</span></div>'
            . '<p class="q-status-body">' . self::e(self::t('quissly_search.su_background')) . '</p>'
            . '<ol class="q-rows">' . $rows . '</ol>'
            . '<p class="q-status-foot"><button type="button" class="q-btn q-btn--quiet q-btn--small" data-q-retry hidden>' . self::e(self::t('quissly_search.su_retry')) . '</button> '
            . self::e(self::t('quissly_search.su_usually')) . '</p></div></div>'
            . '<div class="q-actionbar"><button type="button" class="q-btn q-btn--quiet" data-q-back="plan">' . self::e(self::t('quissly_search.su_back')) . '</button>'
            . '<span class="q-actionbar__note">' . self::e(self::t('quissly_search.su_golive_note')) . '</span>'
            // The Shopify app's "Save changes": done for now, search switched on later.
            . '<button type="button" class="q-btn q-btn--quiet" data-q-save hidden disabled>' . self::e(self::t('quissly_search.su_save_changes')) . '</button>'
            . '<button type="button" class="q-btn" data-q-finish disabled>' . self::e(self::t('quissly_search.su_finish')) . '</button></div></section>';
    }

    /**
     * Everything the plan step shows; null when Quissly's plans could not be loaded.
     *
     * @return ?array{billing_open:bool, families:array<string, list<array>>, current:?string, discount_pct:int}
     */
    public static function planView(?array $plans, ?array $subscriptions): ?array
    {
        if ($plans === null) {
            return null;
        }
        $trialEligible = is_array($subscriptions['trial_eligible'] ?? null) ? $subscriptions['trial_eligible'] : [];
        $currency = (string) ($plans['currency'] ?? 'USD');
        $trialDays = (int) ($plans['trial_days'] ?? 0);
        $open = !empty($plans['billing_open']);

        $families = ['qsearch' => [], 'qchat' => []];
        foreach ((array) $plans['plans'] as $plan) {
            $family = (string) ($plan['family'] ?? '');
            if (isset($families[$family])) {
                $families[$family][] = self::cardModel($plan, $currency, $trialDays, $open, $trialEligible);
            }
        }
        foreach ($families as $family => $cards) {
            usort($cards, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
            $families[$family] = $cards;
        }

        $current = null;
        foreach (StoreBilling::livePlans($subscriptions ?? []) as $row) {
            $current = self::planName((string) ($row['plan']['family'] ?? ''), (string) ($row['plan']['tier'] ?? ''));
            break;
        }

        return [
            'billing_open' => $open,
            'families'     => $families,
            'current'      => $current,
            'discount_pct' => (int) ($plans['plans'][0]['annual_discount_pct'] ?? 0),
        ];
    }

    public static function cardModel(array $plan, string $currency, int $trialDays, bool $open, array $trialEligible): array
    {
        $family = (string) $plan['family'];
        $name = self::planName($family, (string) ($plan['tier'] ?? ''));
        $free = !empty($plan['is_free']);
        $monthly = (float) ($plan['price_monthly'] ?? 0);
        $annual = (float) ($plan['price_annual'] ?? 0);
        $monthText = self::money($monthly, $currency);
        $yearMonth = self::money($annual / 12, $currency);
        $saving = (int) round($monthly * 12 - $annual);
        $trial = !$free && $trialDays > 0 && ($trialEligible[$family] ?? true);
        $quota = $plan['quotas'][0] ?? [];
        $requests = (int) ($quota['requests_per_month'] ?? 0);
        $extra = $quota['extra_block'] ?? null;

        $usage = $family === 'qchat' && $requests === 0
            ? [self::t('quissly_search.su_human_only'), self::t('quissly_search.su_no_ai')]
            : [number_format($requests), self::t($family === 'qchat' ? 'quissly_search.su_ai_messages' : 'quissly_search.su_searches')];
        $extraFigure = is_array($extra)
            ? [self::t('quissly_search.su_extra_price', ['[price]' => self::money((float) $extra['price'], $currency), '[count]' => number_format((int) $extra['requests'])]), self::t('quissly_search.su_extra')]
            : [self::t('quissly_search.su_none'), self::t('quissly_search.su_extra')];
        $freeNote = self::t('quissly_search.su_free_forever');
        $freeLine = self::t('quissly_search.su_note_free', ['[plan]' => $name]);

        return [
            'id'           => (string) $plan['id'],
            'family'       => $family,
            'name'         => $name,
            'free'         => $free,
            'popular'      => !empty($plan['is_popular']),
            'disabled'     => !$free && !$open,
            'monthly'      => $monthText,
            'annual_month' => $yearMonth,
            'monthly_note' => $free ? $freeNote : ($trial ? self::t('quissly_search.su_trial', ['[days]' => $trialDays]) : self::t('quissly_search.su_billed_monthly')),
            'annual_note'  => $free ? $freeNote : self::t('quissly_search.su_billed_yearly', ['[price]' => self::money($annual, $currency)]),
            'saving'       => $free || $saving <= 0 ? '' : self::money((float) $saving, $currency),
            'figures'      => [$usage, $extraFigure],
            'annual_total' => $free ? '—' : self::money($annual, $currency),
            'trial_label'  => $free ? self::t('quissly_search.su_free_plan') : ($trial ? self::t('quissly_search.su_days', ['[days]' => $trialDays]) : '—'),
            'cta'          => $free ? self::t('quissly_search.su_start_free') : self::t('quissly_search.su_continue_with', ['[plan]' => $name]),
            'note_monthly' => $free ? $freeLine : self::t('quissly_search.su_note_monthly', ['[plan]' => $name, '[price]' => $monthText]),
            'note_annual'  => $free ? $freeLine : self::t('quissly_search.su_note_annual', ['[plan]' => $name, '[price]' => $yearMonth]),
            'sort'         => $monthly,
        ];
    }

    /** "QSearch Growth", from the plan's family and tier. */
    public static function planName(string $family, string $tier): string
    {
        $families = ['qsearch' => 'QSearch', 'qchat' => 'QChat'];
        $tiers = ['free', 'basic', 'starter', 'growth', 'scale'];

        return trim(($families[$family] ?? ucfirst($family)) . ' '
            . (in_array($tier, $tiers, true) ? self::t('quissly_search.su_tier_' . $tier) : ucfirst($tier)));
    }

    /** A price as the merchant reads it: "$89", "$75.65". */
    public static function money(float $amount, string $currency): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $whole = abs($amount - round($amount)) < 0.005;
        $number = number_format($amount, $whole ? 0 : 2);

        return isset($symbols[$currency]) ? $symbols[$currency] . $number : $currency . ' ' . $number;
    }

    private static function kicker(int $n): string
    {
        return '<div class="q-kicker">' . self::e(self::t('quissly_search.su_step_of', ['[n]' => $n, '[total]' => 3])) . '</div>';
    }

    /** One field; $check names the field setup.js checks as the merchant types (its error line). */
    private static function field(string $id, string $label, string $input, string $help, string $check = ''): string
    {
        return '<div class="q-field"><label for="' . self::e($id) . '">' . self::e($label) . '</label>' . $input
            . '<p class="q-help">' . self::e($help) . '</p>'
            . ($check !== '' ? '<p class="q-help q-help--error" data-q-field-error="' . self::e($check) . '" hidden></p>' : '')
            . '</div>';
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
