// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * GLPI Command Palette.
 *
 * Ctrl/Cmd+K opens one box that reaches records (through GLPI's global
 * search), every menu destination the user can see, and a few named actions.
 *
 * Two different latencies are at play, and the palette treats them
 * differently: commands and menu entries are a bounded per-session list, so
 * they are fetched once and matched in the browser (instant, from the first
 * keystroke), while records need a server round-trip per query and are
 * therefore debounced, sequenced and abortable.
 */
(function () {
    'use strict';

    var ROOT = (window.CFG_GLPI && window.CFG_GLPI.root_doc) || '';
    var ENDPOINT = ROOT + '/plugins/glpipalette/ajax/palette.php';

    var RECENTS_KEY = 'glpipalette:recents';
    var RECENTS_MAX = 6;
    var DEBOUNCE_MS = 180;
    var LOCAL_LIMIT = 6;   // command/menu rows shown alongside records

    var boot = null;       // bootstrap payload (commands, scopes, config)
    var booting = null;    // in-flight bootstrap promise
    var els = null;        // built lazily on first open
    var rows = [];         // flattened, navigable result rows
    var active = 0;
    var seq = 0;           // guards against out-of-order search responses
    var inflight = null;
    var debounceTimer = null;
    var lastFocus = null;

    // --- Transport --------------------------------------------------------

    function csrf() {
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function post(params, signal) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.set(k, params[k]); });

        return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            signal: signal,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                // Header form keeps GLPI's kernel from consuming the token —
                // a palette fires one request per keystroke.
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrf()
            },
            body: body.toString()
        }).then(function (res) {
            if (!res.ok) { throw new Error('http ' + res.status); }
            return res.json();
        });
    }

    function bootstrap() {
        if (boot) { return Promise.resolve(boot); }
        if (booting) { return booting; }
        booting = post({ action: 'bootstrap' }).then(function (data) {
            boot = data;
            booting = null;
            return boot;
        }).catch(function (e) {
            booting = null;
            throw e;
        });
        return booting;
    }

    // --- Query parsing ----------------------------------------------------
    //
    // ">" restricts to commands, "type:" scopes records to one itemtype, and
    // anything else searches both.

    function parse(raw) {
        var text = raw.trim();

        if (text.charAt(0) === '>') {
            return { mode: 'commands', text: text.slice(1).trim(), scope: null };
        }

        var m = /^([\w-]+):\s*(.*)$/.exec(text);
        if (m && boot && boot.scopes) {
            var keyword = m[1].toLowerCase();
            for (var i = 0; i < boot.scopes.length; i++) {
                var s = boot.scopes[i];
                if (s.keyword === keyword || s.label.toLowerCase() === keyword) {
                    return { mode: 'records', text: m[2].trim(), scope: s.itemtype };
                }
            }
        }

        return { mode: 'all', text: text, scope: null };
    }

    // --- Local matching ---------------------------------------------------

    /**
     * Rank a candidate against the query. Lower is better; null means no
     * match. The tiers matter more than the exact numbers: an exact title
     * should always outrank a subsequence hit buried in a long menu path.
     */
    function score(title, query) {
        if (!query) { return 50; }
        var t = title.toLowerCase();
        var q = query.toLowerCase();

        if (t === q) { return 0; }
        if (t.indexOf(q) === 0) { return 1; }

        var wordStart = t.indexOf(' ' + q);
        if (wordStart !== -1) { return 2; }
        if (t.indexOf(q) !== -1) { return 3; }

        // Subsequence: "fnd mnu" should still reach "Find menu".
        var qi = 0;
        for (var i = 0; i < t.length && qi < q.length; i++) {
            if (t.charAt(i) === q.charAt(qi)) { qi++; }
        }
        return qi === q.length ? 4 : null;
    }

    function matchCommands(query, limit) {
        if (!boot || !boot.commands) { return []; }

        var scored = [];
        for (var i = 0; i < boot.commands.length; i++) {
            var c = boot.commands[i];
            var s = score(c.title, query);
            if (s === null) { continue; }
            scored.push({ cmd: c, s: s, len: c.title.length });
        }

        scored.sort(function (a, b) {
            return a.s - b.s || a.len - b.len || a.cmd.title.localeCompare(b.cmd.title);
        });

        return scored.slice(0, limit).map(function (x) { return x.cmd; });
    }

    // --- Recents ----------------------------------------------------------

    function recents() {
        try {
            return JSON.parse(window.localStorage.getItem(RECENTS_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function remember(row) {
        if (!row || !row.url) { return; }
        try {
            var list = recents().filter(function (r) { return r.url !== row.url; });
            list.unshift({
                title: row.title,
                subtitle: row.subtitle || '',
                url: row.url,
                icon: row.icon || 'ti ti-clock'
            });
            window.localStorage.setItem(RECENTS_KEY, JSON.stringify(list.slice(0, RECENTS_MAX)));
        } catch (e) { /* storage unavailable; recents are a nicety */ }
    }

    // --- DOM --------------------------------------------------------------

    function build() {
        var overlay = document.createElement('div');
        overlay.className = 'glpipalette-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Command palette');

        var panel = document.createElement('div');
        panel.className = 'glpipalette-panel';

        var head = document.createElement('div');
        head.className = 'glpipalette-head';

        var icon = document.createElement('i');
        icon.className = 'ti ti-search glpipalette-headicon';
        head.appendChild(icon);

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'glpipalette-input';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-controls', 'glpipalette-list');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');
        input.placeholder = 'Search tickets, assets, people…  >' + ' for commands';
        head.appendChild(input);

        var spinner = document.createElement('span');
        spinner.className = 'glpipalette-spinner';
        head.appendChild(spinner);

        panel.appendChild(head);

        var list = document.createElement('div');
        list.className = 'glpipalette-list';
        list.id = 'glpipalette-list';
        list.setAttribute('role', 'listbox');
        panel.appendChild(list);

        var foot = document.createElement('div');
        foot.className = 'glpipalette-foot';
        foot.appendChild(hint('↑↓', 'navigate'));
        foot.appendChild(hint('↵', 'open'));
        foot.appendChild(hint('⌘↵', 'new tab'));
        foot.appendChild(hint('esc', 'close'));
        panel.appendChild(foot);

        overlay.appendChild(panel);
        document.body.appendChild(overlay);

        overlay.addEventListener('mousedown', function (ev) {
            if (ev.target === overlay) { close(); }
        });
        input.addEventListener('input', onInput);
        input.addEventListener('keydown', onKeyDown);

        return { overlay: overlay, panel: panel, input: input, list: list, spinner: spinner };
    }

    function hint(key, label) {
        var el = document.createElement('span');
        el.className = 'glpipalette-hint';
        var k = document.createElement('kbd');
        k.textContent = key;
        el.appendChild(k);
        el.appendChild(document.createTextNode(' ' + label));
        return el;
    }

    // --- Rendering --------------------------------------------------------

    function groupHeader(text) {
        var el = document.createElement('div');
        el.className = 'glpipalette-group';
        el.textContent = text;
        return el;
    }

    function rowEl(row, index) {
        var el = document.createElement('div');
        el.className = 'glpipalette-row';
        el.id = 'glpipalette-row-' + index;
        el.setAttribute('role', 'option');
        el.setAttribute('aria-selected', String(index === active));
        if (index === active) { el.classList.add('is-active'); }

        var ic = document.createElement('i');
        ic.className = (row.icon || 'ti ti-file') + ' glpipalette-rowicon';
        el.appendChild(ic);

        var body = document.createElement('div');
        body.className = 'glpipalette-rowbody';

        var title = document.createElement('div');
        title.className = 'glpipalette-title';
        // textContent throughout: titles are user data (ticket subjects,
        // machine names) and must never be parsed as markup.
        title.textContent = row.title;
        body.appendChild(title);

        if (row.subtitle) {
            var sub = document.createElement('div');
            sub.className = 'glpipalette-subtitle';
            sub.textContent = row.subtitle;
            body.appendChild(sub);
        }

        el.appendChild(body);

        if (row.badge) {
            var badge = document.createElement('span');
            badge.className = 'glpipalette-badge';
            badge.textContent = row.badge;
            el.appendChild(badge);
        }

        el.addEventListener('mousemove', function () { setActive(index); });
        el.addEventListener('click', function (ev) { activate(row, ev.metaKey || ev.ctrlKey); });

        return el;
    }

    function render(sections) {
        rows = [];
        els.list.textContent = '';

        sections.forEach(function (section) {
            if (!section.items.length) { return; }
            els.list.appendChild(groupHeader(section.label));
            section.items.forEach(function (row) {
                var index = rows.length;
                rows.push(row);
                els.list.appendChild(rowEl(row, index));
            });
        });

        if (!rows.length) {
            var empty = document.createElement('div');
            empty.className = 'glpipalette-empty';
            empty.textContent = els.input.value.trim()
                ? 'No matches'
                : 'Start typing to search';
            els.list.appendChild(empty);
        }

        if (active >= rows.length) { active = Math.max(0, rows.length - 1); }
        syncActive();
    }

    function setActive(index) {
        if (index === active || index < 0 || index >= rows.length) { return; }
        active = index;
        syncActive();
    }

    function syncActive() {
        var nodes = els.list.querySelectorAll('.glpipalette-row');
        for (var i = 0; i < nodes.length; i++) {
            var on = i === active;
            nodes[i].classList.toggle('is-active', on);
            nodes[i].setAttribute('aria-selected', String(on));
            if (on) {
                els.input.setAttribute('aria-activedescendant', nodes[i].id);
                nodes[i].scrollIntoView({ block: 'nearest' });
            }
        }
    }

    // --- Search flow ------------------------------------------------------

    function onInput() {
        var parsed = parse(els.input.value);

        // Local half repaints immediately, so the palette always feels
        // responsive even while the record request is still out.
        active = 0;
        renderLocal(parsed);

        if (debounceTimer) { window.clearTimeout(debounceTimer); }
        if (inflight) { inflight.abort(); inflight = null; }

        if (parsed.mode === 'commands' || parsed.text === '') {
            els.spinner.classList.remove('is-busy');
            return;
        }

        els.spinner.classList.add('is-busy');
        debounceTimer = window.setTimeout(function () {
            runSearch(parsed);
        }, DEBOUNCE_MS);
    }

    function renderLocal(parsed, serverData) {
        var sections = [];

        if (parsed.text === '' && parsed.mode !== 'commands') {
            var rec = recents();
            if (rec.length) {
                sections.push({ label: 'Recent', items: rec });
            }
            sections.push({ label: 'Commands', items: matchCommands('', LOCAL_LIMIT) });
            render(sections);
            return;
        }

        if (serverData && serverData.jump) {
            sections.push({
                label: 'Jump to',
                items: [{
                    title: serverData.jump.title,
                    subtitle: serverData.jump.itemtype + ' #' + serverData.jump.id,
                    url: serverData.jump.url,
                    icon: 'ti ti-arrow-right',
                    badge: '#' + serverData.jump.id
                }]
            });
        }

        var cmdLimit = parsed.mode === 'commands' ? 50 : LOCAL_LIMIT;
        var cmds = parsed.mode === 'records' ? [] : matchCommands(parsed.text, cmdLimit);
        if (cmds.length) {
            sections.push({ label: 'Commands', items: cmds });
        }

        if (serverData && serverData.groups) {
            serverData.groups.forEach(function (g) {
                sections.push({
                    label: g.type_label,
                    items: g.items.map(function (it) {
                        return {
                            title: it.title,
                            subtitle: it.subtitle,
                            url: it.url,
                            icon: g.icon,
                            badge: '#' + it.id
                        };
                    })
                });
            });
        }

        render(sections);
    }

    function runSearch(parsed) {
        var mine = ++seq;
        var controller = new AbortController();
        inflight = controller;

        post(
            { action: 'search', q: parsed.text, scope: parsed.scope || '' },
            controller.signal
        ).then(function (data) {
            // Responses can land out of order when typing quickly; only the
            // newest one is allowed to paint.
            if (mine !== seq) { return; }
            inflight = null;
            els.spinner.classList.remove('is-busy');
            renderLocal(parsed, data);
        }).catch(function (e) {
            if (e.name === 'AbortError' || mine !== seq) { return; }
            inflight = null;
            els.spinner.classList.remove('is-busy');
            renderLocal(parsed, null);
        });
    }

    // --- Keyboard ---------------------------------------------------------

    function onKeyDown(ev) {
        if (ev.key === 'Escape') {
            ev.preventDefault();
            close();
        } else if (ev.key === 'ArrowDown') {
            ev.preventDefault();
            setActive(active + 1 >= rows.length ? 0 : active + 1);
        } else if (ev.key === 'ArrowUp') {
            ev.preventDefault();
            setActive(active - 1 < 0 ? rows.length - 1 : active - 1);
        } else if (ev.key === 'Home' && rows.length) {
            ev.preventDefault();
            setActive(0);
        } else if (ev.key === 'End' && rows.length) {
            ev.preventDefault();
            setActive(rows.length - 1);
        } else if (ev.key === 'Enter') {
            ev.preventDefault();
            if (rows[active]) { activate(rows[active], ev.metaKey || ev.ctrlKey); }
        }
    }

    function activate(row, newTab) {
        if (!row || !row.url) { return; }
        remember(row);
        var url = row.url.charAt(0) === '/' || /^https?:/.test(row.url)
            ? row.url
            : ROOT + '/' + row.url;

        if (newTab) {
            window.open(url, '_blank', 'noopener');
            return;
        }
        close();
        window.location.href = url;
    }

    // --- Open / close -----------------------------------------------------

    function open() {
        if (!els) { els = build(); }
        if (els.overlay.classList.contains('is-open')) { return; }

        lastFocus = document.activeElement;
        els.overlay.classList.add('is-open');
        document.body.classList.add('glpipalette-locked');
        els.input.value = '';
        active = 0;

        bootstrap()
            .then(function () { renderLocal(parse('')); })
            .catch(function () {
                els.list.textContent = '';
                var err = document.createElement('div');
                err.className = 'glpipalette-empty';
                err.textContent = 'Palette unavailable';
                els.list.appendChild(err);
            });

        renderLocal(parse(''));
        els.input.focus();
    }

    function close() {
        if (!els) { return; }
        els.overlay.classList.remove('is-open');
        document.body.classList.remove('glpipalette-locked');
        if (inflight) { inflight.abort(); inflight = null; }
        if (debounceTimer) { window.clearTimeout(debounceTimer); }
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    function isOpen() {
        return !!els && els.overlay.classList.contains('is-open');
    }

    /** True when the user is mid-typing somewhere and must not be hijacked. */
    function inEditable(target) {
        if (!target) { return false; }
        var tag = (target.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select'
            || target.isContentEditable === true;
    }

    document.addEventListener('keydown', function (ev) {
        if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && (ev.key === 'k' || ev.key === 'K')) {
            // Bindings arrive with the bootstrap, which may not have happened
            // yet — default to on so the very first Ctrl+K of a session works.
            if (boot && boot.bindings && !boot.bindings.ctrl_k) { return; }
            ev.preventDefault();
            isOpen() ? close() : open();
            return;
        }

        if (ev.key === '/' && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
            if (!boot || !boot.bindings || !boot.bindings.slash) { return; }
            if (inEditable(ev.target) || isOpen()) { return; }
            ev.preventDefault();
            open();
        }
    });

    // Warm the bootstrap once the page is idle so the first open is instant.
    if (window.requestIdleCallback) {
        window.requestIdleCallback(function () {
            bootstrap().catch(function () { /* opens will retry */ });
        }, { timeout: 4000 });
    }
})();
