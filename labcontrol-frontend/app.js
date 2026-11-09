import { api } from './api-service.js?v=3.0';
import { LabControlRealtime } from './realtime.js?v=3.0';

// Escape HTML para evitar XSS armazenado (ex.: via hostname de host ou nome de processo)
const lcEscape = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[c]));

// Configuráveis em index.html via window.LABCONTROL_*:
//  - REALTIME_FALLBACK_MS: intervalo do polling leve quando o SSE falha.
//  - PROCESS_REFRESH_MS: processos via WinRM são caros; não é "tempo real".
//  - CHART_SIMULATION: a BD não guarda cpu/ram por host; desligue para um
//    gráfico estritamente real (plano a 0%) em vez da simulação decorativa.
const REALTIME_FALLBACK_MS = Number(window.LABCONTROL_REALTIME_FALLBACK_MS || 15000);
const PROCESS_REFRESH_MS = Number(window.LABCONTROL_PROCESS_REFRESH_MS || 60000);
// Só usado se o backend ainda não tiver state.php (instalação antiga).
const LEGACY_INTERVAL_MS = Number(window.LABCONTROL_LEGACY_INTERVAL_MS || 30000);
const CHART_SIMULATION = window.LABCONTROL_CHART_SIMULATION !== false;

const Components = {
    KPICard: (title, value, icon, colorClass, delay, animate = true) => `
        <div class="glass-card p-6 rounded-3xl flex items-center gap-5 ${animate ? 'fade-in-up' : ''}" style="${animate ? `animation-delay: ${delay}ms` : ''}">
            <div class="w-14 h-14 rounded-2xl flex items-center justify-center shadow-inner ${colorClass.replace('text-', 'bg-').replace('400', '500/10')}">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="${colorClass}">${icon}</svg>
            </div>
            <div>
                <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-0.5">${title}</p>
                <h4 class="text-3xl font-extrabold text-white">${value}</h4>
            </div>
        </div>
    `,

    HostItem: (host, isActive) => `
        <div class="flex items-center gap-2 group">
            <button data-nav="hosts" data-id="${host.id}" class="nav-link flex-1 flex items-center justify-between p-3.5 rounded-2xl transition-all ${isActive ? 'active-nav' : 'text-gray-400 hover:bg-white/5'}">
                <div class="flex items-center gap-3.5">
                    <div class="relative flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="transition-transform group-hover:scale-110"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>
                        ${host.status === 'online' ? 
                            `<span class="absolute -bottom-1 -right-1 w-2.5 h-2.5 rounded-full border-2 border-[#121212] bg-green-400 animate-pulse"></span>` : 
                            `<span class="absolute -bottom-1 -right-1 w-2.5 h-2.5 rounded-full border-2 border-[#121212] bg-gray-600"></span>`
                        }
                    </div>
                    <div class="flex flex-col items-start">
                        <span class="text-xs font-bold tracking-tight text-white group-hover:text-blue-400 transition-colors">${lcEscape(host.name)}</span>
                        <span class="text-[10px] font-mono text-gray-500">${host.ip}</span>
                    </div>
                </div>
            </button>
            ${host.status !== 'online' ? `
                <button data-action="wol-host" data-ip="${host.ip}" data-mac="${host.mac}" class="w-10 h-10 rounded-xl bg-green-500/10 text-green-500 opacity-0 group-hover:opacity-100 transition-all flex items-center justify-center hover:bg-green-500/20" title="Wake up host">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                </button>
            ` : ''}
        </div>
    `,

    LogItem: (log) => `
        <div class="flex gap-4 p-3 rounded-2xl hover:bg-white/[0.03] transition-all border border-transparent hover:border-white/5 group">
            <div class="mt-1">
                <div class="w-8 h-8 rounded-xl bg-white/5 flex items-center justify-center relative">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="${log.status === 'success' ? 'text-green-400' : 'text-amber-400'}">
                        ${log.status === 'success' ? '<path d="M20 6 9 17l-5-5"/>' : '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>'}
                    </svg>
                </div>
            </div>
            <div class="flex-1">
                <div class="flex justify-between items-start mb-0.5">
                    <p class="text-xs font-bold text-gray-200">${log.action}</p>
                    <span class="text-[10px] font-mono text-gray-600">${log.time}</span>
                </div>
                <p class="text-[11px] text-gray-500"><span class="text-blue-500 font-medium">${log.user}</span> on ${log.target}</p>
            </div>
        </div>
    `,

    Skeleton: () => `
        <div class="space-y-10 animate-pulse">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                ${Array(4).fill('<div class="h-32 glass-panel rounded-3xl bg-white/5"></div>').join('')}
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 h-80 glass-panel rounded-[2rem] bg-white/5"></div>
                <div class="h-80 glass-panel rounded-[2rem] bg-white/5"></div>
            </div>
        </div>
    `
};

class LabControlApp {
    constructor() {
        this.currentHostId = null;
        this.hosts = [];
        this.logs = [];
        this.processes = [];
        this.charts = {};
        this.currentView = 'dashboard';
        this.processError = null;      // último erro da consulta de processos
        this.processesLoading = false; // evita sessões WinRM sobrepostas no polling

        // Estado do canal de tempo real / diff de dados
        this.realtime = null;
        this.processTimer = null;
        this.legacyTimer = null;
        this.dataLoaded = false;
        this.hostsSignature = '';
        this.logsSignature = '';
        this.refreshing = false;        // impede refreshes sobrepostos
        this.pendingChange = null;     // último evento recebido durante um refresh
        this.forceChecking = false;    // check-all manual em curso
        
        this.attachEventListeners();
        this.init();
    }

    // Função para atualizar o DOM sem "piscar"
    safeUpdateHTML(elementId, newHTML) {
        const el = document.getElementById(elementId);
        if (!el) return;
        
        // Criar um elemento temporário para comparar o conteúdo real (normalizado pelo browser)
        const temp = document.createElement('div');
        temp.innerHTML = newHTML;
        
        if (el.innerHTML !== temp.innerHTML) {
            // Preservar a posição do scroll (ex.: feed de logs / lista de hosts):
            // reescrever innerHTML sem isto faz o conteúdo "saltar" ao atualizar.
            const scrollTop = el.scrollTop;
            el.innerHTML = newHTML;
            el.scrollTop = scrollTop;
        }
    }

