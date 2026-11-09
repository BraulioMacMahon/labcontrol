/**
 * Testes da camada de tempo real (node --test, sem browser/servidor).
 *
 *   cd labcontrol-frontend && npm test
 * ou: node --test tests/frontend/realtime.test.mjs
 *
 * Validam o contrato que impede o "piscar": só se notifica a UI quando o
 * estado muda de facto, o SSE degrada para polling leve e a reconexão nunca
 * implica recarregar a página.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { LabControlRealtime } from '../../labcontrol-frontend/realtime.js';

class FakeEventSource {
    static instances = [];

    constructor(url) {
        this.url = url;
        this.readyState = 0; // CONNECTING
        this.listeners = new Map();
        this.closed = false;
        FakeEventSource.instances.push(this);
    }
    addEventListener(type, fn) {
        if (!this.listeners.has(type)) this.listeners.set(type, []);
        this.listeners.get(type).push(fn);
    }
    emit(type, data = '') {
        for (const fn of this.listeners.get(type) || []) fn({ data });
    }
    open() { this.readyState = 1; this.emit('open'); }
    error(fatal = false) { if (fatal) this.readyState = 2; this.emit('error'); }
    close() { this.closed = true; this.readyState = 2; }
}

class FakeTimers {
    constructor() { this.now = 0; this.id = 0; this.tasks = new Map(); }
    setTimeout(fn, ms = 0) {
        const id = ++this.id;
        this.tasks.set(id, { fn, at: this.now + ms, interval: null });
        return id;
    }
    setInterval(fn, ms) {
        const id = ++this.id;
        this.tasks.set(id, { fn, at: this.now + ms, interval: ms });
        return id;
    }
    clearTimeout(id) { this.tasks.delete(id); }
    clearInterval(id) { this.tasks.delete(id); }
    get intervals() { return [...this.tasks.values()].filter(t => t.interval).length; }
    get pending() { return this.tasks.size; }

    /** Avança o relógio, dispara os callbacks devidos e drena microtasks. */
    async tick(ms = 0) {
        this.now += ms;
        for (let round = 0; round < 20; round++) {
            const due = [...this.tasks.entries()]
                .filter(([, t]) => t.at <= this.now)
                .sort((a, b) => a[1].at - b[1].at);
            if (!due.length) break;
            for (const [id, task] of due) {
                if (!this.tasks.has(id)) continue;
                if (task.interval) task.at = this.now + task.interval;
                else this.tasks.delete(id);
                task.fn();
            }
            await new Promise(resolve => setImmediate(resolve));
        }
        await new Promise(resolve => setImmediate(resolve));
    }
}

function makeClient(overrides = {}) {
    FakeEventSource.instances.length = 0;
    const timers = new FakeTimers();
    const states = [];
    const statuses = [];
    const fetchCalls = [];
    let remote = { version: 'v1', hosts_rev: 'h1', logs_rev: 'l1', server_time: '2026-01-01T00:00:00Z' };

    const fetchVersion = async (etag) => {
        fetchCalls.push(etag);
        if (overrides.fetchError || overrides.fetchStatus) {
            const error = new Error(overrides.fetchError || `Erro ${overrides.fetchStatus}`);
            if (overrides.fetchStatus) error.status = overrides.fetchStatus;
            throw error;
        }
        return { unchanged: false, etag: `"${remote.version}"`, data: { ...remote } };
    };

    const client = new LabControlRealtime({
        streamURL: (since) => `/api/state.php?action=stream&since=${since}`,
        fetchVersion,
        onState: (state) => states.push(state),
        onStatus: (status) => statuses.push(status),
        EventSource: overrides.noEventSource ? null : FakeEventSource,
        timers,
        document: null,
        fallbackIntervalMs: 15000,
        openTimeoutMs: 8000,
        retryProbeMs: 60000,
        maxStreamFailures: 2,
        reconnectDelayMs: 1500,
        ...(overrides.clientOptions || {})
    });

    return {
        client, timers, states, statuses, fetchCalls,
        setRemote: (value) => { remote = value; },
        lastSource: () => FakeEventSource.instances.at(-1),
        sourceCount: () => FakeEventSource.instances.length
    };
}

test('arranque semeia a versão e o hello idêntico NÃO provoca re-render', async () => {
    const ctx = makeClient();
    ctx.client.start();
    await ctx.timers.tick(0);

    const source = ctx.lastSource();
    assert.match(source.url, /since=v1/);
    assert.equal(ctx.client.version, 'v1');

    source.open();
    source.emit('hello', JSON.stringify({ version: 'v1', hosts_rev: 'h1', logs_rev: 'l1' }));

    assert.equal(ctx.states.length, 0, 'não deve atualizar a UI sem alterações');
    assert.equal(ctx.client.mode, 'sse');
    assert.ok(ctx.statuses.some(s => s.mode === 'sse'));
});

