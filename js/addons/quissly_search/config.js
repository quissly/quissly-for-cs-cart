/*
 * Quissly for CS-Cart - Configuration's suggestion lists, edited as the Quissly Shopify app's
 * Settings edits them (lib/Admin/SuggestionsView.php draws the markup; inlined there, so it also
 * runs when the settings form is loaded into a dialog). Vanilla, no framework.
 *
 *  - Search Examples ([data-q-suggestions]): one list per storefront language with a Language
 *    select, a row per example with Remove, "Add a suggestion", "N of 20" and "Reset to
 *    generated". Every list travels in ONE hidden field as JSON {language: [queries]};
 *    controllers/backend/addons.pre.php writes the lists that changed to Quissly.
 *    Config (data-config): {main, max, maxLength, languages: [{code, label, queries, generated,
 *    note}], text: {...}}.
 *  - Search Suggestions, Manual ([data-q-fields]): one list per language, a field per suggestion
 *    and one empty field after them ("fill one and the next field appears"), up to max. Also one
 *    hidden JSON field. Config: {max, maxLength, languages: [{code, label, queries}], text}.
 *  - The "Suggestions" select ([data-q-mode]): its note, the read-only Automatic buttons
 *    ([data-q-auto]) and the Manual fields ([data-q-manual]) follow it.
 */
