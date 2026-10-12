/**
 * Shopping activity for Quissly's analytics: sends this page's one event (a product page view
 * or a search, decided by lib/Storefront.php) to the store's own quissly_search.event. Who the
 * shopper is, and whether they consented, is the store's to decide there - this only says what
 * was viewed or searched.
 *
 * A search is sent once per tab: reloading the results page, or coming back to it, is not
 * another search. (The same script as quissly-for-magento's quissly-events.js.)
 */
(function () {
    'use strict';

    var host = document.querySelector('[data-quissly-events]');
    if (!host) {
        return;
    }
    var config;
    try {
        config = JSON.parse(host.getAttribute('data-quissly-config') || 'null');
    } catch (e) {
        return;
    }
    var event = config && config.event;
    if (!config || !config.url || !event || !event.type) {
        return;
    }

    if (event.type === 'search') {
        var key = 'quissly_search_sent';
        var id = event.modality + ':' + (event.modality === 'text' ? event.query : location.search);
        try {
            if (sessionStorage.getItem(key) === id) {
                return;
            }
            sessionStorage.setItem(key, id);
        } catch (e) {
            // Storage blocked: send it anyway.
        }
    }

    // CS-Cart refuses a storefront POST without the session's security hash.
    var url = config.url + (config.url.indexOf('?') === -1 ? '?' : '&')
        + 'security_hash=' + encodeURIComponent(config.securityHash || '');
    var body = JSON.stringify(event);
    try {
        if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], {type: 'application/json'}))) {
            return;
        }
    } catch (e) {
        // Fall through to fetch.
    }
    try {
        fetch(url, {
            method: 'POST',
            body: body,
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            keepalive: true
        });
    } catch (e) {
        // Analytics never gets in the shopper's way.
    }
}());
