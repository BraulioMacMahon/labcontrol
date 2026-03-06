const API_CONFIG = {
    baseURL: 'http://localhost/labcontrol/labcontrol-backend/api',  // CAMINHO CORRETO
    timeout: 15000,
    retries: 2
};

class LabControlAPI {
    constructor() {
        this.token = localStorage.getItem('labcontrol_token');
        this.isOnline = navigator.onLine;
        this.refreshPromise = null;
        
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());
    }

    async request(endpoint, options = {}, retryCount = 0) {
        const url = `${API_CONFIG.baseURL}/${endpoint}`;
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), API_CONFIG.timeout);

        const defaultHeaders = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };

        if (this.token) {
            defaultHeaders['Authorization'] = `Bearer ${this.token}`;
        }

        try {
            const response = await fetch(url, {
                ...options,
                headers: { ...defaultHeaders, ...options.headers },
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                if (response.status === 401 && retryCount < API_CONFIG.retries && endpoint !== 'auth.php?action=login') {
                    // Só tenta refresh se houver um token
                    if (this.token) {
                        const refreshRes = await this.refreshToken();
                        if (refreshRes && refreshRes.success) {
                            return this.request(endpoint, options, retryCount + 1);
                        }
                    }
                    
                    // Se o refresh falhar ou não houver token, limpa e avisa o app
                    this.clearAuth();
                    // Só recarrega se NÃO for uma verificação inicial ou monitoramento
                    if (!endpoint.includes('verify') && !endpoint.includes('check-all')) {
                        window.location.reload();
                    }
                    return;
                }
                
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

    async login(email, password) {
        try {
            const data = await this.request('auth.php?action=login', {
                method: 'POST',
                body: JSON.stringify({ email, password })
            });

            if (!data) {
                throw new Error('Resposta vazia do servidor');
            }

            if (data.success && data.data && data.data.token) {
                this.token = data.data.token;
                localStorage.setItem('labcontrol_token', this.token);
                localStorage.setItem('user_data', JSON.stringify(data.data.user));
                return { success: true, data: data.data };
            } else {
                // Retornar erro sem lançar exceção
                return { 
                    success: false, 
                    message: data.message || 'Falha na autenticação',
                    data: null
                };
            }
        } catch (error) {
            console.error('Login API error:', error);
            return {
                success: false,
                message: error.message || 'Erro ao conectar com o servidor',
                data: null,
                isNetworkError: true
            };
        }
    }

    async logout() {
        try {
            await this.request('auth.php?action=logout', { method: 'POST' });
        } finally {
            this.clearAuth();
        }
    }

    async verifyToken() {
        return this.request('auth.php?action=verify', { method: 'GET' });
    }

    async refreshToken() {
        if (!this.token) return { success: false };
        
        // Se já houver um refresh em andamento, espera por ele
        if (this.refreshPromise) {
            return this.refreshPromise;
        }

        this.refreshPromise = (async () => {
            try {
                console.log('🔄 Renovando token de sessão...');
                const response = await fetch(`${API_CONFIG.baseURL}/auth.php?action=refresh`, {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${this.token}`,
                        'Accept': 'application/json'
                    }
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.data.token) {
                        this.token = data.data.token;
                        localStorage.setItem('labcontrol_token', this.token);
                        console.log('✅ Token renovado com sucesso');
                        return data;
                    }
                }
                
                console.error('❌ Falha ao renovar token');
                this.clearAuth();
                return { success: false };
            } catch (e) {
                console.error('❌ Erro na rede durante o refresh:', e);
                return { success: false };
            } finally {
                this.refreshPromise = null;
            }
        })();

        return this.refreshPromise;
    }

    clearAuth() {
        this.token = null;
        localStorage.removeItem('labcontrol_token');
        localStorage.removeItem('user_data');
    }

    getUser() {
        const user = localStorage.getItem('user_data');
        return user ? JSON.parse(user) : null;
    }

    isAdmin() {
        const user = this.getUser();
        return user?.role === 'admin';
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