<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    echo "🔍 Verificação da Tabela LOGS\n";
    echo "============================\n\n";
    
    // 1. Verificar se tabela existe
    $tableCheck = $db->selectOne(
        "SELECT table_name FROM information_schema.tables 
         WHERE table_schema = ? AND table_name = 'logs'",
        [DB_NAME]
    );
    
    if (!$tableCheck) {
        echo "❌ Tabela 'logs' NÃO existe!\n";
        echo "\nExecutar: php labcontrol-backend/full-setup.php\n";
        exit(1);
    }
    
    echo "✅ Tabela 'logs' existe\n\n";
    
    // 2. Obter estrutura da tabela
    $columns = $db->select(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.columns 
         WHERE table_schema = ? AND table_name = 'logs'",
        [DB_NAME]
    );
    
    echo "📋 Colunas da tabela:\n";
    echo "---\n";
    foreach ($columns as $col) {
        echo "• " . $col['COLUMN_NAME'] . " (" . $col['COLUMN_TYPE'] . ") - Null: " . $col['IS_NULLABLE'] . "\n";
    }
    
    // 3. Contar registos
    $total = $db->selectOne("SELECT COUNT(*) as cnt FROM logs");
    echo "\n\n📊 Total de registos: " . $total['cnt'] . "\n";
    
    // 4. Ver últimos registos
    $logs = $db->select(
        "SELECT id, user_id, user_email, action_type, status, timestamp FROM logs 
         ORDER BY id DESC LIMIT 10"
    );
    
    if (!empty($logs)) {
        echo "\n📝 Últimos 10 registos:\n";
        echo "---\n";
        foreach ($logs as $log) {
            echo "ID: " . $log['id'] . " | Email: " . $log['user_email'] . " | Tipo: " . $log['action_type'] 
                . " | Status: " . $log['status'] . " | Data: " . $log['timestamp'] . "\n";
        }
    } else {
        echo "\nℹ️  Nenhum registo na tabela logs.\n";
    }
    
    // 5. Tentar inserir um registo de teste
    echo "\n\n🧪 Teste de Insert\n";
    echo "---\n";
    
    $testInsert = $db->logAction(
        null,
        'teste@labcontrol.local',
        null,
        null,
        'Teste de logging',
        'auth',
        json_encode(['ip' => '127.0.0.1', 'test' => true]),
        'failed'
    );
    
    if ($testInsert) {
        echo "✅ Insert bem-sucedido! ID: " . $testInsert . "\n";
    } else {
        echo "❌ Falha no insert\n";
    }
    
} catch (Throwable $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
}
?>

