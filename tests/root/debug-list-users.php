<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    
    // Get all users
    $users = $db->select("SELECT id, email, role, is_active, created_at FROM users ORDER BY id");
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'SUCCESS',
        'total_users' => count($users),
        'users' => $users
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'ERROR',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
?>

