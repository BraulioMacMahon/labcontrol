/**
 * LabControl — Bancada de Testes (interface)
 * ==========================================
 *
 * Vanilla JS sem build e sem CDN: a página serve para diagnosticar uma rede
 * que pode estar sem internet, portanto nada de Tailwind/Babel/ESM externos.
 *
 * Fluxo de dados:
 *   1. index.php mete o estado inicial num <script type="application/json"
 *      id="lc-bootstrap"> (primeiro paint sem espera). Se a Island não existir
 *      — por exemplo executando num servidor estático de pré-visualização — cai
 *     -se para `GET api.php?action=bootstrap`.
 *   2. Toda a mutação passa por api.php (handshake, grant, logout) e é protegida
 *      por CSRF; a senha vai diretamente para auth.php da aplicação.
 *   3. Executar um script é `GET index.php?run=1&view=…`, buscado por fetch para
 *      que a resposta possa ser normalizada e lida ao lado da ficha do script.
 *
 * Segurança no painel de leitura: a saída dos utilitários é tratada como
 * conteúdo não confiável — <script>, <style>, iframes, <object> e atributos
 * atributos de evento (on…) são removidos antes de entrar no DOM. O modo «Formato original» usa
 * srcdoc com o mesmo sanitizador, exceto para páginas marcadas runner:"frame"
 * no catálogo (frontend/debug-login.html), que precisam de JS vivo e por isso
 * são apontadas para o URL do servidor — o que só é permitido a scripts de só
 * leitura, já que o bilhete de execução é de uso único.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // utilitários
    // ------------------------------------------------------------------
    var doc = document;

    function $(sel, root) { return (root || doc).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function el(tag, attrs, html) {
        var node = doc.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (key) {
                if (key === 'class') { node.className = attrs[key]; }
                else if (attrs[key] != null) { node.setAttribute(key, attrs[key]); }
            });
        }
        if (html != null) { node.innerHTML = html; }
        return node;
    }

    function icon(name, size) {
        var paths = {
            pulse: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 8.3-8.3"/><path d="m17 5 2 2"/><path d="m14 8 2 2"/>',
            database: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
            shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            network: '<rect x="9" y="2" width="6" height="6" rx="1.5"/><rect x="2" y="16" width="6" height="6" rx="1.5"/><rect x="16" y="16" width="6" height="6" rx="1.5"/><path d="M12 8v4M5 16v-2h14v2"/>',
            terminal: '<path d="m4 17 6-6-6-6"/><path d="M12 19h8"/>',
            browser: '<rect x="2" y="4" width="20" height="16" rx="2.5"/><path d="M2 9h20"/><path d="M6 6.5h.01M9 6.5h.01"/>',
            wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/>',
            play: '<path d="m7 4 12 8-12 8z"/>',
            copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            open: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            book: '<path d="M4 19.5V5.5A2.5 2.5 0 0 1 6.5 3H20v18H6.5A2.5 2.5 0 0 1 4 18.5z"/>',
            alert: '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="m10.3 3.3-8 14A2 2 0 0 0 4 20.5h16a2 2 0 0 0 1.7-3.2l-8-14a2 2 0 0 0-3.4 0z"/>',
            bolt: '<path d="M13 2 4 14h6l-1 8 9-12h-6z"/>',
            clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            check: '<path d="m5 13 4 4L19 7"/>',
        };
        return '<svg viewBox="0 0 24 24" width="' + (size || 15) + '" height="' + (size || 15) +
            '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            (paths[name] || paths.pulse) + '</svg>';
    }

    function humanBytes(bytes) {
        if (bytes == null) { return '—'; }
        if (bytes < 1024) { return bytes + ' B'; }
        return (bytes / 1024).toFixed(1) + ' KB';
    }

    function humanAge(ts) {
        if (!ts) { return 'desconhecida'; }
        var diff = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (diff < 90) { return 'agora mesmo'; }
        if (diff < 5400) { return Math.round(diff / 60) + ' min'; }
        if (diff < 172800) { return Math.round(diff / 3600) + ' h'; }
        return Math.round(diff / 86400) + ' dias';
    }

    function clock(ms) {
        var total = Math.max(0, Math.round(ms / 1000));
        var h = Math.floor(total / 3600), m = Math.floor((total % 3600) / 60), s = total % 60;
        var pad = function (value) { return (value < 10 ? '0' : '') + value; };
        return h > 0 ? h + 'h ' + pad(m) + 'm' : pad(m) + ':' + pad(s);
    }

    function toast(message, tone) {
        var wrap = $('#lc-toasts');
        if (!wrap) { return; }
        var node = el('div', { class: 'lc-toast', 'data-tone': tone || '' }, esc(message));
        wrap.appendChild(node);
        setTimeout(function () {
            node.style.opacity = '0';
            node.style.transition = 'opacity .35s ease';
            setTimeout(function () { if (node.parentNode) { node.parentNode.removeChild(node); } }, 400);
        }, tone === 'error' ? 6500 : 3800);
    }

    // ------------------------------------------------------------------
    // estado
    // ------------------------------------------------------------------
    var state = {
        data: null,
        scripts: {},
        groups: [],
        missing: [],
        modes: {},
        checks: null,
        filter: { q: '', group: 'all', mode: 'all' },
        view: [],
        cursor: -1,
        run: null,
        runTab: 'read',
        confirm: null,
        diag: { hosts: [], results: [], running: false, wolRunning: false, hostId: '' },
        autorefresh: null
    };

    function csrf() { return (state.data && state.data.csrf) || ''; }
    function cfg(key, fallback) {
        var c = (state.data && state.data.config) || {};
        return c[key] != null ? c[key] : (fallback != null ? fallback : '');
    }

    // ------------------------------------------------------------------
    // rede
    // ------------------------------------------------------------------
    function api(action, options) {
        options = options || {};
        var headers = {
            'Accept': 'application/json',
            'X-LC-CSRF': csrf()
        };
        var init = { method: options.method || 'GET', headers: headers, credentials: 'same-origin' };
        if (options.body) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        return fetch('api.php?action=' + encodeURIComponent(action), init).then(function (res) {
            return res.text().then(function (text) {
                var json = null;
                try { json = JSON.parse(text); } catch (e) { json = null; }
                if (!json) {
                    var err = new Error('HTTP ' + res.status + ' — resposta da bancada não é JSON');
                    err.status = res.status;
                    throw err;
                }
                if (!res.ok || json.success === false) {
                    var failure = new Error(json.message || ('HTTP ' + res.status));
                    failure.status = res.status || json.status;
                    failure.payload = json;
                    throw failure;
                }
                return json;
            });
        });
    }

    /** Login na API da aplicação — a bancada nunca vê a senha a não ser de passagem. */
    // ------------------------------------------------------------------
    // arranque
    // ------------------------------------------------------------------
    function readBootstrap() {
        var island = $('#lc-bootstrap');
        if (island) {
            try { return JSON.parse(island.textContent || '{}'); } catch (e) { /* segue para fetch */ }
        }
        return null;
    }

    function boot() {
        var data = readBootstrap();
        var chain = data ? Promise.resolve(data) : api('bootstrap').catch(function (e) {
            return { authenticated: true, reason: null };
        });

        chain.then(function (payload) {
            adopt(payload);
            render();
        }).catch(function (error) {
            toast(error && error.message ? error.message : 'Falha ao ler o estado da bancada.', 'error');
            render();
        });
    }

    function adopt(payload) {
        state.data = Object.assign({}, state.data || {}, payload);
        if (payload.catalog) { hydrateCatalog(payload.catalog); }
        if (payload.checks) { state.checks = payload.checks; }
        if (payload.modes) { state.modes = payload.modes; }
    }

    function hydrateCatalog(catalog) {
        state.groups = catalog.groups || [];
        state.scripts = {};
        state.groups.forEach(function (group) {
            (group.items || []).forEach(function (item) { state.scripts[item.rel] = item; });
        });
        Object.keys(catalog.scripts || {}).forEach(function (rel) {
            if (!state.scripts[rel]) { state.scripts[rel] = catalog.scripts[rel]; }
        });
        state.missing = catalog.missing || [];
    }

    // ------------------------------------------------------------------
    // render: cabedal do topo
    // ------------------------------------------------------------------
    function renderEnv() {
        var runtime = (state.data && state.data.runtime) || {};
        var checks = state.checks && state.checks.checks ? state.checks.checks : [];
        function find(id) {
            for (var i = 0; i < checks.length; i++) { if (checks[i].id === id) { return checks[i]; } }
            return null;
        }
        var db = find('database');
        var badges = [
            { label: 'PHP', value: runtime.php || '?' },
            { label: 'SO', value: (runtime.os || '?').replace(/ +/g, ' ') },
            { label: 'banco', value: db ? (db.state === 'ok' ? db.value.split(' · ')[0] : 'fora do ar') : '?', tone: db && db.state !== 'ok' ? 'fail' : null },
            { label: 'fuso', value: (runtime.timezone || '').split('/').pop() || '—' },
            { label: 'caminho', value: runtime.path || '/labcontrol/tests' }
        ];
        if (cfg('ip_restricted')) {
            badges.push({ label: 'rede', value: 'TESTS_ALLOWED_IPS', tone: null });
        }
        if (cfg('debug_mode')) {
            badges.push({ label: 'DEBUG_MODE', value: 'ligado', tone: 'warn' });
        }
        $('#lc-env').innerHTML = badges.map(function (badge) {
            return '<span class="lc-badge"' + (badge.tone ? ' data-tone="' + esc(badge.tone) + '"' : '') + '>' +
                esc(badge.label) + ' <b>' + esc(badge.value) + '</b></span>';
        }).join('');
    }

    function stopTimers() {
        if (state.autorefresh) { clearInterval(state.autorefresh); state.autorefresh = null; }
    }

    // ------------------------------------------------------------------
    // render: faixa de estado
    // ------------------------------------------------------------------
    function renderHero() {
        var checks = (state.checks && state.checks.checks) || [];
        var summary = (state.checks && state.checks.summary) || { ok: 0, warn: 0, fail: 0, info: 0 };
        var overall = (state.checks && state.checks.overall) || 'info';
        var counts = (state.data && state.data.catalog && state.data.catalog.counts) || {};
        var label = overall === 'ok' ? 'Ambiente sem problemas à vista'
            : overall === 'warn' ? 'Atenção: ' + summary.warn + ' verificação(ões) em aviso'
                : overall === 'fail' ? summary.fail + ' verificação(ões) com falha'
                    : 'Ainda sem sondagem';

        var hero = $('#lc-hero');
        hero.setAttribute('data-overall', overall);
        $('#lc-hero-state').innerHTML = '<span class="lc-dot" data-state="' + esc(overall) + '"></span>' +
            '<span>' + esc(label) +
            '<small>' + esc(checks.length) + ' verificações · ' + esc(state.groups.length) +
            ' grupos · ' + esc(counts.total || 0) + ' scripts' +
            (state.checks && state.checks.at ? ' · sondado há ' + esc(humanAge(state.checks.at)) : '') +
            '</small></span>';

        var cards = [
            { k: 'ok', v: summary.ok, l: 'ok' },
            { k: 'warn', v: summary.warn, l: 'avisos' },
            { k: 'fail', v: summary.fail, l: 'falhas' },
            { k: 'read', v: counts.read || 0, l: 'só leitura' },
            { k: 'destructive', v: counts.destructive || 0, l: 'destrutivos' }
        ];
        $('#lc-hero-counts').innerHTML = cards.map(function (c) {
            return '<span class="lc-hero-count"><b>' + esc(c.v) + '</b><span>' + esc(c.l) + '</span></span>';
        }).join('');

        var hintBits = [];
        var failFixes = checks.filter(function (c) { return c.state === 'fail' && c.fix; });
        if (failFixes.length) {
            hintBits.push('Remédios sugeridos: ' + failFixes.map(function (c) {
                return '<a href="#lc-check-' + esc(c.id) + '">' + esc(c.label) + '</a>';
            }).join(', '));
        }
        if (state.missing.length) {
            hintBits.push('Catálogo aponta para arquivos que já não existem: <span class="lc-mono">' + esc(state.missing.join(', ')) + '</span>');
        }
        if (counts.undocumented) {
            hintBits.push(counts.undocumented + ' script(s) sem ficha em catalog.json');
        }
        if (!hintBits.length) {
            hintBits.push('Clique em um cartão para executar e ler a saída aqui ao lado. <span class="lc-mono">/</span> para procurar.');
        }
        $('#lc-hero-hint').innerHTML = hintBits.join(' · ');
    }

    // ------------------------------------------------------------------
    // render: verificações
    // ------------------------------------------------------------------
    var checkTone = { ok: 'ok', warn: 'warn', fail: 'fail', info: 'info' };

    function renderChecks() {
        var wrap = $('#lc-checks');
        var checks = (state.checks && state.checks.checks) || [];
        if (!checks.length) {
            wrap.innerHTML = '<p class="lc-empty">A sondagem não retornou verificações.</p>';
            return;
        }
        wrap.innerHTML = checks.map(function (check) {
            var fix = check.fix && state.scripts[check.fix] ? state.scripts[check.fix] : null;
            return '<article class="lc-check" id="lc-check-' + esc(check.id) + '" data-state="' + esc(checkTone[check.state] || 'info') + '">' +
                '<div class="lc-check-top">' +
                '<p class="lc-check-label"><span class="lc-dot" data-state="' + esc(check.state) + '"></span>' + esc(check.label) + '</p>' +
                (fix ? '<span class="lc-check-fix"><button type="button" class="lc-chip" data-act="open" data-file="' + esc(fix.rel) + '">' +
                    icon('wrench', 12) + esc(fix.title.length > 22 ? fix.file : fix.file) + '</button></span>' : '') +
                '</div>' +
                '<p class="lc-check-value">' + esc(check.value || '—') + '</p>' +
                (check.detail ? '<p class="lc-check-detail">' + esc(check.detail) + '</p>' : '') +
                '</article>';
        }).join('');

        var updated = $('#lc-checks-updated');
        if (state.checks && state.checks.at) {
            var when = new Date(state.checks.at * 1000);
            updated.textContent = 'há ' + humanAge(state.checks.at) + ' às ' +
                when.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' }) +
                (state.checks.cached ? ' (cache)' : '');
        } else {
            updated.textContent = '';
        }
    }

    // ------------------------------------------------------------------
    // render: como o acesso funciona
    // ------------------------------------------------------------------
    function renderRules() {
        var rows = [
            ['A bancada é uma porta separada de <code>/labcontrol/tests</code>. A aplicação não tem menu para executar scripts, nem este local tem sessão compartilhada com ela.', 'shield'],
            ['Sem login: a bancada abre direto. A sessão que usa (cookie <code>labcontrol_tests</code>) é própria desta área e é separada da aplicação.', 'key'],
            ['Os scripts não têm URL público. <code>tests/.htaccess</code> nega <code>root/</code>, <code>backend/</code> e <code>frontend/</code>, por isso tudo passa pelo portão.', 'bolt'],
            ['Escrever precisa de um clique de confirmação; destruir pede <b>EXECUTAR</b> por escrito e um bilhete de ' + cfg('grant_seconds', 60) + ' s, de uso único.', 'alert'],
            ['Em produção basta criar <code>tests/DISABLED</code> (ou <code>TESTS_ENABLED=false</code>) para a área responder 503 a tudo.', 'wrench']
        ];
        if (cfg('ip_restricted')) {
            rows.splice(2, 0, ['Está ativa a restrição por rede (<code>TESTS_ALLOWED_IPS</code>): pedidos de fora da lista são recusados antes de olhar para a sessão.', 'network']);
        }
        $('#lc-rules').innerHTML = rows.map(function (row) {
            return '<li><span class="lc-group-icon" aria-hidden="true">' + icon(row[1], 15) + '</span><span>' + row[0] + '</span></li>';
        }).join('');
    }

    // ------------------------------------------------------------------
    // render: catálogo
    // ------------------------------------------------------------------
    function renderFilters() {
        var counts = { all: 0 };
        Object.keys(state.scripts).forEach(function (rel) {
            var meta = state.scripts[rel];
            counts.all++;
            counts[meta.mode] = (counts[meta.mode] || 0) + 1;
        });

        var modes = [['all', 'Tudo']].concat(Object.keys(state.modes).map(function (mode) {
            return [mode, state.modes[mode].label];
        })).concat([['undocumented', 'Sem ficha']]);

        $('#lc-filter-modes').innerHTML = modes.filter(function (entry) {
            return entry[0] === 'all' || counts[entry[0]] > 0 || entry[0] === 'undocumented';
        }).map(function (entry) {
            var id = entry[0], label = entry[1];
            var count = id === 'all' ? counts.all : (counts[id] || 0);
            return '<button type="button" class="lc-chip" data-act="filter-mode" data-mode="' + esc(id) + '"' +
                ' aria-pressed="' + (state.filter.mode === id ? 'true' : 'false') + '">' +
                esc(label) + ' <i>' + esc(count) + '</i></button>';
        }).join('');

        var groups = [{ id: 'all', label: 'Todas as categorias' }].concat(state.groups.map(function (g) {
            return { id: g.id, label: g.label, count: (g.items || []).length };
        }));
        $('#lc-filter-groups').innerHTML = groups.map(function (group) {
            return '<button type="button" class="lc-chip" data-act="filter-group" data-group="' + esc(group.id) + '"' +
                ' aria-pressed="' + (state.filter.group === group.id ? 'true' : 'false') + '">' +
                esc(group.label) + (group.count != null ? ' <i>' + esc(group.count) + '</i>' : '') + '</button>';
        }).join('');
    }

    function matches(meta, tokens) {
        if (state.filter.group !== 'all' && meta.group !== state.filter.group) { return false; }
        if (state.filter.mode !== 'all') {
            if (state.filter.mode === 'undocumented') { if (meta.documented) { return false; } }
            else if (meta.mode !== state.filter.mode) { return false; }
        }
        if (!tokens.length) { return true; }
        var hay = (meta.title + ' ' + meta.file + ' ' + meta.rel + ' ' + meta.summary + ' ' +
            (meta.expect || '') + ' ' + (meta.side_effects || '') + ' ' + (meta.warn || '') + ' ' + meta.mode).toLowerCase();
        return tokens.every(function (token) { return hay.indexOf(token) > -1; });
    }

    function highlight(text, tokens) {
        var out = esc(text);
        tokens.forEach(function (token) {
            if (!token || token.length < 2) { return; }
            var pattern = token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            out = out.replace(new RegExp('(' + pattern + ')', 'ig'), '<mark>$1</mark>');
        });
        return out;
    }

    function renderCatalog() {
        var tokens = state.filter.q.toLowerCase().split(/\s+/).filter(Boolean);
        var body = $('#lc-groups');
        var visible = 0;
        var html = '';

        state.groups.forEach(function (group) {
            var items = (group.items || []).filter(function (meta) { return matches(meta, tokens); });
            if (!items.length) { return; }
            visible += items.length;
            html += '<section class="lc-group" data-tone="' + esc(group.tone || 'blue') + '">' +
                '<header class="lc-group-head">' +
                '<span class="lc-group-icon">' + icon(group.icon || 'pulse', 17) + '</span>' +
                '<div class="lc-group-titles"><h3 class="lc-group-title">' + esc(group.label) + '</h3>' +
                (group.hint ? '<p class="lc-group-hint">' + esc(group.hint) + '</p>' : '') + '</div>' +
                '<span class="lc-group-count">' + esc(items.length) + ' / ' + esc((group.items || []).length) + '</span>' +
                '</header><div class="lc-cards">' +
                items.map(function (meta) { return card(meta, tokens); }).join('') +
                '</div></section>';
        });

        body.innerHTML = html;

        var empty = $('#lc-empty');
        if (visible === 0) {
            empty.hidden = false;
            empty.innerHTML = '<b>Nada corresponde</b> a «' + esc(state.filter.q) + '» com estes filtros. ' +
                '<button type="button" class="lc-chip" data-act="filter-reset">limpar filtros</button>';
        } else {
            empty.hidden = true;
        }

        state.view = $all('.lc-card', body).map(function (node) { return node.getAttribute('data-rel'); });
        var updated = $('#lc-catalog-updated');
        var catalogMeta = state.data && state.data.catalog && state.data.catalog.catalog;
        updated.textContent = catalogMeta && catalogMeta.updated
            ? 'fichas atualizadas em ' + catalogMeta.updated + ' · v' + catalogMeta.version
            : '';
    }

    function card(meta, tokens) {
        var mode = state.modes[meta.mode] || { label: meta.mode, tone: 'gray', note: '' };
        var actions = [];
        if (meta.blocked) {
            actions.push('<button type="button" class="lc-btn lc-btn-tiny" data-act="open" data-file="' + esc(meta.rel) + '">Porquê?</button>');
        } else {
            actions.push('<button type="button" class="lc-btn lc-btn-tiny ' +
                (meta.mode === 'destructive' ? 'lc-btn-danger' : 'lc-btn-primary') +
                '" data-act="open" data-file="' + esc(meta.rel) + '">' + icon('play', 12) + 'Executar</button>');
        }
        actions.push('<button type="button" class="lc-btn lc-btn-tiny lc-btn-quiet" data-act="copy-file" data-file="' + esc(meta.rel) + '" title="Copiar caminho relativo">' + icon('copy', 12) + '</button>');

        var notes = [];
        if (meta.warn) { notes.push('⚠ ' + meta.warn); }
        if (meta.deprecated) { notes.push('Descontinuado → ' + meta.deprecated); }
        if (meta.blocked) { notes.push('Bloqueado na web: ' + meta.blocked); }

        var fixes = (meta.fixes || []).filter(function (rel) { return !!state.scripts[rel]; });

        return '<article class="lc-card" data-rel="' + esc(meta.rel) + '" data-mode="' + esc(meta.mode) + '"' +
            (meta.blocked ? ' data-blocked="1"' : '') + ' tabindex="0">' +
            '<div class="lc-card-top">' +
            '<h4 class="lc-card-title">' + highlight(meta.title, tokens) + '</h4>' +
            '<div class="lc-card-tags">' +
            '<span class="lc-tag" data-tone="' + esc(mode.tone) + '" title="' + esc(mode.note) + '">' + esc(mode.label) + '</span>' +
            (meta.documented ? '' : '<span class="lc-tag" data-tone="blue" title="Sem ficha em catalog.json — descrição lida do comentário do arquivo">sem ficha</span>') +
            '</div></div>' +
            (meta.summary ? '<p class="lc-card-summary">' + esc(meta.summary) + '</p>' : '') +
            (notes.length ? '<p class="lc-card-note">' + esc(notes.join('  ·  ')) + '</p>' : '') +
            (fixes.length ? '<div class="lc-card-fixes"><span>depois disto:</span>' +
                fixes.map(function (rel) {
                    return '<button type="button" data-act="open" data-file="' + esc(rel) + '">' + esc(state.scripts[rel].file) + '</button>';
                }).join('') + '</div>' : '') +
            '<div class="lc-card-foot">' +
            '<span class="lc-card-path">' + esc(meta.rel) + '</span>' +
            '<span class="lc-card-path" title="Última modificação">' + esc(humanAge(meta.modified)) + ' · ' + esc(humanBytes(meta.bytes)) + '</span>' +
            '<span class="lc-card-actions">' + actions.join('') + '</span>' +
            '</div></article>';
    }

    function moveCursor(step) {
        if (!state.view.length) { return; }
        var next = state.cursor + step;
        if (next < 0) { next = state.view.length - 1; }
        if (next >= state.view.length) { next = 0; }
        $all('.lc-card').forEach(function (node) {
            node.setAttribute('data-cursor', node.getAttribute('data-rel') === state.view[next] ? '1' : '0');
        });
        state.cursor = next;
        var chosen = $('.lc-card[data-rel="' + cssEscape(state.view[next]) + '"]');
        if (chosen && chosen.scrollIntoView) { chosen.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    }

    function cssEscape(value) {
        return window.CSS && CSS.escape ? CSS.escape(value) : String(value).replace(/["\\]/g, '\\$&');
    }

    // ------------------------------------------------------------------
    // diagnóstico de conectividade (herdado do separador dentro da app)
    // ------------------------------------------------------------------
    function renderConnectivity() {
        var list = $('#lc-diag-results');
        var results = state.diag.results.length ? state.diag.results : [
            { id: 'api', label: 'API e banco de dados', status: 'pending', detail: 'A lista de hosts passa pelo Apache, o PHP e o MySQL de uma vez.' },
            { id: 'winrm', label: 'WinRM e consulta de processos', status: 'pending', detail: 'Read-only no host escolhido; nada é enviado à máquina.' },
            { id: 'wol', label: 'Wake-on-LAN', status: 'pending', detail: 'Manual: envia um magic packet e pode ligar o computador.' }
        ];
        var statusText = { pending: 'à espera', running: 'executando', passed: 'ok', failed: 'falhou', skipped: 'ignorado' };
        list.innerHTML = results.map(function (item) {
            var dot = item.status === 'running' ? '<span class="lc-spinner" aria-hidden="true"></span>'
                : '<span class="lc-dot" data-state="' + (item.status === 'passed' ? 'ok' : item.status === 'failed' ? 'fail' : item.status === 'skipped' ? 'warn' : 'info') + '"></span>';
            return '<li class="lc-diag-item" data-status="' + esc(item.status) + '">' + dot +
                '<div><p class="lc-diag-name">' + esc(item.label) + '</p>' +
                '<p class="lc-diag-detail">' + esc(item.detail || '') + '</p></div>' +
                '<span class="lc-diag-meta">' + esc(item.duration != null ? Math.round(item.duration) + ' ms' : statusText[item.status] || item.status) + '</span>' +
                '</li>';
        }).join('');

        var select = $('#lc-diag-host');
        var options = state.diag.hosts.length
            ? state.diag.hosts.map(function (host) {
                return '<option value="' + esc(host.id) + '"' + (String(host.id) === String(state.diag.hostId) ? ' selected' : '') + '>' +
                    esc(host.hostname || host.name || host.ip) + ' — ' + esc(host.ip) + (host.status === 'online' ? ' · online' : '') + '</option>';
            }).join('')
            : '<option value="">Sem hosts carregados — executa o diagnóstico para os listar</option>';
        select.innerHTML = options;
        $('#lc-diag-run').disabled = state.diag.running || state.diag.wolRunning;
        $('#lc-diag-wol').disabled = state.diag.running || state.diag.wolRunning || !state.diag.hosts.length;
    }

    function patchDiag(id, patch) {
        var list = state.diag.results.slice();
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === id) {
                list[i] = Object.assign({}, list[i], patch);
                state.diag.results = list;
                renderConnectivity();
                return;
            }
        }
        list.push(Object.assign({ id: id, label: id, status: 'pending', detail: '' }, patch));
        state.diag.results = list;
        renderConnectivity();
    }

    function apiCall(endpoint, options) {
        options = options || {};
        var controller = typeof window.AbortController === 'function' ? new AbortController() : null;
        var timeout = setTimeout(function () { if (controller) { controller.abort(); } }, options.timeout || 75000);
        var init = {
            method: options.method || 'GET',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        };
        if (options.body) { init.body = JSON.stringify(options.body); }
        return fetch(cfg('api_base') + '/' + endpoint, init).then(function (res) {
            return res.text().then(function (text) {
                clearTimeout(timeout);
                var json = null;
                try { json = JSON.parse(text); } catch (e) { json = null; }
                if (!json) { throw new Error('HTTP ' + res.status + ' — a API não respondeu em JSON'); }
                if (!json.success) {
                    var detail = json.data && (json.data.error || json.data.raw_output);
                    throw new Error((json.message || 'Falha na API') + (detail ? ' — ' + String(detail).slice(0, 200) : ''));
                }
                return json;
            });
        }, function (error) {
            clearTimeout(timeout);
            throw new Error(error.name === 'AbortError' ? 'A chamada demorou demasiado tempo.' : error.message);
        });
    }

    function runDiagnostics() {
        if (state.diag.running) { return; }
        state.diag.running = true;
        state.diag.results = [
            { id: 'api', label: 'API e banco de dados', status: 'running', detail: 'Solicitando a lista de hosts…' },
            { id: 'winrm', label: 'WinRM e consulta de processos', status: 'pending', detail: 'Aguardando a API.' },
            { id: 'wol', label: 'Wake-on-LAN', status: 'pending', detail: 'Manual: envia um magic packet e pode ligar o computador.' }
        ];
        renderConnectivity();

        var started = performance.now();
        var hosts = [];
        apiCall('hosts.php?action=list')
            .then(function (json) {
                hosts = (json.data && (json.data.hosts || json.data)) || [];
                if (!Array.isArray(hosts)) { throw new Error('A API retornou uma lista de hosts inválida.'); }
                state.diag.hosts = hosts;
                patchDiag('api', {
                    status: 'passed', duration: performance.now() - started,
                    detail: 'Ligação completa em ' + Math.round(performance.now() - started) + ' ms; ' + hosts.length + ' host(s).'
                });
                renderConnectivity();
                if (!hosts.length) {
                    patchDiag('winrm', { status: 'skipped', detail: 'Sem hosts registrados: adicione-os na aplicação e volte aqui.' });
                    return null;
                }
                var chosen = state.diag.hosts.filter(function (host) {
                    return String(host.id) === String(state.diag.hostId);
                })[0] || hosts[0];
                state.diag.hostId = String(chosen.id);
                patchDiag('winrm', {
                    status: 'running',
                    detail: 'Consultando ' + (chosen.hostname || chosen.name) + ' (' + chosen.ip + ')… pode demorar ~30 s com WinRM frio.'
                });
                var winrmStart = performance.now();
                return apiCall('control.php?action=processes&hostname=' + encodeURIComponent(chosen.hostname || chosen.name))
                    .then(function (winrm) {
                        var data = winrm.data || {};
                        var processes = data.processes || [];
                        patchDiag('winrm', {
                            status: 'passed', duration: performance.now() - winrmStart,
                            detail: 'WinRM respondeu; ' + (Array.isArray(processes) ? processes.length : '?') + ' processo(s) na lista.'
                        });
                    }, function (error) {
                        patchDiag('winrm', {
                            status: 'failed', duration: performance.now() - winrmStart,
                            detail: error.message + ' — executa root/test-control-path.php para ver caminhos e scripts .ps1.'
                        });
                    });
            }, function (error) {
                patchDiag('api', {
                    status: 'failed', duration: performance.now() - started,
                    detail: error.message + ' — começa pelo cartão «Banco de dados» e por backend/test-connection.php.'
                });
                patchDiag('winrm', { status: 'skipped', detail: 'Sem hosts não há WinRM para testar.' });
            })
            .then(function () { state.diag.running = false; renderConnectivity(); });
    }

    function testWol() {
        if (!state.diag.hosts.length) { toast('Carrega primeiro a lista de hosts.', 'error'); return; }
        var hostId = $('#lc-diag-host').value;
        var host = state.diag.hosts.filter(function (item) { return String(item.id) === String(hostId); })[0] || state.diag.hosts[0];
        if (!host.mac_address && !host.mac) { toast('O host ' + (host.hostname || host.ip) + ' não tem MAC registado.', 'error'); return; }
        if (!window.confirm('Enviar magic packet WOL para ' + (host.hostname || '') + ' (' + host.ip + ')? O computador pode ligar-se.')) { return; }

        state.diag.wolRunning = true;
        patchDiag('wol', { status: 'running', detail: 'Enviando para ' + host.ip + '…' });
        var started = performance.now();
        apiCall('control.php?action=wol', {
            method: 'POST',
            body: { ip: host.ip, mac_address: host.mac_address || host.mac }
        }).then(function () {
            patchDiag('wol', {
                status: 'passed', duration: performance.now() - started,
                detail: 'Pacote enviado. O envio não confirma arranque — confirma com root/test-ping-server.php.'
            });
        }, function (error) {
            patchDiag('wol', { status: 'failed', duration: performance.now() - started, detail: error.message });
        }).then(function () { state.diag.wolRunning = false; renderConnectivity(); });
    }

    // ------------------------------------------------------------------
    // painel de execução
    // ------------------------------------------------------------------
    function openRunner(rel) {
        var meta = state.scripts[rel];
        if (!meta) { toast('Não conheço esse script.', 'error'); return; }
        $all('.lc-card').forEach(function (node) {
            node.setAttribute('data-selected', node.getAttribute('data-rel') === rel ? '1' : '0');
        });
        var runner = $('#lc-runner');
        runner.hidden = false;
        doc.body.classList.add('lc-runner-open');
        $('#lc-run-title').textContent = meta.title;
        $('#lc-run-path').textContent = 'tests/' + meta.rel;
        $('#lc-run-kicker').textContent = meta.blocked ? 'Indisponível na web' : 'Preparando…';
        state.run = { meta: meta, phase: 'idle', text: '', raw: '', status: 0, ms: 0, contentType: '' };
        renderRunFacts();

        if (meta.blocked) {
            state.run.phase = 'blocked';
            renderRunBody();
            return;
        }
        // Degrau certo antes de tocar no servidor: nada com efeito de escrita
        // executa por um simples clique no cartão.
        if (confirmTier(meta) === 'none') {
            requestRun(rel);
        } else {
            state.run.phase = 'idle';
            renderRunBody();
            showConfirm(meta);
        }
    }

    function closeRunner() {
        $('#lc-runner').hidden = true;
        doc.body.classList.remove('lc-runner-open');
        hideConfirm();
        var frame = $('#lc-run-frame');
        frame.removeAttribute('src');
        frame.srcdoc = '';
        state.run = null;
    }

    function renderRunFacts() {
        var meta = state.run && state.run.meta;
        if (!meta) { $('#lc-run-facts').innerHTML = ''; return; }
        var mode = state.modes[meta.mode] || { label: meta.mode, tone: 'gray' };
        var facts = [
            '<span class="lc-tag" data-tone="' + esc(mode.tone) + '">' + esc(mode.label) + '</span>',
            '<span class="lc-fact">executa em <b>' + esc(meta.folder) + '/</b></span>',
            '<span class="lc-fact"><b>' + esc(meta.lang.toUpperCase()) + '</b> · ' + esc(humanBytes(meta.bytes)) + '</span>'
        ];
        if (meta.runner === 'frame') { facts.push('<span class="lc-fact">interativo <b>(iframe)</b></span>'); }
        if (meta.cli) { facts.push('<span class="lc-fact">CLI: <b>' + esc(meta.cli) + '</b></span>'); }
        $('#lc-run-facts').innerHTML = facts.join('');
    }

    function renderRunBody() {
        var run = state.run;
        if (!run) { return; }
        var read = $('#lc-run-read');
        var frame = $('#lc-run-frame');
        var tabs = $('#lc-run-tabs');
        var stateNode = $('#lc-run-state');

        // «Formato original»: páginas interativas (runner:’frame’) vêm do servidor,
        // porque precisam de JavaScript vivo; tudo o mais é o documento já apanhado
        // pela primeira chamada, metido em srcdoc — assim separador nenhum volta a
        // executar o script, o que importa quando o bilhete é de uso único.
        var canRaw = run.meta.runner === 'frame' ? run.phase !== 'blocked' : !!run.rawDocument;
        var useIframe = canRaw && state.runTab === 'raw' && run.phase !== 'running' && run.phase !== 'idle';

        $all('.lc-tab', tabs).forEach(function (tab) {
            tab.classList.toggle('is-on', tab.getAttribute('data-tab') === state.runTab);
        });
        $('#lc-run-tab-note').textContent = canRaw
            ? (run.meta.runner === 'frame' ? 'página interativa — JS ativo dentro do iframe' : 'leitura normaliza o HTML do utilitário')
            : '';

        // estado
        var stateMap = {
            idle: ['preparando…', ''],
            running: ['executando', ''],
            blocked: ['bloqueado', 'fail'],
            done: ['concluído', 'ok'],
            error: ['falhou', 'fail']
        };
        var stateInfo = stateMap[run.phase] || stateMap.idle;
        stateNode.innerHTML = (run.phase === 'running' ? '<span class="lc-spinner" aria-hidden="true"></span>' : '') +
            esc(run.httpStatus ? run.httpStatus + ' · ' + stateInfo[0] : stateInfo[0]) +
            (run.ms ? ' · ' + run.ms + ' ms' : '');
        stateNode.setAttribute('data-state', run.phase === 'done' ? (run.httpStatus >= 400 ? 'fail' : 'ok') : stateInfo[1]);
        $('#lc-run-kicker').textContent = run.phase === 'running' ? 'Executando no servidor'
            : run.phase === 'blocked' ? 'Bloqueado'
                : run.phase === 'error' ? 'Falhou na execução'
                    : run.meta.blocked ? 'Indisponível na web' : 'Saída';

        // corpo
        if (useIframe) {
            read.hidden = true;
            frame.hidden = false;
            if (run.meta.runner === 'frame') {
                var live = 'index.php?run=1&view=' + encodeURIComponent(run.meta.rel);
                if (frame.getAttribute('data-src') !== live) {
                    frame.setAttribute('data-src', live);
                    frame.removeAttribute('srcdoc');
                    frame.src = live;
                }
            } else {
                frame.removeAttribute('src');
                frame.setAttribute('data-src', '');
                if (frame.getAttribute('data-doc') !== run.rawDocument) {
                    frame.setAttribute('data-doc', run.rawDocument || '');
                    frame.srcdoc = run.rawDocument || '';
                }
            }
            return;
        }

        frame.hidden = true;
        read.hidden = false;

        var pieces = [];
        pieces.push('<ul class="lc-run-meta">' +
            '<li><b>faz</b><span>' + esc(run.meta.summary || 'sem descrição') + '</span></li>' +
            (run.meta.expect ? '<li><b>espera-se</b><span>' + esc(run.meta.expect) + '</span></li>' : '') +
            (run.meta.side_effects ? '<li><b>efeitos</b><span>' + esc(run.meta.side_effects) + '</span></li>' : '') +
            '</ul>');

        if (run.phase === 'blocked') {
            pieces.push(banner('fail', icon('alert', 16) + '<span>' + esc(run.meta.blocked || 'Bloqueado') + '</span>' +
                (run.meta.cli ? '<br>Fora do navegador funciona: <code>' + esc(run.meta.cli) + '</code>' : '')));
        } else if (run.phase === 'idle') {
            pieces.push(banner('info', 'Confirmar no painel — o script ainda não foi executado.'));
        } else if (run.phase === 'running') {
            pieces.push(banner('info', 'O pedido vai a caminho. Scripts de WinRM podem demorar ~30 s.'));
        } else if (run.phase === 'error') {
            pieces.push(banner('fail', esc(run.error || 'O pedido falhou.')));
        } else if (run.httpStatus >= 400) {
            pieces.push(banner('fail', 'HTTP ' + run.httpStatus + '. O script respondeu, mas com erro — a saída está por baixo.'));
        } else if (run.meta.mode === 'destructive' || run.meta.mode === 'write') {
            pieces.push(banner('ok', 'Executado. ' + (run.meta.mode === 'destructive'
                ? 'O bilhete de uso único foi consumido: executar outra vez pede confirmação de novo.'
                : 'Confirma o resultado na aplicação antes de sair daqui.')));
        }

        if (run.phase === 'done' || run.phase === 'error') {
            if (run.kind === 'json') {
                pieces.push('<pre class="lc-pre">' + run.jsonHtml + '</pre>');
            } else if (run.kind === 'html') {
                pieces.push('<div class="lc-embed">' + run.html + '</div>');
            } else {
                pieces.push('<pre class="lc-pre">' + esc(run.text || '(saída vazia)') + '</pre>');
            }
        } else if (run.phase === 'running') {
            pieces.push('<div class="lc-skeleton"></div><div class="lc-skeleton"></div>');
        }

        read.innerHTML = pieces.join('');
        $('#lc-run-foot-info').textContent = run.phase === 'done'
            ? run.httpStatus + ' · ' + (run.text || '').length + ' caracteres · ' + run.contentType
            : '';
        // «Nova aba» faria um segundo pedido: para scripts que escrevem o bilhete
        // já foi consumido, por isso o link direto só é oferecido em leituras puras.
        $('#lc-run-open').setAttribute('href', 'index.php?run=1&view=' + encodeURIComponent(run.meta.rel));
        $('#lc-run-open').hidden = lcNeedsGrant(run.meta) || run.meta.blocked;
    }

    function banner(tone, html) {
        return '<div class="lc-run-banner" data-tone="' + tone + '">' + html + '</div>';
    }

    function lcNeedsGrant(meta) {
        return confirmTier(meta) !== 'none';
    }

    function requestRun(rel, grant) {
        var meta = state.scripts[rel];
        state.run = state.run || {};
        state.run.meta = meta;
        state.run.phase = 'running';
        renderRunFacts();
        renderRunBody();

        var url = 'index.php?run=1&view=' + encodeURIComponent(rel) + (grant ? '&_g=' + encodeURIComponent(grant) : '');
        var started = performance.now();
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html, application/json;q=0.9, text/plain;q=0.8', 'X-LC-Panel': '1' }
        }).then(function (res) {
            return res.text().then(function (text) {
                var contentType = (res.headers.get('content-type') || '').split(';')[0].trim();
                state.run.httpStatus = res.status;
                state.run.contentType = contentType;
                state.run.ms = Math.round(performance.now() - started);
                state.run.text = text;
                state.run.grantUrl = grant || null;
                if (contentType === 'application/json' || /^\s*[[{]/.test(text)) {
                    state.run.kind = 'json';
                    state.run.jsonHtml = prettyJson(text);
                } else if (contentType === 'text/html' || /<\w+/.test(text)) {
                    state.run.kind = 'html';
                    state.run.html = sanitize(text, { keepStyle: false });
                    state.run.rawDocument = sanitizeDocument(text);
                } else {
                    state.run.kind = 'text';
                }
                state.run.phase = 'done';
                if (meta.mode === 'destructive' || meta.mode === 'write') {
                    refreshChecks(true);
                }
                renderRunBody();
            });
        }).catch(function (error) {
            state.run.phase = 'error';
            state.run.error = (error && error.message) || 'Falha de rede.';
            state.run.ms = Math.round(performance.now() - started);
            renderRunBody();
        });
    }

    function prettyJson(text) {
        var formatted;
        try {
            formatted = JSON.stringify(JSON.parse(text), null, 2);
        } catch (e) {
            return esc(text);
        }
        return esc(formatted)
            .replace(/&quot;([^&]*?)&quot;(\s*:)/g, '<span class="j-key">&quot;$1&quot;</span>$2')
            .replace(/: &quot;([^&]*?)&quot;/g, ': <span class="j-str">&quot;$1&quot;</span>')
            .replace(/: (-?\d+(?:\.\d+)?)(?=[,\n\]}])/g, ': <span class="j-num">$1</span>')
            .replace(/: (true|false)(?=[,\n\]}])/g, ': <span class="j-bool">$1</span>')
            .replace(/: null(?=[,\n\]}])/g, ': <span class="j-null">null</span>');
    }

    /**
     * Remoção do que pode executar ou estilizar por fora do nosso tema.
     * keepStyle:true é o modo «Formato original», em que o CSS do utilitário fica.
     */
    function sanitize(html, options) {
        options = options || {};
        var parsed;
        try {
            parsed = new DOMParser().parseFromString(html, 'text/html');
        } catch (e) {
            return esc(html);
        }
        var body = parsed.body;
        if (!body) { return esc(html); }

        $all('script, template, object, embed, frame, frameset, link, meta[http-equiv], base', body).forEach(function (node) {
            node.parentNode.removeChild(node);
        });
        if (!options.keepStyle) {
            $all('style', body).forEach(function (node) { node.parentNode.removeChild(node); });
            $all('[style]', body).forEach(function (node) { node.removeAttribute('style'); });
        }
        $all('*', body).forEach(function (node) {
            Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
                var name = attribute.name.toLowerCase();
                if (name.indexOf('on') === 0) { node.removeAttribute(attribute.name); return; }
                if ((name === 'href' || name === 'src' || name === 'srcdoc') &&
                    /^\s*(javascript|data):/i.test(attribute.value || '')) {
                    node.removeAttribute(attribute.name);
                }
                if (name === 'formaction' || name === 'autofocus') { node.removeAttribute(attribute.name); }
            });
        });
        return body.innerHTML;
    }

    /**
     * Modo «Formato original»: documento inteiro, com o CSS do próprio utilitário,
     * mas ainda sem o que executa ou redireciona. Vai para iframe srcdoc.
     */
    function sanitizeDocument(html) {
        var parsed;
        try {
            parsed = new DOMParser().parseFromString(html, 'text/html');
        } catch (e) {
            return null;
        }
        if (!parsed.documentElement) { return null; }
        $all('script, base, object, embed, frame, frameset, meta[http-equiv], link[rel="import"]', parsed).forEach(function (node) {
            if (node.parentNode) { node.parentNode.removeChild(node); }
        });
        $all('*', parsed).forEach(function (node) {
            Array.prototype.slice.call(node.attributes || []).forEach(function (attribute) {
                var name = attribute.name.toLowerCase();
                if (name.indexOf('on') === 0 || name === 'formaction') { node.removeAttribute(attribute.name); return; }
                if ((name === 'href' || name === 'src') && /^\s*(javascript|data):/i.test(attribute.value || '')) {
                    node.removeAttribute(attribute.name);
                }
            });
        });
        return '<!DOCTYPE html>' + parsed.documentElement.outerHTML;
    }

    // ------------------------------------------------------------------
    // degraus de confirmação
    // ------------------------------------------------------------------
    function confirmTier(meta) {
        if (meta.mode === 'destructive') { return 'reauth'; }
        if (meta.mode === 'write') { return 'confirm'; }
        return 'none';
    }

    function showConfirm(meta) {
        var tier = confirmTier(meta);
        state.confirm = { rel: meta.rel, tier: tier };
        var card = $('#lc-confirm');
        card.hidden = false;

        $('#lc-confirm-eyebrow').textContent = tier === 'reauth' ? 'Operação irreversível' : 'Este script escreve';
        $('#lc-confirm-title').textContent = tier === 'reauth'
            ? 'Executar ' + meta.file + '?'
            : 'Permitir que ' + meta.file + ' escreva?';
        $('#lc-confirm-lede').textContent = meta.summary || 'Sem descrição no catálogo.';
        $('#lc-confirm-ttl').textContent = cfg('grant_seconds', 60);
        $('#lc-confirm-email').textContent = (state.data.user && state.data.user.email) || '—';

        var bullets = [];
        if (meta.expect) { bullets.push('Esperas: ' + meta.expect); }
        if (meta.side_effects) { bullets.push('Efeitos: ' + meta.side_effects); }
        if (meta.warn) { bullets.push('Aviso: ' + meta.warn); }
        if ((meta.requires || []).length) { bullets.push('Requer: ' + meta.requires.join(', ') + ' — se o banco de dados estiver fora do ar o pedido é recusado antes de começar.'); }
        bullets.push('O bilhete dura ' + cfg('grant_seconds', 60) + ' s e morre ao primeiro uso; fechar esta janela não o retorna.');
        $('#lc-confirm-list').innerHTML = bullets.map(function (text) { return '<li>' + esc(text) + '</li>'; }).join('');

        $('#lc-confirm-word-wrap').hidden = tier !== 'reauth';
        $('#lc-confirm-auth').hidden = true; // sem login, não há senha para confirmar
        $('#lc-confirm-word-input').value = '';
        $('#lc-confirm-password').value = '';
        setConfirmStatus('', '');
        $('#lc-confirm-go').textContent = tier === 'reauth' ? 'Executar agora' : 'Executar';
        $('#lc-confirm-go').className = 'lc-btn ' + (tier === 'reauth' ? 'lc-btn-danger' : 'lc-btn-primary');
        setTimeout(function () {
            var focus = tier === 'reauth' ? $('#lc-confirm-word-input') : $('#lc-confirm-go');
            if (focus && focus.focus) { focus.focus(); }
        }, 40);
    }

    function hideConfirm() {
        $('#lc-confirm').hidden = true;
        state.confirm = null;
    }

    function setConfirmStatus(message, tone) {
        var node = $('#lc-confirm-status');
        node.textContent = message || '';
        node.setAttribute('data-tone', tone || '');
    }

    function submitConfirm(event) {
        event.preventDefault();
        if (!state.confirm) { return; }
        var rel = state.confirm.rel;
        var tier = state.confirm.tier;

        if (tier === 'reauth' && $('#lc-confirm-word-input').value.trim() !== 'EXECUTAR') {
            setConfirmStatus('Escreve EXECUTAR (mesmo em maiúsculas) para continuar.', 'error');
            return;
        }

        var go = $('#lc-confirm-go');
        go.disabled = true;

        setConfirmStatus('Solicitando bilhete…');
        api('grant', { method: 'POST', body: { file: rel } }).then(function (res) {
            hideConfirm();
            state.run.phase = 'idle';
            requestRun(rel, res.grant.token);
            toast('Bilhete emitido: ' + clock(res.grant.seconds * 1000) + ' de validade, um uso.', 'ok');
        }).catch(function (error) {
            setConfirmStatus(error.message || 'Recusado.', 'error');
        }).then(function () { go.disabled = false; });
    }

    // ------------------------------------------------------------------
    // verificações / recarga
    // ------------------------------------------------------------------
    function refreshChecks(fresh) {
        return api('checks' + (fresh ? '&fresh=1' : '')).then(function (res) {
            state.checks = res.checks;
            renderChecks();
            renderHero();
            renderEnv();
        }).catch(function (error) {
            toast('Sondagem falhou: ' + error.message, 'error');
        });
    }

    function render() {
        doc.body.setAttribute('data-auth', 'admin');
        $('#lc-app').hidden = false;
        doc.body.setAttribute('data-ready', '1');
        renderEnv();
        renderHero();
        renderChecks();
        renderRules();
        renderFilters();
        renderCatalog();
        renderConnectivity();
        applyDeepLink();
        if (state.autorefresh) { clearInterval(state.autorefresh); }
        state.autorefresh = setInterval(function () {
            if (doc.hidden || !$('#lc-runner').hidden) { return; }
            refreshChecks(true);
        }, 300000);
    }

    function applyDeepLink() {
        var requested = (state.data.runtime && state.data.runtime.requested) ||
            (window.URLSearchParams ? new URLSearchParams(location.search).get('view') : '');
        if (!requested) { return; }
        var resolved = String(requested).replace(/^\/+|\.\/(tests\/)?/g, '').replace(/^tests\//, '');
        if (state.scripts[resolved]) { openRunner(resolved); }
    }

    // ------------------------------------------------------------------
    // eventos
    // ------------------------------------------------------------------
    function copy(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(function () { toast('Copiado.', 'ok'); }, function () {
                toast('O browser recusou a área de transferência.', 'error');
            });
        }
        var area = el('textarea', { class: 'lc-visually-hidden' });
        area.value = text;
        doc.body.appendChild(area);
        area.select();
        try { doc.execCommand('copy'); toast('Copiado.', 'ok'); } catch (e) { toast('Copia manual necessária.', 'error'); }
        doc.body.removeChild(area);
        return Promise.resolve();
    }

    function absolutize(path) {
        try { return new URL(path, location.href).href; } catch (e) { return path; }
    }

    function bindEvents() {
        doc.addEventListener('click', function (event) {
            var trigger = event.target.closest ? event.target.closest('[data-act]') : null;
            if (!trigger) {
                // clicar no fundo de um cartão abre o painel
                var card = event.target.closest ? event.target.closest('.lc-card') : null;
                if (card && state.scripts[card.getAttribute('data-rel')]) {
                    openRunner(card.getAttribute('data-rel'));
                }
                return;
            }
            var act = trigger.getAttribute('data-act');
            var file = trigger.getAttribute('data-file');

            if (act === 'open') { event.preventDefault(); openRunner(file); }
            else if (act === 'refresh') { refreshChecks(true); toast('A re-sondar o servidor…'); }
            else if (act === 'checks-fresh') { refreshChecks(true); }
            else if (act === 'filter-mode') { state.filter.mode = trigger.getAttribute('data-mode'); renderFilters(); renderCatalog(); }
            else if (act === 'filter-group') { state.filter.group = trigger.getAttribute('data-group'); renderFilters(); renderCatalog(); }
            else if (act === 'filter-reset') { state.filter = { q: '', group: 'all', mode: 'all' }; $('#lc-search').value = ''; renderFilters(); renderCatalog(); }
            else if (act === 'run-close') { closeRunner(); }
            else if (act === 'run-rerun') {
                if (!state.run) { return; }
                if (lcNeedsGrant(state.run.meta)) { showConfirm(state.run.meta); }
                else { requestRun(state.run.meta.rel); }
            }
            else if (act === 'run-tab') { state.runTab = trigger.getAttribute('data-tab'); renderRunBody(); }
            else if (act === 'run-copy') {
                if (state.run) { copy(state.run.text || ''); }
            }
            else if (act === 'run-download') {
                if (!state.run || !state.run.text) { toast('Ainda não há saída para descarregar.', 'error'); return; }
                var blob = new Blob([state.run.text], { type: 'text/plain;charset=utf-8' });
                var link = el('a', { href: URL.createObjectURL(blob), download: state.run.meta.file.replace(/\.php$/, '') + '-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.txt' });
                doc.body.appendChild(link);
                link.click();
                doc.body.removeChild(link);
            }
            else if (act === 'copy-file') { event.preventDefault(); copy('tests/' + file); }
            else if (act === 'run-diag') { runDiagnostics(); }
            else if (act === 'test-wol') { testWol(); }
        });

        $('#lc-confirm-form').addEventListener('submit', submitConfirm);
        $('[data-act="confirm-cancel"]').addEventListener('click', function () {
            hideConfirm();
            if (state.run && state.run.phase === 'idle') {
                state.run.phase = 'error';
                state.run.error = 'Cancelado — nenhum script executou, o bilhete não foi emitido.';
                renderRunBody();
            }
        });

        var search = $('#lc-search');
        search.addEventListener('input', function () {
            state.filter.q = search.value;
            renderCatalog();
        });

        $('#lc-diag-host').addEventListener('change', function (event) {
            state.diag.hostId = event.target.value;
        });

        doc.addEventListener('keydown', function (event) {
            var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName);
            if (event.key === 'Escape') {
                if (!$('#lc-confirm').hidden) { hideConfirm(); }
                else if (!$('#lc-runner').hidden) { closeRunner(); }
                else if (typing) { event.target.blur(); }
                return;
            }
            if (typing) {
                if (event.key === 'Enter' && event.target.classList.contains('lc-card')) { event.preventDefault(); openRunner(event.target.getAttribute('data-rel')); }
                return;
            }
            if (event.key === '/') { event.preventDefault(); search.focus(); return; }
            if (event.key === 'r' || event.key === 'R') { refreshChecks(true); return; }
            if (event.key === 'j' || event.key === 'ArrowDown') { event.preventDefault(); moveCursor(1); return; }
            if (event.key === 'k' || event.key === 'ArrowUp') { event.preventDefault(); moveCursor(-1); return; }
            if (event.key === 'Enter' && state.cursor > -1 && state.view[state.cursor]) {
                event.preventDefault();
                openRunner(state.view[state.cursor]);
            }
        });

        // Enter em cima de um cartão focado
        doc.addEventListener('keypress', function (event) {
            if (event.key !== 'Enter') { return; }
            var card = event.target.closest ? event.target.closest('.lc-card') : null;
            if (card && event.target === card) { event.preventDefault(); openRunner(card.getAttribute('data-rel')); }
        });
    }

    // ------------------------------------------------------------------
    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    function start() {
        bindEvents();
        boot();
        var foot = $('#lc-foot-build');
        if (foot) {
            foot.textContent = 'LabControl · bancada de diagnóstico · ' +
                (state.data && state.data.catalog && state.data.catalog.catalog && state.data.catalog.catalog.updated
                    ? 'catálogo v' + state.data.catalog.catalog.version + ' de ' + state.data.catalog.catalog.updated
                    : 'catálogo local');
        }
    }
})();
