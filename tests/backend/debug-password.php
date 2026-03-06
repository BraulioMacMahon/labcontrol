<?php
/**
 * LabControl - Debug Login Password
 * Verifica a senha armazenada no banco e testa password_verify
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../labcontrol-backend/config/config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    echo json_encode([
        'passo' => '1',
        'message' => 'Conectado ao banco de dados'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Buscar admin
    $stmt = $pdo->prepare("SELECT id, email, password_hash, role, is_active FROM users WHERE email = ?");
    $stmt->execute(['admin@labcontrol.local']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode([
            'passo' => '2_erro',
            'error' => 'Usuário admin@labcontrol.local NÃO ENCONTRADO no banco!'
        ], JSON_PRETTY_PRINT) . "\n";
        exit;
    }
    
    echo json_encode([
        'passo' => '2',
        'message' => 'Usuário encontrado',
        'email' => $user['email'],
        'role' => $user['role'],
        'is_active' => $user['is_active'],
        'user_id' => $user['id']
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Mostrar hash
    echo json_encode([
        'passo' => '3',
        'message' => 'Hash da senha armazenada',
        'password_hash' => $user['password_hash'],
        'hash_length' => strlen($user['password_hash']),
        'hash_type' => password_get_info($user['password_hash'])
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Testar a senha padrão
    $testPassword = 'admin123';
    $isValid = password_verify($testPassword, $user['password_hash']);
    
    echo json_encode([
        'passo' => '4',
        'message' => 'Teste de password_verify',
        'test_password' => $testPassword,
        'password_verify_result' => $isValid ? 'CORRETO ✅' : 'INCORRETO ❌',
        'is_valid' => $isValid
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Se não validar, criar nova senha
    if (!$isValid) {
        echo json_encode([
            'passo' => '5',
            'message' => 'Senha não corresponde! Vou recriar o usuário com hash correto...'
        ], JSON_PRETTY_PRINT) . "\n";
        
        // Deletar usuário existente
        $pdo->prepare("DELETE FROM users WHERE email = ?")->execute(['admin@labcontrol.local']);
        
        // Criar novo com hash correto
        $newPasswordHash = password_hash('admin123', PASSWORD_BCRYPT);
        $sql = "INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, 'admin', 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['admin@labcontrol.local', $newPasswordHash]);
        
        echo json_encode([
            'passo' => '6',
            'message' => 'Usuário admin recriado com hash correto!',
            'new_hash' => $newPasswordHash,
            'action' => 'DELETE + INSERT'
        ], JSON_PRETTY_PRINT) . "\n";
        
        // Verificar novamente
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE email = ?");
        $stmt->execute(['admin@labcontrol.local']);
        $newUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $verifyNew = password_verify('admin123', $newUser['password_hash']);
        echo json_encode([
            'passo' => '7',
            'message' => 'Verificação pós-recriação',
            'password_verify_result' => $verifyNew ? 'CORRETO ✅' : 'INCORRETO ❌'
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // Listar todos os usuários
    echo json_encode([
        'passo' => '8',
        'message' => 'Usuários atuais no banco'
    ], JSON_PRETTY_PRINT) . "\n";
    
    $stmt = $pdo->query("SELECT id, email, role, is_active FROM users ORDER BY id");
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'passo' => '9',
        'usuarios' => $allUsers
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Status final
    echo json_encode([
        'status' => 'PRONTO PARA LOGIN',
        'credentials' => [
            'email' => 'admin@labcontrol.local',
            'password' => 'admin123'
        ],
        'next_step' => 'Agora teste o login novamente em debug-login.html'
    ], JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'erro' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT) . "\n";
}
?>


