<?php
/**
 * LabControl - API de Logs
 * 
 * Endpoints: list, get, search, export
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Database.php';

// Headers CORS
header('Access-Control-Allow-Origin: ' . CORS_ALLOWED_ORIGINS);
header('Access-Control-Allow-Methods: ' . CORS_ALLOWED_METHODS);
header('Access-Control-Allow-Headers: ' . CORS_ALLOWED_HEADERS);
header('Content-Type: application/json; charset=utf-8');

// Responder a preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

switch ($action) {
    // =====================================================
    // LISTAR LOGS
    // =====================================================
    case 'list':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Parâmetros de paginação
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        // Filtros
        $userId = $_GET['user_id'] ?? null;
        $hostId = $_GET['host_id'] ?? null;
        $actionType = $_GET['action_type'] ?? null;
        $status = $_GET['status'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $search = $_GET['search'] ?? null;
        
        // Construir query
        $where = ["1=1"];
        $params = [];
        
        if ($userId) {
            $where[] = "user_id = :user_id";
            $params[':user_id'] = $userId;
        }
        
        if ($hostId) {
            $where[] = "host_id = :host_id";
            $params[':host_id'] = $hostId;
        }
        
        if ($actionType) {
            $where[] = "action_type = :action_type";
            $params[':action_type'] = $actionType;
        }
        
        if ($status) {
            $where[] = "status = :status";
            $params[':status'] = $status;
        }
        
        if ($dateFrom) {
            $where[] = "timestamp >= :date_from";
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        
        if ($dateTo) {
            $where[] = "timestamp <= :date_to";
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }
        
        if ($search) {
            $where[] = "(action LIKE :search OR user_email LIKE :search OR host_ip LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Contar total
        $countResult = $db->selectOne(
            "SELECT COUNT(*) as total FROM logs WHERE {$whereClause}",
            $params
        );
        $total = intval($countResult['total'] ?? 0);
        
        // Buscar logs
        $logs = $db->select(
            "SELECT l.*, h.hostname as host_name 
             FROM logs l 
             LEFT JOIN hosts h ON l.host_id = h.id 
             WHERE {$whereClause} 
             ORDER BY l.`timestamp` DESC 
             LIMIT :limit OFFSET :offset",
            array_merge($params, [':limit' => $limit, ':offset' => $offset])
        );
        
        // Estatísticas
        $stats = $db->selectOne(
            "SELECT 
                COUNT(*) as total_logs,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
                COUNT(CASE WHEN DATE(timestamp) = CURDATE() THEN 1 END) as today_count
            FROM logs"
        );
        
        jsonResponse(true, 'Logs listados', [
            'logs' => $logs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ],
            'stats' => $stats
        ]);
        break;
    
    // =====================================================
    // OBTER LOG POR ID
    // =====================================================
    case 'get':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $id = intval($_GET['id'] ?? 0);
        
        if (!$id) {
            jsonResponse(false, 'ID do log é obrigatório', null, 400);
        }
        
        $log = $db->selectOne(
            "SELECT l.*, h.hostname as host_name 
             FROM logs l 
             LEFT JOIN hosts h ON l.host_id = h.id 
             WHERE l.id = :id",
            [':id' => $id]
        );
        
        if (!$log) {
            jsonResponse(false, 'Log não encontrado', null, 404);
        }
        
        jsonResponse(true, 'Log encontrado', ['log' => $log]);
        break;
    
    // =====================================================
    // PESQUISAR LOGS
    // =====================================================
    case 'search':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $query = $_GET['q'] ?? '';
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        
        if (empty($query)) {
            jsonResponse(false, 'Termo de busca é obrigatório', null, 400);
        }
        
        $logs = $db->select(
            "SELECT l.*, h.hostname as host_name 
             FROM logs l 
             LEFT JOIN hosts h ON l.host_id = h.id 
             WHERE l.action LIKE :query 
                OR l.user_email LIKE :query 
                OR l.host_ip LIKE :query
                OR l.details LIKE :query
             ORDER BY l.timestamp DESC 
             LIMIT :limit",
            [':query' => '%' . $query . '%', ':limit' => $limit]
        );
        
        jsonResponse(true, 'Resultados da busca', [
            'query' => $query,
            'logs' => $logs,
            'count' => count($logs)
        ]);
        break;
    
    // =====================================================
    // ESTATÍSTICAS DE LOGS
    // =====================================================
    case 'stats':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Estatísticas gerais
        $general = $db->selectOne(
            "SELECT 
                COUNT(*) as total_logs,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT host_id) as unique_hosts
            FROM logs"
        );
        
        // Logs por dia (últimos 7 dias)
        $byDay = $db->select(
            "SELECT 
                DATE(timestamp) as date,
                COUNT(*) as count,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as success,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed
            FROM logs 
            WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DATE(timestamp)
            ORDER BY date DESC"
        );
        
        // Logs por tipo de ação
        $byActionType = $db->select(
            "SELECT 
                action_type,
                COUNT(*) as count
            FROM logs 
            GROUP BY action_type
            ORDER BY count DESC"
        );
        
        // Logs por usuário (top 10)
        $byUser = $db->select(
            "SELECT 
                user_id,
                user_email,
                COUNT(*) as count
            FROM logs 
            WHERE user_id IS NOT NULL
            GROUP BY user_id, user_email
            ORDER BY count DESC
            LIMIT 10"
        );
        
        jsonResponse(true, 'Estatísticas de logs', [
            'general' => $general,
            'by_day' => $byDay,
            'by_action_type' => $byActionType,
            'by_user' => $byUser
        ]);
        break;
    
    // =====================================================
    // EXPORTAR LOGS
    // =====================================================
    case 'export':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Apenas admin pode exportar
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $format = $_GET['format'] ?? 'json'; // json, csv
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        $logs = $db->select(
            "SELECT l.*, h.hostname as host_name 
             FROM logs l 
             LEFT JOIN hosts h ON l.host_id = h.id 
             WHERE l.timestamp BETWEEN :date_from AND :date_to
             ORDER BY l.timestamp DESC",
            [
                ':date_from' => $dateFrom . ' 00:00:00',
                ':date_to' => $dateTo . ' 23:59:59'
            ]
        );
        
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=logs_' . date('Y-m-d') . '.csv');
            
            $output = fopen('php://output', 'w');
            
            // Cabeçalho
            fputcsv($output, ['ID', 'Data/Hora', 'Usuário', 'Host', 'IP do Host', 'Ação', 'Tipo', 'Status', 'Detalhes']);
            
            // Dados
            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['id'],
                    $log['timestamp'],
                    $log['user_email'],
                    $log['host_name'],
                    $log['host_ip'],
                    $log['action'],
                    $log['action_type'],
                    $log['status'],
                    $log['details']
                ]);
            }
            
            fclose($output);
            exit;
        } else {
            // JSON
            jsonResponse(true, 'Logs exportados', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'count' => count($logs),
                'logs' => $logs
            ]);
        }
        break;
    
    // =====================================================
    // LIMPAR LOGS ANTIGOS
    // =====================================================
    case 'cleanup':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Apenas admin pode limpar logs
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $days = intval($input['days'] ?? LOG_RETENTION_DAYS);
        
        if ($days < 7) {
            jsonResponse(false, 'Período mínimo de retenção é 7 dias', null, 400);
        }
        
        // Contar logs a serem removidos
        $countResult = $db->selectOne(
            "SELECT COUNT(*) as count FROM logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)",
            [':days' => $days]
        );
        $toDelete = intval($countResult['count'] ?? 0);
        
        // Remover logs antigos
        $deleted = $db->cleanupOldLogs($days);
        
        // Registrar log
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            null,
            null,
            'Limpeza de logs antigos',
            'system',
            json_encode(['days' => $days, 'deleted' => $toDelete]),
            'success'
        );
        
        jsonResponse(true, 'Limpeza concluída', [
            'days_retained' => $days,
            'logs_deleted' => $toDelete
        ]);
        break;
    
    default:
        jsonResponse(false, 'Ação não encontrada', null, 404);
}
