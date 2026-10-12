/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Quissly Billing - the behaviour behind the plans, usage and invoices page.
 *
 * The same file ships in quissly-for-woocommerce and quissly-for-cs-cart; keep the copies
 * identical. The page is rendered by the platform (every word through its translations,
 * previews already written as sentences by the server); this script opens the plan picker
 * the way the Shopify app's Settings does (pick a card, then Confirm plan), asks for
 * confirmation with the server's preview before anything is charged, and opens Quissly's
 * payment pages in a new tab. It also carries the automatic top-up choice (in the picker for
 * a new plan, and saved on its own for a live one) and the refund request: the server's
 * preview says whether the refund is automatic, and a merchant it does not cover can write
 * to a person instead. Vanilla, no framework.
 *
 * Config (JSON in data-config on [data-q-billing]): endpoint, reload, csrf {name: value},
 * params {name: value} (merged into every POST), text {...}.
 */
(function () {
    'use strict';

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

        function post(data) {
            var body = new URLSearchParams();
            [config.params || {}, config.csrf || {}, data].forEach(function (set) {
                Object.keys(set).forEach(function (key) {
                    body.append(key, set[key]);
                });
            });
            return fetch(config.endpoint, {
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

        function uuid() {
            if (window.crypto && window.crypto.randomUUID) {
                return window.crypto.randomUUID();
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                var r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        }

        // ---- The confirmation dialog -------------------------------------------

        var modal = $('[data-q-modal]');
        var modalOk = $('[data-q-modal-ok]');
        var modalError = $('[data-q-modal-error]');
        var onConfirm = null;

        var claim = $('[data-q-modal-claim]');
        var claimInput = $('[data-q-claim]');
        var claimCount = $('[data-q-claim-count]');

        function openModal(title, summary, confirmLabel, action) {
            $('[data-q-modal-title]').textContent = title;
            $('[data-q-modal-body]').textContent = summary;
            if (claim) {
                claim.hidden = true;
            }
            modalOk.textContent = confirmLabel;
            modalOk.disabled = false;
            modalError.hidden = true;
            onConfirm = action;
            modal.hidden = false;
            modalOk.focus();
        }

        function closeModal() {
            modal.hidden = true;
            onConfirm = null;
        }

        function fail(message) {
            modalError.textContent = message || text.error;
            modalError.hidden = false;
            modalOk.disabled = false;
            modalOk.textContent = modalOk.getAttribute('data-label') || modalOk.textContent;
        }

        /** The refund claim's text box, under the preview's message. */
        function showClaim() {
            claim.hidden = false;
            claimInput.value = '';
            countClaim();
            claimInput.focus();
        }

        function countClaim() {
            claimCount.textContent = (text.claimCount || '%1 / %2').split('%1').join(claimInput.value.trim().length)
                .split('%2').join(claimInput.getAttribute('maxlength'));
        }

        /** The claim's words, or null (with the reason shown) when too short or too long. */
        function claimText() {
            var words = claimInput.value.trim();
            if (words.length < Number(claimInput.getAttribute('data-min')) ||
                words.length > Number(claimInput.getAttribute('maxlength'))) {
                fail(text.claimLength);
                claimInput.focus();
                return null;
            }
            return words;
        }

        if (claimInput) {
            claimInput.addEventListener('input', countClaim);
        }

        if (modal) {
            $('[data-q-modal-cancel]').addEventListener('click', closeModal);
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) {
                    closeModal();
                }
            });
            modalOk.addEventListener('click', function () {
                if (!onConfirm) {
                    return;
                }
                modalOk.setAttribute('data-label', modalOk.textContent);
                modalOk.disabled = true;
                modalOk.textContent = text.working;
                onConfirm();
            });
        }

        /** Run an op that finishes on the server, then show the result after a reload. */
        function finish(data) {
            post(data).then(function (result) {
                if (result && result.ok) {
                    reload();
                    return;
                }
                fail(result && result.message);
            });
        }

        // ---- Plan pickers ------------------------------------------------------
        // As in the Shopify app's Settings: the picker opens in the plan's card at full
        // width, a card is picked like a radio, and nothing happens until Confirm plan.

        var settings = $('[data-q-settings]');

        function pickerOf(el) {
            return el.closest('[data-q-family]');
        }

        function picked(section) {
            var card = $('[data-q-pick][aria-checked="true"]', section);
            return card ? card.getAttribute('data-q-pick') : '';
        }

        function syncConfirm(section) {
            var picker = $('[data-q-picker]', section);
            var planId = picked(section);
            var same = planId !== '' && planId === picker.getAttribute('data-current');
            $('[data-q-confirm]', section).disabled = planId === '' || same;
            $('[data-q-same]', section).hidden = !same;
        }

        function select(section, card) {
            $$('[data-q-pick]', section).forEach(function (other) {
                other.setAttribute('aria-checked', other === card ? 'true' : 'false');
                other.tabIndex = other === card ? 0 : -1;
            });
            syncConfirm(section);
            syncTopup(section, card);
        }

        // ---- Automatic top-up --------------------------------------------------
        // Quissly buys one block of extra requests on the saved card when the month's quota is
        // about to run out, up to the merchant's maximum a month. Offered only on a plan that
        // sells blocks; the card (or the live plan's form) carries its limits and words.

        /** The picker's top-up box follows the picked card: shown for a plan that sells blocks. */
        function syncTopup(section, card) {
            var box = $('[data-q-autotopup]', section);
            if (!box) {
                return;
            }
            var price = card ? card.getAttribute('data-at-min') : '';
            box.hidden = !price;
            if (!price) {
                return;
            }
            var input = $('[data-q-at-max]', box);
            ['min', 'max'].forEach(function (end) {
                input.setAttribute(end, card.getAttribute('data-at-' + end));
                input.setAttribute('data-' + end + '-text', card.getAttribute('data-at-' + end + '-text'));
            });
            if (!input.getAttribute('data-touched')) {
                input.value = card.getAttribute('data-at-default');
            }
            $('[data-q-at-rate]', box).textContent = card.getAttribute('data-at-rate');
            $('[data-q-at-hint]', box).textContent = card.getAttribute('data-at-hint');
            $('[data-q-at-error]', box).hidden = true;
        }

        /** The chosen maximum: 0 when off, null when it is out of range (the reason is shown). */
        function topupAmount(box) {
            var error = $('[data-q-at-error]', box);
            error.hidden = true;
            if (!$('[data-q-at-toggle]', box).checked) {
                return 0;
            }
            var input = $('[data-q-at-max]', box);
            var value = Number(input.value);
            if (!(value >= Number(input.getAttribute('min')) && value <= Number(input.getAttribute('max')))) {
                error.textContent = (text.topupRange || '').split('%1').join(input.getAttribute('data-min-text'))
                    .split('%2').join(input.getAttribute('data-max-text'));
                error.hidden = false;
                input.focus();
                return null;
            }
            return value;
        }

        $$('[data-q-at-toggle]').forEach(function (toggle) {
            var box = toggle.closest('[data-q-autotopup], [data-q-at-form]');
            function show() {
                $('[data-q-at-fields]', box).hidden = !toggle.checked;
                $('[data-q-at-error]', box).hidden = true;
            }
            toggle.addEventListener('change', show);
            show();
        });

        $$('[data-q-at-max]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.setAttribute('data-touched', '1');
                $('[data-q-at-error]', input.closest('[data-q-autotopup], [data-q-at-form]')).hidden = true;
            });
        });

        $$('[data-q-at-save]').forEach(function (button) {
            button.addEventListener('click', function () {
                var form = button.closest('[data-q-at-form]');
                var amount = topupAmount(form);
                if (amount === null) {
                    return;
                }
                button.disabled = true;
                post({op: 'auto_topup', id: form.getAttribute('data-id'), max_usd: amount}).then(function (result) {
                    if (result && result.ok) {
                        reload();
                        return;
                    }
                    button.disabled = false;
                    var error = $('[data-q-at-error]', form);
                    error.textContent = (result && result.message) || text.error;
                    error.hidden = false;
                });
            });
        });

        function closePicker(section) {
            $('[data-q-picker]', section).hidden = true;
            $('[data-q-actions]', section).hidden = false;
            if (settings && !$('[data-q-picker]:not([hidden])')) {
                settings.classList.remove('is-picking');
            }
        }

        $$('[data-q-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                var section = pickerOf(button);
                var picker = $('[data-q-picker]', section);
                var current = $('[data-q-pick="' + picker.getAttribute('data-current') + '"]', section);
                var first = $('[data-q-pick]:not([aria-disabled="true"])', section);
                select(section, current || first);
                $('[data-q-actions]', section).hidden = true;
                picker.hidden = false;
                if (settings) {
                    settings.classList.add('is-picking');
                }
                section.scrollIntoView({block: 'nearest', behavior: 'smooth'});
            });
        });

        $$('[data-q-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                closePicker(pickerOf(button));
            });
        });

        $$('[data-q-cycle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var section = pickerOf(button);
                var annual = button.getAttribute('data-q-cycle') === 'annual';
                $$('[data-q-cycle]', section).forEach(function (other) {
                    other.setAttribute('aria-checked', other === button ? 'true' : 'false');
                });
                $$('[data-q-monthly]', section).forEach(function (el) {
                    el.hidden = annual;
                });
                $$('[data-q-annual]', section).forEach(function (el) {
                    el.hidden = !annual;
                });
            });
        });

        $$('[data-q-pick]').forEach(function (card) {
            card.addEventListener('click', function () {
                if (card.getAttribute('aria-disabled') === 'true') {
                    return;
                }
                select(pickerOf(card), card);
            });
            card.addEventListener('keydown', function (event) {
                var step = {ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1}[event.key];
                if (!step) {
                    return;
                }
                event.preventDefault();
                var section = pickerOf(card);
                var cards = $$('[data-q-pick]:not([aria-disabled="true"])', section);
                var next = cards[(cards.indexOf(card) + step + cards.length) % cards.length];
                if (next) {
                    select(section, next);
                    next.focus();
                }
            });
        });

        function waitForPlan(family, tab, payUrl) {
            if (tab) {
                tab.location.href = payUrl;
            }
            openModal(text.titlePay, text.payWaiting, text.close, closeModal);
            var started = Date.now();
            (function check() {
                post({op: 'check', family: family}).then(function (result) {
                    if (result && result.ok && result.active) {
                        reload();
                        return;
                    }
                    if (Date.now() - started < PAY_POLL_FOR_MS) {
                        window.setTimeout(check, PAY_POLL_MS);
                    } else {
                        $('[data-q-modal-body]').textContent = text.payTimeout;
                    }
                });
            })();
        }

        $$('[data-q-confirm]').forEach(function (button) {
            button.addEventListener('click', function () {
                var section = pickerOf(button);
                var family = section.getAttribute('data-q-family');
                var planId = picked(section);
                var sub = $('[data-q-picker]', section).getAttribute('data-sub');
                if (!planId) {
                    return;
                }
                if (sub) {
                    // A live paid plan: show what the change does before it is made.
                    openModal(text.titleChange, text.working, text.working, null);
                    modalOk.disabled = true;
                    post({op: 'change_preview', id: sub, plan_id: planId}).then(function (result) {
                        if (!result || !result.ok) {
                            fail(result && result.message);
                            modalOk.disabled = true;
                            return;
                        }
                        openModal(text.titleChange, result.summary, result.confirm, function () {
                            finish({op: 'change', id: sub, plan_id: planId});
                        });
                    });
                    return;
                }
                // No plan yet (or the free one): start the chosen plan. The payment tab is opened
                // inside the click so no popup blocker stops it. The automatic top-up choice goes
                // with the checkout: Paddle's card form cannot carry it.
                var card = $('[data-q-pick="' + planId + '"]', section);
                var annual = $('[data-q-cycle="annual"][aria-checked="true"]', section);
                var topupBox = $('[data-q-autotopup]', section);
                var autoMax = topupBox && !topupBox.hidden ? topupAmount(topupBox) : 0;
                if (autoMax === null) {
                    return;
                }
                var tab = card.getAttribute('data-free') === '1' ? null : window.open('', '_blank');
                button.disabled = true;
                post({
                    op: 'checkout',
                    plan_id: planId,
                    billing_cycle: annual ? 'annual' : 'monthly',
                    auto_topup_max_usd: autoMax
                }).then(function (result) {
                    button.disabled = false;
                    if (result && result.ok && result.done) {
                        if (tab) {
                            tab.close();
                        }
                        reload();
                        return;
                    }
                    if (result && result.ok && result.pay_url) {
                        waitForPlan(family, tab, result.pay_url);
                        return;
                    }
                    if (tab) {
                        tab.close();
                    }
                    openModal(text.titleChange, '', text.close, closeModal);
                    fail(result && result.message);
                    modalOk.disabled = false;
                    modalOk.textContent = text.close;
                });
            });
        });

        // ---- Plan actions ------------------------------------------------------

        $$('[data-q-op]').forEach(function (button) {
            button.addEventListener('click', function () {
                var op = button.getAttribute('data-q-op');
                var id = button.getAttribute('data-id');
                if (op === 'cancel_confirm') {
                    openModal(text.titleCancel, button.getAttribute('data-summary'), text.confirmCancel, function () {
                        finish({op: 'cancel', id: id});
                    });
                    return;
                }
                if (op === 'topup_preview') {
                    // The key is minted when the dialog opens and resent on a retry, so a double
                    // click or a lost answer charges the card once.
                    var key = uuid();
                    openModal(text.titleTopup, text.working, text.working, null);
                    modalOk.disabled = true;
                    post({op: 'topup_preview', id: id}).then(function (result) {
                        if (!result || !result.ok) {
                            fail(result && result.message);
                            modalOk.disabled = true;
                            return;
                        }
                        openModal(text.titleTopup, result.summary, result.confirm, function () {
                            finish({op: 'topup', id: id, idempotency_key: key});
                        });
                    });
                    return;
                }
                if (op === 'refund_preview') {
                    // The preview says what a refund does now, or why not; a merchant it does not
                    // cover can still write to a person, who answers within days.
                    openModal(text.titleRefund, text.working, text.working, null);
                    modalOk.disabled = true;
                    post({op: 'refund_preview', id: id}).then(function (result) {
                        if (!result || !result.ok) {
                            fail(result && result.message);
                            modalOk.disabled = true;
                            return;
                        }
                        if (result.eligible) {
                            openModal(text.titleRefund, result.summary, text.confirmRefund, function () {
                                finish({op: 'refund', id: id});
                            });
                            return;
                        }
                        if (result.claim) {
                            openModal(text.titleClaim, result.summary, text.sendClaim, function () {
                                var reason = claimText();
                                if (reason !== null) {
                                    finish({op: 'refund_claim', id: id, reason: reason});
                                }
                            });
                            showClaim();
                            return;
                        }
                        openModal(text.titleRefund, result.summary, text.close, closeModal);
                    });
                    return;
                }
                if (op === 'payment_method' || op === 'invoice_pdf') {
                    var tab = window.open('', '_blank');
                    button.disabled = true;
                    post({op: op, id: id}).then(function (result) {
                        button.disabled = false;
                        if (result && result.ok && result.url) {
                            tab.location.href = result.url;
                            return;
                        }
                        tab.close();
                        openModal(text.titleError, '', text.close, closeModal);
                        fail(result && result.message);
                        modalOk.disabled = false;
                        modalOk.textContent = text.close;
                    });
                    return;
                }
                // resume, abort: no charge, nothing to confirm.
                button.disabled = true;
                post({op: op, id: id}).then(function (result) {
                    if (result && result.ok) {
                        reload();
                        return;
                    }
                    button.disabled = false;
                    openModal(text.titleError, '', text.close, closeModal);
                    fail(result && result.message);
                    modalOk.disabled = false;
                    modalOk.textContent = text.close;
                });
            });
        });
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-q-billing]'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
