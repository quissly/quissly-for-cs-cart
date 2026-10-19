/**
 * Errors in the add-on's own scripts, sent to the store's quissly_search.client_error (storefront and
 * admin), which keeps them for Quissly (lib/Diagnostics/ErrorReport.php). Loaded on every page by the
 * index:scripts hooks.
 *
 * CS-Cart joins (and minifies) a page's scripts into one file, so a file name cannot tell whose an
 * error is. Each of the add-on's scripts therefore notes where it starts and ends as it runs - the
 * first and last lines of each push [name, new Error(), end?] to window.quisslyMarks - and an error
 * is the add-on's when a frame of its stack lies between one script's two marks (or after a start
 * with no end: the script failed as it first ran, which stops the joined file there). Errors of the
 * Quissly chat (loaded from quissly.com) count too. Anything else on the page is not looked at.
 *
 * Sent: the script's name, the message, the position, the stack (addresses without their query
 * strings) and the page's path. At most 5 per page, each once.
 */
(function () {
    'use strict';

    var left = 5;
    var sent = {};

    function frames(stack) {
        var found = [];
        var re = /(https?:\/\/[^\s()@]+?):(\d+):(\d+)/g;
        var m;
        while ((m = re.exec(String(stack || ''))) && found.length < 30) {
            found.push([m[1], +m[2], +m[3]]);
        }
        return found;
    }

    function before(a, b) {
        return a[1] < b[1] || (a[1] === b[1] && a[2] <= b[2]);
    }

    function strip(url) {
        return String(url || '').split('#')[0];
    }

    // The add-on's script a position lies in, or ''.
    function owner(at) {
        if (/^https?:\/\/[^\/]*quissly\.com\//.test(at[0])) {
            return 'qchat';
        }
        var marks = window.quisslyMarks || [];
        for (var i = 0; i < marks.length; i++) {
            if (marks[i][2]) {
                continue;
            }
            var start = frames(marks[i][1] && marks[i][1].stack)[0];
            if (!start || strip(start[0]) !== strip(at[0]) || !before(start, at)) {
                continue;
            }
            // No end yet: the script failed as it ran, which stopped the rest of its file there.
            var end = null;
            for (var j = i + 1; j < marks.length; j++) {
                if (marks[j][0] === marks[i][0] && marks[j][2]) {
                    end = frames(marks[j][1] && marks[j][1].stack)[0] || null;
                    break;
                }
            }
            if (!end || before(at, end)) {
                return marks[i][0];
            }
        }
        return '';
    }

    function send(kind, message, positions, stack) {
        var file = '';
        var at = null;
        for (var i = 0; i < positions.length && !file; i++) {
            file = owner(positions[i]);
            at = positions[i];
        }
        var key = file + '|' + message;
        if (!file || left <= 0 || sent[key]) {
            return;
        }
        sent[key] = true;
        left--;
        deliver(JSON.stringify({
            kind: kind,
            file: file,
            message: String(message || '').slice(0, 500),
            at: at ? at[1] + ':' + at[2] : '',
            stack: String(stack || '').replace(/(https?:\/\/[^\s()?]+)\?[^\s():]*/g, '$1').slice(0, 1500),
            page: location.pathname.slice(0, 200)
        }));
    }

    // CS-Cart names the store's address (Tygh.current_location) after its joined scripts: an error
    // while they first run waits for the page to load.
    var waiting = [];

    function deliver(body) {
        var tygh = window.Tygh;
        if (!tygh || !tygh.current_location || !tygh.index_script) {
            if (waiting.push(body) === 1) {
                window.addEventListener('load', function () {
                    var bodies = waiting.splice(0);
                    if (window.Tygh && window.Tygh.current_location) {
                        bodies.forEach(deliver);
                    }
                });
            }
            return;
        }
        var url = tygh.current_location + '/' + tygh.index_script + '?dispatch=quissly_search.client_error'
            + '&security_hash=' + encodeURIComponent(tygh.security_hash || '');
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], {type: 'application/json'}))) {
                return;
            }
        } catch (e) {
            // Fall through to fetch.
        }
        try {
            fetch(url, {method: 'POST', body: body, headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', keepalive: true});
        } catch (e) {
            // Reporting never gets in the page's way.
        }
    }

    window.addEventListener('error', function (event) {
        try {
            var stack = event.error && event.error.stack;
            var positions = event.filename ? [[event.filename, event.lineno || 0, event.colno || 0]] : [];
            send('error', event.message, positions.concat(frames(stack)), stack);
        } catch (e) {
            // Nothing to do.
        }
    });
    window.addEventListener('unhandledrejection', function (event) {
        try {
            var reason = event.reason || {};
            send('promise', reason.message || String(event.reason), frames(reason.stack), reason.stack);
        } catch (e) {
            // Nothing to do.
        }
    });
}());
