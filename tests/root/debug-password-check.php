<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    
    // Get all users with password hashes
    $users = $db->select("SELECT id, email, password_hash, role FROM users ORDER BY id");
    
    header('Content-Type: application/json; charset=utf-8');
    
    // Test credentials for each user
    $testCredentials = [
        'operator1@labcontrol.local' => 'operator123',
        'operator2@labcontrol.local' => 'operator123',
        'operator@labcontrol.local' => 'operator123',
        'admin@labcontrol.local' => 'admin123'
    ];
    
    $results = [];
    foreach ($users as $user) {
        $email = $user['email'];
        $passwordHash = $user['password_hash'];
        $testPassword = $testCredentials[$email] ?? 'unknown';
        
        // Test password verification
        $passwordMatch = password_verify($testPassword, $passwordHash);
        
        $results[] = [
            'id' => $user['id'],
            'email' => $email,
            'role' => $user['role'],
            'password_hash' => substr($passwordHash, 0, 20) . '...',
            'test_password' => $testPassword,
            'password_matches' => $passwordMatch,
            'hash_algorithm' => password_get_info($passwordHash)['algoName'] ?? 'unknown'
        ];
    }
    
    echo json_encode([
        'status' => 'SUCCESS',
        'total_users' => count($results),
        'password_check' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'ERROR',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>

