<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    
    echo "🔧 Atualizando tabela LOGS\n";
    echo "=========================\n\n";
    
    $conn = $db->getConnection();
    
    // Lista de colunas que faltam
    $sqlStatements = [
        "ALTER TABLE logs ADD COLUMN user_email VARCHAR(100) DEFAULT NULL AFTER user_id",
        "ALTER TABLE logs ADD COLUMN host_id INT DEFAULT NULL AFTER user_email",
        "ALTER TABLE logs ADD COLUMN action_type VARCHAR(50) DEFAULT 'general' AFTER action",
        "ALTER TABLE logs ADD COLUMN details TEXT DEFAULT NULL AFTER action_type",
        "ALTER TABLE logs ADD COLUMN status VARCHAR(20) DEFAULT 'success' AFTER details",
        "ALTER TABLE logs ADD COLUMN error_message TEXT DEFAULT NULL AFTER status",
        "ALTER TABLE logs ADD COLUMN firebase_id VARCHAR(100) DEFAULT NULL AFTER error_message",
        "ALTER TABLE logs ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL AFTER firebase_id",
        "ALTER TABLE logs ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL AFTER ip_address",
        "ALTER TABLE logs ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER user_agent"
    ];
    
    foreach ($sqlStatements as $sql) {
        try {
            echo "Executando: " . substr($sql, 0, 60) . "...\n";
            $conn->exec($sql);
            echo "✅ OK\n\n";
        } catch (PDOException $e) {
            // Coluna já pode existir
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "⚠️  Coluna já existe\n\n";
            } else {
                echo "❌ Erro: " . $e->getMessage() . "\n\n";
            }
        }
    }
    
    // Verificar colunas agora
    echo "\n📋 Colunas após atualização:\n";
    echo "===========================\n";
    
    $describe = $db->select("DESCRIBE logs");
    foreach ($describe as $col) {
        echo "• " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    
    echo "\n✅ Tabela atualizada com sucesso!\n";
    
} catch (Throwable $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
?>

