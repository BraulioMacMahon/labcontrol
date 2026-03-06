<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    $count = $db->selectOne("SELECT COUNT(*) as total FROM logs");
    $size = $db->selectOne("SELECT 
        data_length + index_length AS total_size 
        FROM information_schema.TABLES 
        WHERE table_schema = '" . DB_NAME . "' 
        AND table_name = 'logs'");
        
    header('Content-Type: application/json');
    echo json_encode([
        'total_logs' => $count['total'],
        'size_mb' => round($size['total_size'] / 1024 / 1024, 2)
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

