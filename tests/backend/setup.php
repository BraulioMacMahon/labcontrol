<?php
/**
 * LabControl - Script de Inicialização
 * 
 * Cria usuário padrão e faz setup inicial do sistema
 * Execute uma única vez: http://localhost/labcontrol/labcontrol-backend/setup.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../labcontrol-backend/config/config.php';
require_once __DIR__ . '/../../labcontrol-backend/includes/Database.php';

// =====================================================
// SEGURANÇA: Apenas permitir localhost
// =====================================================
$clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$allowedIPs = ['127.0.0.1', 'localhost', '::1'];

if (!in_array($clientIP, $allowedIPs)) {
    http_response_code(403);
    die(json_encode([
        'success' => false,
        'message' => 'Setup pode ser executado apenas do localhost',
        'ip' => $clientIP
    ]));
}

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
    
    // =====================================================
    // 1. Verificar se usuário admin já existe
    // =====================================================
    $existingAdmin = $db->selectOne(
        "SELECT id FROM users WHERE email = ?",
        ['admin@labcontrol.local']
    );
    
    if ($existingAdmin) {
        http_response_code(200);
        die(json_encode([
            'success' => true,
            'message' => 'Usuário admin já existe no sistema',
            'user_id' => $existingAdmin['id']
        ]));
    }
    
    // =====================================================
    // 2. Criar usuário admin padrão
    // =====================================================
    $adminPassword = 'admin123';
    $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);
    
    $adminId = $db->insert('users', [
        'email' => 'admin@labcontrol.local',
        'password_hash' => $passwordHash,
        'role' => 'admin',
        'is_active' => 1
    ]);
    
    if (!$adminId) {
        throw new Exception('Erro ao criar usuário admin');
    }
    
    // =====================================================
    // 3. Criar usuário operador padrão
    // =====================================================
    $operatorPassword = 'operator123';
    $operatorHash = password_hash($operatorPassword, PASSWORD_BCRYPT);
    
    $operatorId = $db->insert('users', [
        'email' => 'operator@labcontrol.local',
        'password_hash' => $operatorHash,
        'role' => 'operator',
        'is_active' => 1
    ]);
    
    if (!$operatorId) {
        throw new Exception('Erro ao criar usuário operador');
    }
    
    // =====================================================
    // 4. Adicionar hosts de exemplo (opcionais)
    // =====================================================
    $hostsAdded = 0;
    $exampleHosts = [
        [
            'ip' => '192.168.1.100',
            'hostname' => 'PC-LAB-01',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'os_type' => 'Windows',
            'description' => 'PC Laboratório #1'
        ],
        [
            'ip' => '192.168.1.101',
            'hostname' => 'PC-LAB-02',
            'mac_address' => 'AA:BB:CC:DD:EE:02',
            'os_type' => 'Windows',
            'description' => 'PC Laboratório #2'
        ],
        [
            'ip' => '192.168.1.102',
            'hostname' => 'PC-LAB-03',
            'mac_address' => 'AA:BB:CC:DD:EE:03',
            'os_type' => 'Windows',
            'description' => 'PC Laboratório #3'
        ]
    ];
    
    foreach ($exampleHosts as $host) {
        try {
            $id = $db->insert('hosts', [
                'ip' => $host['ip'],
                'hostname' => $host['hostname'],
                'mac_address' => $host['mac_address'],
                'os_type' => $host['os_type'],
                'description' => $host['description'],
                'status' => 'unknown',
                'is_active' => 1,
                'use_default_credentials' => 1
            ]);
            if ($id) $hostsAdded++;
        } catch (Exception $e) {
            error_log("Erro ao adicionar host {$host['hostname']}: " . $e->getMessage());
        }
    }
    
    // =====================================================
    // 5. Retornar resultado
    // =====================================================
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Setup inicial concluído com sucesso',
        'data' => [
            'admin_user_created' => [
                'id' => $adminId,
                'email' => 'admin@labcontrol.local',
                'password' => $adminPassword . ' (MUDE ISTO!)',
                'role' => 'admin'
            ],
            'operator_user_created' => [
                'id' => $operatorId,
                'email' => 'operator@labcontrol.local',
                'password' => $operatorPassword . ' (MUDE ISTO!)',
                'role' => 'operator'
            ],
            'example_hosts_added' => $hostsAdded,
            'total_users' => 2,
            'total_hosts' => $hostsAdded,
            'next_steps' => [
                '1. Teste o login com admin@labcontrol.local / admin123',
                '2. Mude as senhas padrão IMEDIATAMENTE',
                '3. Comece a implementação do GUIA_IMPLEMENTACAO.md',
                '4. Não acesse setup.php novamente (delete o arquivo)',
                '5. Use CHECKLIST_DEPLOYMENT.md antes de produção'
            ]
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro durante setup: ' . $e->getMessage(),
        'error' => DEBUG_MODE ? $e->getMessage() : 'Erro interno'
    ]);
}
?>


