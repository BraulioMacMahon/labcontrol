<?php
/**
 * LabControl - API de Gerenciamento de Hosts
 * 
 * CRUD de hosts e operações relacionadas
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/FirebaseIntegration.php';

// Headers CORS
header('Access-Control-Allow-Origin: ' . getCorsOrigin());
header('Access-Control-Allow-Methods: ' . CORS_ALLOWED_METHODS);
header('Access-Control-Allow-Headers: ' . CORS_ALLOWED_HEADERS);
header('Content-Type: application/json; charset=utf-8');

// Responder a preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Obter ação e método cedo para uso em validações/fallbacks
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Verificar autenticação
$headers = getallheaders();
$token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
$payload = validateJWT($token);

if (!$payload) {
    jsonResponse(false, 'Token inválido ou expirado', null, 401);
}

// Obter ação
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$db = Database::getInstance();
$firebase = new FirebaseIntegration();

switch ($action) {
    // =====================================================
    // LISTAR TODOS OS HOSTS
    // =====================================================
    case 'list':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $status = $_GET['status'] ?? null;
        $hosts = $db->getAllHosts($status);
        $stats = $db->getHostStats();

        // Normalizar retornos em caso de erro nas consultas (evita enviar boolean false ao frontend)
        if ($hosts === false) {
            logError('getAllHosts failed', ['status' => $status]);
            $hosts = [];
        }

        if ($stats === false) {
            logError('getHostStats failed');
            $stats = [];
        }

        jsonResponse(true, 'Hosts listados', [
            'hosts' => $hosts,
            'stats' => $stats
        ]);
        break;
    
    // =====================================================
    // OBTER HOST POR ID
    // =====================================================
    case 'get':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $id = intval($_GET['id'] ?? 0);
        
        if (!$id) {
            jsonResponse(false, 'ID do host é obrigatório', null, 400);
        }
        
        $host = $db->getHostById($id);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        // Buscar logs do host
        $logs = $db->select(
            "SELECT * FROM logs WHERE host_id = :host_id ORDER BY timestamp DESC LIMIT 20",
            [':host_id' => $id]
        );
        
        jsonResponse(true, 'Host encontrado', [
            'host' => $host,
            'recent_logs' => $logs
        ]);
        break;
    
    // =====================================================
    // CRIAR HOST
    // =====================================================
    case 'create':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Apenas admin pode criar hosts
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validações
        $ip = $input['ip'] ?? '';
        $hostname = $input['hostname'] ?? '';
        $macAddress = $input['mac_address'] ?? null;
        $isActive = $input['is_active'] ?? 1;

        if (empty($ip) || empty($hostname)) {
            jsonResponse(false, 'IP e hostname são obrigatórios', null, 400);
        }

        // Validar IP
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            jsonResponse(false, 'IP inválido', null, 400);
        }

        // Verificar se IP já existe (incluindo inativos)
        $existingRaw = $db->selectOne("SELECT id, is_active FROM hosts WHERE ip = :ip", [':ip' => $ip]);

        if ($existingRaw) {
            if ($existingRaw['is_active'] == 1) {
                jsonResponse(false, 'Já existe um host ativo com este IP', null, 409);
            }

            // Reativar e atualizar host existente
            $hostId = $existingRaw['id'];
            $db->update('hosts', [
                'hostname' => $hostname,
                'mac_address' => $macAddress,
                'status' => 'unknown',
                'is_active' => $isActive,
                'synced' => 0
            ], 'id = :id', [':id' => $hostId]);
        } else {
            // Inserir novo host
            $hostId = $db->insert('hosts', [
                'ip' => $ip,
                'hostname' => $hostname,
                'mac_address' => $macAddress,
                'status' => 'unknown',
                'is_active' => $isActive,
                'last_seen' => date('Y-m-d H:i:s'),
                'synced' => 0
            ]);
        }
        
        if (!$hostId) {
            jsonResponse(false, 'Erro ao criar/reativar host', null, 500);
        }
        
        // Sincronizar com Firebase (com supressão de erro para não bloquear API)
        try {
            if ($firebase->isEnabled()) {
                $host = $db->getHostById($hostId);
                $firebase->syncHost($host);
            }
        } catch (Exception $e) {
            logError('Firebase Sync Error during creation', ['error' => $e->getMessage()]);
        }
        
        // Registrar log
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            $hostId,
            $ip,
            'Host criado',
            'host',
            json_encode(['hostname' => $hostname]),
            'success'
        );
        
        jsonResponse(true, 'Host criado com sucesso', [
            'host_id' => $hostId,
            'ip' => $ip,
            'hostname' => $hostname
        ], 201);
        break;
    
    // =====================================================
    // ATUALIZAR HOST
    // =====================================================
    case 'update':
        if ($method !== 'PUT' && $method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Apenas admin pode atualizar hosts
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = intval($_GET['id'] ?? ($input['id'] ?? 0));
        
        if (!$id) {
            jsonResponse(false, 'ID do host é obrigatório', null, 400);
        }
        
        $host = $db->getHostById($id);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Dados a atualizar
        $updateData = [];
        $allowedFields = ['hostname', 'mac_address', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }
        
        if (empty($updateData)) {
            jsonResponse(false, 'Nenhum dado para atualizar', null, 400);
        }
        
        $updateData['synced'] = 0;
        
        $updated = $db->update('hosts', $updateData, 'id = :id', [':id' => $id]);
        
        if ($updated === false) {
            jsonResponse(false, 'Erro ao atualizar host', null, 500);
        }
        
        // Sincronizar com Firebase
        if ($firebase->isEnabled()) {
            $updatedHost = $db->getHostById($id);
            $firebase->syncHost($updatedHost);
        }
        
        // Registrar log
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            $id,
            $host['ip'],
            'Host atualizado',
            'host',
            json_encode($updateData),
            'success'
        );
        
        jsonResponse(true, 'Host atualizado com sucesso', [
            'host_id' => $id,
            'updated_fields' => array_keys($updateData)
        ]);
        break;
    
    // =====================================================
    // EXCLUIR HOST (SOFT DELETE)
    // =====================================================
    case 'delete':
        if ($method !== 'DELETE') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Apenas admin pode excluir hosts
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $id = intval($_GET['id'] ?? 0);
        
        if (!$id) {
            jsonResponse(false, 'ID do host é obrigatório', null, 400);
        }
        
        $host = $db->getHostById($id);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        // Soft delete
        $updated = $db->update('hosts', 
            ['is_active' => 0, 'synced' => 0], 
            'id = :id', 
            [':id' => $id]
        );
        
        if ($updated === false) {
            jsonResponse(false, 'Erro ao excluir host', null, 500);
        }
        
        // Remover do Firebase
        if ($firebase->isEnabled()) {
            $firebase->deleteHost($id);
        }
        
        // Registrar log
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            $id,
            $host['ip'],
            'Host excluído',
            'host',
            json_encode(['hostname' => $host['hostname']]),
            'success'
        );
        
        jsonResponse(true, 'Host excluído com sucesso', ['host_id' => $id]);
        break;
    
    // =====================================================
    // VERIFICAR STATUS DE TODOS OS HOSTS
    // =====================================================
    case 'check-all':
        if ($method !== 'GET' && $method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $hosts = $db->getAllHosts();
        $results = [];
        
        foreach ($hosts as $host) {
            $isOnline = pingHost($host['ip']);
            $newStatus = $isOnline ? 'online' : 'offline';
            
            if ($host['status'] !== $newStatus) {
                $db->updateHostStatus($host['id'], $newStatus);
            }
            
            $results[] = [
                'id' => $host['id'],
                'ip' => $host['ip'],
                'hostname' => $host['hostname'],
                'status' => $newStatus,
                'changed' => $host['status'] !== $newStatus
            ];
        }
        
        // Registrar log
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            null,
            null,
            'Verificação de status de todos os hosts',
            'monitor',
            json_encode(['checked' => count($results)]),
            'success'
        );
        
        jsonResponse(true, 'Verificação concluída', [
            'results' => $results,
            'stats' => $db->getHostStats()
        ]);
        break;
    
    // =====================================================
    // OBTER ESTATÍSTICAS
    // =====================================================
    case 'stats':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $stats = $db->getHostStats();
        
        // Adicionar estatísticas de logs
        $logStats = $db->selectOne(
            "SELECT 
                COUNT(*) as total_logs,
                COUNT(CASE WHEN DATE(timestamp) = CURDATE() THEN 1 END) as today_logs,
                COUNT(CASE WHEN synced = 0 THEN 1 END) as pending_sync
            FROM logs"
        );
        
        jsonResponse(true, 'Estatísticas obtidas', [
            'hosts' => $stats,
            'logs' => $logStats
        ]);
        break;
    
    // =====================================================
    // IMPORTAR HOSTS EM MASSA
    // =====================================================
    case 'import':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Apenas admin pode importar
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $hosts = $input['hosts'] ?? [];
        
        if (empty($hosts) || !is_array($hosts)) {
            jsonResponse(false, 'Lista de hosts é obrigatória', null, 400);
        }
        
        $imported = 0;
        $failed = 0;
        $errors = [];
        
        $db->beginTransaction();
        
        try {
            foreach ($hosts as $index => $hostData) {
                if (empty($hostData['ip']) || empty($hostData['hostname'])) {
                    $failed++;
                    $errors[] = "Linha {$index}: IP e hostname são obrigatórios";
                    continue;
                }
                
                if (!filter_var($hostData['ip'], FILTER_VALIDATE_IP)) {
                    $failed++;
                    $errors[] = "Linha {$index}: IP inválido";
                    continue;
                }
                
                // Verificar se já existe
                $existing = $db->getHostByIP($hostData['ip']);
                if ($existing) {
                    $failed++;
                    $errors[] = "Linha {$index}: IP {$hostData['ip']} já existe";
                    continue;
                }
                
                $hostId = $db->insert('hosts', [
                    'ip' => $hostData['ip'],
                    'hostname' => $hostData['hostname'],
                    'mac_address' => $hostData['mac_address'] ?? null,
                    'status' => 'unknown',
                    'os_type' => $hostData['os_type'] ?? 'Windows',
                    'location' => $hostData['location'] ?? null,
                    'description' => $hostData['description'] ?? null,
                    'is_active' => 1,
                    'last_seen' => date('Y-m-d H:i:s'),
                    'synced' => 0
                ]);
                
                if ($hostId) {
                    $imported++;
                } else {
                    $failed++;
                    $errors[] = "Linha {$index}: Erro ao inserir";
                }
            }
            
            $db->commit();
            
            // Sincronizar com Firebase
            if ($firebase->isEnabled()) {
                $pendingHosts = $db->select("SELECT * FROM hosts WHERE synced = 0");
                foreach ($pendingHosts as $host) {
                    $firebase->syncHost($host);
                }
            }
            
            // Registrar log
            $db->logAction(
                $payload['user_id'],
                $payload['email'],
                null,
                null,
                'Importação de hosts em massa',
                'host',
                json_encode(['imported' => $imported, 'failed' => $failed]),
                'success'
            );
            
            jsonResponse(true, 'Importação concluída', [
                'imported' => $imported,
                'failed' => $failed,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            jsonResponse(false, 'Erro na importação: ' . $e->getMessage(), null, 500);
        }
        break;
    
    // =====================================================
    // GERENCIAR CREDENCIAIS PADRÃO
    // =====================================================
    case 'set-credentials':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Apenas admin pode configurar credenciais
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        $description = $input['description'] ?? 'Credenciais padrão para acesso remoto';
        
        if (empty($username) || empty($password)) {
            jsonResponse(false, 'Username e password são obrigatórios', null, 400);
        }
        
        // Criptografar senha
        $encryptedPassword = encryptString($password);
        
        if ($encryptedPassword === null) {
            jsonResponse(false, 'Erro ao criptografar senha', null, 500);
        }
        
        // Salvar credenciais
        $credentialId = $db->saveDefaultCredentials($username, $encryptedPassword, $description);
        
        if ($credentialId) {
            // Atualizar configuração em memória (para uso imediato)
            // Nota: Em produção, você pode querer reiniciar o servidor ou usar cache
            
            $db->logAction(
                $payload['user_id'],
                $payload['email'],
                null,
                null,
                'Credenciais padrão atualizadas',
                'config',
                json_encode(['username' => $username]),
                'success'
            );
            
            jsonResponse(true, 'Credenciais padrão salvas com sucesso', [
                'credential_id' => $credentialId,
                'username' => $username
            ]);
        } else {
            jsonResponse(false, 'Erro ao salvar credenciais', null, 500);
        }
        break;
    
    // =====================================================
    // OBTER CREDENCIAIS CONFIGURADAS (SEM SENHA)
    // =====================================================
    case 'get-credentials':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Apenas admin pode ver credenciais
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $credentials = $db->getAllCredentials();
        
        // Remover senhas criptografadas da resposta
        $safeCredentials = array_map(function($cred) {
            return [
                'id' => $cred['id'],
                'credential_name' => $cred['credential_name'],
                'username' => $cred['username'],
                'description' => $cred['description'],
                'is_default' => (bool)$cred['is_default'],
                'created_at' => $cred['created_at'],
                'updated_at' => $cred['updated_at']
            ];
        }, $credentials);
        
        jsonResponse(true, 'Credenciais obtidas', [
            'credentials' => $safeCredentials,
            'default_configured' => !empty($credentials) && $credentials[0]['is_default']
        ]);
        break;
    
    // =====================================================
    // ATUALIZAR CREDENCIAIS DE HOST ESPECÍFICO
    // =====================================================
    case 'set-host-credentials':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Apenas admin pode configurar credenciais
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $hostId = intval($_GET['id'] ?? 0);
        
        if (!$hostId) {
            jsonResponse(false, 'ID do host é obrigatório', null, 400);
        }
        
        $host = $db->getHostById($hostId);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        $useDefault = $input['use_default'] ?? false;
        
        if (!$useDefault && (empty($username) || empty($password))) {
            jsonResponse(false, 'Username e password são obrigatórios (ou use_default: true)', null, 400);
        }
        
        if ($useDefault) {
            // Usar credenciais padrão
            $updated = $db->updateHostCredentials($hostId, null, null, true);
            
            $db->logAction(
                $payload['user_id'],
                $payload['email'],
                $hostId,
                $host['ip'],
                'Host configurado para usar credenciais padrão',
                'config',
                null,
                'success'
            );
            
            jsonResponse(true, 'Host configurado para usar credenciais padrão');
        } else {
            // Criptografar e salvar credenciais específicas
            $encryptedPassword = encryptString($password);
            
            if ($encryptedPassword === null) {
                jsonResponse(false, 'Erro ao criptografar senha', null, 500);
            }
            
            $updated = $db->updateHostCredentials($hostId, $username, $encryptedPassword, false);
            
            $db->logAction(
                $payload['user_id'],
                $payload['email'],
                $hostId,
                $host['ip'],
                'Credenciais específicas do host atualizadas',
                'config',
                json_encode(['username' => $username]),
                'success'
            );
            
            jsonResponse(true, 'Credenciais do host atualizadas com sucesso', [
                'host_id' => $hostId,
                'username' => $username
            ]);
        }
        break;
    
    default:
        jsonResponse(false, 'Ação não encontrada', null, 404);
}