(function () {
    'use strict';

    // Drawn by two blocks on one page: the second copy only starts what is new.
    if (window.quisslyConfigBoot) {
        window.quisslyConfigBoot();
        return;
    }

    function key(query) {
        return query.trim().replace(/\s+/g, ' ').toLowerCase();
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    function readConfig(root) {
        try {
            return JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) {
            return null;
        }
    }

    /** Search Examples: rows with Remove, per language. */
    function examples(root) {
        var config = readConfig(root);
        if (!config) {
            return;
        }
        var text = config.text || {};
        var lists = {};
        var byCode = {};
        (config.languages || []).forEach(function (language) {
            lists[language.code] = (language.queries || []).slice();
            byCode[language.code] = language;
        });
        var current = config.main;

        var field = root.querySelector('[data-q-value]');
        var select = root.querySelector('[data-q-language]');
        var rows = root.querySelector('[data-q-rows]');
        var input = root.querySelector('[data-q-new]');
        var add = root.querySelector('[data-q-add]');
        var count = root.querySelector('[data-q-count]');
        var reset = root.querySelector('[data-q-reset]');
        var note = root.querySelector('[data-q-note]');
        var error = root.querySelector('[data-q-error]');

        function render() {
            var list = lists[current] || [];
            rows.textContent = '';
            list.forEach(function (query, index) {
                var row = el('div', 'q-row');
                row.appendChild(el('span', 'q-row__text', query));
                var remove = el('button', 'q-row__remove', text.remove || 'Remove');
                remove.type = 'button';
                remove.setAttribute('aria-label', (text.remove || 'Remove') + ': ' + query);
                remove.addEventListener('click', function () {
                    list.splice(index, 1);
                    render();
                    input.focus();
                });
                row.appendChild(remove);
                rows.appendChild(row);
            });
            if (!list.length) {
                rows.appendChild(el('p', 'q-rows__empty', current === config.main ? (text.emptyMain || '') : (text.emptyOther || '')));
            }
            count.textContent = (text.count || '%1 of %2').split('%1').join(list.length).split('%2').join(config.max);
            count.classList.toggle('is-over', list.length > config.max);
            add.disabled = list.length >= config.max;
            var language = byCode[current] || {};
            var generated = language.generated || [];
            reset.hidden = !generated.length || JSON.stringify(generated) === JSON.stringify(list);
            note.textContent = !list.length && language.note ? language.note : '';
            note.hidden = note.textContent === '';
            error.hidden = true;
            field.value = JSON.stringify(lists);
        }

        function addQuery() {
            var query = input.value.trim().replace(/\s+/g, ' ');
            var list = lists[current] || (lists[current] = []);
            if (query === '' || list.length >= config.max) {
                return;
            }
            if (query.length > config.maxLength) {
                error.textContent = (text.tooLong || '').split('%1').join(config.maxLength);
                error.hidden = false;
                return;
            }
            // A repeat is dropped, as the Shopify app does.
            if (!list.some(function (q) { return key(q) === key(query); })) {
                list.push(query);
            }
            input.value = '';
            render();
            input.focus();
        }

        add.addEventListener('click', addQuery);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                // Enter adds the suggestion; it must not submit the whole Configuration form.
                event.preventDefault();
                addQuery();
            }
        });
        reset.addEventListener('click', function () {
            lists[current] = (byCode[current].generated || []).slice();
            render();
        });
        if (select) {
            select.addEventListener('change', function () {
                current = select.value;
                render();
            });
        }
        render();
    }

    /** Search Suggestions, Manual: a field per suggestion, per language. */
    function fields(root) {
        var config = readConfig(root);
        if (!config) {
            return;
        }
        var text = config.text || {};
        var lists = {};
        (config.languages || []).forEach(function (language) {
            lists[language.code] = (language.queries || []).slice(0, config.max);
        });
        var current = (config.languages || [{}])[0].code;
        var field = root.querySelector('[data-q-value]');
        var box = root.querySelector('[data-q-list]');
        var select = root.querySelector('[data-q-language]');

        function store() {
            var out = {};
            Object.keys(lists).forEach(function (code) {
                out[code] = lists[code].filter(function (q) { return q.trim() !== ''; }).map(function (q) {
                    return q.trim().replace(/\s+/g, ' ');
                });
            });
            field.value = JSON.stringify(out);
        }

        function input(index) {
            var wrap = el('div', 'q-field');
            var control = el('input', 'input-text input-large');
            control.type = 'text';
            control.maxLength = config.maxLength;
            control.id = field.id + '_' + current + '_' + index;
            control.value = lists[current][index] || '';
            if (index === 0) {
                control.placeholder = text.placeholder || '';
            }
            control.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            });
            control.addEventListener('input', function () {
                var list = lists[current];
                list[Array.prototype.indexOf.call(box.children, wrap)] = control.value;
                // The trailing empty ones go, as the Shopify app's fields do.
                while (list.length && !String(list[list.length - 1]).trim()) {
                    list.pop();
                }
                sync();
                store();
            });
            var label = el('label', 'q-field__label', (text.field || '%1').split('%1').join(index + 1));
            label.htmlFor = control.id;
            wrap.appendChild(label);
            wrap.appendChild(control);
            return wrap;
        }

        // Every filled field plus one empty one, up to max.
        function sync() {
            var wanted = Math.min(lists[current].length + 1, config.max);
            while (box.children.length < wanted) {
                box.appendChild(input(box.children.length));
            }
            while (box.children.length > wanted) {
                box.removeChild(box.lastChild);
            }
        }

        function render() {
            box.textContent = '';
            sync();
        }

        if (select) {
            select.addEventListener('change', function () {
                current = select.value;
                render();
            });
        }
        render();
        store();
    }

    /** The "Suggestions" select: its note, the Automatic buttons and the Manual fields follow it. */
    function mode(root) {
        var select = root.querySelector('select');
        var note = root.querySelector('[data-q-mode-note]');
        var automatic = root.querySelector('[data-q-auto]');
        var manual = root.querySelector('[data-q-manual]');
        if (!select) {
            return;
        }
        function sync() {
            if (note) {
                note.textContent = note.getAttribute('data-' + select.value) || '';
            }
            if (automatic) {
                automatic.hidden = select.value === 'manual';
            }
            if (manual) {
                manual.hidden = select.value !== 'manual';
            }
        }
        select.addEventListener('change', sync);
        sync();
    }

    function each(selector, start) {
        Array.prototype.forEach.call(document.querySelectorAll(selector + ':not([data-q-ready])'), function (root) {
            root.setAttribute('data-q-ready', '1');
            start(root);
        });
    }

    function boot() {
        each('[data-q-suggestions]', examples);
        each('[data-q-fields]', fields);
        each('[data-q-mode]', mode);
    }
    window.quisslyConfigBoot = boot;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
