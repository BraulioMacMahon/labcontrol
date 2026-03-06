<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

try {
    $db = Database::getInstance();
    
    // Obter todos os registos da tabela com DESCRIBE
    $describe = $db->select("DESCRIBE logs");
    
    echo "📋 Colunas completas:\n";
    echo "===================\n";
    foreach ($describe as $col) {
        echo "Field: " . $col['Field'] . " | Type: " . $col['Type'] . " | Null: " . $col['Null'] . " | Key: " . $col['Key'] . " | Default: " . ($col['Default'] ?? 'NULL') . "\n";
    }
    
    echo "\n\n📊 Dados na tabela:\n";
    echo "=================\n";
    
    // Usar SELECT *
    $allLogs = $db->select("SELECT * FROM logs");
    
    echo "Total de registos: " . count($allLogs) . "\n\n";
    
    if (!empty($allLogs)) {
        foreach ($allLogs as $log) {
            echo "Registo:\n";
            foreach ($log as $key => $value) {
                echo "  " . $key . ": " . ($value ?? 'NULL') . "\n";
            }
            echo "\n";
        }
    }
    
} catch (Throwable $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
?>

