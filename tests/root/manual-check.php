<?php
require_once '../../labcontrol-backend/config/config.php';
require_once '../../labcontrol-backend/includes/Database.php';

$db = Database::getInstance();
$hosts = $db->getAllHosts();

echo "Verificando " . count($hosts) . " hosts...
";

foreach ($hosts as $host) {
    $isOnline = pingHost($host['ip']);
    $newStatus = $isOnline ? 'online' : 'offline';
    
    echo "Host: {$host['hostname']} ({$host['ip']}) - Atual: {$host['status']} -> Novo: $newStatus ";
    
    if ($host['status'] !== $newStatus) {
        $db->updateHostStatus($host['id'], $newStatus);
        echo "[ATUALIZADO]";
    }
    echo "
";
}

echo "Concluído.
";

