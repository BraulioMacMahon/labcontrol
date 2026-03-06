<?php
/**
 * LabControl - Fix Database Script
 * Repara estrutura das tabelas adicionando colunas faltantes
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../labcontrol-backend/config/config.php';

function addColumnIfNotExists($pdo, $table, $column, $definition) {
    try {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM $table LIKE '$column'");
        if ($checkColumn->rowCount() === 0) {
            $sql = "ALTER TABLE $table ADD COLUMN $column $definition";
            $pdo->exec($sql);
            return [
                'status' => 'adicionada',
                'column' => $column,
                'message' => "Coluna $column adicionada"
            ];
        } else {
            return [
                'status' => 'existe',
                'column' => $column,
                'message' => "Coluna $column já existe"
            ];
        }
    } catch (Exception $e) {
        return [
            'status' => 'erro',
            'column' => $column,
            'error' => $e->getMessage()
        ];
    }
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    echo json_encode([
        'step' => 'conectado',
        'message' => 'Conectado ao banco: ' . DB_NAME
    ], JSON_PRETTY_PRINT) . "\n";
    
    // ===== REPARAR TABELA USERS =====
    echo json_encode([
        'step' => 'reparando_users',
        'message' => 'Reparando tabela users'
    ], JSON_PRETTY_PRINT) . "\n";
    
    $columnsToAdd = [
        'role' => "ENUM('admin', 'operator') DEFAULT 'operator'",
        'firebase_uid' => 'VARCHAR(100) DEFAULT NULL',
        'is_active' => 'TINYINT(1) DEFAULT 1',
        'last_login' => 'DATETIME DEFAULT NULL',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];
    
    $results = [];
    foreach ($columnsToAdd as $column => $definition) {
        $result = addColumnIfNotExists($pdo, 'users', $column, $definition);
        $results[] = $result;
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    }
    
    // Criar índices se não existirem
    try {
        $pdo->exec("ALTER TABLE users ADD INDEX idx_email (email)");
        echo json_encode(['status' => 'ok', 'message' => 'Índice idx_email criado']
, JSON_PRETTY_PRINT) . "\n";
    } catch (Exception $e) {
        echo json_encode(['status' => 'info', 'message' => 'Índice idx_email já existe'], JSON_PRETTY_PRINT) . "\n";
    }
    
    try {
        $pdo->exec("ALTER TABLE users ADD INDEX idx_firebase_uid (firebase_uid)");
        echo json_encode(['status' => 'ok', 'message' => 'Índice idx_firebase_uid criado'], JSON_PRETTY_PRINT) . "\n";
    } catch (Exception $e) {
        echo json_encode(['status' => 'info', 'message' => 'Índice idx_firebase_uid já existe'], JSON_PRETTY_PRINT) . "\n";
    }
    
    // ===== INSERIR USUÁRIOS =====
    echo json_encode([
        'step' => 'inserindo_usuarios',
        'message' => 'Verificando/Inserindo usuários'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Verificar admin
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM users WHERE email = ?");
    $stmt->execute(['admin@labcontrol.local']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['cnt'] == 0) {
        try {
            $adminPasswordHash = password_hash('admin123', PASSWORD_BCRYPT);
            $sql = "INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, 'admin', 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['admin@labcontrol.local', $adminPasswordHash]);
            
            echo json_encode([
                'step' => 'admin_criado',
                'email' => 'admin@labcontrol.local',
                'password' => 'admin123',
                'role' => 'admin'
            ], JSON_PRETTY_PRINT) . "\n";
        } catch (Exception $e) {
            echo json_encode([
                'step' => 'admin_erro',
                'error' => $e->getMessage()
            ], JSON_PRETTY_PRINT) . "\n";
        }
    } else {
        echo json_encode([
            'step' => 'admin_existe',
            'email' => 'admin@labcontrol.local'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // Verificar operator
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM users WHERE email = ?");
    $stmt->execute(['operator@labcontrol.local']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['cnt'] == 0) {
        try {
            $operatorPasswordHash = password_hash('operator123', PASSWORD_BCRYPT);
            $sql = "INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, 'operator', 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['operator@labcontrol.local', $operatorPasswordHash]);
            
            echo json_encode([
                'step' => 'operator_criado',
                'email' => 'operator@labcontrol.local',
                'password' => 'operator123',
                'role' => 'operator'
            ], JSON_PRETTY_PRINT) . "\n";
        } catch (Exception $e) {
            echo json_encode([
                'step' => 'operator_erro',
                'error' => $e->getMessage()
            ], JSON_PRETTY_PRINT) . "\n";
        }
    } else {
        echo json_encode([
            'step' => 'operator_existe',
            'email' => 'operator@labcontrol.local'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // ===== LISTAR USUÁRIOS =====
    echo json_encode([
        'step' => 'listando_usuarios',
        'message' => 'Usuários cadastrados'
    ], JSON_PRETTY_PRINT) . "\n";
    
    $stmt = $pdo->query("SELECT id, email, role, is_active FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'step' => 'usuarios_listados',
        'count' => count($users),
        'usuarios' => $users
    ], JSON_PRETTY_PRINT) . "\n";
    
    // ===== SUCESSO =====
    echo json_encode([
        'step' => 'completo',
        'status' => 'SUCCESS',
        'message' => 'Banco de dados reparado com sucesso!',
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
        'step' => 'erro_fatal',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT) . "\n";
}
?>