test('evento state novo notifica apenas o grupo que mudou', async () => {
    const ctx = makeClient();
    ctx.client.start();
    await ctx.timers.tick(0);
    const source = ctx.lastSource();
    source.open();

    source.emit('state', JSON.stringify({ version: 'v2', hosts_rev: 'h2', logs_rev: 'l1' }));
    assert.equal(ctx.states.length, 1);
    assert.deepEqual(ctx.states[0].changed, ['hosts']);
    assert.equal(ctx.states[0].source, 'sse');

    // Frame repetido (reconexão, duplicação de evento) não deve redesenhar nada.
    source.emit('state', JSON.stringify({ version: 'v2', hosts_rev: 'h2', logs_rev: 'l1' }));
    assert.equal(ctx.states.length, 1);
});

test('hello após reconexão deteta alterações ocorridas enquanto a página esteve fora', async () => {
    const ctx = makeClient();
    ctx.setRemote({ version: 'v7', hosts_rev: 'h7', logs_rev: 'l7' });
    ctx.client.start(); // seed apanha v7
    await ctx.timers.tick(0);
    assert.equal(ctx.client.version, 'v7');

    // Simula ligação nova com um estado mais antigo no cliente (ex.: cache).
    ctx.client.version = 'v1';
    ctx.client.hostsRev = 'h1';
    ctx.client.logsRev = 'l1';
    ctx.lastSource().open();
    ctx.lastSource().emit('hello', JSON.stringify({ version: 'v7', hosts_rev: 'h7', logs_rev: 'l7' }));
    assert.equal(ctx.states.length, 1);
    assert.deepEqual(ctx.states[0].changed, ['hosts', 'logs']);
});

test('SSE indisponível degrada para polling leve e só notifica em mudança real', async () => {
    const ctx = makeClient();
    ctx.client.start();
    await ctx.timers.tick(0);
    const source = ctx.lastSource();

    // Erro fatal (readyState CLOSED) -> fallback imediato.
    source.error(true);
    assert.equal(ctx.client.mode, 'polling');
    assert.equal(ctx.timers.intervals, 1, 'um único intervalo de polling');

    // Primeiro tick: versão igual -> silêncio.
    await ctx.timers.tick(15000);
    assert.equal(ctx.states.length, 0);
    assert.equal(ctx.fetchCalls.length, 2, 'seed + 1 poll, nunca check-all');

    // Segundo tick: estado novo -> uma notificação, com os dois grupos.
    ctx.setRemote({ version: 'v3', hosts_rev: 'h3', logs_rev: 'l2' });
    await ctx.timers.tick(15000);
    assert.equal(ctx.states.length, 1);
    assert.deepEqual(ctx.states[0].changed, ['hosts', 'logs']);
    assert.equal(ctx.states[0].source, 'poll');
});

test('bye (limite de duração do worker) reconecta sem recarregar a página', async () => {
    const ctx = makeClient();
    ctx.client.start();
    await ctx.timers.tick(0);
    const first = ctx.lastSource();
    first.open();
    first.emit('bye', JSON.stringify({ reason: 'max_duration' }));

    assert.equal(first.closed, true);
    await ctx.timers.tick(0);
    assert.equal(ctx.sourceCount(), 2, 'nova ligação EventSource');
    assert.match(ctx.lastSource().url, /since=v1/);
});

test('pausa/retoma por visibilidade deteta mudanças e não mantém workers ativos', async () => {
    const ctx = makeClient();
    ctx.client.start();
    await ctx.timers.tick(0);
    const source = ctx.lastSource();
    source.open();

    ctx.setRemote({ version: 'v9', hosts_rev: 'h9', logs_rev: 'l9' });
    ctx.client.setPauseReason('hidden', true);
    assert.equal(source.closed, true);
    assert.equal(ctx.client.mode, 'paused');
    assert.equal(ctx.timers.pending, 0, 'sem timers enquanto oculta');

    ctx.client.setPauseReason('hidden', false);
    await ctx.timers.tick(0);
    assert.equal(ctx.states.length, 1, 'mudanças durante a pausa são recuperadas');
    assert.equal(ctx.sourceCount(), 2);
});

test('backend sem state.php (404) ativa compatibilidade sem abrir SSE nem martelar 404s', async () => {
    const signals = [];
    const ctx = makeClient({
        fetchStatus: 404,
        clientOptions: { onUnsupported: (detail) => signals.push(detail) }
    });
    ctx.client.start();
    await ctx.timers.tick(0);

    assert.equal(signals.length, 1);
    assert.match(signals[0], /compatibilidade/i);
    assert.equal(ctx.client.mode, 'unsupported');
    assert.equal(ctx.sourceCount(), 0, 'não deve abrir EventSource para um 404');
    assert.equal(ctx.timers.pending, 0, 'sem retries em loop');
    assert.equal(ctx.fetchCalls.length, 1, 'uma única tentativa');
});

test('browser sem EventSource usa polling; stop() liberta tudo', async () => {
    const ctx = makeClient({ noEventSource: true });
    ctx.client.start();
    await ctx.timers.tick(0);
    assert.equal(ctx.client.mode, 'polling');

    ctx.client.stop();
    assert.equal(ctx.timers.pending, 0);
    assert.equal(ctx.client.mode, 'offline');
});
