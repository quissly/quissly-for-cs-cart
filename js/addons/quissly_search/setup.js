/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Quissly Setup - the behaviour behind the onboarding screen.
 *
 * The same file ships in quissly-for-woocommerce and quissly-for-cs-cart; keep
 * the three copies identical. The page is rendered by the platform (so every
 * word goes through its own translation system); this script only moves
 * between the steps, posts to the endpoints the page names, and fills in the
 * status the server answers. Vanilla, no framework: it runs in three admins.
 *
 * Config (JSON in data-config on [data-q-setup]):
 *   step       the step the server says the store is on
 *   reached    the furthest step the store has reached
 *   endpoints  {connect, plan, status, finish}: {url, params} - params are
 *              merged into every POST to that endpoint (WordPress' action name)
 *   csrf       {name: value} merged into every POST (form key, nonce, hash)
 *   reload     the page's own URL, loaded again after a step completes
 *   text       the few words the script writes itself
 */
(function () {
    'use strict';

    var STEPS = ['details', 'plan', 'golive'];
    var STATUS_FAST_MS = 5000;
    var STATUS_SLOW_MS = 20000;
    var STATUS_FAST_FOR_MS = 10 * 60 * 1000;
    var PAY_POLL_MS = 3000;
    var PAY_POLL_FOR_MS = 2 * 60 * 1000;

    function init(root) {
        var config;
        try {
            config = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) {
            return;
        }
        var text = config.text || {};

        function $(selector, scope) {
            return (scope || root).querySelector(selector);
        }

        function $$(selector, scope) {
            return Array.prototype.slice.call((scope || root).querySelectorAll(selector));
        }

        function post(name, data) {
            var endpoint = (config.endpoints || {})[name];
            var body = new URLSearchParams();
            [endpoint.params || {}, config.csrf || {}, data || {}].forEach(function (set) {
                Object.keys(set).forEach(function (key) {
                    body.append(key, set[key]);
                });
            });
            return fetch(endpoint.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: body
            }).then(function (response) {
                return response.json().catch(function () {
                    return {ok: false, message: text.error};
                });
            }, function () {
                return {ok: false, message: text.error};
            });
        }

        function reload() {
            window.location.href = config.reload || window.location.href;
        }

        function showError(step, message) {
            var box = $('[data-q-step="' + step + '"] [data-q-error]');
            if (!box) {
                return;
            }
            box.textContent = message || '';
            box.hidden = !message;
        }

        function busy(button, on, label) {
            if (!button) {
                return;
            }
            if (on) {
                button.setAttribute('data-q-label', button.textContent);
                button.disabled = true;
                button.innerHTML = '';
                var spinner = document.createElement('span');
                spinner.className = 'q-spinner';
                button.appendChild(spinner);
                button.appendChild(document.createTextNode(' ' + (label || '')));
            } else if (button.hasAttribute('data-q-label')) {
                button.disabled = false;
                button.textContent = button.getAttribute('data-q-label');
                button.removeAttribute('data-q-label');
            }
        }

        // ---- Steps ----------------------------------------------------------

        var reachedIndex = Math.max(0, STEPS.indexOf(config.reached || config.step));

        function show(step) {
            var index = STEPS.indexOf(step);
            if (index < 0 || index > reachedIndex) {
                return;
            }
            $$('[data-q-step]').forEach(function (section) {
                section.hidden = section.getAttribute('data-q-step') !== step;
            });
            $$('[data-q-goto]').forEach(function (item) {
                var at = STEPS.indexOf(item.getAttribute('data-q-goto'));
                var li = item.closest('.q-stepper__item');
                li.classList.toggle('is-done', at < reachedIndex);
                li.classList.toggle('is-current', at === index);
                item.setAttribute('aria-current', at === index ? 'step' : 'false');
            });
            $$('.q-stepper__line').forEach(function (line, at) {
                line.classList.toggle('is-done', at < reachedIndex);
            });
            if (step === 'golive') {
                startStatus();
            }
        }

        $$('[data-q-goto]').forEach(function (item) {
            item.addEventListener('click', function () {
                show(item.getAttribute('data-q-goto'));
            });
        });
        $$('[data-q-back]').forEach(function (button) {
            button.addEventListener('click', function () {
                show(button.getAttribute('data-q-back'));
            });
        });
        $$('[data-q-forward]').forEach(function (button) {
            button.addEventListener('click', function () {
                show(button.getAttribute('data-q-forward'));
            });
        });

        // ---- Step 1: connect ------------------------------------------------

        var connectForm = $('[data-q-connect]');

        // The workspace preview beside the form follows what is typed, as on Shopify.
        $$('[data-q-preview]').forEach(function (target) {
            var input = connectForm ? $('[name="' + target.getAttribute('data-q-preview') + '"]', connectForm) : null;
            if (!input) {
                return;
            }
            input.addEventListener('input', function () {
                var value = input.value.trim();
                target.textContent = value || target.getAttribute('data-fallback') || '';
            });
        });

        // What Quissly's backend accepts (the Shopify app's email-validation.ts), checked as the
        // merchant types; the server checks again.
        var LOCAL_PART = /^[\p{L}\p{N}_!#$%&'*+\-/=?^`{|}~]+(?:\.[\p{L}\p{N}_!#$%&'*+\-/=?^`{|}~]+)*$/u;
        var TYPED_LABEL = /^[\p{L}\p{Nd}](?:[\p{L}\p{Nd}-]*[\p{L}\p{Nd}])?$/u;
        var ASCII_LABEL = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;
        var SPECIAL_USE = ['arpa', 'invalid', 'local', 'localhost', 'onion', 'test'];

        function emailProblem(raw) {
            var email = raw.trim();
            if (!email) {
                return text.emailRequired;
            }
            if (email.length > 254) {
                return text.emailTooLong;
            }
            var at = email.lastIndexOf('@');
            if (at < 1 || at === email.length - 1 || !LOCAL_PART.test(email.slice(0, at))) {
                return text.emailInvalid;
            }
            var typed = email.slice(at + 1);
            var labels = typed.split('.');
            if (labels.length < 2 || labels.some(function (l) { return !TYPED_LABEL.test(l) || l.slice(2, 4) === '--'; })) {
                return text.emailInvalid;
            }
            var domain;
            try {
                domain = new URL('http://' + typed).hostname;
            } catch (e) {
                return text.emailInvalid;
            }
            if (domain.length > 253 || domain.split('.').some(function (l) { return l.length > 63 || !ASCII_LABEL.test(l); })
                || !/[a-z]$/.test(domain)) {
                return text.emailInvalid;
            }
            if (SPECIAL_USE.some(function (d) { return domain === d || domain.slice(-d.length - 1) === '.' + d; })) {
                return text.emailPublic;
            }
            return '';
        }

        function nameProblem(raw) {
            var name = raw.trim();
            return name === '' || (name.length <= 60 && /^[A-Za-z0-9 -]+$/.test(name)) ? '' : text.nameInvalid;
        }

        var checks = {email: emailProblem, store_name: nameProblem};

        function checkFields() {
            var ok = true;
            Object.keys(checks).forEach(function (name) {
                var input = connectForm ? $('[name="' + name + '"]', connectForm) : null;
                var box = $('[data-q-field-error="' + name + '"]');
                if (!input || !box) {
                    return;
                }
                var problem = checks[name](input.value);
                box.textContent = problem;
                box.hidden = !problem;
                ok = ok && !problem;
            });
            var submit = $('[data-q-connect-submit]');
            if (submit && !submit.hasAttribute('data-q-label')) {
                submit.disabled = !ok;
            }
            return ok;
        }

        if (connectForm) {
            Object.keys(checks).forEach(function (name) {
                var input = $('[name="' + name + '"]', connectForm);
                if (input) {
                    input.addEventListener('input', checkFields);
                }
            });
            checkFields();
            connectForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var button = $('[data-q-connect-submit]');
                showError('details', '');
                if (!checkFields()) {
                    return;
                }
                busy(button, true, text.connecting);
                var data = {};
                $$('input[name], textarea[name]', connectForm).forEach(function (input) {
                    data[input.name] = input.value;
                });
                post('connect', data).then(function (result) {
                    if (result && result.ok) {
                        reload();
                        return;
                    }
                    busy(button, false);
                    checkFields();
                    showError('details', (result && result.message) || text.error);
                });
            });
        }

        // ---- Step 2: plan ---------------------------------------------------

        var plansRoot = $('[data-q-plan-picker]');
        var selected = null;
        var cadence = 'monthly';
        var comparing = false;
        var payTimer = null;

        function selectedCard() {
            return selected ? $('[data-q-plan][data-plan-id="' + selected + '"]') : null;
        }

        function renderPlanChoice() {
            var card = selectedCard();
            var submit = $('[data-q-plan-submit]');
            var note = $('[data-q-plan-note]');
            // A plan is both a card and a column of the comparison table: both follow the choice.
            var first = firstEnabled();
            $$('[data-q-plan]').forEach(function (item) {
                var on = !!card && item.getAttribute('data-plan-id') === selected;
                item.setAttribute('aria-checked', on ? 'true' : 'false');
                item.tabIndex = on || (!card && item === first) ? 0 : -1;
            });
            $$('[data-q-plans]').forEach(function (grid) {
                grid.classList.toggle('is-annual', cadence === 'annual');
            });
            $$('[data-q-cadence]').forEach(function (button) {
                button.setAttribute('aria-checked', button.getAttribute('data-q-cadence') === cadence ? 'true' : 'false');
            });
            var saving = $('[data-q-saving]');
            if (saving) {
                var amount = card ? card.getAttribute('data-saving') : '';
                saving.textContent = amount ? (text.saveYear || '%1').replace('%1', amount) : (text.saveDefault || '');
            }
            if (submit) {
                submit.disabled = !card;
                submit.textContent = card ? card.getAttribute('data-cta') : (text.pickPlan || '');
            }
            if (note) {
                note.textContent = card
                    ? card.getAttribute(cadence === 'annual' ? 'data-note-annual' : 'data-note-monthly')
                    : (text.pickPlan || '');
            }
        }

        function enabledCards(scope) {
            return $$('[data-q-plan]:not([aria-disabled="true"])', scope).filter(function (card) {
                var view = card.closest('[data-q-view]');
                return !card.closest('[data-q-plans]').hidden && !(view && view.hidden);
            });
        }

        function firstEnabled() {
            return enabledCards()[0] || null;
        }

        function choose(card) {
            if (!card || card.getAttribute('aria-disabled') === 'true') {
                return;
            }
            selected = card.getAttribute('data-plan-id');
            renderPlanChoice();
        }

        function showFamily(family) {
            $$('[data-q-tab]').forEach(function (tab) {
                var on = tab.getAttribute('data-q-tab') === family;
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            $$('[data-q-plans]').forEach(function (grid) {
                grid.hidden = grid.getAttribute('data-q-plans') !== family;
            });
            // A plan stays chosen only in the family on screen, or the button
            // would offer a plan the merchant can no longer see.
            var grid = $('[data-q-plans="' + family + '"]');
            var keep = selectedCard();
            if (!keep || keep.closest('[data-q-plans]') !== grid) {
                var popular = grid ? $('[data-q-plan].is-popular:not([aria-disabled="true"])', grid) : null;
                var first = enabledCards(grid)[0] || null;
                selected = (popular || first) ? (popular || first).getAttribute('data-plan-id') : null;
            }
            renderPlanChoice();
        }

        function waitForPlan(card, payUrl) {
            var box = $('[data-q-pay]');
            var open = $('[data-q-pay-open]');
            var started = Date.now();
            if (box) {
                box.hidden = false;
            }
            if (open) {
                open.href = payUrl;
            }
            window.clearTimeout(payTimer);
            function check() {
                post('plan', {op: 'check', family: card.getAttribute('data-family')}).then(function (result) {
                    if (result && result.ok && result.active) {
                        reload();
                        return;
                    }
                    if (Date.now() - started < PAY_POLL_FOR_MS) {
                        payTimer = window.setTimeout(check, PAY_POLL_MS);
                    } else {
                        showError('plan', text.payTimeout);
                    }
                });
            }
            payTimer = window.setTimeout(check, PAY_POLL_MS);
        }

        if (plansRoot) {
            $$('[data-q-plan]').forEach(function (card) {
                card.addEventListener('click', function () {
                    choose(card);
                });
            });
            // One tab stop, arrows move within the family on screen and wrap.
            plansRoot.addEventListener('keydown', function (event) {
                var keys = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'];
                if (keys.indexOf(event.key) < 0 || !event.target.hasAttribute('data-q-plan')) {
                    return;
                }
                var cards = enabledCards();
                if (!cards.length) {
                    return;
                }
                event.preventDefault();
                var at = cards.indexOf(selectedCard());
                var next;
                if (event.key === 'Home') {
                    next = 0;
                } else if (event.key === 'End') {
                    next = cards.length - 1;
                } else if (at < 0) {
                    next = 0;
                } else {
                    var step = (event.key === 'ArrowRight' || event.key === 'ArrowDown') ? 1 : -1;
                    next = (at + step + cards.length) % cards.length;
                }
                choose(cards[next]);
                cards[next].focus();
            });
            $$('[data-q-tab]').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    showFamily(tab.getAttribute('data-q-tab'));
                });
            });
            $$('[data-q-cadence]').forEach(function (button) {
                button.addEventListener('click', function () {
                    cadence = button.getAttribute('data-q-cadence');
                    renderPlanChoice();
                });
            });
            // "Compare all features" swaps the cards for the table, in place (direction 3a).
            var compare = $('[data-q-compare]');
            if (compare) {
                compare.addEventListener('click', function () {
                    comparing = !comparing;
                    $$('[data-q-view]').forEach(function (view) {
                        view.hidden = (view.getAttribute('data-q-view') === 'table') !== comparing;
                    });
                    compare.textContent = comparing ? text.compareBack : text.compare;
                    renderPlanChoice();
                });
            }
            var firstTab = $('[data-q-tab]');
            showFamily(firstTab ? firstTab.getAttribute('data-q-tab') : 'qsearch');
        }

        var planSubmit = $('[data-q-plan-submit]');
        if (planSubmit) {
            planSubmit.addEventListener('click', function () {
                var card = selectedCard();
                if (!card) {
                    return;
                }
                showError('plan', '');
                // Opened now, inside the click, so no popup blocker stops it;
                // pointed at the payment page once Quissly answers. A free plan
                // never needs it and it is closed again.
                var paid = card.getAttribute('data-free') !== '1';
                var tab = paid ? window.open('', '_blank') : null;
                busy(planSubmit, true, text.saving);
                post('plan', {op: 'checkout', plan_id: selected, billing_cycle: cadence}).then(function (result) {
                    if (result && result.ok && result.kind === 'active') {
                        if (tab) {
                            tab.close();
                        }
                        reload();
                        return;
                    }
                    busy(planSubmit, false);
                    renderPlanChoice();
                    if (result && result.ok && result.kind === 'pay' && result.pay_url) {
                        if (tab) {
                            tab.location.href = result.pay_url;
                        }
                        waitForPlan(card, result.pay_url);
                        return;
                    }
                    if (tab) {
                        tab.close();
                    }
                    showError('plan', (result && result.message) || text.error);
                });
            });
        }

        var payCheck = $('[data-q-pay-check]');
        if (payCheck) {
            payCheck.addEventListener('click', function () {
                var card = selectedCard();
                if (!card) {
                    return;
                }
                showError('plan', '');
                busy(payCheck, true, '');
                post('plan', {op: 'check', family: card.getAttribute('data-family')}).then(function (result) {
                    busy(payCheck, false);
                    if (result && result.ok && result.active) {
                        reload();
                        return;
                    }
                    showError('plan', (result && result.message) || text.payNotYet);
                });
            });
        }

        $$('[data-q-plan-op]').forEach(function (button) {
            button.addEventListener('click', function () {
                showError('plan', '');
                busy(button, true, text.saving);
                post('plan', {op: button.getAttribute('data-q-plan-op')}).then(function (result) {
                    if (result && result.ok) {
                        reload();
                        return;
                    }
                    busy(button, false);
                    showError('plan', (result && result.message) || text.error);
                });
            });
        });

        // ---- Step 3: go live -------------------------------------------------

        var statusTimer = null;
        var statusStarted = 0;
        var statusRunning = false;

        function renderStatus(result) {
            Object.keys(result.rows || {}).forEach(function (key) {
                var row = $('[data-q-row="' + key + '"]');
                var data = result.rows[key];
                if (!row || !data) {
                    return;
                }
                row.setAttribute('data-state', data.state);
                var state = $('[data-q-row-state]', row);
                if (state) {
                    state.textContent = (text.states || {})[data.state] || data.state;
                }
                var detail = $('[data-q-row-detail]', row);
                if (detail) {
                    detail.textContent = data.detail || '';
                    detail.hidden = !data.detail;
                }
            });
            var chip = $('[data-q-chip]');
            if (chip) {
                chip.classList.toggle('is-ready', !!result.ready);
                chip.classList.toggle('is-failed', !!result.failed && !result.ready);
                chip.textContent = result.ready ? text.chipReady : (result.failed ? text.chipFailed : text.chipWorking);
            }
            var finish = $('[data-q-finish]');
            if (finish && !finish.hasAttribute('data-q-label')) {
                finish.disabled = !result.ready;
            }
            var retry = $('[data-q-retry]');
            if (retry) {
                retry.hidden = !result.failed || !!result.ready;
            }
            // "Save changes" stands in while Finish Setup is not possible (the Shopify app's rule).
            var save = $('[data-q-save]');
            if (save && !save.hasAttribute('data-q-label')) {
                save.hidden = !!result.ready;
                save.disabled = !result.saveable;
            }
        }

        function pollStatus() {
            post('status', {}).then(function (result) {
                if (result && result.ok) {
                    renderStatus(result);
                    if (result.ready) {
                        statusRunning = false;
                        return;
                    }
                }
                var wait = Date.now() - statusStarted < STATUS_FAST_FOR_MS ? STATUS_FAST_MS : STATUS_SLOW_MS;
                statusTimer = window.setTimeout(pollStatus, wait);
            });
        }

        function startStatus() {
            if (statusRunning || !$('[data-q-row]')) {
                return;
            }
            statusRunning = true;
            statusStarted = Date.now();
            window.clearTimeout(statusTimer);
            pollStatus();
        }

        var retryButton = $('[data-q-retry]');
        if (retryButton) {
            retryButton.addEventListener('click', function () {
                busy(retryButton, true, '');
                post('status', {retry: '1'}).then(function (result) {
                    busy(retryButton, false);
                    if (result && result.ok) {
                        renderStatus(result);
                    }
                    startStatus();
                });
            });
        }

        var saveButton = $('[data-q-save]');
        if (saveButton) {
            saveButton.addEventListener('click', function () {
                showError('golive', '');
                busy(saveButton, true, text.saving);
                post('finish', {later: '1'}).then(function (result) {
                    if (result && result.ok) {
                        window.location.href = result.redirect || config.reload;
                        return;
                    }
                    busy(saveButton, false);
                    showError('golive', (result && result.message) || text.error);
                });
            });
        }

        var finishButton = $('[data-q-finish]');
        if (finishButton) {
            finishButton.addEventListener('click', function () {
                showError('golive', '');
                busy(finishButton, true, text.goingLive);
                post('finish', {}).then(function (result) {
                    if (result && result.ok) {
                        window.location.href = result.redirect || config.reload;
                        return;
                    }
                    busy(finishButton, false);
                    showError('golive', (result && result.message) || text.error);
                });
            });
        }

        show(config.step || 'details');
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-q-setup]'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
