<?php
require_once '../../labcontrol-backend/config/config.php';

function testPing($ip) {
    echo "Testando PING para: $ip
";
    $timeout = 2; // segundos
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = "ping -n 1 -w " . ($timeout * 1000) . " " . escapeshellarg($ip);
    } else {
        $command = "ping -c 1 -W " . $timeout . " " . escapeshellarg($ip);
    }
    
    echo "Comando: $command
";
    exec($command, $output, $returnCode);
    
    echo "Return Code: $returnCode
";
    echo "Output:
" . implode("
", $output) . "
";
    
    return $returnCode === 0;
}

// Tenta pingar o localhost e um IP externo comum
echo "--- Teste 1: Localhost ---
";
var_dump(testPing('127.0.0.1'));

echo "
--- Teste 2: Google DNS (Externo) ---
";
var_dump(testPing('8.8.8.8'));

