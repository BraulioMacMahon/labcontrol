<?php
/**
 * LabControl - API de Segurança
 * Monitoramento de tentativas de login e atividades suspeitas
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'PHP Error',
        'error' => $errstr
    ], JSON_PRETTY_PRINT);
    exit;
}, E_ALL);

try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../bootstrap/security.php';
    require_once __DIR__ . '/../includes/Database.php';
    
    ob_end_clean();
    
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    $db = Database::getInstance();
    
    switch ($action) {
        // =====================================================
        // LISTAR TENTATIVAS DE LOGIN FALHADAS
        // =====================================================
        case 'failed-logins':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Método não permitido'], JSON_PRETTY_PRINT);
                exit;
            }
            
            // VERIFICAR SE UTILIZADOR É ADMIN
            $headers = getallheaders();
            $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
            
            if (!$token) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Token ausente'], JSON_PRETTY_PRINT);
                exit;
            }
            
            $payload = validateJWT($token);
            if (!$payload || $payload['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acesso negado'], JSON_PRETTY_PRINT);
                exit;
            }
            
            // OBTER TENTATIVAS FALHADAS
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);
            
            $failedLogins = $db->select(
                "SELECT id, user_email, action, status, details, timestamp 
                 FROM logs 
                 WHERE action_type = 'auth' AND status = 'failed'
                 ORDER BY timestamp DESC 
                 LIMIT ? OFFSET ?",
                [$limit, $offset]
            );
            
            // CONTAR TOTAL
            $totalResult = $db->selectOne(
                "SELECT COUNT(*) as total FROM logs WHERE action_type = 'auth' AND status = 'failed'"
            );
            
            // ESTATÍSTICAS DE SEGURANÇA
            $recentFailures = $db->selectOne(
                "SELECT COUNT(*) as count FROM logs 
                 WHERE action_type = 'auth' AND status = 'failed' 
                 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Tentativas de login falhadas',
                'statistics' => [
                    'total_failed_attempts' => (int)$totalResult['total'],
                    'failed_last_hour' => (int)$recentFailures['count'],
                    'alert' => (int)$recentFailures['count'] > 10 ? 'ATIVIDADE SUSPEITA - Múltiplas tentativas falhadas' : 'Normal'
                ],
                'failed_logins' => $failedLogins,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'total' => (int)$totalResult['total']
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
        
        // =====================================================
        // OBTER ESTATÍSTICAS DE SEGURANÇA
        // =====================================================
        case 'security-stats':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Método não permitido'], JSON_PRETTY_PRINT);
                exit;
            }
            
            // VERIFICAR SE UTILIZADOR É ADMIN
            $headers = getallheaders();
            $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
            
            if (!$token) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Token ausente'], JSON_PRETTY_PRINT);
                exit;
            }
            
            $payload = validateJWT($token);
            if (!$payload || $payload['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acesso negado'], JSON_PRETTY_PRINT);
                exit;
            }
            
            // ESTATÍSTICAS DETALHADAS
            $stats = [
                'failed_logins_today' => $db->selectOne(
                    "SELECT COUNT(*) as count FROM logs 
                     WHERE action_type = 'auth' AND status = 'failed' AND DATE(created_at) = CURDATE()"
                ),
                'failed_logins_week' => $db->selectOne(
                    "SELECT COUNT(*) as count FROM logs 
                     WHERE action_type = 'auth' AND status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
                ),
                'successful_logins_today' => $db->selectOne(
                    "SELECT COUNT(*) as count FROM logs 
                     WHERE action_type = 'auth' AND status = 'success' AND DATE(created_at) = CURDATE()"
                ),
                'logins_by_email' => $db->select(
                    "SELECT user_email, status, COUNT(*) as count 
                     FROM logs 
                     WHERE action_type = 'auth' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                     GROUP BY user_email, status
                     ORDER BY count DESC LIMIT 20"
                ),
                'failed_attempts_by_ip' => $db->select(
                    "SELECT 
                        JSON_EXTRACT(details, '$.ip') as ip,
                        COUNT(*) as attempts,
                        MAX(created_at) as last_attempt
                     FROM logs 
                     WHERE action_type = 'auth' AND status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                     AND details IS NOT NULL
                     GROUP BY JSON_EXTRACT(details, '$.ip')
                     ORDER BY attempts DESC LIMIT 20"
                )
            ];
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Estatísticas de segurança',
                'statistics' => [
                    'failed_logins_today' => (int)$stats['failed_logins_today']['count'],
                    'failed_logins_week' => (int)$stats['failed_logins_week']['count'],
                    'successful_logins_today' => (int)$stats['successful_logins_today']['count'],
                    'logins_by_email' => $stats['logins_by_email'],
                    'suspicious_ips' => $stats['failed_attempts_by_ip']
                ],
                'recommendation' => (int)$stats['failed_logins_today']['count'] > 20 ? 
                    'AVISO: Muitas tentativas de login falhadas. Considere investigar ou ativar CAPTCHA.' : 
                    'Sistema em operação normal'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
        
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Ação não encontrada',
                'available_actions' => ['failed-logins', 'security-stats']
            ], JSON_PRETTY_PRINT);
    }
    
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>
