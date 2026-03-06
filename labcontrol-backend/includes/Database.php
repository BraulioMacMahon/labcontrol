<?php
/**
 * LabControl - Classe de Conexão com Banco de Dados
 */

// CORREÇÃO DO CAMINHO - sobe 2 níveis a partir de includes/
require_once __DIR__ . '/../config/config.php';

class Database {
    private static $instance = null;
    private $connection;
    private $lastError = null;
    
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
            throw new Exception("Erro de conexão com o banco de dados: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // ... (restante dos métodos mantidos igual ao teu ficheiro original)
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
    
    public function selectOne($query, $params = []) {
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
    
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
            return false;
        }
    }
    
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
            return false;
        }
    }
    
    public function delete($table, $where, $params = []) {
        try {
            $query = "DELETE FROM {$table} WHERE {$where}";
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
    
    public function execute($query, $params = []) {
        try {
            $stmt = $this->connection->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
    
    // Métodos específicos do LabControl
    public function getHostStats() {
        return $this->selectOne("SELECT * FROM v_host_stats");
    }
    
    public function getUserByEmail($email) {
        return $this->selectOne(
            "SELECT * FROM users WHERE email = :email AND is_active = 1",
            [':email' => $email]
        );
    }
    
    public function getUserById($id) {
        return $this->selectOne(
            "SELECT id, email, role, firebase_uid, is_active, last_login, created_at FROM users WHERE id = :id",
            [':id' => $id]
        );
    }
    
    public function getHostByIP($ip) {
        return $this->selectOne(
            "SELECT * FROM hosts WHERE ip = :ip AND is_active = 1",
            [':ip' => $ip]
        );
    }
    
    public function getHostById($id) {
        return $this->selectOne(
            "SELECT * FROM hosts WHERE id = :id AND is_active = 1",
            [':id' => $id]
        );
    }
    
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
    
    public function logAction($userId, $userEmail, $hostId, $hostIp, $action, $actionType = 'general', $details = null, $status = 'success') {
        if ($details !== null && !is_string($details)) {
            $details = json_encode($details, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        
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

    public function getSyncQueue($limit = 50) {
        return $this->select(
            "SELECT * FROM sync_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT :limit",
            [':limit' => $limit]
        );
    }

    public function updateSyncQueueStatus($id, $status, $errorMessage = null) {
        $data = [
            'status' => $status,
            'processed_at' => date('Y-m-d H:i:s')
        ];
        
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }

        return $this->update('sync_queue', $data, 'id = :id', [':id' => $id]);
    }

    public function getPendingLogs($limit = 100) {
        return $this->select(
            "SELECT * FROM logs WHERE synced = 0 ORDER BY timestamp ASC LIMIT :limit",
            [':limit' => $limit]
        );
    }

    public function cleanupOldLogs($days) {
        return $this->delete(
            'logs',
            "timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)",
            [':days' => $days]
        );
    }
}