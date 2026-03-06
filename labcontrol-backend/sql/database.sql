-- =====================================================
-- LabControl - Banco de Dados MySQL
-- Sistema de Controle de Laboratório
-- =====================================================

-- Criar banco de dados
CREATE DATABASE IF NOT EXISTS labcontrol 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE labcontrol;

-- =====================================================
-- TABELA: users
-- Descrição: Usuários para autenticação offline
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABELA: hosts
-- Descrição: Máquinas/PCs do laboratório
-- =====================================================
CREATE TABLE IF NOT EXISTS hosts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    hostname VARCHAR(100) NOT NULL,
    mac_address VARCHAR(17) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'unknown',
    os_type VARCHAR(50) DEFAULT 'Windows',
    location VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    -- Credenciais para acesso remoto (criptografadas)
    remote_user VARCHAR(100) DEFAULT NULL,
    remote_password_encrypted TEXT DEFAULT NULL,
    use_default_credentials TINYINT(1) DEFAULT 1,
    -- Configurações de conexão
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABELA: logs
-- Descrição: Histórico de ações e auditoria
-- =====================================================
CREATE TABLE IF NOT EXISTS logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABELA: sync_queue
-- Descrição: Fila de sincronização para modo offline
-- =====================================================
CREATE TABLE IF NOT EXISTS sync_queue (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABELA: system_config
-- Descrição: Configurações do sistema
-- =====================================================
CREATE TABLE IF NOT EXISTS system_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABELA: remote_credentials
-- Descrição: Credenciais padrão para acesso remoto (criptografadas)
-- =====================================================
CREATE TABLE IF NOT EXISTS remote_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    credential_name VARCHAR(100) NOT NULL UNIQUE,
    username VARCHAR(100) NOT NULL,
    password_encrypted TEXT NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_credential_name (credential_name),
    INDEX idx_is_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DADOS INICIAIS
-- =====================================================

-- Usuários iniciais (senha: admin123 para admin, operator123 para operators)
-- Hash gerado via password_hash() do PHP com bcrypt
INSERT INTO users (email, password_hash, role, is_active) VALUES
('admin@labcontrol.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1),
('operator1@labcontrol.local', '$2y$10$N9qo8uLOickgx2ZMRZoMy.MqrqQzBZN0UfFyBqQxqJ8zE5L1Qq5q2', 'operator', 1),
('operator2@labcontrol.local', '$2y$10$N9qo8uLOickgx2ZMRZoMy.MqrqQzBZN0UfFyBqQxqJ8zE5L1Qq5q2', 'operator', 1);

-- Hosts iniciais de exemplo
INSERT INTO hosts (ip, hostname, mac_address, status, os_type, location, description, is_active, last_seen) VALUES
('192.168.21.40', 'PC-LAB01', '00:1A:2B:3C:4D:5E', 'online', 'Windows 10', 'Sala A - Fila 1', 'Estação de trabalho principal', 1, NOW()),
('192.168.21.41', 'PC-LAB02', '00:1A:2B:3C:4D:5F', 'offline', 'Windows 10', 'Sala A - Fila 1', 'Estação de trabalho secundária', 1, NOW()),
('192.168.21.42', 'PC-LAB03', '00:1A:2B:3C:4D:60', 'unknown', 'Windows 11', 'Sala A - Fila 2', 'Estação de trabalho nova', 1, NOW()),
('192.168.21.43', 'PC-LAB04', '00:1A:2B:3C:4D:61', 'online', 'Windows 10', 'Sala A - Fila 2', 'Estação de trabalho', 1, NOW()),
('192.168.21.44', 'PC-LAB05', '00:1A:2B:3C:4D:62', 'offline', 'Windows 10', 'Sala B - Fila 1', 'Estação de trabalho', 1, NOW());

-- Logs iniciais
INSERT INTO logs (user_id, user_email, host_id, host_ip, action, action_type, status, synced) VALUES
('1', 'admin@labcontrol.local', 1, '192.168.21.40', 'Sistema inicializado', 'system', 'success', 1),
('1', 'admin@labcontrol.local', 2, '192.168.21.41', 'Host registrado no sistema', 'host', 'success', 1),
('2', 'operator1@labcontrol.local', 3, '192.168.21.42', 'Consulta de status do host', 'query', 'success', 1);

-- Configurações do sistema
INSERT INTO system_config (config_key, config_value, description) VALUES
('firebase_enabled', 'true', 'Habilitar sincronização com Firebase'),
('offline_mode', 'false', 'Forçar modo offline'),
('sync_interval', '300', 'Intervalo de sincronização em segundos'),
('ping_timeout', '2', 'Timeout para ping em segundos'),
('wol_port', '9', 'Porta para Wake-on-LAN'),
('session_timeout', '3600', 'Timeout da sessão em segundos'),
('max_login_attempts', '5', 'Máximo de tentativas de login'),
('log_retention_days', '90', 'Dias de retenção de logs');

-- NOTA: Credenciais de acesso remoto devem ser configuradas via API
-- ou inseridas aqui manualmente (senha deve estar criptografada)
-- Exemplo (senha criptografada necessária):
-- INSERT INTO remote_credentials (credential_name, username, password_encrypted, description, is_default) 
-- VALUES ('default', 'Administrador', 'senha_criptografada_aqui', 'Credenciais padrão', 1);

-- =====================================================
-- VIEWS ÚTEIS
-- =====================================================

-- View para hosts online
CREATE OR REPLACE VIEW v_hosts_online AS
SELECT * FROM hosts WHERE status = 'online' AND is_active = 1;

-- View para hosts offline
CREATE OR REPLACE VIEW v_hosts_offline AS
SELECT * FROM hosts WHERE status = 'offline' AND is_active = 1;

-- View para logs não sincronizados
CREATE OR REPLACE VIEW v_logs_pending_sync AS
SELECT * FROM logs WHERE synced = 0 ORDER BY timestamp DESC;

-- View para estatísticas de hosts
CREATE OR REPLACE VIEW v_host_stats AS
SELECT 
    COUNT(*) as total_hosts,
    SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online_count,
    SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline_count,
    SUM(CASE WHEN status = 'unknown' THEN 1 ELSE 0 END) as unknown_count
FROM hosts 
WHERE is_active = 1;

-- =====================================================
-- STORED PROCEDURES
-- =====================================================

DELIMITER //

-- Procedure para registrar log
CREATE PROCEDURE sp_register_log(
    IN p_user_id VARCHAR(50),
    IN p_user_email VARCHAR(100),
    IN p_host_id INT,
    IN p_host_ip VARCHAR(45),
    IN p_action VARCHAR(255),
    IN p_action_type VARCHAR(50),
    IN p_details TEXT,
    IN p_status VARCHAR(20),
    IN p_ip_address VARCHAR(45)
)
BEGIN
    INSERT INTO logs (user_id, user_email, host_id, host_ip, action, action_type, details, status, ip_address, synced)
    VALUES (p_user_id, p_user_email, p_host_id, p_host_ip, p_action, p_action_type, p_details, p_status, p_ip_address, 0);
    SELECT LAST_INSERT_ID() as log_id;
END //

-- Procedure para atualizar status do host
CREATE PROCEDURE sp_update_host_status(
    IN p_host_id INT,
    IN p_status VARCHAR(20)
)
BEGIN
    UPDATE hosts 
    SET status = p_status, 
        last_seen = NOW(),
        synced = 0
    WHERE id = p_host_id;
END //

-- Procedure para adicionar à fila de sincronização
CREATE PROCEDURE sp_add_to_sync_queue(
    IN p_table_name VARCHAR(50),
    IN p_record_id INT,
    IN p_operation VARCHAR(20),
    IN p_data JSON
)
BEGIN
    INSERT INTO sync_queue (table_name, record_id, operation, data, status)
    VALUES (p_table_name, p_record_id, p_operation, p_data, 'pending');
END //

DELIMITER ;

-- =====================================================
-- FIM DO SCRIPT
-- =====================================================
