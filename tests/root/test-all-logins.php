<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    
    // Test credentials for all users
    $testCredentials = [
        ['email' => 'admin@labcontrol.local', 'password' => 'admin123'],
        ['email' => 'operator@labcontrol.local', 'password' => 'operator123'],
        ['email' => 'operator1@labcontrol.local', 'password' => 'operator123'],
        ['email' => 'operator2@labcontrol.local', 'password' => 'operator123']
    ];
    
    $results = [];
    
    foreach ($testCredentials as $cred) {
        $email = $cred['email'];
        $password = $cred['password'];
        
        // Get user from database
        $user = $db->selectOne(
            "SELECT id, email, role, password_hash FROM users WHERE email = ?",
            [$email]
        );
        
        if (!$user) {
            $results[] = [
                'email' => $email,
                'test_password' => $password,
                'status' => 'USER_NOT_FOUND'
            ];
        } else {
            // Verify password
            $passwordMatch = password_verify($password, $user['password_hash']);
            
            $results[] = [
                'email' => $email,
                'role' => $user['role'],
                'test_password' => $password,
                'password_verified' => $passwordMatch,
                'status' => $passwordMatch ? 'LOGIN_OK' : 'PASSWORD_MISMATCH',
                'hash_type' => substr($user['password_hash'], 0, 4)
            ];
        }
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'SUCCESS',
        'message' => 'Teste de credenciais completado',
        'login_tests' => $results
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

