import { api } from './api-service.js?v=1.1';

const Components = {
    AuthUI: () => `
        <div class="w-full max-w-md p-10 glass-panel rounded-[2rem] shadow-2xl animate-float relative overflow-hidden">
            <div class="absolute top-0 right-0 w-32 h-32 bg-blue-500/10 blur-[80px]"></div>
            <div class="flex flex-col items-center mb-10 relative z-10">
                <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-indigo-700 rounded-2xl flex items-center justify-center mb-5 shadow-[0_0_30px_rgba(59,130,246,0.5)]">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <h2 class="text-3xl font-extrabold text-white tracking-tight">LabControl</h2>
                <p class="text-gray-400 text-sm mt-2 font-medium">Infrastructure Management</p>
            </div>
            <form id="login-form" class="space-y-5 relative z-10">
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold text-gray-500 uppercase tracking-wider ml-1">Email</label>
                    <input type="email" id="login-email" placeholder="admin@labcontrol.local" value="admin@labcontrol.local"
                        class="w-full bg-white/5 border border-white/10 rounded-2xl px-5 py-4 text-sm focus:border-blue-500 focus:bg-white/10 outline-none transition-all placeholder:text-gray-600 text-white">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold text-gray-500 uppercase tracking-wider ml-1">Password</label>
                    <input type="password" id="login-password" placeholder="••••••••" 
                        class="w-full bg-white/5 border border-white/10 rounded-2xl px-5 py-4 text-sm focus:border-blue-500 focus:bg-white/10 outline-none transition-all placeholder:text-gray-600 text-white">
                </div>
                <button type="submit" id="auth-submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-4 rounded-2xl mt-4 shadow-[0_10px_25px_rgba(37,99,235,0.3)] transition-all active:scale-[0.98] flex items-center justify-center gap-2">
                    Initialize System <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </form>
            <div class="mt-6 text-center relative z-10">
                <p class="text-[10px] font-mono text-gray-600">2026 - Todos direitos reservados | Braulio Mac-Mahon</p>
            </div>
        </div>
    `,

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
                        <span class="text-xs font-bold tracking-tight text-white group-hover:text-blue-400 transition-colors">${host.name}</span>
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
        this.isAuthenticated = false;
        this.currentView = 'dashboard';
        this.sessionTimer = null;
        this.sessionInterval = null;
        this.sessionRemaining = 900; // 15 minutos em segundos
        this.lastActivityTime = Date.now();
        
        this.attachEventListeners();
        this.setupActivityListeners();
        this.init();
    }

    setupActivityListeners() {
        ['mousedown', 'keydown', 'touchstart', 'scroll'].forEach(event => {
            window.addEventListener(event, () => this.resetSessionTimer());
        });
    }

    startSessionTimer() {
        if (!this.isAuthenticated) return;
        if (this.sessionTimer) clearTimeout(this.sessionTimer);
        if (this.sessionInterval) clearInterval(this.sessionInterval);
        
        this.sessionRemaining = 900;
        this.updateSessionUI();
        
        this.sessionTimer = setTimeout(() => {
            this.handleAutoLogout();
        }, 900 * 1000);

        this.sessionInterval = setInterval(() => {
            if (this.sessionRemaining > 0) {
                this.sessionRemaining--;
                this.updateSessionUI();
            } else {
                clearInterval(this.sessionInterval);
            }
        }, 1000);
    }

    updateSessionUI() {
        const el = document.getElementById('session-countdown');
        if (!el) return;

        const minutes = Math.floor(this.sessionRemaining / 60);
        const seconds = this.sessionRemaining % 60;
        const formattedTime = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        
        if (el.innerText !== `SESSION: ${formattedTime}`) {
            el.innerText = `SESSION: ${formattedTime}`;
        }
        
        if (this.sessionRemaining < 120) {
            el.classList.add('text-red-500', 'animate-pulse');
            el.classList.remove('text-amber-500/80');
        } else {
            el.classList.remove('text-red-500', 'animate-pulse');
            el.classList.add('text-amber-500/80');
        }
    }

    // Função para atualizar o DOM sem "piscar"
    safeUpdateHTML(elementId, newHTML) {
        const el = document.getElementById(elementId);
        if (!el) return;
        
        // Criar um elemento temporário para comparar o conteúdo real (normalizado pelo browser)
        const temp = document.createElement('div');
        temp.innerHTML = newHTML;
        
        if (el.innerHTML !== temp.innerHTML) {
            el.innerHTML = newHTML;
        }
    }

    handleAutoLogout() {
        this.notify('🔴 Sessão expirada por inatividade', 'warning', 5000);
        setTimeout(() => {
            api.logout();
            location.reload();
        }, 2000);
    }

    resetSessionTimer() {
        if (!this.isAuthenticated) return;
        const now = Date.now();
        if (now - this.lastActivityTime > 10000) {
            this.lastActivityTime = now;
            this.startSessionTimer();
        }
    }

    async init() {
        await this.checkSession();
        this.startSyncTimer();
        this.startNetworkMonitor();
        
        document.getElementById('host-form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleHostSubmit();
        });
    }

    startNetworkMonitor() {
        // Só executa a primeira checagem se já estiver autenticado
        if (this.isAuthenticated) this.checkAllHosts();
        
        setInterval(() => {
            if (this.isAuthenticated) this.checkAllHosts();
        }, 10000);
    }

    async checkAllHosts() {
        try {
            const res = await api.checkAllHosts();
            if (res && res.success) {
                await this.loadDashboardData();
                this.renderSidebar();
                
                if (this.currentView === 'dashboard') {
                    this.renderDashboard(false); // Background update: no animation
                } else if (this.currentView === 'host-detail' && this.currentHostId) {
                    this.updateHostDetailDynamic();
                }
            }
        } catch (error) {
            console.error('Network monitor error:', error);
        }
    }

    async checkSession() {
        const token = localStorage.getItem('labcontrol_token');
        if (token) {
            try {
                await api.verifyToken();
                this.isAuthenticated = true;
                await this.enterApp();
            } catch (error) {
                this.showAuth();
            }
        } else {
            this.showAuth();
        }
    }

    showAuth() {
        this.isAuthenticated = false;
        const overlay = document.getElementById('auth-overlay');
        overlay.innerHTML = Components.AuthUI();
        overlay.classList.remove('opacity-0', 'pointer-events-none');
        document.getElementById('app').classList.add('opacity-0');
        
        document.getElementById('login-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleLogin();
        });
    }

    async enterApp() {
        const overlay = document.getElementById('auth-overlay');
        const app = document.getElementById('app');
        this.isAuthenticated = true;
        overlay.classList.add('opacity-0', 'pointer-events-none');
        app.classList.remove('opacity-0');
        await this.loadDashboardData();
        this.renderSidebar();
        this.startSessionTimer();
        this.navigate('dashboard');
    }

    async loadDashboardData() {
        try {
            const [hostsRes, logsRes] = await Promise.all([
                api.getHosts(),
                api.getLogs({ limit: 8 })
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
            }
        } catch (error) {
            console.error('Load error:', error);
        }
    }

    renderSidebar() {
        this.safeUpdateHTML('host-list', this.hosts.map(host => Components.HostItem(host, host.id === this.currentHostId)).join(''));
    }

    async navigate(viewId, params = {}) {
        const skeleton = document.getElementById('skeleton-loader');
        const views = document.querySelectorAll('.page-view');
        const navLinks = document.querySelectorAll('.nav-link');
        const viewTitle = document.getElementById('view-title');

        document.getElementById('sidebar')?.classList.add('-translate-x-full');
        document.getElementById('sidebar-overlay')?.classList.add('hidden');

        // Só mostra o skeleton se não for uma atualização automática
        if (skeleton && !params.silent) {
            skeleton.innerHTML = Components.Skeleton();
            skeleton.classList.remove('hidden');
            views.forEach(v => v.classList.add('hidden', 'opacity-0'));
        }
        
        await new Promise(r => setTimeout(r, 150));

        navLinks.forEach(link => {
            const isTarget = link.dataset.nav === viewId || (viewId === 'host-detail' && link.dataset.id === params.id);
            if (isTarget) link.classList.add('active-nav');
            else link.classList.remove('active-nav');
        });

        const target = document.getElementById(`view-${viewId === 'host-detail' ? 'host-detail' : viewId}`);
        if (!target) return;

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
                    this.renderDashboard(true); // Navigation render: animate
                    // Delay para garantir que o canvas esteja no DOM após animação
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

    renderDashboard(animate = true) {
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

    async loadHostDetail(id) {
        this.currentHostId = id;
        const host = this.hosts.find(h => h.id === id);
        if (!host) return;

        const viewTitle = document.getElementById('view-title');
        if (viewTitle) viewTitle.innerText = `Node: ${host.name}`;
        
        let processes = [];
        if (host.status === 'online') {
            try {
                const procRes = await api.getProcesses(host.name);
                if (procRes.success) processes = procRes.data.processes.slice(0, 15);
            } catch (e) {}
        }

        const detailHTML = `
            <div class="space-y-8 fade-in-up">
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                    <div>
                        <div class="flex items-center gap-3 mb-3">
                            <span class="px-3 py-1 rounded-lg bg-blue-500/10 text-blue-400 text-[10px] font-black uppercase tracking-tighter border border-blue-500/20">WinRM</span>
                            <div class="flex items-center gap-2 px-3 py-1 rounded-lg bg-white/5 border border-white/5">
                                <span class="w-2 h-2 rounded-full ${host.status === 'online' ? 'bg-green-400 animate-pulse' : 'bg-gray-500'}"></span>
                                <span class="text-[10px] font-bold text-gray-400 uppercase">${host.status}</span>
                            </div>
                        </div>
                        <h1 class="text-4xl font-extrabold tracking-tight text-white mb-2">${host.name}</h1>
                        <div class="flex items-center gap-4 text-gray-500 text-sm font-medium">
                            <span class="font-mono">${host.ip}</span>
                            <span class="font-mono">${host.mac || 'No MAC'}</span>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <button data-action="edit-host" data-id="${host.id}" class="w-12 h-12 glass-card rounded-2xl flex items-center justify-center text-blue-400 hover:bg-blue-500/10 transition-all"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg></button>
                        ${host.status !== 'online' ? `
                            <button data-action="wol-host" data-ip="${host.ip}" data-mac="${host.mac}" class="w-12 h-12 glass-card rounded-2xl flex items-center justify-center text-green-500 hover:bg-green-500/10 transition-all" title="Ligar Host"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg></button>
                        ` : ''}
                        <button data-action="restart-host" data-hostname="${host.name}" class="w-12 h-12 glass-card rounded-2xl flex items-center justify-center text-amber-500 hover:bg-amber-500/10 transition-all"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg></button>
                        <button data-action="shutdown-host" data-hostname="${host.name}" class="w-12 h-12 glass-card rounded-2xl flex items-center justify-center text-red-500 hover:bg-red-500/10 transition-all"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" x2="12" y1="2" y2="12"/></svg></button>
                    </div>
                </div>
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">
                    <div class="glass-panel rounded-[2rem] overflow-hidden flex flex-col h-[500px]">
                        <div class="px-8 py-6 border-b border-white/5 bg-white/[0.01] flex justify-between items-center">
                            <h3 class="font-bold text-sm text-white">Gerenciador de Processos</h3>
                            <span class="text-[10px] text-gray-500 font-mono">TOTAL: ${processes.length}</span>
                        </div>
                        <div class="overflow-y-auto flex-1 custom-scrollbar" id="process-table-container">
                            ${this.renderProcessTable(host, processes)}
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

    renderProcessTable(host, processes) {
        if (host.status !== 'online') {
            return '<div class="flex items-center justify-center h-full text-gray-500 text-sm">Host offline - Sem dados de processos</div>';
        }
        if (processes.length === 0) {
            return '<div class="flex items-center justify-center h-full text-gray-500 text-sm">Carregando processos...</div>';
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
                    ${processes.map(p => {
                        const pid = p.Id || p.pid;
                        const name = p.ProcessName || p.name;
                        return `
                            <tr class="hover:bg-white/[0.02] group transition-colors">
                                <td class="px-8 py-4 text-xs text-gray-300 font-bold">${name}</td>
                                <td class="px-8 py-4 font-mono text-[10px] text-gray-500">${pid}</td>
                                <td class="px-8 py-4 text-xs text-gray-400">${p.CPU ? p.CPU.toFixed(1)+'%' : '0%'}</td>
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

        try {
            const procRes = await api.getProcesses(host.name);
            if (procRes.success) {
                const processes = procRes.data.processes.slice(0, 15);
                this.safeUpdateHTML('process-table-container', this.renderProcessTable(host, processes));
            }
        } catch (e) {
            console.error('Update dynamic detail error:', e);
        }
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
            this.notify('Erro ao encerrar processo', 'error');
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
        } catch (e) { output.innerHTML += `<div class="text-red-400 ml-4">Connection failed</div>`; }
        output.scrollTop = output.scrollHeight;
    }

    escapeHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

    async handleLogin() {
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        const btn = document.getElementById('auth-submit');
        if (!email || !password) return this.notify('Preencha os campos', 'error');
        btn.disabled = true; btn.innerText = 'Autenticando...';
        try {
            const res = await api.login(email, password);
            if (res?.success) { this.notify('Bem-vindo!', 'success'); await this.enterApp(); }
            else { this.notify(res?.message || 'Falha no login', 'error'); btn.disabled = false; btn.innerText = 'Initialize System'; }
        } catch (e) { this.notify('Erro inesperado', 'error'); btn.disabled = false; }
    }

    async handleShutdown(hostname) {
        if (!confirm(`Desligar ${hostname}?`)) return;
        try {
            this.notify('Enviando comando...', 'info');
            const res = await api.shutdownHost(hostname);
            this.notify(res.success ? '✅ Comando enviado' : '❌ Falha: ' + res.message, res.success ? 'success' : 'error');
        } catch (e) { this.notify('Erro na requisição', 'error'); }
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
                await this.loadDashboardData();
                this.renderDashboard();
                this.renderSidebar();
            } else {
                this.notify('❌ Falha: ' + res.message, 'error');
            }
        } catch (e) { this.notify('Erro na requisição em massa', 'error'); }
    }

    async handleRestart(hostname) {
        if (!confirm(`Reiniciar ${hostname}?`)) return;
        try {
            this.notify('Enviando comando...', 'info');
            const res = await api.restartHost(hostname);
            this.notify(res.success ? '✅ Comando enviado' : '❌ Falha: ' + res.message, res.success ? 'success' : 'error');
        } catch (e) { this.notify('Erro na requisição', 'error'); }
    }

    async handleWakeOnLan(ip, mac) {
        try {
            this.notify('Enviando pacote WOL...', 'info');
            const res = await api.wakeOnLan(ip, mac);
            this.notify(res.success ? '✅ WOL enviado' : '❌ Falha', res.success ? 'success' : 'error');
        } catch (e) { this.notify('Erro', 'error'); }
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
        } catch (e) { this.notify('Erro na requisição WOL em massa', 'error'); }
    }

    notify(m, t = 'success', d = 4000) {
        const c = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `glass-panel px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-4 animate-slide-in border-l-4 ${t==='error'?'border-red-500':'border-green-500'}`;
        toast.innerHTML = `<p class="text-sm font-bold text-white">${m}</p>`;
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

    updateMainChart() {
        if (!this.charts.main) return;

        let cpuVal = parseFloat(this.clusterStats?.avg_cpu || 0);
        let ramVal = parseFloat(this.clusterStats?.avg_mem || 0);
        
        // Simulação inteligente se dados reais estiverem zerados (ex: sistema recém ligado)
        if (cpuVal === 0 && ramVal === 0) {
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

        chart.update('active'); 
    }

    attachEventListeners() {
        document.addEventListener('click', (e) => {
            const target = e.target;
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
                if (a === 'close-modal') this.hideHostModal();
                if (a === 'restart-host') this.handleRestart(h);
                if (a === 'shutdown-host') this.handleShutdown(h);
                if (a === 'wol-host') this.handleWakeOnLan(ip, actionBtn.dataset.mac);
                if (a === 'kill-process') this.handleKillProcess(h, actionBtn.dataset.pid, actionBtn.dataset.processName);
                return;
            }
            if (target.id === 'modal-backdrop') this.hideHostModal();
            if (target.closest('#logout-btn')) { api.logout(); location.reload(); }
            if (target.closest('#shutdown-all-btn')) this.handleShutdownAll();
            if (target.closest('#wol-all-btn')) this.handleWakeOnLanAll();
        });
    }

    startSyncTimer() { setInterval(() => { const el = document.getElementById('sync-time'); if (el) el.innerText = `SYNC: ${new Date().toLocaleTimeString()}`; }, 1000); }

    showHostModal(id = null) {
        const modal = document.getElementById('host-modal');
        const form = document.getElementById('host-form');
        form.reset(); document.getElementById('host-id').value = '';
        if (id) {
            const host = this.hosts.find(h => h.id == id);
            if (host) {
                document.getElementById('host-id').value = host.id;
                document.getElementById('host-name').value = host.name;
                document.getElementById('host-ip').value = host.ip;
                document.getElementById('host-mac').value = host.mac;
            }
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

    async handleHostSubmit() {
        const id = document.getElementById('host-id').value;
        const hostData = { hostname: document.getElementById('host-name').value, ip: document.getElementById('host-ip').value, mac_address: document.getElementById('host-mac').value };
        try {
            const res = id ? await api.updateHost(id, hostData) : await api.createHost(hostData);
            if (res.success) { this.notify('Sucesso'); this.hideHostModal(); await this.loadDashboardData(); this.renderSidebar(); }
            else this.notify(res.message, 'error');
        } catch (e) { this.notify('Falha', 'error'); }
    }
}

window.app = new LabControlApp();
