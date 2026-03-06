<?php
/**
 * LabControl - Backend API
 * 
 * Página inicial do backend - redireciona para documentação
 */

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'name' => 'LabControl Backend API',
    'version' => '1.0.0',
    'status' => 'online',
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoints' => [
        'auth' => '/api/auth.php',
        'hosts' => '/api/hosts.php',
        'control' => '/api/control.php',
        'sync' => '/api/sync.php',
        'logs' => '/api/logs.php'
    ],
    'documentation' => '/docs/index.html'
], JSON_PRETTY_PRINT);
