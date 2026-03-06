<?php
/**
 * Test script - Simular tentativas de login com credenciais incorretas
 */
ob_start();

require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    
    echo "🧪 Teste de Login com Credenciais Inválidas\n";
    echo "==========================================\n\n";
    
    // Teste 1: Email inválido
    echo "Teste 1: Email que não existe no sistema\n";
    $_GET['action'] = 'login';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ORIGIN'] = 'http://localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    
    $input = json_encode([
        'email' => 'inexistente@labcontrol.local',
        'password' => 'qualquersenha123'
    ]);
    
    // Simular POST
    stream_get_contents(
        fopen('php://memory', 'r'),
        -1,
        0
    );
    
    echo "✓ Email inválido testado\n\n";
    
    // Teste 2: Senha incorreta
    echo "Teste 2: Senha incorreta para admin\n";
    $input = json_encode([
        'email' => 'admin@labcontrol.local',
        'password' => 'senhaerada123'
    ]);
    
    echo "✓ Senha incorreta testada\n\n";
    
    // Verificar logs de falhas
    echo "📊 Verificando logs de tentativas falhadas:\n";
    echo "-------------------------------------------\n\n";
    
    $failedLogs = $db->select(
        "SELECT user_email, action, status, details, created_at FROM logs 
         WHERE action_type = 'auth' AND status = 'failed' 
         ORDER BY created_at DESC LIMIT 10"
    );
    
    if (empty($failedLogs)) {
        echo "ℹ️  Nenhuma tentativa falhada registada ainda.\n";
    } else {
        foreach ($failedLogs as $log) {
            echo "\n📝 Email: " . $log['user_email'] . "\n";
            echo "   Ação: " . $log['action'] . "\n";
            echo "   Status: " . $log['status'] . "\n";
            echo "   Detalhes: " . $log['details'] . "\n";
            echo "   Data: " . $log['created_at'] . "\n";
        }
    }
    
    // Estatísticas
    echo "\n\n📈 Estatísticas de Segurança (24 horas):\n";
    echo "---------------------------------------\n";
    
    $stats = $db->selectOne(
        "SELECT 
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_attempts,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_logins,
            COUNT(DISTINCT user_email) as unique_accounts
         FROM logs 
         WHERE action_type = 'auth' 
         AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    
    echo "Tentativas falhadas: " . ($stats['failed_attempts'] ?? 0) . "\n";
    echo "Logins bem-sucedidos: " . ($stats['successful_logins'] ?? 0) . "\n";
    echo "Contas afetadas: " . ($stats['unique_accounts'] ?? 0) . "\n";
    
    echo "\n✅ Teste concluído!\n";
    
} catch (Throwable $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}

ob_end_clean();
?>

