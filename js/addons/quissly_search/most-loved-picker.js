/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Most loved products: the product picker (WooCommerce's product search, as its plugin uses it)
 * over the SKU box of each list - "Shown products (8)" and "Backup products (4)". Click the field
 * to browse the catalog (it scrolls, more products load as it does), or type part of a name or
 * SKU; choose from the dropdown, drag to change the order, Remove to take one out.
 *
 * The SKU box stays the field the form saves (one SKU per line), so the server side - and a
 * page without this script - is unchanged; the picker only hides it and writes it.
 *
 * The same file ships in quissly-for-magento, quissly-for-cs-cart and quissly-for-prestashop; keep the copies identical.
 * Vanilla, no framework.
 *
 * Markup: an element with [data-q-picker] and data-config:
 *   field      CSS selector of the SKU box (textarea) it drives
 *   list       "shown" or "backups" - the other list's picks cannot be picked here
 *   max        how many it holds (8, 4)
 *   searchUrl  GET ?q=...&page=N answers {items: [{sku, name, image, note}], more: bool} - note: why
 *              it cannot be shown, or ''; q under 2 characters = the whole catalog, by name (a plain
 *              array is read as one page with nothing more)
 *   items      the picks the page opened with, in order: [{sku, name, image, note}]
 *   text       the words it writes
 */
(function () {
    'use strict';

    var pickers = {};

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

    function init(root) {
        var config;
        try {
            config = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) {
            return;
        }
        var field = document.querySelector(config.field);
        if (!field) {
            return;
        }
        var text = config.text || {};
        var items = (config.items || []).slice(0, config.max);
        var known = {};
        items.forEach(function (item) {
            known[item.sku] = item;
        });
        var picker = {list: config.list, skus: function () {
            return items.map(function (item) { return item.sku; });
        }, render: render};
        pickers[config.list] = picker;

        // The box stays for the form; the picker stands in for it.
        field.hidden = true;
        field.style.display = 'none';
        var old = field.parentNode.querySelector('.q-most-loved__list');
        if (old) {
            old.hidden = true;
        }

        var list = el('ol', 'q-picker__list');
        var search = el('div', 'q-picker__search');
        var input = el('input', 'q-picker__input');
        input.type = 'search';
        input.autocomplete = 'off';
        input.placeholder = text.placeholder || '';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-autocomplete', 'list');
        var results = el('ul', 'q-picker__results');
        results.setAttribute('role', 'listbox');
        results.id = 'q-picker-results-' + config.list;
        input.setAttribute('aria-controls', results.id);
        results.hidden = true;
        var status = el('p', 'q-picker__status');
        status.setAttribute('aria-live', 'polite');
        search.appendChild(input);
        search.appendChild(results);
        root.appendChild(list);
        root.appendChild(search);
        root.appendChild(status);

        function other() {
            var name = config.list === 'shown' ? 'backups' : 'shown';
            return pickers[name] ? pickers[name].skus() : [];
        }

        function save() {
            field.value = items.map(function (item) { return item.sku; }).join('\n');
            field.dispatchEvent(new Event('change', {bubbles: true}));
            Object.keys(pickers).forEach(function (name) {
                if (name !== config.list) {
                    pickers[name].render();
                }
            });
        }

        function move(from, to) {
            if (to < 0 || to >= items.length || from === to) {
                return;
            }
            items.splice(to, 0, items.splice(from, 1)[0]);
            save();
            render();
            var handle = list.children[to] && list.children[to].querySelector('.q-picker__handle');
            if (handle) {
                handle.focus();
            }
        }

        var dragging = -1;

        function row(item, index) {
            var li = el('li', 'q-picker__item');
            li.draggable = true;
            var handle = el('button', 'q-picker__handle');
            handle.type = 'button';
            handle.setAttribute('aria-label', (text.move || 'Move') + ': ' + item.name);
            handle.title = text.moveHint || '';
            handle.textContent = '⠿';
            handle.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                    event.preventDefault();
                    move(index, index + (event.key === 'ArrowUp' ? -1 : 1));
                }
            });
            li.appendChild(handle);
            li.appendChild(el('span', 'q-picker__position', String(index + 1)));
            var media = el('span', 'q-picker__media');
            if (item.image && /^(https?:)?\/\//i.test(item.image)) {
                var img = el('img');
                img.src = item.image;
                img.alt = '';
                img.loading = 'lazy';
                media.appendChild(img);
            }
            li.appendChild(media);
            var info = el('span', 'q-picker__info');
            info.appendChild(el('span', 'q-picker__name', item.name || item.sku));
            info.appendChild(el('span', 'q-picker__sku', item.sku));
            var note = item.note || (other().indexOf(item.sku) >= 0 ? text.inOther : '');
            if (note) {
                info.appendChild(el('span', 'q-picker__note', note));
            }
            li.appendChild(info);
            var remove = el('button', 'q-picker__remove', text.remove || 'Remove');
            remove.type = 'button';
            remove.setAttribute('aria-label', (text.remove || 'Remove') + ': ' + item.name);
            remove.addEventListener('click', function () {
                items.splice(index, 1);
                save();
                render();
                input.focus();
            });
            li.appendChild(remove);
            li.addEventListener('dragstart', function (event) {
                dragging = index;
                li.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', item.sku);
            });
            li.addEventListener('dragend', function () {
                li.classList.remove('is-dragging');
                dragging = -1;
            });
            li.addEventListener('dragover', function (event) {
                if (dragging >= 0) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                }
            });
            li.addEventListener('drop', function (event) {
                event.preventDefault();
                if (dragging >= 0) {
                    move(dragging, index);
                }
            });
            return li;
        }

        function render() {
            list.textContent = '';
            items.forEach(function (item, index) {
                list.appendChild(row(item, index));
            });
            var full = items.length >= config.max;
            search.hidden = full;
            status.textContent = (full ? (text.full || '') : (text.count || ''))
                .split('%1').join(items.length).split('%2').join(config.max);
        }

        // ---- Search -------------------------------------------------------------------------

        var timer = null;
        var asked = 0;
        var found = [];
        var active = -1;
        var page = 0;
        var more = false;
        var loading = false;
        var loader = null;

        function close() {
            results.hidden = true;
            results.textContent = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
            found = [];
            page = 0;
            more = false;
            loading = false;
            loader = null;
        }

        function choose(item) {
            if (!item || items.length >= config.max || item.blocked) {
                return;
            }
            items.push({sku: item.sku, name: item.name, image: item.image, note: item.note});
            known[item.sku] = item;
            input.value = '';
            close();
            save();
            render();
            if (items.length < config.max) {
                input.focus();
            }
        }

        function highlight(index) {
            active = index;
            Array.prototype.forEach.call(results.children, function (option, at) {
                option.classList.toggle('is-active', at === index);
                option.setAttribute('aria-selected', at === index ? 'true' : 'false');
            });
            if (index >= 0 && results.children[index]) {
                input.setAttribute('aria-activedescendant', results.children[index].id);
                results.children[index].scrollIntoView({block: 'nearest'});
            }
        }

        // A page of products: the first replaces the list, the next ones are added under it.
        function show(answer, append) {
            if (loader) {
                loader.remove();
                loader = null;
            }
            if (!append) {
                results.textContent = '';
                found = [];
            }
            var mine = items.map(function (item) { return item.sku; });
            var theirs = other();
            var start = found.length;
            var batch = answer.map(function (item) {
                var blocked = mine.indexOf(item.sku) >= 0 ? text.picked
                    : (theirs.indexOf(item.sku) >= 0 ? text.inOther : '');
                return {sku: item.sku, name: item.name, image: item.image, note: item.note, blocked: blocked};
            });
            found = found.concat(batch);
            if (!found.length) {
                results.appendChild(el('li', 'q-picker__empty', text.none || ''));
            }
            batch.forEach(function (item, at) {
                var index = start + at;
                var option = el('li', 'q-picker__option' + (item.blocked ? ' is-blocked' : ''));
                option.id = results.id + '-' + index;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-disabled', item.blocked ? 'true' : 'false');
                var media = el('span', 'q-picker__media');
                if (item.image && /^(https?:)?\/\//i.test(item.image)) {
                    var img = el('img');
                    img.src = item.image;
                    img.alt = '';
                    media.appendChild(img);
                }
                option.appendChild(media);
                var info = el('span', 'q-picker__info');
                info.appendChild(el('span', 'q-picker__name', item.name || item.sku));
                info.appendChild(el('span', 'q-picker__sku', item.sku));
                if (item.blocked || item.note) {
                    info.appendChild(el('span', 'q-picker__note', item.blocked || item.note));
                }
                option.appendChild(info);
                option.addEventListener('mousedown', function (event) {
                    event.preventDefault(); // keep the focus in the field
                    choose(item);
                });
                results.appendChild(option);
            });
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            if (!append) {
                highlight(found.length ? 0 : -1);
            }
        }

        // The first page for what is typed (under 2 characters: the whole catalog), or - next -
        // the page after the last one shown, while the server says there is more.
        function lookUp(next) {
            if (next && (!more || loading)) {
                return;
            }
            var query = input.value.trim();
            if (query.length < 2) {
                query = '';
            }
            var mine = ++asked;
            if (next) {
                loader = el('li', 'q-picker__empty', text.searching || '');
                results.appendChild(loader);
            } else {
                page = 0;
                more = false;
                results.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                results.textContent = '';
                results.appendChild(el('li', 'q-picker__empty', text.searching || ''));
            }
            loading = true;
            var url = config.searchUrl + (config.searchUrl.indexOf('?') >= 0 ? '&' : '?')
                + 'q=' + encodeURIComponent(query) + '&page=' + (page + 1);
            fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}})
                .then(function (response) { return response.ok ? response.json() : []; })
                .then(function (answer) {
                    if (mine !== asked) {
                        return;
                    }
                    loading = false;
                    page += 1;
                    more = !Array.isArray(answer) && !!(answer && answer.more);
                    show(Array.isArray(answer) ? answer : (answer && Array.isArray(answer.items) ? answer.items : []), next);
                }, function () {
                    if (mine !== asked) {
                        return;
                    }
                    loading = false;
                    if (loader) {
                        loader.remove();
                        loader = null;
                    }
                    if (!next) {
                        results.textContent = '';
                        results.appendChild(el('li', 'q-picker__empty', text.error || ''));
                    }
                });
        }

        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { lookUp(false); }, 250);
        });
        // A click on the field opens the catalog, as the Shopify app's product picker does.
        function browse() {
            if (results.hidden && items.length < config.max) {
                lookUp(false);
            }
        }
        input.addEventListener('focus', browse);
        input.addEventListener('click', browse);
        results.addEventListener('scroll', function () {
            if (results.scrollTop + results.clientHeight >= results.scrollHeight - 60) {
                lookUp(true);
            }
        });
        // Dragging the list's scrollbar must not take the focus from the field (which closes it).
        results.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                // Enter picks; it must not submit the whole settings form.
                event.preventDefault();
                if (active >= 0) {
                    choose(found[active]);
                }
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (!found.length) {
                    return;
                }
                event.preventDefault();
                var step = event.key === 'ArrowDown' ? 1 : -1;
                highlight((active + step + found.length) % found.length);
            } else if (event.key === 'Escape') {
                close();
            }
        });
        input.addEventListener('blur', function () {
            window.setTimeout(close, 150);
        });

        render();
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-q-picker]:not([data-q-ready])'), function (root) {
            root.setAttribute('data-q-ready', '1');
            init(root);
        });
        // A list re-renders once the other one is there, so a product in both is flagged at once.
        Object.keys(pickers).forEach(function (name) {
            pickers[name].render();
        });
    }
    window.quisslyPickerBoot = boot;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
