<?php
/**
 * LabControl - API de Sincronização
 */

// Start output buffering
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set up error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'PHP Error: ' . $errstr,
        'file' => $errfile,
        'line' => $errline
    ], JSON_PRETTY_PRINT);
    exit;
}, E_ALL);

try {
    require_once __DIR__ . '/../config/config.php';
    if (ob_get_length()) ob_end_clean();
    ob_start();
    
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../includes/FirebaseIntegration.php';

    // Responder a preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        if (ob_get_length()) ob_end_clean();
        header('Access-Control-Allow-Origin: ' . CORS_ALLOWED_ORIGINS);
        header('Access-Control-Allow-Methods: ' . CORS_ALLOWED_METHODS);
        header('Access-Control-Allow-Headers: ' . CORS_ALLOWED_HEADERS);
        http_response_code(200);
        exit;
    }

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
        case 'status':
            if ($method !== 'GET') jsonResponse(false, 'Método não permitido', null, 405);
            
            $firebaseEnabled = $firebase->isEnabled();
            $pendingHosts = $db->selectOne("SELECT COUNT(*) as count FROM hosts WHERE synced = 0");
            $pendingLogs = $db->selectOne("SELECT COUNT(*) as count FROM logs WHERE synced = 0");
            $queueItems = $db->selectOne("SELECT COUNT(*) as count FROM sync_queue WHERE status = 'pending'");
            $lastSync = $db->selectOne("SELECT timestamp FROM logs WHERE action_type = 'sync' ORDER BY timestamp DESC LIMIT 1");
            
            jsonResponse(true, 'Status da sincronização', [
                'firebase_connected' => $firebaseEnabled,
                'offline_mode' => !$firebaseEnabled,
                'pending_hosts' => intval($pendingHosts['count'] ?? 0),
                'pending_logs' => intval($pendingLogs['count'] ?? 0),
                'queue_items' => intval($queueItems['count'] ?? 0),
                'last_sync' => $lastSync['timestamp'] ?? null
            ]);
            break;
        
        case 'sync':
            if ($method !== 'POST') jsonResponse(false, 'Método não permitido', null, 405);
            
            if (!$firebase->isEnabled()) {
                jsonResponse(false, 'Firebase não configurado ou inacessível. Crie o banco Firestore no console.', [
                    'error_type' => 'firebase_missing',
                    'action_required' => 'https://console.firebase.google.com/'
                ], 200); // Retorna 200 para o frontend processar a mensagem
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $syncType = $input['type'] ?? 'all';
            $results = [];
            
            try {
                if ($syncType === 'all' || $syncType === 'hosts') {
                    $results['hosts'] = $firebase->syncPendingHosts();
                }
                
                if ($syncType === 'all' || $syncType === 'logs') {
                    $results['logs'] = $firebase->syncPendingLogs();
                }
                
                $db->logAction($payload['user_id'], $payload['email'], null, null, 'Sincronização manual', 'sync', json_encode($results), 'success');
                jsonResponse(true, 'Sincronização concluída', $results);
            } catch (Exception $e) {
                jsonResponse(false, 'Falha na sincronização: ' . $e->getMessage(), null, 200);
            }
            break;

        default:
            jsonResponse(false, 'Ação não encontrada', null, 404);
    }

} catch (Throwable $e) {
    if (ob_get_length()) ob_end_clean();
    jsonResponse(false, 'Erro: ' . $e->getMessage(), null, 500);
}
