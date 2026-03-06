<?php
/**
 * Debug script - Check all users in database
 */
ob_start();

require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    
    // Get all users
    $users = $db->select("SELECT id, email, role, is_active, created_at FROM users ORDER BY id");
    
    echo json_encode([
        'status' => 'SUCCESS',
        'total_users' => count($users),
        'users' => $users
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'ERROR',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT) . "\n";
}

ob_end_clean();
?>

