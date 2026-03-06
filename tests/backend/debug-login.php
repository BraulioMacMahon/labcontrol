<?php
/**
 * Debug script para testar o login
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../labcontrol-backend/config/config.php';
require_once __DIR__ . '/../../labcontrol-backend/includes/Database.php';

echo "=== Debug Login ===\n\n";

// 1. Testar conexão BD
echo "1. Conectando ao BD...\n";
try {
    $db = Database::getInstance();
    echo "✓ Conectado ao BD\n\n";
} catch (Exception $e) {
    echo "✗ Erro: " . $e->getMessage() . "\n\n";
    exit;
}

// 2. Listar usuários
echo "2. Usuários no BD:\n";
$users = $db->select("SELECT id, email, role, is_active FROM users");
if ($users) {
    foreach ($users as $u) {
        echo "   - {$u['email']} (role: {$u['role']}, active: {$u['is_active']})\n";
    }
} else {
    echo "   ✗ Nenhum usuário found\n";
}
echo "\n";

// 3. Buscar admin
echo "3. Buscando admin@labcontrol.local...\n";
$admin = $db->getUserByEmail('admin@labcontrol.local');
if ($admin) {
    echo "   ✓ Encontrado!\n";
    echo "   - ID: {$admin['id']}\n";
    echo "   - Email: {$admin['email']}\n";
    echo "   - Role: {$admin['role']}\n";
    echo "   - Password hash: {$admin['password_hash']}\n";
    echo "   - Active: {$admin['is_active']}\n\n";
} else {
    echo "   ✗ Não encontrado!\n\n";
}

// 4. Testar password_verify
echo "4. Testando password_verify:\n";
if ($admin) {
    $testPassword = 'admin123';
    $isValid = password_verify($testPassword, $admin['password_hash']);
    echo "   Senha testada: $testPassword\n";
    echo "   Hash: " . substr($admin['password_hash'], 0, 20) . "...\n";
    echo "   Resultado: " . ($isValid ? "✓ VÁLIDA" : "✗ INVÁLIDA") . "\n\n";
} else {
    echo "   ✗ Não foi possível testar (usuário não existe)\n\n";
}

// 5. Testar login request
echo "5. Simulando requisição POST de login:\n";
$testEmail = 'admin@labcontrol.local';
$testPass = 'admin123';
$user = $db->getUserByEmail($testEmail);

if (!$user) {
    echo "   ✗ Usuário não encontrado\n";
} elseif (!password_verify($testPass, $user['password_hash'])) {
    echo "   ✗ Senha inválida\n";
} elseif (!$user['is_active']) {
    echo "   ✗ Usuário desativado\n";
} else {
    echo "   ✓ Login bem-sucedido!\n";
    echo "   - Usuário: " . $user['email'] . "\n";
    echo "   - Role: " . $user['role'] . "\n";
}

echo "\n=== Fim do Debug ===\n";
?>


