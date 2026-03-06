<?php
require_once __DIR__ . '/../../labcontrol-backend/config/config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Listar colunas existentes
    $stmt = $pdo->query("DESCRIBE logs");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Colunas atuais: " . implode(', ', $columns) . "
";

    if (!in_array('timestamp', $columns)) {
        echo "Adicionando coluna timestamp...
";
        $pdo->exec("ALTER TABLE logs ADD COLUMN timestamp DATETIME DEFAULT CURRENT_TIMESTAMP AFTER error_message");
        echo "Coluna timestamp adicionada com sucesso!
";
    } else {
        echo "A coluna timestamp já existe.
";
    }

} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "
";
}


