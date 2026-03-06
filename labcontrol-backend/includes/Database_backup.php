<?php
/**
 * LabControl - Classe de Conexão com Banco de Dados
 * 
 * Gerencia conexões MySQL e operações CRUD
 */

require_once __DIR__ . '/../config/config.php';

class Database {
    private static $instance = null;
    private $connection;
    private $lastError = null;
    
    /**
     * Construtor - estabelece conexão com o banco
     */
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            logError('Erro de conexão com banco de dados', ['error' => $e->getMessage()]);
            throw new Exception("Erro de conexão com o banco de dados");
        }
    }
    
    /**
     * Singleton - retorna instância única
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Retorna conexão PDO
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Retorna último erro
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * Executa query SELECT e retorna resultados
     */
    public function select($query, $params = []) {
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            logError('Erro na consulta SELECT', ['query' => $query, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Executa query SELECT e retorna uma linha
     */
    public function selectOne($query, $params = []) {
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            logError('Erro na consulta SELECT ONE', ['query' => $query, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Executa INSERT e retorna ID inserido
     */
    public function insert($table, $data) {
        try {
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            
            $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->connection->prepare($query);
            $stmt->execute($data);
            
            return $this->connection->lastInsertId();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            logError('Erro no INSERT', ['table' => $table, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Executa UPDATE
     */
    public function update($table, $data, $where, $whereParams = []) {
        try {
            $setParts = [];
            foreach ($data as $key => $value) {
                $setParts[] = "{$key} = :{$key}";
            }
            $setClause = implode(', ', $setParts);
            
            $query = "UPDATE {$table} SET {$setClause} WHERE {$where}";
            $stmt = $this->connection->prepare($query);
            $stmt->execute(array_merge($data, $whereParams));
            
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            logError('Erro no UPDATE', ['table' => $table, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Executa DELETE
     */
    public function delete($table, $where, $params = []) {
        try {
            $query = "DELETE FROM {$table} WHERE {$where}";
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            logError('Erro no DELETE', ['table' => $table, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Executa query genérica
     */
    public function execute($query, $params = []) {
        try {
            $stmt = $this->connection->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            logError('Erro na execução', ['query' => $query, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Inicia transação
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Commit transação
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transação
     */
    public function rollback() {
        return $this->connection->rollBack();
    }
    
    /**
     * Verifica se está em transação
     */
    public function inTransaction() {
        return $this->connection->inTransaction();
    }
    
    /**
     * Retorna estatísticas de hosts
     */
    public function getHostStats() {
        return $this->selectOne("SELECT * FROM v_host_stats");
    }
    
    /**
     * Busca usuário por email
     */
    public function getUserByEmail($email) {
        return $this->selectOne(
            "SELECT * FROM users WHERE email = :email AND is_active = 1",
            [':email' => $email]
        );
    }
    
    /**
     * Busca usuário por ID
     */
    public function getUserById($id) {
        return $this->selectOne(
            "SELECT id, email, role, firebase_uid, is_active, last_login, created_at FROM users WHERE id = :id",
            [':id' => $id]
        );
    }
    
    /**
     * Busca host por IP
     */
    public function getHostByIP($ip) {
        return $this->selectOne(
            "SELECT * FROM hosts WHERE ip = :ip",
            [':ip' => $ip]
        );
    }
    
    /**
     * Busca host por ID
     */
    public function getHostById($id) {
        return $this->selectOne(
            "SELECT * FROM hosts WHERE id = :id",
            [':id' => $id]
        );
    }
    
    /**
     * Lista todos os hosts ativos
     */
    public function getAllHosts($status = null) {
        $query = "SELECT * FROM hosts WHERE is_active = 1";
        $params = [];
        
        if ($status) {
            $query .= " AND status = :status";
            $params[':status'] = $status;
        }
        
        $query .= " ORDER BY hostname ASC";
        return $this->select($query, $params);
    }
    
    /**
     * Registra log de ação
     */
    public function logAction($userId, $userEmail, $hostId, $hostIp, $action, $actionType = 'general', $details = null, $status = 'success') {
        return $this->insert('logs', [
            'user_id' => $userId,
            'user_email' => $userEmail,
            'host_id' => $hostId,
            'host_ip' => $hostIp,
            'action' => $action,
            'action_type' => $actionType,
            'details' => $details,
            'status' => $status,
            'ip_address' => getClientIP(),
            'synced' => 0
        ]);
    }
    
    /**
     * Obtém logs pendentes de sincronização
     */
    public function getPendingLogs($limit = 100) {
        return $this->select(
            "SELECT * FROM logs WHERE synced = 0 ORDER BY timestamp ASC LIMIT :limit",
            [':limit' => $limit]
        );
    }
    
    /**
     * Marca logs como sincronizados
     */
    public function markLogsAsSynced($logIds) {
        if (empty($logIds)) return true;
        
        $placeholders = implode(',', array_fill(0, count($logIds), '?'));
        return $this->execute(
            "UPDATE logs SET synced = 1 WHERE id IN ({$placeholders})",
            $logIds
        );
    }
    
    /**
     * Atualiza status do host
     */
    public function updateHostStatus($hostId, $status) {
        return $this->update(
            'hosts',
            [
                'status' => $status,
                'last_seen' => date('Y-m-d H:i:s'),
                'synced' => 0
            ],
            'id = :id',
            [':id' => $hostId]
        );
    }
    
    /**
     * Adiciona à fila de sincronização
     */
    public function addToSyncQueue($tableName, $recordId, $operation, $data = null) {
        return $this->insert('sync_queue', [
            'table_name' => $tableName,
            'record_id' => $recordId,
            'operation' => $operation,
            'data' => $data ? json_encode($data) : null,
            'status' => 'pending'
        ]);
    }
    
    /**
     * Obtém itens pendentes da fila de sincronização
     */
    public function getSyncQueue($limit = 50) {
        return $this->select(
            "SELECT * FROM sync_queue 
             WHERE status = 'pending' AND attempts < max_attempts 
             ORDER BY created_at ASC 
             LIMIT :limit",
            [':limit' => $limit]
        );
    }
    
    /**
     * Atualiza status do item da fila
     */
    public function updateSyncQueueStatus($id, $status, $errorMessage = null) {
        $data = [
            'status' => $status,
            'attempts' => $status === 'pending' ? 'attempts + 1' : 'attempts'
        ];
        
        if ($status === 'completed') {
            $data['processed_at'] = date('Y-m-d H:i:s');
        }
        
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }
        
        return $this->update('sync_queue', $data, 'id = :id', [':id' => $id]);
    }
    
    /**
     * Limpa logs antigos
     */
    public function cleanupOldLogs($days = null) {
        $days = $days ?: LOG_RETENTION_DAYS;
        return $this->execute(
            "DELETE FROM logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)",
            [':days' => $days]
        );
    }
    
    /**
     * Obtém configuração do sistema
     */
    public function getConfig($key) {
        $result = $this->selectOne(
            "SELECT config_value FROM system_config WHERE config_key = :key",
            [':key' => $key]
        );
        return $result ? $result['config_value'] : null;
    }
    
    /**
     * Atualiza configuração do sistema
     */
    public function setConfig($key, $value) {
        return $this->execute(
            "INSERT INTO system_config (config_key, config_value) 
             VALUES (:key, :value) 
             ON DUPLICATE KEY UPDATE config_value = :value",
            [':key' => $key, ':value' => $value]
        );
    }
    
    // =====================================================
    // MÉTODOS DE CREDENCIAIS REMOTAS
    // =====================================================
    
    /**
     * Obtém credenciais padrão do sistema
     */
    public function getDefaultCredentials() {
        return $this->selectOne(
            "SELECT * FROM remote_credentials WHERE is_default = 1 LIMIT 1"
        );
    }
    
    /**
     * Salva credenciais padrão
     */
    public function saveDefaultCredentials($username, $encryptedPassword, $description = null) {
        // Remove credencial padrão existente
        $this->execute("UPDATE remote_credentials SET is_default = 0 WHERE is_default = 1");
        
        // Insere nova credencial padrão
        return $this->insert('remote_credentials', [
            'credential_name' => 'default',
            'username' => $username,
            'password_encrypted' => $encryptedPassword,
            'description' => $description,
            'is_default' => 1
        ]);
    }
    
    /**
     * Atualiza credenciais de um host específico
     */
    public function updateHostCredentials($hostId, $username, $encryptedPassword, $useDefault = false) {
        return $this->update('hosts', [
            'remote_user' => $useDefault ? null : $username,
            'remote_password_encrypted' => $useDefault ? null : $encryptedPassword,
            'use_default_credentials' => $useDefault ? 1 : 0,
            'synced' => 0
        ], 'id = :id', [':id' => $hostId]);
    }
    
    /**
     * Obtém todas as credenciais cadastradas
     */
    public function getAllCredentials() {
        return $this->select(
            "SELECT id, credential_name, username, description, is_default, created_at, updated_at 
             FROM remote_credentials 
             ORDER BY is_default DESC, credential_name ASC"
        );
    }
    
    /**
     * Remove credenciais
     */
    public function deleteCredentials($id) {
        return $this->execute(
            "DELETE FROM remote_credentials WHERE id = :id AND is_default = 0",
            [':id' => $id]
        );
    }
}
