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
 * payment pages in a new tab. Vanilla, no framework.
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

        function openModal(title, summary, confirmLabel, action) {
            $('[data-q-modal-title]').textContent = title;
            $('[data-q-modal-body]').textContent = summary;
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
        }

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
                // inside the click so no popup blocker stops it.
                var card = $('[data-q-pick="' + planId + '"]', section);
                var annual = $('[data-q-cycle="annual"][aria-checked="true"]', section);
                var tab = card.getAttribute('data-free') === '1' ? null : window.open('', '_blank');
                button.disabled = true;
                post({
                    op: 'checkout',
                    plan_id: planId,
                    billing_cycle: annual ? 'annual' : 'monthly'
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
