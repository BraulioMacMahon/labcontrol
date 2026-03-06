<?php
/**
 * LabControl - Migration Script
 * Adiciona colunas faltantes nas tabelas
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../labcontrol-backend/config/config.php';

echo json_encode([
    'step' => 'iniciando_migration',
    'timestamp' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT) . "\n";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    echo json_encode([
        'step' => 'conectado',
        'message' => 'Conectado ao banco de dados'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // ===== TABELA USERS =====
    echo json_encode([
        'step' => 'verificando_users',
        'message' => 'Verificando tabela users'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Verificar se tabela existe
    $checkTable = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($checkTable->rowCount() === 0) {
        // Criar tabela
        echo json_encode([
            'step' => 'criando_users',
            'message' => 'Tabela users não existe, criando...'
        ], JSON_PRETTY_PRINT) . "\n";
        
        $sql = "CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin', 'operator') DEFAULT 'operator',
            firebase_uid VARCHAR(100) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            last_login DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_firebase_uid (firebase_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        echo json_encode([
            'step' => 'users_criada',
            'message' => 'Tabela users criada com sucesso'
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode([
            'step' => 'users_existe',
            'message' => 'Tabela users já existe'
        ], JSON_PRETTY_PRINT) . "\n";
        
        // Verificar colunas
        $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
        if ($checkColumn->rowCount() === 0) {
            echo json_encode([
                'step' => 'adicionando_is_active',
                'message' => 'Adicionando coluna is_active'
            ], JSON_PRETTY_PRINT) . "\n";
            
            $sql = "ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER firebase_uid";
            $pdo->exec($sql);
            
            echo json_encode([
                'step' => 'is_active_adicionada',
                'message' => 'Coluna is_active adicionada'
            ], JSON_PRETTY_PRINT) . "\n";
        }
        
        // Verificar outras colunas necessárias
        $requiredColumns = [
            'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'email' => 'VARCHAR(100) NOT NULL UNIQUE',
            'password_hash' => 'VARCHAR(255) NOT NULL',
            'role' => "ENUM('admin', 'operator') DEFAULT 'operator'",
            'firebase_uid' => 'VARCHAR(100) DEFAULT NULL',
            'is_active' => 'TINYINT(1) DEFAULT 1',
            'last_login' => 'DATETIME DEFAULT NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ];
        
        $result = $pdo->query("SHOW COLUMNS FROM users");
        $existingColumns = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $existingColumns[] = $row['Field'];
        }
        
        echo json_encode([
            'step' => 'colunas_existentes',
            'columns' => $existingColumns
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // ===== TABELA HOSTS =====
    echo json_encode([
        'step' => 'verificando_hosts',
        'message' => 'Verificando tabela hosts'
    ], JSON_PRETTY_PRINT) . "\n";
    
    $checkTable = $pdo->query("SHOW TABLES LIKE 'hosts'");
    if ($checkTable->rowCount() === 0) {
        echo json_encode([
            'step' => 'criando_hosts',
            'message' => 'Criando tabela hosts'
        ], JSON_PRETTY_PRINT) . "\n";
        
        $sql = "CREATE TABLE hosts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL UNIQUE,
            hostname VARCHAR(100) NOT NULL,
            mac_address VARCHAR(17) DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'unknown',
            os_type VARCHAR(50) DEFAULT 'Windows',
            location VARCHAR(100) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            remote_user VARCHAR(100) DEFAULT NULL,
            remote_password_encrypted TEXT DEFAULT NULL,
            use_default_credentials TINYINT(1) DEFAULT 1,
            winrm_port INT DEFAULT 5985,
            use_ssl TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            last_seen DATETIME DEFAULT NULL,
            last_boot DATETIME DEFAULT NULL,
            firebase_id VARCHAR(100) DEFAULT NULL,
            synced TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ip (ip),
            INDEX idx_status (status),
            INDEX idx_firebase_id (firebase_id),
            INDEX idx_synced (synced)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        echo json_encode([
            'step' => 'hosts_criada',
            'message' => 'Tabela hosts criada'
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode([
            'step' => 'hosts_existe',
            'message' => 'Tabela hosts já existe'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // ===== TABELA LOGS =====
    echo json_encode([
        'step' => 'verificando_logs',
        'message' => 'Verificando tabela logs'
    ], JSON_PRETTY_PRINT) . "\n";
    
    $checkTable = $pdo->query("SHOW TABLES LIKE 'logs'");
    if ($checkTable->rowCount() === 0) {
        echo json_encode([
            'step' => 'criando_logs',
            'message' => 'Criando tabela logs'
        ], JSON_PRETTY_PRINT) . "\n";
        
        $sql = "CREATE TABLE logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) DEFAULT NULL,
            user_email VARCHAR(100) DEFAULT NULL,
            host_id INT DEFAULT NULL,
            host_ip VARCHAR(45) DEFAULT NULL,
            action VARCHAR(255) NOT NULL,
            action_type VARCHAR(50) DEFAULT 'general',
            details TEXT DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'success',
            error_message TEXT DEFAULT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            synced TINYINT(1) DEFAULT 0,
            firebase_id VARCHAR(100) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_host_id (host_id),
            INDEX idx_timestamp (timestamp),
            INDEX idx_synced (synced),
            INDEX idx_action_type (action_type),
            FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        echo json_encode([
            'step' => 'logs_criada',
            'message' => 'Tabela logs criada'
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode([
            'step' => 'logs_existe',
            'message' => 'Tabela logs já existe'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // ===== TABELA SYNC_QUEUE =====
    echo json_encode([
        'step' => 'verificando_sync_queue',
        'message' => 'Verificando tabela sync_queue'
    ], JSON_PRETTY_PRINT) . "\n";
    
    $checkTable = $pdo->query("SHOW TABLES LIKE 'sync_queue'");
    if ($checkTable->rowCount() === 0) {
        echo json_encode([
            'step' => 'criando_sync_queue',
            'message' => 'Criando tabela sync_queue'
        ], JSON_PRETTY_PRINT) . "\n";
        
        $sql = "CREATE TABLE sync_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(50) NOT NULL,
            record_id INT NOT NULL,
            operation VARCHAR(20) NOT NULL,
            data JSON DEFAULT NULL,
            attempts INT DEFAULT 0,
            max_attempts INT DEFAULT 3,
            status VARCHAR(20) DEFAULT 'pending',
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_table_record (table_name, record_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        echo json_encode([
            'step' => 'sync_queue_criada',
            'message' => 'Tabela sync_queue criada'
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode([
            'step' => 'sync_queue_existe',
            'message' => 'Tabela sync_queue já existe'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // ===== INSERIR USUÁRIOS =====
    echo json_encode([
        'step' => 'inserindo_usuarios',
        'message' => 'Verificando/Inserindo usuários'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Verificar admin
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM users WHERE email = 'admin@labcontrol.local'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['cnt'] == 0) {
        $adminPasswordHash = password_hash('admin123', PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, 'admin', 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['admin@labcontrol.local', $adminPasswordHash]);
        
        echo json_encode([
            'step' => 'admin_criado',
            'message' => 'Usuário admin criado',
            'email' => 'admin@labcontrol.local',
            'password' => 'admin123'
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode([
            'step' => 'admin_existe',
            'message' => 'Usuário admin já existe'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // Verificar operator
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM users WHERE email = 'operator@labcontrol.local'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['cnt'] == 0) {
        $operatorPasswordHash = password_hash('operator123', PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, 'operator', 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['operator@labcontrol.local', $operatorPasswordHash]);
        
        echo json_encode([
            'step' => 'operator_criado',
            'message' => 'Usuário operator criado',
            'email' => 'operator@labcontrol.local',
            'password' => 'operator123'
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode([
            'step' => 'operator_existe',
            'message' => 'Usuário operator já existe'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // ===== GARANTIR COLUNAS NA TABELA HOSTS =====
    echo json_encode([
        'step' => 'verificando_hosts_colunas',
        'message' => 'Verificando colunas necessárias em hosts'
    ], JSON_PRETTY_PRINT) . "\n";

    $requiredHostColumns = [
        'is_active' => "TINYINT(1) DEFAULT 1",
        'synced' => "TINYINT(1) DEFAULT 0"
    ];

    foreach ($requiredHostColumns as $col => $definition) {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM hosts LIKE '$col'");
        if ($checkColumn->rowCount() === 0) {
            echo json_encode([
                'step' => 'adicionando_host_coluna',
                'column' => $col,
                'message' => "Adicionando coluna $col"
            ], JSON_PRETTY_PRINT) . "\n";
            $sql = "ALTER TABLE hosts ADD COLUMN $col $definition";
            $pdo->exec($sql);
            echo json_encode([
                'step' => 'coluna_host_adicionada',
                'column' => $col
            ], JSON_PRETTY_PRINT) . "\n";
        }
    }

    // ===== CRIAR VIEW =====
    echo json_encode([
        'step' => 'criando_view',
        'message' => 'Criando view v_host_stats'
    ], JSON_PRETTY_PRINT) . "\n";
    
    $sql = "CREATE OR REPLACE VIEW v_host_stats AS
            SELECT 
                COUNT(*) as total_hosts,
                SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online_count,
                SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline_count,
                SUM(CASE WHEN status = 'unknown' THEN 1 ELSE 0 END) as unknown_count
            FROM hosts 
            WHERE is_active = 1";
    
    $pdo->exec($sql);
    
    echo json_encode([
        'step' => 'completo',
        'message' => 'Todas as migrações executadas com sucesso!',
        'status' => 'OK'
    ], JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT) . "\n";
}
?>