    async init() {
        await this.enterApp();
        this.markUpdated('carga inicial');
        // Liga o canal push DEPOIS da primeira carga: o `hello` do stream só
        // notifica alterações ocorridas a partir daqui.
        this.startRealtime();
        this.startProcessRefresh();
        this.startConnectivityEvents();
        
        document.getElementById('host-form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleHostSubmit();
        });
    }

    /**
     * Liga o canal de tempo real (SSE) com fallback de polling leve.
     * Substitui o antigo `setInterval(checkAllHosts, 10000)`: esse loop fazia
     * ping a TODOS os hosts dentro do pedido HTTP, empilhava pedidos quando o
     * PHP demorava e redesenhava a página a cada 10 s — era o "piscar".
     */
    startRealtime() {
        this.realtime = new LabControlRealtime({
            streamURL: (since) => api.getStateStreamURL(since),
            fetchVersion: (etag) => api.getStateVersion(etag),
            fallbackIntervalMs: REALTIME_FALLBACK_MS,
            onState: (change) => this.applyStateChange(change),
            onStatus: (status) => this.setRealtimeStatus(status),
            onUnsupported: () => this.startLegacyMonitor()
        });
        this.realtime.start();
    }

    /**
     * Compatibilidade com backends ainda sem `state.php`: mantém o estado
     * atualizado, mas a 30 s (não 10 s), com guarda contra pedidos empilhados e
     * usando o MESMO refresco incremental — portanto também não pisca.
     * Desaparece assim que o backend for atualizado.
     */
    startLegacyMonitor() {
        if (this.legacyTimer) return;
        console.warn('state.php não encontrado: monitor de compatibilidade ativo.');
        this.setRealtimeStatus({
            mode: 'legacy',
            detail: 'Atualize o backend para usar SSE (state.php)'
        });
        this.legacyTimer = setInterval(async () => {
            if (document.visibilityState === 'hidden') return;
            if (this.forceChecking || this.refreshing) return;
            try {
                await api.checkAllHosts();
                await this.applyStateChange({ changed: ['hosts', 'logs'], source: 'legacy' });
            } catch (error) {
                console.warn('Verificação de compatibilidade falhou:', error.message);
            }
        }, LEGACY_INTERVAL_MS);
    }

    startConnectivityEvents() {
        window.addEventListener('api:offline', () => {
            if (this.realtime && !this.realtime.closed) this.realtime.setPauseReason('network', true);
        });
        window.addEventListener('api:online', () => {
            if (this.realtime && !this.realtime.closed) this.realtime.setPauseReason('network', false);
        });
    }

    /**
     * Refresco incremental: pede só o que mudou e toca apenas nos elementos
     * afetados. Sem navigate(), sem skeleton, sem location.reload().
     */
    async applyStateChange(change = {}) {
        if (this.refreshing) {
            this.pendingChange = this.mergeChanges(this.pendingChange, change);
            return;
        }
        this.refreshing = true;
        try {
            const changed = Array.isArray(change.changed) && change.changed.length
                ? [...new Set(change.changed)]
                : ['hosts', 'logs'];

            const diff = await this.loadDashboardData({
                force: true,
                hosts: changed.includes('hosts'),
                logs: changed.includes('logs')
            });

            if (diff.hostsChanged) {
                this.renderSidebar();
                this.renderDashboard(false); // sem animação em background
                this.updateMainChart(false);
                this.updateHostStatusBadge();
                // Só abre uma sessão WinRM se o estado do host aberto mudou.
                if (diff.openHostStatusChanged) this.updateHostDetailDynamic();
            } else if (diff.logsChanged) {
                this.renderDashboard(false);
            }

            if (diff.logsChanged && this.currentView === 'audit') {
                await this.loadAuditLogs();
            }

            this.markUpdated(change.source || 'push');
        } catch (error) {
            console.error('Atualização em tempo real falhou:', error);
        } finally {
            this.refreshing = false;
            if (this.pendingChange) {
                const next = this.pendingChange;
                this.pendingChange = null;
                this.applyStateChange(next);
            }
        }
    }

    mergeChanges(previous, next) {
        if (!previous) return next;
        return {
            ...next,
            changed: [...new Set([...(previous.changed || []), ...(next.changed || [])])]
        };
    }

    /** Ação manual do botão de sincronização: UM check-all, nunca um loop. */
    async forceHostCheck() {
        if (this.forceChecking) return;
        this.forceChecking = true;
        const button = document.getElementById('manual-sync');
        if (button) button.classList.add('animate-spin');
        try {
            await api.checkAllHosts();
            await this.applyStateChange({ changed: ['hosts', 'logs'], source: 'manual' });
            this.notify('Verificação de hosts concluída', 'success');
        } catch (error) {
            this.notify('Falha na verificação: ' + (error.message || 'sem resposta'), 'error');
        } finally {
            this.forceChecking = false;
            if (button) button.classList.remove('animate-spin');
        }
    }

    /** Cabeçalho: só muda quando há dados novos (antes havia um timer de 1 s). */
    markUpdated(reason = '') {
        const el = document.getElementById('sync-time');
        if (el) {
            el.textContent = `SYNC: ${new Date().toLocaleTimeString()}`;
            el.title = `Última atualização: ${reason || 'tempo real'}`;
        }
    }

    setRealtimeStatus({ mode, detail = '' }) {
        const el = document.getElementById('realtime-status');
        const dot = document.getElementById('realtime-dot');
        const styles = {
            idle:         ['A INICIAR', '#94a3b8'],
            connecting:   ['A LIGAR', '#fbbf24'],
            sse:          ['LIVE · SSE', '#4ade80'],
            reconnecting: ['A RELIGAR', '#fbbf24'],
            polling:      [`POLL · ${Math.round(REALTIME_FALLBACK_MS / 1000)}s`, '#fbbf24'],
            paused:       ['PAUSADO', '#94a3b8'],
            unsupported:  ['LEGACY', '#fbbf24'],
            legacy:       [`LEGACY · ${Math.round(LEGACY_INTERVAL_MS / 1000)}s`, '#fbbf24'],
            offline:      ['OFFLINE', '#f87171']
        };
        const [label, color] = styles[mode] || styles.idle;
        if (el) {
            el.textContent = label;
            el.style.color = color;
            el.title = detail || 'Canal de atualizações em tempo real';
        }
        if (dot) dot.style.backgroundColor = color;
    }

    /**
     * Processos remotos (WinRM) são caros: ficam num timer próprio e bem mais
     * espaçado, apenas com a aba visível e a vista de detalhe aberta.
     */
    startProcessRefresh() {
        if (this.processTimer) clearInterval(this.processTimer);
        this.processTimer = setInterval(() => {
            if (document.visibilityState === 'hidden') return;
            if (this.currentView !== 'host-detail' || !this.currentHostId) return;
            this.updateHostDetailDynamic();
        }, PROCESS_REFRESH_MS);
    }

    async enterApp() {
        document.getElementById('app').classList.remove('opacity-0');
        await this.loadDashboardData();
        this.renderSidebar();
        await this.navigate('dashboard');
    }

    /**
     * Carrega hosts e/ou logs e devolve o que mudou de facto.
     * As assinaturas evitam re-render quando o SSE dispara mas os dados são
     * iguais (ex.: dois eventos seguidos, ou `hello` após reconexão).
     * `force: false` + `dataLoaded` evita o duplo pedido enterApp -> navigate.
     */
    async loadDashboardData({ force = false, hosts: withHosts = true, logs: withLogs = true } = {}) {
        const diff = { hostsChanged: false, logsChanged: false, openHostStatusChanged: false };
        if (!force && this.dataLoaded) return diff;
        if (!withHosts && !withLogs) return diff;

        const previousOpenStatus = this.hosts.find(h => h.id === this.currentHostId)?.status;

        try {
            const [hostsRes, logsRes] = await Promise.all([
                withHosts ? api.getHosts() : Promise.resolve(null),
                withLogs ? api.getLogs({ limit: 8 }) : Promise.resolve(null)
            ]);

            if (hostsRes && hostsRes.success) {
                const rawHosts = hostsRes.data?.hosts || [];
                this.clusterStats = hostsRes.data?.stats || {};
                this.hosts = rawHosts.map(h => ({
                    id: String(h.id),
                    name: h.hostname || 'Unnamed',
                    ip: h.ip || '',
                    status: h.status || 'unknown',
                    mac: h.mac_address || '',
                    cpu: h.cpu_usage || 0,
                    ram: h.memory_usage || 0,
                    is_active: h.is_active ?? 1
                }));

                const signature = JSON.stringify([
                    this.hosts.map(h => [h.id, h.name, h.ip, h.status, h.mac, h.cpu, h.ram, h.is_active]),
                    this.clusterStats
                ]);
                diff.hostsChanged = signature !== this.hostsSignature;
                this.hostsSignature = signature;

                const newOpenStatus = this.hosts.find(h => h.id === this.currentHostId)?.status;
                diff.openHostStatusChanged = Boolean(
                    previousOpenStatus && newOpenStatus && previousOpenStatus !== newOpenStatus
                );
            }

            if (logsRes && logsRes.success) {
                const rawLogs = logsRes.data?.logs || [];
                this.logs = rawLogs.map(l => ({
                    user: l.user_email || 'system',
                    action: l.action || 'Unknown',
                    target: l.host_ip || 'System',
                    time: l.timestamp ? new Date(l.timestamp).toLocaleTimeString() : '--:--',
                    status: l.status || 'success'
                }));

                const signature = JSON.stringify(this.logs);
                diff.logsChanged = signature !== this.logsSignature;
                this.logsSignature = signature;
            }

            if ((hostsRes && hostsRes.success) || (logsRes && logsRes.success)) {
                this.dataLoaded = true;
            }
        } catch (error) {
            console.error('Load error:', error);
        }
        return diff;
    }

    renderSidebar() {
        this.safeUpdateHTML('host-list', this.hosts.map(host => Components.HostItem(host, host.id === this.currentHostId)).join(''));
    }

    async navigate(viewId, params = {}) {
        const skeleton = document.getElementById('skeleton-loader');
        const views = document.querySelectorAll('.page-view');
        const navLinks = document.querySelectorAll('.nav-link');

        document.getElementById('sidebar')?.classList.add('-translate-x-full');
        document.getElementById('sidebar-overlay')?.classList.add('hidden');

        const target = document.getElementById(`view-${viewId === 'host-detail' ? 'host-detail' : viewId}`);
        if (!target) return;

        // O skeleton só aparece no PRIMEIRO carregamento. Depois disso os dados
        // já estão em memória e são mantidos pelo SSE: esconder todas as views
        // a cada navegação era outra fonte do "piscar" da página.
        const showSkeleton = !params.silent && !this.dataLoaded;
        if (skeleton && showSkeleton) {
            skeleton.innerHTML = Components.Skeleton();
            skeleton.classList.remove('hidden');
        }
        views.forEach(v => {
            if (v !== target) v.classList.add('hidden', 'opacity-0');
        });

        await new Promise(r => setTimeout(r, showSkeleton ? 150 : 40));

        navLinks.forEach(link => {
            const isTarget = link.dataset.nav === viewId || (viewId === 'host-detail' && link.dataset.id === params.id);
            if (isTarget) link.classList.add('active-nav');
            else link.classList.remove('active-nav');
        });

        if (!params.silent) {
            target.classList.remove('hidden');
            setTimeout(() => {
                target.classList.remove('opacity-0');
                if (skeleton) skeleton.classList.add('hidden');
            }, 50);
        }

        try {
            this.currentView = viewId;
            if (viewId === 'host-detail') {
                this.currentHostId = params.id;
            }
            await this.loadViewData(viewId, params);
        } catch (error) {
            console.error('Nav load error:', error);
        }
    }

    async loadViewData(viewId, params) {
        const viewTitle = document.getElementById('view-title');
        try {
            switch(viewId) {
                case 'dashboard':
                    if (viewTitle) viewTitle.innerText = 'Dashboard';
                    await this.loadDashboardData();
                    // Sem fade-in-up nos cartões: a transição da própria view já
                    // dá feedback, e reanimar KPIs a cada navegação parecia um
                    // "refresh" da página.
                    this.renderDashboard(false);
                    // Delay para garantir que o canvas esteja no DOM
                    setTimeout(() => this.initMainChart(), 100);
                    break;
                case 'audit':
                    if (viewTitle) viewTitle.innerText = 'Audit Logs';
                    await this.loadAuditLogs();
                    break;
                case 'host-detail':
                    if (params.id) await this.loadHostDetail(params.id);
                    break;
            }
        } catch (error) {
            this.notify('Erro ao atualizar view', 'error');
        }
    }

    renderDashboard(animate = false) {
        const onlineCount = parseInt(this.clusterStats?.online_count || this.hosts.filter(h => h.status === 'online').length);
        const offlineCount = parseInt(this.clusterStats?.offline_count || this.hosts.filter(h => h.status === 'offline').length);
        const avgCpu = parseFloat(this.clusterStats?.avg_cpu || 0);
        const avgMem = parseFloat(this.clusterStats?.avg_mem || 0);
        
        const health = onlineCount > 0 ? Math.round(100 - (avgCpu * 0.4 + avgMem * 0.2)) + '%' : '0%';

        const icons = {
            server: '<rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
            cpu: '<path d="M4 4h16v16H4V4z"/><path d="M9 4V2"/><path d="M15 4V2"/><path d="M9 22v-2"/><path d="M15 22v-2"/><path d="M20 9h2"/><path d="M20 15h2"/><path d="M2 9h2"/><path d="M2 15h2"/><path d="M12 9v6"/><path d="M9 12h6"/>',
            ram: '<path d="M6 19V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v14"/><path d="M6 19h12"/><path d="M6 7h12"/><path d="M6 11h12"/><path d="M6 15h12"/><path d="M10 3v4"/><path d="M14 3v4"/>',
            'heart': '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/>'
        };

        const kpiHTML = `
            ${Components.KPICard('Avg CPU', avgCpu.toFixed(1) + '%', icons.cpu, avgCpu > 80 ? 'text-red-400' : 'text-blue-400', 0, animate)}
            ${Components.KPICard('Avg RAM', avgMem.toFixed(1) + '%', icons.ram, avgMem > 85 ? 'text-red-400' : 'text-green-400', 100, animate)}
            ${Components.KPICard('Nodes Online', onlineCount, icons.server, 'text-indigo-400', 200, animate)}
            ${Components.KPICard('Health', health, icons.heart, 'text-amber-400', 300, animate)}
        `;

        this.safeUpdateHTML('kpi-grid', kpiHTML);
        this.safeUpdateHTML('log-feed', this.logs.slice(0, 8).map(log => Components.LogItem(log)).join(''));
    }

    async loadAuditLogs() {
        try {
            const res = await api.getLogs({ limit: 50 });
            const container = document.getElementById('audit-logs-container');
            if (res?.success && container) {
                const logs = res.data?.logs || [];
                const tableHTML = `
                    <div class="glass-panel rounded-[2rem] overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-black/20 font-black text-gray-500 uppercase text-[10px] tracking-widest">
                                <tr><th class="px-8 py-4">Time</th><th class="px-8 py-4">User</th><th class="px-8 py-4">Action</th><th class="px-8 py-4">Target</th><th class="px-8 py-4">Status</th></tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.03]">
                                ${logs.length > 0 ? logs.map(l => `
                                    <tr class="hover:bg-white/[0.02] transition-colors">
                                        <td class="px-8 py-4 text-xs text-gray-400 font-mono">${new Date(l.timestamp).toLocaleString()}</td>
                                        <td class="px-8 py-4 text-xs text-white font-bold">${l.user_email || 'system'}</td>
                                        <td class="px-8 py-4 text-xs text-gray-300">${l.action}</td>
                                        <td class="px-8 py-4 text-xs text-gray-500 font-mono">${l.host_ip || '-'}</td>
                                        <td class="px-8 py-4">
                                            <span class="px-2 py-1 rounded-lg text-[10px] font-bold uppercase ${l.status === 'success' ? 'bg-green-500/10 text-green-400' : 'bg-red-500/10 text-red-400'}">${l.status}</span>
                                        </td>
                                    </tr>
                                `).join('') : '<tr><td colspan="5" class="px-8 py-10 text-center text-gray-500 text-xs">Nenhum log encontrado</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                `;
                this.safeUpdateHTML('audit-logs-container', tableHTML);
            }
        } catch (e) { this.notify('Erro ao carregar logs', 'error'); }
    }

    /** Botões do detalhe: re-renderizáveis isoladamente quando o status muda. */
    renderHostActions(host) {
        return `
            <button data-action="edit-host" data-id="${host.id}" class="w-12 h-12 glass-card rounded-2xl flex items-center justify-center text-blue-400 hover:bg-blue-500/10 transition-all"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg></button>
            ${host.status !== 'online' ? `
                <button data-action="wol-host" data-ip="${host.ip}" data-mac="${host.mac}" class="w-12 h-12 glass-card rounded-2xl flex items-center justify-center text-green-500 hover:bg-green-500/10 transition-all" title="Ligar Host"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg></button>
            ` : ''}
            <button data-action="restart-host" data-hostname="${lcEscape(host.name)}" class="w-12 h-12 glass-card rounded-2xl flex items-center justify-center text-amber-500 hover:bg-amber-500/10 transition-all"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg></button>
            <button data-action="shutdown-host" data-hostname="${lcEscape(host.name)}" class="w-12 h-12 glass-card rounded-2xl flex items-center justify-center text-red-500 hover:bg-red-500/10 transition-all"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" x2="12" y1="2" y2="12"/></svg></button>
        `;
    }

    /**
     * Patch do badge/ações do host aberto SEM reconstruir a vista inteira
     * (o terminal PowerShell guarda histórico e não pode ser apagado).
     */
    updateHostStatusBadge() {
        if (this.currentView !== 'host-detail' || !this.currentHostId) return;
        const host = this.hosts.find(h => h.id === this.currentHostId);
        if (!host) return;

        const dot = document.getElementById('host-status-dot');
        const text = document.getElementById('host-status-text');
        if (dot) dot.className = `w-2 h-2 rounded-full ${host.status === 'online' ? 'bg-green-400 animate-pulse' : 'bg-gray-500'}`;
        if (text) text.textContent = String(host.status).toUpperCase();
        if (document.getElementById('host-actions')) {
            this.safeUpdateHTML('host-actions', this.renderHostActions(host));
        }
    }

    async loadHostDetail(id) {
        this.currentHostId = id;
        const host = this.hosts.find(h => h.id === id);
        if (!host) return;

        const viewTitle = document.getElementById('view-title');
        if (viewTitle) viewTitle.innerText = `Node: ${host.name}`;
        
        let processes = [];
        this.processError = null;
        if (host.status === 'online') {
            try {
                const procRes = await api.getProcesses(host.name);
                if (procRes.success) {
                    processes = this.normalizeProcesses(procRes.data?.processes);
                } else {
                    this.processError = procRes.message || 'Falha ao obter processos';
                }
            } catch (e) {
                this.processError = e.message || 'Falha ao obter processos';
            }
        }

        const detailHTML = `
            <div class="space-y-8 fade-in-up">
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                    <div>
                        <div class="flex items-center gap-3 mb-3">
                            <span class="px-3 py-1 rounded-lg bg-blue-500/10 text-blue-400 text-[10px] font-black uppercase tracking-tighter border border-blue-500/20">WinRM</span>
                            <div id="host-status-badge" class="flex items-center gap-2 px-3 py-1 rounded-lg bg-white/5 border border-white/5">
                                <span id="host-status-dot" class="w-2 h-2 rounded-full ${host.status === 'online' ? 'bg-green-400 animate-pulse' : 'bg-gray-500'}"></span>
                                <span id="host-status-text" class="text-[10px] font-bold text-gray-400 uppercase">${host.status}</span>
                            </div>
                        </div>
                        <h1 class="text-4xl font-extrabold tracking-tight text-white mb-2">${lcEscape(host.name)}</h1>
                        <div class="flex items-center gap-4 text-gray-500 text-sm font-medium">
                            <span class="font-mono">${host.ip}</span>
                            <span class="font-mono">${host.mac || 'No MAC'}</span>
                        </div>
                    </div>
                    <div class="flex gap-3" id="host-actions">${this.renderHostActions(host)}</div>
                </div>
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">
                    <div class="glass-panel rounded-[2rem] overflow-hidden flex flex-col h-[500px]">
                        <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex justify-between items-center">
                            <h3 class="font-bold text-sm text-white">Gerenciador de Processos</h3>
                            <span class="text-[10px] text-gray-500 font-mono">TOTAL: <span id="process-total">${processes.length}</span></span>
                        </div>
                        <div class="overflow-y-auto flex-1 custom-scrollbar" id="process-table-container">
                            ${this.renderProcessTable(host, processes, this.processError)}
                        </div>
                    </div>
                    <div class="bg-[#050505] border border-white/10 rounded-[2rem] overflow-hidden flex flex-col h-[500px]">
                        <div class="px-6 py-4 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-[0.2em]">PowerShell Terminal</span>
                        </div>
                        <div id="terminal-output" class="flex-1 p-6 font-mono text-xs overflow-y-auto text-blue-100/70 custom-scrollbar"><div class="text-gray-500 mb-2">// Ready for commands on ${host.name}</div></div>
                        <div class="p-5 bg-black/40 border-t border-white/5 flex items-center gap-3"><span class="text-blue-400 font-mono text-xs font-bold">λ</span><input type="text" id="terminal-input" class="flex-1 bg-transparent border-none outline-none text-xs font-mono text-white" placeholder="Type command..."></div>
                    </div>
                </div>
            </div>
        `;
        
        this.safeUpdateHTML('view-host-detail', detailHTML);

        const input = document.getElementById('terminal-input');
        if (input) {
            input.addEventListener('keydown', (e) => { 
                if (e.key === 'Enter' && input.value.trim()) { 
                    this.executeCommand(host.name, input.value.trim()); 
                    input.value = ''; 
                } 
            });
        }
        this.renderSidebar();
    }

    // O backend pode devolver uma lista, um único objecto ou nada.
    normalizeProcesses(data) {
        if (!data) return [];
        const list = Array.isArray(data) ? data : [data];
        return list.filter(p => p && typeof p === 'object').slice(0, 15);
    }

    renderProcessTable(host, processes, error = null) {
        if (host.status !== 'online') {
            return '<div class="flex items-center justify-center h-full text-gray-500 text-sm">Host offline - Sem dados de processos</div>';
        }
        const list = this.normalizeProcesses(processes);
        if (error) {
            return `<div class="flex flex-col items-center justify-center h-full gap-2 px-8 text-center">
                        <span class="text-amber-400 text-sm font-bold">Processos indisponíveis</span>
                        <span class="text-gray-500 text-xs">${lcEscape(error)}</span>
                        <span class="text-gray-600 text-[10px]">Verifique WinRM, TrustedHosts e REMOTE_USER/REMOTE_PASSWORD no servidor.</span>
                    </div>`;
        }
        if (list.length === 0) {
            return '<div class="flex items-center justify-center h-full text-gray-500 text-sm">Nenhum processo devolvido pelo host</div>';
        }

        return `
            <table class="w-full text-left">
                <thead class="bg-black/20 sticky top-0 font-black text-gray-600 uppercase text-[9px] tracking-widest z-10">
                    <tr>
                        <th class="px-8 py-4">Processo</th>
                        <th class="px-8 py-4">PID</th>
                        <th class="px-8 py-4">CPU</th>
                        <th class="px-8 py-4 text-right">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.03]">
                    ${list.map(p => {
                        const pid = p.Id || p.pid;
                        const name = p.ProcessName || p.Name || p.name;
                        const cpu = p.CPU;
                        return `
                            <tr class="hover:bg-white/[0.02] group transition-colors">
                                <td class="px-8 py-4 text-xs text-gray-300 font-bold">${lcEscape(name)}</td>
                                <td class="px-8 py-4 font-mono text-[10px] text-gray-500">${pid}</td>
                                <td class="px-8 py-4 text-xs text-gray-400">${cpu == null ? '—' : Number(cpu).toFixed(1) + '%'}</td>
                                <td class="px-8 py-4 text-right">
                                    <button data-action="kill-process" data-hostname="${host.name}" data-pid="${pid}" data-process-name="${name}" 
                                        class="p-2 text-gray-600 hover:text-red-500 hover:bg-red-500/10 rounded-lg transition-all opacity-0 group-hover:opacity-100" title="Finalizar Processo">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                    </button>
                                </td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        `;
    }

    async updateHostDetailDynamic() {
        if (this.currentView !== 'host-detail' || !this.currentHostId) return;
        const host = this.hosts.find(h => h.id === this.currentHostId);
        if (!host || host.status !== 'online') return;
        // Eventos SSE + timer de PROCESS_REFRESH_MS podem coincidir: não abrir
        // outra sessão WinRM enquanto a anterior ainda está a responder.
        if (this.processesLoading) return;
        this.processesLoading = true;

        let processes = [];
        let error = null;
        try {
            const procRes = await api.getProcesses(host.name);
            if (procRes.success) {
                processes = this.normalizeProcesses(procRes.data?.processes);
            } else {
                error = procRes.message || 'Falha ao obter processos';
            }
        } catch (e) {
            error = e.message || 'Falha ao obter processos';
        } finally {
            this.processesLoading = false;
        }

        // Só escrever na consola quando o erro muda, em vez de em cada consulta.
        if (error && error !== this.processError) {
            console.warn('Processos indisponíveis em ' + host.name + ':', error);
        }
        this.processError = error;
        this.safeUpdateHTML('process-table-container', this.renderProcessTable(host, processes, error));
        const total = document.getElementById('process-total');
        if (total) total.textContent = String(this.normalizeProcesses(processes).length);
    }

    async handleKillProcess(hostname, pid, processName) {
        if (!confirm(`Deseja finalizar o processo "${processName}" (PID: ${pid}) em ${hostname}?`)) return;
        
        try {
            this.notify(`Finalizando ${processName}...`, 'info');
            const res = await api.killProcess(hostname, pid, processName, true);
            if (res.success) {
                this.notify('✅ Processo encerrado', 'success');
                this.updateHostDetailDynamic();
            } else {
                this.notify('❌ Falha: ' + res.message, 'error');
            }
        } catch (e) {
            this.notify('Erro ao encerrar processo: ' + (e.message || 'falha de comunicação'), 'error');
        }
    }

    async executeCommand(hostname, command) {
        const output = document.getElementById('terminal-output');
        output.innerHTML += `<div class="mb-1 text-white"><span class="text-blue-400 font-bold">λ</span> ${command}</div><div class="text-gray-400 mb-2 ml-4">Executing...</div>`;
        output.scrollTop = output.scrollHeight;
        try {
            const res = await api.executeCommand(hostname, command);
            if (res.success && res.data?.output) {
                res.data.output.split('\n').slice(0, 20).forEach(line => { if (line.trim()) output.innerHTML += `<div class="text-green-400/80 ml-4 font-mono text-[11px]">${this.escapeHtml(line)}</div>`; });
            } else output.innerHTML += `<div class="text-red-400 ml-4">Error: ${res.message}</div>`;
        } catch (e) { output.innerHTML += `<div class="text-red-400 ml-4">${this.escapeHtml(e.message || 'Connection failed')}</div>`; }
        output.scrollTop = output.scrollHeight;
    }

    escapeHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

    async handleShutdown(hostname) {
        if (!confirm(`Desligar ${hostname}?`)) return;
        try {
            this.notify('Enviando comando...', 'info');
            const res = await api.shutdownHost(hostname);
            this.notify(res.success ? '✅ Comando enviado' : '❌ Falha: ' + res.message, res.success ? 'success' : 'error');
        } catch (e) { this.notify('Erro na requisição: ' + (e.message || 'falha de comunicação'), 'error'); }
    }

    async handleShutdownAll() {
        if (!confirm('DESLIGAR TODOS OS HOSTS ONLINE? Esta ação não pode ser desfeita.')) return;
        try {
            this.notify('Iniciando desligamento em massa...', 'info');
            const res = await api.shutdownAllHosts();
            if (res.success) {
                this.notify(`✅ ${res.data.success_count} hosts desligados`, 'success');
                if (res.data.failed_count > 0) {
                    this.notify(`⚠️ Falha em ${res.data.failed_count} hosts`, 'warning');
                }
                await this.loadDashboardData({ force: true });
                this.renderDashboard(false);
                this.renderSidebar();
            } else {
                this.notify('❌ Falha: ' + res.message, 'error');
            }
        } catch (e) { this.notify('Erro na requisição em massa: ' + (e.message || 'falha de comunicação'), 'error'); }
    }

    async handleRestart(hostname) {
        if (!confirm(`Reiniciar ${hostname}?`)) return;
        try {
            this.notify('Enviando comando...', 'info');
            const res = await api.restartHost(hostname);
            this.notify(res.success ? '✅ Comando enviado' : '❌ Falha: ' + res.message, res.success ? 'success' : 'error');
        } catch (e) { this.notify('Erro na requisição: ' + (e.message || 'falha de comunicação'), 'error'); }
    }

    async handleWakeOnLan(ip, mac) {
        try {
            this.notify('Enviando pacote WOL...', 'info');
            const res = await api.wakeOnLan(ip, mac);
            this.notify(res.success ? '✅ WOL enviado' : '❌ Falha', res.success ? 'success' : 'error');
        } catch (e) { this.notify('Erro WOL: ' + (e.message || 'falha de comunicação'), 'error'); }
    }

    async handleWakeOnLanAll() {
        if (!confirm('Ligar todos os hosts offline via Wake-on-LAN?')) return;
        try {
            this.notify('Enviando pacotes WOL em massa...', 'info');
            const res = await api.wakeOnLanAll();
            if (res.success) {
                this.notify(`✅ ${res.data.success_count} pacotes enviados`, 'success');
                if (res.data.failed_count > 0) {
                    this.notify(`⚠️ Falha em ${res.data.failed_count} hosts (sem MAC?)`, 'warning');
                }
            } else {
                this.notify('❌ Falha: ' + res.message, 'error');
            }
        } catch (e) { this.notify('Erro na requisição WOL em massa: ' + (e.message || 'falha de comunicação'), 'error'); }
    }

    notify(m, t = 'success', d = 4000) {
        const c = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `glass-panel px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-4 animate-slide-in border-l-4 ${t==='error'?'border-red-500':'border-green-500'}`;
        // As mensagens podem incluir data.error devolvido pelo PowerShell;
        // escapar antes de as inserir como HTML.
        toast.innerHTML = `<p class="text-sm font-bold text-white">${lcEscape(m)}</p>`;
        c.appendChild(toast);
        setTimeout(() => toast.remove(), d);
    }

    initMainChart() {
        const canvas = document.getElementById('mainChart');
        if (!canvas || typeof Chart === 'undefined') return;
        
        if (this.charts.main) {
            this.updateMainChart();
            return;
        }

        const ctx = canvas.getContext('2d');
        
        // Estilo para CPU
        const gradCpu = ctx.createLinearGradient(0, 0, 0, 300);
        gradCpu.addColorStop(0, 'rgba(99, 102, 241, 0.3)');
        gradCpu.addColorStop(1, 'rgba(99, 102, 241, 0)');

        // Estilo para RAM
        const gradRam = ctx.createLinearGradient(0, 0, 0, 300);
        gradRam.addColorStop(0, 'rgba(16, 185, 129, 0.2)');
        gradRam.addColorStop(1, 'rgba(16, 185, 129, 0)');

        this.charts.main = new Chart(ctx, {
            type: 'line',
            data: {
                labels: Array(12).fill('').map((_, i) => `${(11-i)*10}s`),
                datasets: [
                    {
                        label: 'CPU Usage',
                        data: Array(12).fill(0),
                        borderColor: '#818cf8',
                        borderWidth: 3,
                        backgroundColor: gradCpu,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#818cf8'
                    },
                    {
                        label: 'RAM Usage',
                        data: Array(12).fill(0),
                        borderColor: '#10b981',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        backgroundColor: gradRam,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#10b981'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: { color: '#94a3b8', boxWidth: 10, font: { size: 10, weight: 'bold' }, usePointStyle: true }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#94a3b8',
                        titleFont: { size: 10 },
                        bodyFont: { size: 12, weight: 'bold' },
                        borderColor: 'rgba(255,255,255,0.1)',
                        borderWidth: 1,
                        padding: 12,
                        displayColors: true,
                        callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(1)}%` }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#475569', font: { size: 9 } }
                    },
                    y: {
                        min: 0, max: 100,
                        grid: { color: 'rgba(255,255,255,0.03)' },
                        ticks: { color: '#475569', font: { size: 9 }, stepSize: 25, callback: (v) => v + '%' }
                    }
                }
            }
        });
    }

    updateMainChart(animate = false) {
        if (!this.charts.main) return;

        let cpuVal = parseFloat(this.clusterStats?.avg_cpu || 0);
        let ramVal = parseFloat(this.clusterStats?.avg_mem || 0);
        
        // A view v_host_stats não fornece avg_cpu/avg_mem. A simulação antiga
        // corria a cada 10 s e animava o gráfico mesmo sem dados novos — outra
        // fonte de "piscar". Agora só corre em mudanças reais de estado e pode
        // ser desligada com window.LABCONTROL_CHART_SIMULATION = false.
        if (CHART_SIMULATION && cpuVal === 0 && ramVal === 0) {
            const onlineCount = this.hosts.filter(h => h.status === 'online').length;
            const total = this.hosts.length || 1;
            const activityBase = (onlineCount / total) * 30;
            cpuVal = activityBase + (Math.random() * 20);
            ramVal = activityBase + 40 + (Math.random() * 10);
        }

        const chart = this.charts.main;
        const now = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });

        chart.data.labels.push(now);
        chart.data.datasets[0].data.push(cpuVal);
        chart.data.datasets[1].data.push(ramVal);

        if (chart.data.labels.length > 12) {
            chart.data.labels.shift();
            chart.data.datasets.forEach(d => d.data.shift());
        }

        // 'none' = redesenho sem animação; não há flash a cada atualização.
        chart.update(animate ? 'active' : 'none');
    }

    attachEventListeners() {
        document.addEventListener('click', (e) => {
            const target = e.target;

            // Verificação manual (um único check-all, a pedido do operador).
            if (target.closest('#manual-sync')) {
                this.forceHostCheck();
                return;
            }

            const navBtn = target.closest('[data-nav]');
            if (navBtn) {
                const view = navBtn.dataset.nav;
                if (view === 'hosts' && navBtn.dataset.id) this.navigate('host-detail', { id: navBtn.dataset.id });
                else this.navigate(view);
                return;
            }
            const actionBtn = target.closest('[data-action]');
            if (actionBtn) {
                const a = actionBtn.dataset.action;
                const h = actionBtn.dataset.hostname;
                const ip = actionBtn.dataset.ip;
                if (a === 'edit-host') this.showHostModal(actionBtn.dataset.id);
                if (a === 'add-host') this.showHostModal();
                if (a === 'discover-host') this.handleDiscoverHost();
                if (a === 'close-modal') this.hideHostModal();
                if (a === 'restart-host') this.handleRestart(h);
                if (a === 'shutdown-host') this.handleShutdown(h);
                if (a === 'wol-host') this.handleWakeOnLan(ip, actionBtn.dataset.mac);
                if (a === 'kill-process') this.handleKillProcess(h, actionBtn.dataset.pid, actionBtn.dataset.processName);
                return;
            }
            if (target.id === 'modal-backdrop') this.hideHostModal();
            if (target.closest('#delete-host-btn')) this.handleDeleteHost();
            if (target.closest('#shutdown-all-btn')) this.handleShutdownAll();
            if (target.closest('#wol-all-btn')) this.handleWakeOnLanAll();
        });
    }

    showHostModal(id = null) {
        const modal = document.getElementById('host-modal');
        const form = document.getElementById('host-form');
        const deleteButton = document.getElementById('delete-host-btn');
        form.reset();
        document.getElementById('host-id').value = '';
        document.getElementById('host-modal-title').innerText = id ? 'Edit Host' : 'Add New Host';
        deleteButton.classList.toggle('hidden', !id);

        // Inserção: pede-se apenas o hostname — IP e MAC são preenchidos pela
        // consulta automática (ping -4 + arp -a). Na edição mantêm-se editáveis.
        const ipField = document.getElementById('host-ip');
        const macField = document.getElementById('host-mac');
        const discoverBtn = document.getElementById('discover-host-btn');
        const hint = document.getElementById('host-form-hint');
        ipField.readOnly = !id;
        macField.readOnly = !id;
        ipField.classList.toggle('opacity-60', !id);
        macField.classList.toggle('opacity-60', !id);
        discoverBtn.classList.toggle('hidden', !!id);
        hint?.classList.toggle('hidden', !!id);

        if (id) {
            const host = this.hosts.find(h => h.id == id);
            if (!host) return this.notify('Host não encontrado', 'error');
            document.getElementById('host-id').value = host.id;
            document.getElementById('host-name').value = host.name;
            ipField.value = host.ip;
            macField.value = host.mac;
        }
        modal.classList.remove('hidden');
        setTimeout(() => { modal.classList.remove('opacity-0'); modal.querySelector('#modal-content').classList.remove('translate-x-full'); }, 50);
    }

    hideHostModal() {
        const modal = document.getElementById('host-modal');
        modal.querySelector('#modal-content').classList.add('translate-x-full');
        modal.classList.add('opacity-0');
        setTimeout(() => modal.classList.add('hidden'), 500);
    }

    async handleDiscoverHost() {
        const hostname = document.getElementById('host-name').value.trim();
        if (!hostname) {
            this.notify('Indique o hostname para consultar', 'error');
            return false;
        }

        const btn = document.getElementById('discover-host-btn');
        const prevHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="animate-spin pointer-events-none"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> A consultar...`;

        try {
            const res = await api.discoverHost(hostname);
            if (!res.success || !res.data?.ip) {
                this.notify(res.message || 'Host não encontrado na rede', 'error');
                return false;
            }

            document.getElementById('host-ip').value = res.data.ip;
            document.getElementById('host-mac').value = res.data.mac_address || '';

            if (res.data.mac_address) {
                this.notify(`IP ${res.data.ip} · MAC ${res.data.mac_address} encontrados`, 'success');
            } else {
                this.notify(`IP ${res.data.ip} encontrado, mas o MAC não consta na tabela ARP`, 'error');
            }
            return true;
        } catch (e) {
            this.notify(e.message || 'Falha ao consultar o host', 'error');
            return false;
        } finally {
            btn.disabled = false;
            btn.innerHTML = prevHtml;
        }
    }

    async handleHostSubmit() {
        const id = document.getElementById('host-id').value;

        // Inserção sem consulta prévia: descobre IP/MAC automaticamente pelo hostname
        if (!id && !document.getElementById('host-ip').value.trim()) {
            const discovered = await this.handleDiscoverHost();
            if (!discovered) return;
        }

        const hostData = {
            hostname: document.getElementById('host-name').value.trim(),
            ip: document.getElementById('host-ip').value.trim(),
            mac_address: document.getElementById('host-mac').value.trim() || null
        };
        try {
            const res = id ? await api.updateHost(id, hostData) : await api.createHost(hostData);
            if (!res.success) return this.notify(res.message, 'error');

            this.notify(id ? 'Host atualizado' : 'Host registado', 'success');
            this.hideHostModal();
            await this.loadDashboardData({ force: true });
            this.renderSidebar();
            if (id && this.currentView === 'host-detail' && this.currentHostId === id) {
                await this.loadHostDetail(id);
            } else if (this.currentView === 'dashboard') {
                this.renderDashboard(false);
            }
        } catch (e) {
            this.notify(e.message || 'Falha ao guardar o host', 'error');
        }
    }

    async handleDeleteHost() {
        const id = document.getElementById('host-id').value;
        const host = this.hosts.find(h => h.id === id);
        if (!id || !host || !confirm(`Remover ${host.name} do LabControl?`)) return;

        try {
            const res = await api.deleteHost(id);
            if (!res.success) return this.notify(res.message, 'error');

            this.notify('Host removido', 'success');
            this.hideHostModal();
            if (this.currentHostId === id) this.currentHostId = null;
            await this.loadDashboardData({ force: true });
            this.renderSidebar();
            await this.navigate('dashboard');
        } catch (e) {
            this.notify(e.message || 'Falha ao remover o host', 'error');
        }
    }
}

window.app = new LabControlApp();
