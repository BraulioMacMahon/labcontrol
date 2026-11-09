/**
 * LabControl - camada de tempo real (Server-Sent Events)
 * =====================================================
 *
 * Mantém UMA ligação HTTP aberta com `state.php?action=stream`. O servidor
 * avisa apenas quando o estado da base de dados muda; o frontend então pede
 * os dados e atualiza só os nós que mudaram. Nunca há `location.reload()`,
 * `navigate()` automático, skeleton nem re-render da página inteira.
 *
 * Porquê SSE e não WebSocket?
 *  - O backend é PHP/Apache (sem loop de eventos nem upgrade de protocolo).
 *  - O fluxo é unidirecional: servidor -> browser. Os comandos (shutdown,
 *    WOL, processos) já são POST/fetch normais e não precisam de socket.
 *  - EventSource reconecta sozinho, funciona com proxy/HTTPS e usa HTTP/1.1
 *    ou HTTP/2 sem configuração extra no Apache.
 *
 * Resiliência (importante em laboratório, atrás de proxies que fazem buffering):
 *  1. `seed()` lê a versão atual antes de ligar: mudanças ocorridas enquanto a
 *     página esteve fechada são detetadas no `hello`.
 *  2. Se o SSE falhar/timeout, cai para polling LEVE de `state.php?action=version`
 *     (1 query + ETag/304), nunca para `check-all`.
 *  3. Tenta voltar a SSE periodicamente (retryProbeMs) sem intervenção.
 *  4. Fecha a ligação em abas ocultas e sem rede, para não gastar workers Apache.
 *
 * O módulo não toca no DOM: recebe callbacks (`onState`, `onStatus`) e
 * dependências injetáveis, o que permite testá-lo com `node --test`.
 */

const DEFAULTS = {
    fallbackIntervalMs: 15000,  // polling de fallback (pedido mínimo, com ETag)
    openTimeoutMs: 8000,        // tempo para o SSE abrir antes de desistir
    maxStreamFailures: 2,       // falhas consecutivas antes de cair para polling
    reconnectDelayMs: 1500,     // backoff entre tentativas SSE
    retryProbeMs: 60000         // tentativa de voltar a SSE após fallback
};

const globalTimers = {
    setTimeout: (...args) => setTimeout(...args),
    clearTimeout: (...args) => clearTimeout(...args),
    setInterval: (...args) => setInterval(...args),
    clearInterval: (...args) => clearInterval(...args)
};

export class LabControlRealtime {
    constructor(options = {}) {
        this.streamURL = options.streamURL || (() => '');
        this.fetchVersion = options.fetchVersion || (async () => null);
        this.onState = options.onState || (() => {});
        this.onStatus = options.onStatus || (() => {});
        // Backend antigo (sem state.php): sinaliza para a app ativar o modo de
        // compatibilidade, em vez de ficar para sempre a tentar um 404.
        this.onUnsupported = options.onUnsupported || (() => {});
        this.EventSource = options.EventSource ?? (typeof EventSource !== 'undefined' ? EventSource : null);
        this.timers = options.timers || globalTimers;
        this.doc = options.document ?? (typeof document !== 'undefined' ? document : null);

        this.fallbackIntervalMs = options.fallbackIntervalMs ?? DEFAULTS.fallbackIntervalMs;
        this.openTimeoutMs = options.openTimeoutMs ?? DEFAULTS.openTimeoutMs;
        this.maxStreamFailures = options.maxStreamFailures ?? DEFAULTS.maxStreamFailures;
        this.reconnectDelayMs = options.reconnectDelayMs ?? DEFAULTS.reconnectDelayMs;
        this.retryProbeMs = options.retryProbeMs ?? DEFAULTS.retryProbeMs;

        this.mode = 'idle';
        this.detail = '';
        this.version = null;
        this.hostsRev = null;
        this.logsRev = null;
        this.etag = null;
        this.seeded = false;

        this.source = null;
        this.pollTimer = null;
        this.openTimer = null;
        this.reconnectTimer = null;
        this.probeTimer = null;
        this.pollingInFlight = false;

        this.streamFailures = 0;
        this.pauseReasons = new Set();
        this.paused = false;
        this.closed = false;
        this.visibilityAttached = false;
    }

    /** Arranca: semeia a versão (sem notificar) e abre o stream. */
    start() {
        this.closed = false;
        this.attachVisibility();
        this.seed(false).then(() => {
            if (!this.closed && !this.paused) this.connect();
        });
        return this;
    }

