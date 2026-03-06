<?php
/**
 * LabControl - Setup Completo
 * Script para inicializar completamente o banco de dados
 * Execute uma única vez via navegador ou CLI
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../labcontrol-backend/config/config.php';

echo json_encode([
    'step' => 'iniciando',
    'timestamp' => date('Y-m-d H:i:s'),
    'message' => 'Iniciando setup completo do banco de dados'
], JSON_PRETTY_PRINT) . "\n";

try {
    // Conectar sem selecionar BD para criar a BD
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    echo json_encode([
        'step' => 'conectado',
        'message' => 'Conectado ao MySQL'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // 1. Criar banco de dados
    echo json_encode([
        'step' => 'criando_database',
        'message' => 'Criando banco de dados ' . DB_NAME
    ], JSON_PRETTY_PRINT) . "\n";
    
    $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $pdo->exec($sql);
    
    echo json_encode([
        'step' => 'database_ok',
        'message' => 'Banco de dados criado/verificado'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // 2. Selecionar BD
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    // 3. Criar tabelas
    echo json_encode([
        'step' => 'criando_tabelas',
        'message' => 'Criando tabelas'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Tabela users
    $sql = "CREATE TABLE IF NOT EXISTS users (
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
        'step' => 'tabela_users',
        'message' => 'Tabela users criada'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Tabela hosts
    $sql = "CREATE TABLE IF NOT EXISTS hosts (
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
        'step' => 'tabela_hosts',
        'message' => 'Tabela hosts criada'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Tabela logs
    $sql = "CREATE TABLE IF NOT EXISTS logs (
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
        'step' => 'tabela_logs',
        'message' => 'Tabela logs criada'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Tabela sync_queue
    $sql = "CREATE TABLE IF NOT EXISTS sync_queue (
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
        'step' => 'tabela_sync_queue',
        'message' => 'Tabela sync_queue criada'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Tabela system_config
    $sql = "CREATE TABLE IF NOT EXISTS system_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        config_key VARCHAR(100) NOT NULL UNIQUE,
        config_value TEXT DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_config_key (config_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
    
    echo json_encode([
        'step' => 'tabela_system_config',
        'message' => 'Tabela system_config criada'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // 4. Inserir usuários padrão
    echo json_encode([
        'step' => 'inserindo_usuarios',
        'message' => 'Inserindo usuários padrão'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Verificar se admin já existe
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM users WHERE email = 'admin@labcontrol.local'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['cnt'] == 0) {
        // Inserir admin
        $adminPasswordHash = password_hash('admin123', PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, 'admin', 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['admin@labcontrol.local', $adminPasswordHash]);
        
        echo json_encode([
            'step' => 'admin_criado',
            'message' => 'Usuário admin criado: admin@labcontrol.local / admin123'
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode([
            'step' => 'admin_existe',
            'message' => 'Usuário admin já existe'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // Verificar se operator já existe
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM users WHERE email = 'operator@labcontrol.local'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['cnt'] == 0) {
        // Inserir operator
        $operatorPasswordHash = password_hash('operator123', PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, 'operator', 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['operator@labcontrol.local', $operatorPasswordHash]);
        
        echo json_encode([
            'step' => 'operator_criado',
            'message' => 'Usuário operator criado: operator@labcontrol.local / operator123'
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo json_encode([
            'step' => 'operator_existe',
            'message' => 'Usuário operator já existe'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // 5. Criar view de estatísticas
    echo json_encode([
        'step' => 'criando_view',
        'message' => 'Criando view de estatísticas'
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
        'message' => 'Setup completado com sucesso!',
        'credentials' => [
            'admin' => [
                'email' => 'admin@labcontrol.local',
                'password' => 'admin123'
            ],
            'operator' => [
                'email' => 'operator@labcontrol.local',
                'password' => 'operator123'
            ]
        ]
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


