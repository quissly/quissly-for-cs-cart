/**
 * Quissly for CS-Cart - Configuration's sections open and close as the WooCommerce and Magento
 * plugins' do: each header of the add-on's settings is a title bar (CS-Cart already makes it a
 * collapse toggle), Features starts open and the rest closed, and the page remembers which ones
 * the merchant left open (localStorage). Inlined by the status block (func.php
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