    /** Pausa/retoma por motivo explícito ('hidden', 'network', 'manual', ...). */
    setPauseReason(reason, active) {
        const had = this.pauseReasons.has(reason);
        if (active === had) return;
        if (active) this.pauseReasons.add(reason);
        else this.pauseReasons.delete(reason);

        const shouldPause = this.pauseReasons.size > 0;
        if (shouldPause === this.paused) return;
        if (shouldPause) this.pause();
        else this.resume();
    }

    pause() {
        this.paused = true;
        this.clearAllTimers();
        this.closeSource();
        this.setMode('paused', 'Ligação suspensa (aba oculta ou sem rede)');
    }

    /** Ao voltar, notifica se houve mudanças entretanto (seed(true)). */
    resume() {
        if (!this.paused || this.closed) return;
        this.paused = false;
        this.streamFailures = 0;
        this.seed(true).then(() => {
            if (!this.closed && !this.paused) this.connect();
        });
    }

    stop() {
        this.closed = true;
        this.paused = false;
        this.pauseReasons.clear();
        this.clearAllTimers();
        this.closeSource();
        this.setMode('offline', 'Ligação de tempo real encerrada');
    }

    /** Baseline: uma única consulta leve antes de abrir o stream. */
    async seed(notify = false) {
        try {
            const result = await this.fetchVersion(this.etag);
            if (!result || result.unchanged || !result.data) return this.seeded;
            this.etag = result.etag || this.etag;
            this.applyBaseline(result.data, notify);
            this.seeded = true;
        } catch (error) {
            const status = error?.status;
            if (status === 404 || status === 405) {
                // Endpoint não existe nesta instalação: desligar este cliente e
                // deixar a app usar o monitor de compatibilidade (sem 404s em loop).
                this.disable('Backend sem state.php — modo de compatibilidade');
                return this.seeded;
            }
            // Falha de rede/BD: o SSE logo avisará, ou o polling tentará de novo.
            if (notify) this.setMode('offline', error?.message || 'API de estado indisponível');
        }
        return this.seeded;
    }

    disable(detail) {
        this.closed = true;
        this.clearAllTimers();
        this.closeSource();
        this.setMode('unsupported', detail);
        this.onUnsupported(detail);
    }

    /** Abre (ou reabre) a ligação EventSource. */
    connect() {
        if (this.closed || this.paused) return;
        this.stopPolling();
        this.clearReconnectTimers();
        this.clearProbe();
        this.closeSource();

        const Ctor = this.EventSource;
        if (typeof Ctor !== 'function') {
            this.startPolling('EventSource não suportado neste browser');
            return;
        }

        let source;
        try {
            source = new Ctor(this.streamURL(this.version || ''));
        } catch (error) {
            this.handleStreamFailure(error?.message || 'Não foi possível abrir o SSE');
            return;
        }
        this.source = source;
        this.setMode('connecting', 'A abrir ligação em tempo real...');

        this.openTimer = this.timers.setTimeout(() => {
            if (this.source !== source || this.mode === 'sse') return;
            this.handleStreamFailure('O servidor não respondeu ao stream (proxy/buffering?)');
        }, this.openTimeoutMs);

        source.addEventListener('open', () => {
            if (this.source !== source) return;
            this.clearOpenTimer();
            this.streamFailures = 0;
            this.setMode('sse', 'Ligação ativa: atualizações por push');
        });

        source.addEventListener('hello', (event) => this.handleEvent(source, 'hello', event.data));
        source.addEventListener('state', (event) => this.handleEvent(source, 'state', event.data));

        source.addEventListener('bye', () => {
            if (this.source !== source) return;
            // O servidor libertou o worker Apache; reconectar de imediato.
            this.closeSource();
            this.scheduleReconnect(0);
        });

        source.addEventListener('error', () => {
            if (this.source !== source) return;
            // readyState 2 = CLOSED: o EventSource não vai reconectar sozinho.
            const fatal = source.readyState === 2;
            this.handleStreamFailure(fatal ? 'Stream encerrado pelo servidor' : 'Falha na ligação SSE', fatal);
        });
    }

    handleEvent(source, type, raw) {
        if (this.source !== source || !raw) return;
        let data;
        try {
            data = JSON.parse(raw);
        } catch (error) {
            return; // ignorar frames malformados
        }
        // `hello` cobre o período em que a página esteve fechada; `state` é o
        // push normal. Ambos usam a mesma comparação de revisões.
        this.applyBaseline(data, true);
    }

