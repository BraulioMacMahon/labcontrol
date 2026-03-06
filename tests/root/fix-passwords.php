<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    
    // Users that need password rehashing
    $usersToFix = [
        'admin@labcontrol.local' => 'admin123',
        'operator1@labcontrol.local' => 'operator123',
        'operator2@labcontrol.local' => 'operator123'
    ];
    
    $fixed = [];
    
    foreach ($usersToFix as $email => $password) {
        // Hash the password with modern bcrypt
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        
        // Update the user
        $updated = $db->update(
            'users',
            ['password_hash' => $newHash],
            'email = :email',
            [':email' => $email]
        );
        
        if ($updated) {
            $fixed[] = [
                'email' => $email,
                'status' => 'FIXED',
                'old_hash' => 'bcrypt $2b$',
                'new_hash' => substr($newHash, 0, 20) . '...'
            ];
        } else {
            $fixed[] = [
                'email' => $email,
                'status' => 'FAILED'
            ];
        }
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'SUCCESS',
        'message' => 'Senhas foram rehasheadas com sucesso',
        'fixed_users' => $fixed
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

