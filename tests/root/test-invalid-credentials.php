<?php
/**
 * Test script - Testar login com credenciais inválidas
 */

require_once '../../labcontrol-backend/config/config.php';

echo "🧪 Teste de Tentativas de Login com Credenciais Inválidas\n";
echo "=======================================================\n\n";

$testCases = [
    [
        'name' => 'Email inválido',
        'email' => 'inexistente@labcontrol.local',
        'password' => 'qualquersenha123'
    ],
    [
        'name' => 'Senha incorreta',
        'email' => 'admin@labcontrol.local',
        'password' => 'senhaerrada123'
    ],
    [
        'name' => 'Email vazio',
        'email' => '',
        'password' => 'admin123'
    ],
    [
        'name' => 'Senha vazia',
        'email' => 'admin@labcontrol.local',
        'password' => ''
    ],
    [
        'name' => 'Email inválido (não é email)',
        'email' => 'naoehum email',
        'password' => 'admin123'
    ]
];

foreach ($testCases as $test) {
    echo "Teste: " . $test['name'] . "\n";
    echo "Email: " . $test['email'] . "\n";
    echo "Senha: " . (str_repeat('*', strlen($test['password']))) . "\n";
    echo "---\n";
    
    // Fazer requisição HTTP
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/labcontrol/labcontrol-backend/api/auth.php?action=login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'email' => $test['email'],
        'password' => $test['password']
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($response, true);
    
    echo "Status HTTP: " . $httpCode . "\n";
    echo "Mensagem: " . ($response['message'] ?? 'N/A') . "\n";
    
    if (isset($response['data']) && is_array($response['data'])) {
        echo "Erros: " . json_encode($response['data']) . "\n";
    }
    
    echo "\n";
}

echo "\n✅ Teste concluído!\n";
echo "\nVerifique os logs em: labcontrol-backend/logs/\n";
?>

