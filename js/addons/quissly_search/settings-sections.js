/**
 * Quissly for CS-Cart - Configuration's sections open and close as the WooCommerce and Magento
 * plugins' do: each header of the add-on's settings is a title bar (CS-Cart already makes it a
 * collapse toggle), Features starts open and the rest closed, and the page remembers which ones
 * the merchant left open (localStorage). Each setting's description moves from under its label
 * (CS-Cart puts it in the narrow, right-aligned label column) to under its control, where it has
 * the width - as the other plugins show them. Inlined by the status block (func.php
 * fn_quissly_search_status_handler), styled by design/backend/css/addons/quissly_search/settings.css.
 */
(function () {
    'use strict';

    var KEY = 'quissly_search_sections';
    var PREFIX = '#collapsable_addon_option_quissly_search_';

    function boot() {
        var saved = {};
        try {
            saved = JSON.parse(window.localStorage.getItem(KEY) || '{}') || {};
        } catch (e) {
            saved = {};
        }
        Array.prototype.forEach.call(document.querySelectorAll('h4.subheader[data-target^="' + PREFIX + '"]'), function (head) {
            if (head.hasAttribute('data-q-section')) {
                return;
            }
            var target = head.getAttribute('data-target');
            var body = document.querySelector(target);
            if (!body) {
                return;
            }
            // collapsable_addon_option_quissly_search_<name>_header_<object id>
            var key = target.slice(PREFIX.length).replace(/_header_\d+$/, '');
            head.setAttribute('data-q-section', key);
            var open = Object.prototype.hasOwnProperty.call(saved, key) ? !!saved[key] : key === 'features';
            body.classList.toggle('in', open);
            head.classList.toggle('collapsed', !open);
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
            head.addEventListener('click', function () {
                // CS-Cart's collapse toggles the body; read where it ended up once it has.
                window.setTimeout(function () {
                    var isOpen = body.classList.contains('in');
                    head.classList.toggle('collapsed', !isOpen);
                    head.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    saved[key] = isOpen;
                    try {
                        window.localStorage.setItem(KEY, JSON.stringify(saved));
                    } catch (e) {
                        return;
                    }
                }, 450);
            });
        });
    }

    function describe() {
        Array.prototype.forEach.call(document.querySelectorAll('[id^="' + PREFIX.slice(1) + '"] .control-group'), function (group) {
            var description = group.querySelector('.control-label > .description');
            var controls = group.querySelector('.controls');
            if (description && controls) {
                controls.appendChild(description);
            }
        });
    }

    function start() {
        boot();
        describe();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
