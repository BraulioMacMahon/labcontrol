const API_CONFIG = {
    // Base URL da API. Usa location.origin para herdar protocolo (HTTP/HTTPS) e host,
    // funcionando fora de localhost. Em hospedagem com subpasta diferente, defina
    // window.LABCONTROL_API_BASE_URL no index.html.
    baseURL: (window.LABCONTROL_API_BASE_URL || `${location.origin}/labcontrol/labcontrol-backend/api`),
    timeout: 15000
};

class LabControlAPI {
    constructor() {
        // Remove dados de versões antigas: a aplicação já não utiliza sessões.
        localStorage.removeItem('labcontrol_token');
        localStorage.removeItem('user_data');
        this.isOnline = navigator.onLine;
        
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());
    }

    async request(endpoint, options = {}) {
        const url = `${API_CONFIG.baseURL}/${endpoint}`;
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), API_CONFIG.timeout);

        const defaultHeaders = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };

        try {
            const response = await fetch(url, {
                ...options,
                headers: { ...defaultHeaders, ...options.headers },
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                let errorMessage = `Erro ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (e) {
                    // Se não for JSON, tenta pegar o texto
                    const textError = await response.text().catch(() => "");
                    if (textError.includes("<b>") || textError.includes("<!DOCTYPE")) {
                        errorMessage = "Erro interno do servidor (PHP Error)";
                    } else if (textError.length > 0 && textError.length < 100) {
                        errorMessage = textError;
                    }
                }
                throw new Error(errorMessage);
            }

            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                return await response.json();
            } else {
                return { success: true, message: "Operação realizada" };
            }

        } catch (error) {
            clearTimeout(timeoutId);
            if (error.name === 'AbortError') {
                throw new Error('Timeout na conexão');
            }
            throw error;
        }
    }

    async getHosts() {
        return this.request('hosts.php?action=list', { method: 'GET' });
    }

    async checkAllHosts() {
        return this.request('hosts.php?action=check-all', { method: 'GET' });
    }

    async getHostStats() {
        return this.request('hosts.php?action=stats', { method: 'GET' });
    }

    async getHostById(id) {
        return this.request(`hosts.php?action=get&id=${id}`, { method: 'GET' });
    }

    async discoverHost(hostname) {
        return this.request(`hosts.php?action=discover&hostname=${encodeURIComponent(hostname)}`, { method: 'GET' });
    }

    async createHost(hostData) {
        return this.request('hosts.php?action=create', {
            method: 'POST',
            body: JSON.stringify(hostData)
        });
    }

    async updateHost(id, hostData) {
        return this.request(`hosts.php?action=update&id=${id}`, {
            method: 'POST',
            body: JSON.stringify(hostData)
        });
    }

    async deleteHost(id) {
        return this.request(`hosts.php?action=delete&id=${id}`, {
            method: 'DELETE'
        });
    }

    async getHostStatus(ip) {
        return this.request(`control.php?action=status&ip=${ip}`, { method: 'GET' });
    }

    async shutdownHost(hostname, options = {}) {
        return this.request('control.php?action=shutdown', {
            method: 'POST',
            body: JSON.stringify({
                hostname,
                force: options.force || false,
                timeout: options.timeout || 30
            })
        });
    }

    async shutdownAllHosts() {
        return this.request('control.php?action=shutdown-all', {
            method: 'POST'
        });
    }

    async restartHost(hostname, options = {}) {
        return this.request('control.php?action=restart', {
            method: 'POST',
            body: JSON.stringify({
                hostname,
                force: options.force || false,
                timeout: options.timeout || 30
            })
        });
    }

    async wakeOnLan(ip, macAddress) {
        return this.request('control.php?action=wol', {
            method: 'POST',
            body: JSON.stringify({ ip, mac_address: macAddress })
        });
    }

    async wakeOnLanAll() {
        return this.request('control.php?action=wol-all', {
            method: 'POST'
        });
    }

    async getProcesses(hostname) {
        return this.request(`control.php?action=processes&hostname=${hostname}`, { method: 'GET' });
    }

    async killProcess(hostname, pid, processName = '', force = false) {
        return this.request('control.php?action=killprocess', {
            method: 'POST',
            body: JSON.stringify({ hostname, pid, process_name: processName, force })
        });
    }

    async executeCommand(hostname, command) {
        return this.request('control.php?action=execute', {
            method: 'POST',
            body: JSON.stringify({ hostname, command })
        });
    }

    async getLogs(filters = {}) {
        const params = new URLSearchParams({ action: 'list', ...filters }).toString();
        return this.request(`logs.php?${params}`, { method: 'GET' });
    }

    async getSyncStatus() {
        return this.request('sync.php?action=status', { method: 'GET' });
    }

    async syncData(type = 'all') {
        return this.request('sync.php?action=sync', {
            method: 'POST',
            body: JSON.stringify({ type })
        });
    }

    handleOnline() {
        this.isOnline = true;
        window.dispatchEvent(new CustomEvent('api:online'));
    }

    handleOffline() {
        this.isOnline = false;
        window.dispatchEvent(new CustomEvent('api:offline'));
    }
}

export const api = new LabControlAPI();
