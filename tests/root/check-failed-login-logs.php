<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    
    echo "📋 Verificar Logs de Tentativas de Login Falhadas\n";
    echo "================================================\n\n";
    
    // Obter todos os logs de falhas
    $failedLogs = $db->select(
        "SELECT id, user_email, action, timestamp, details FROM logs 
         WHERE action_type = 'auth' AND status = 'failed' 
         ORDER BY timestamp DESC LIMIT 20"
    );
    
    if (empty($failedLogs)) {
        echo "❌ Nenhuma tentativa falhada registada ainda.\n";
    } else {
        echo "✅ Total de tentativas falhadas: " . count($failedLogs) . "\n\n";
        
        foreach ($failedLogs as $i => $log) {
            echo ($i + 1) . ". " . str_repeat("-", 50) . "\n";
            echo "Email: " . $log['user_email'] . "\n";
            echo "Ação: " . $log['action'] . "\n";
            echo "Data/Hora: " . $log['timestamp'] . "\n";
            echo "Detalhes: " . ($log['details'] ?? 'N/A') . "\n";
            echo "\n";
        }
    }
    
    // Estatísticas
    echo "\n📊 Estatísticas (Últimas 24 horas):\n";
    echo "==================================\n";
    
    $stats = $db->select(
        "SELECT user_email, COUNT(*) as tentativas FROM logs 
         WHERE action_type = 'auth' AND status = 'failed' 
         AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         GROUP BY user_email
         ORDER BY tentativas DESC"
    );
    
    if (empty($stats)) {
        echo "Nenhuma tentativa falhada nas últimas 24 horas.\n";
    } else {
        foreach ($stats as $stat) {
            echo "• " . $stat['user_email'] . ": " . $stat['tentativas'] . " tentativas\n";
        }
    }
    
} catch (Throwable $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
?>

