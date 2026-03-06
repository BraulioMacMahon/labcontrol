<?php
/**
 * Test Script for Auth API
 */

use function PHPSTORM_META\type;

// Set error reporting to display all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode(['step' => '1', 'message' => 'Loading dependencies'], JSON_PRETTY_PRINT) . "\n";
    
    require_once __DIR__ . '/../../labcontrol-backend/config/config.php';
    echo json_encode(['step' => '2', 'message' => 'Config loaded'], JSON_PRETTY_PRINT) . "\n";
    
    require_once __DIR__ . '/../../labcontrol-backend/bootstrap/security.php';
    echo json_encode(['step' => '3', 'message' => 'Security loaded'], JSON_PRETTY_PRINT) . "\n";
    
    require_once __DIR__ . '/../../labcontrol-backend/includes/Database.php';
    echo json_encode(['step' => '4', 'message' => 'Database class loaded'], JSON_PRETTY_PRINT) . "\n";
    
    require_once __DIR__ . '/../../labcontrol-backend/includes/FirebaseIntegration.php';
    echo json_encode(['step' => '5', 'message' => 'Firebase class loaded'], JSON_PRETTY_PRINT) . "\n";
    
    require_once __DIR__ . '/middleware/RateLimiter.php';
    echo json_encode(['step' => '6', 'message' => 'RateLimiter class loaded'], JSON_PRETTY_PRINT) . "\n";
    
    require_once __DIR__ . '/classes/Validator.php';
    echo json_encode(['step' => '7', 'message' => 'Validator class loaded'], JSON_PRETTY_PRINT) . "\n";
    
    echo json_encode(['step' => '8', 'message' => 'Creating Database instance'], JSON_PRETTY_PRINT) . "\n";
    $db = Database::getInstance();
    echo json_encode(['step' => '9', 'message' => 'Database instance created'], JSON_PRETTY_PRINT) . "\n";
    
    echo json_encode(['step' => '10', 'message' => 'Creating Firebase instance'], JSON_PRETTY_PRINT) . "\n";
    $firebase = new FirebaseIntegration();
    echo json_encode(['step' => '11', 'message' => 'Firebase instance created'], JSON_PRETTY_PRINT) . "\n";
    
    echo json_encode([
        'status' => 'SUCCESS',
        'message' => 'All dependencies loaded successfully',
        'php_version' => phpversion(),
        'constants' => [
            'DB_HOST' => DB_HOST,
            'DB_NAME' => DB_NAME,
            'JWT_SECRET' => substr(JWT_SECRET, 0, 10) . '****'
        ]
    ], JSON_PRETTY_PRINT) . "\n";
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'ERROR',
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT) . "\n";
}
?>