    /**
     * Compara revisões e notifica APENAS o que mudou.
     * `changed` é consumido por app.js para pedir só `hosts` ou só `logs`.
     */
    applyBaseline(data, notify = true) {
        if (!data || typeof data !== 'object') return;

        const changed = [];
        if (data.hosts_rev !== undefined && data.hosts_rev !== this.hostsRev) changed.push('hosts');
        if (data.logs_rev !== undefined && data.logs_rev !== this.logsRev) changed.push('logs');

        const versionChanged = data.version !== undefined && data.version !== this.version;
        this.version = data.version ?? this.version;
        this.hostsRev = data.hosts_rev ?? this.hostsRev;
        this.logsRev = data.logs_rev ?? this.logsRev;

        if (!notify || (!versionChanged && changed.length === 0)) return;

        this.onState({
            version: this.version,
            changed: changed.length ? changed : ['hosts', 'logs'],
            source: this.mode === 'polling' ? 'poll' : 'sse',
            serverTime: data.server_time,
            degraded: Boolean(data.degraded),
            type: data.version === undefined ? 'partial' : 'state'
        });
    }

    handleStreamFailure(detail, fatal = false) {
        this.clearOpenTimer();
        this.streamFailures += 1;
        this.closeSource();
        if (this.closed || this.paused) return;

        if (fatal || this.streamFailures >= this.maxStreamFailures) {
            this.startPolling(detail);
            return;
        }
        this.setMode('reconnecting', detail);
        this.scheduleReconnect(this.reconnectDelayMs * this.streamFailures);
    }

    /** Fallback: 1 request leve por intervalo, com 304 quando nada mudou. */
    startPolling(detail = 'SSE indisponível') {
        this.closeSource();
        this.clearOpenTimer();
        this.stopPolling();
        this.setMode('polling', `${detail} — polling leve ${Math.round(this.fallbackIntervalMs / 1000)}s`);

        this.pollTimer = this.timers.setInterval(() => {
            this.pollOnce().catch(() => {});
        }, this.fallbackIntervalMs);

        this.clearProbe();
        this.probeTimer = this.timers.setTimeout(() => {
            this.probeTimer = null;
            if (this.closed || this.paused) return;
            this.streamFailures = 0;
            this.connect(); // tenta regressar a SSE sem recarregar a página
        }, this.retryProbeMs);
    }

    async pollOnce() {
        if (this.closed || this.paused || this.pollingInFlight) return;
        this.pollingInFlight = true;
        try {
            const result = await this.fetchVersion(this.etag);
            if (!result) return;
            if (result.unchanged) {
                if (this.mode === 'offline') this.setMode('polling', 'Ligação restabelecida');
                return;
            }
            this.etag = result.etag || this.etag;
            if (result.data) this.applyBaseline(result.data, true);
        } catch (error) {
            this.setMode('offline', error?.message || 'API indisponível');
        } finally {
            this.pollingInFlight = false;
        }
    }

    setMode(mode, detail = '') {
        if (this.mode === mode && this.detail === detail) return;
        this.mode = mode;
        this.detail = detail;
        this.onStatus({ mode, detail });
    }

    scheduleReconnect(delayMs = this.reconnectDelayMs) {
        this.clearReconnectTimers();
        this.reconnectTimer = this.timers.setTimeout(() => {
            this.reconnectTimer = null;
            if (!this.closed && !this.paused) this.connect();
        }, delayMs);
    }

    closeSource() {
        if (!this.source) return;
        try {
            this.source.close();
        } catch (error) {
            // Alguns mocks/browsers antigos não implementam close().
        }
        this.source = null;
    }

    stopPolling() {
        if (this.pollTimer !== null) {
            this.timers.clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    clearOpenTimer() {
        if (this.openTimer !== null) {
            this.timers.clearTimeout(this.openTimer);
            this.openTimer = null;
        }
    }

    clearReconnectTimers() {
        if (this.reconnectTimer !== null) {
            this.timers.clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }

    clearProbe() {
        if (this.probeTimer !== null) {
            this.timers.clearTimeout(this.probeTimer);
            this.probeTimer = null;
        }
    }

    clearAllTimers() {
        this.clearOpenTimer();
        this.clearReconnectTimers();
        this.clearProbe();
        this.stopPolling();
    }

    attachVisibility() {
        if (this.visibilityAttached || !this.doc || typeof this.doc.addEventListener !== 'function') return;
        this.visibilityAttached = true;
        this.doc.addEventListener('visibilitychange', () => {
            if (this.closed) return;
            this.setPauseReason('hidden', this.doc.visibilityState === 'hidden');
        });
    }
}

export default LabControlRealtime;
