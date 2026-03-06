<?php
require_once __DIR__ . '/../../labcontrol-backend/config/config.php';

function processPowerShellOutput($output) {
    $jsonOutput = implode('', $output);
    $result = json_decode($jsonOutput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Se nÃ£o for JSON vÃ¡lido, pode ser um erro bruto do PowerShell
        return [
            'success' => false,
            'error' => 'Erro ao processar saÃda: ' . $jsonOutput,
            'raw' => $output
        ];
    }
    
    return $result;
}

$testOutput = ['{"success":true,"processes":[{"Name":"explorer","Id":1234}]}'];
print_r(processPowerShellOutput($testOutput));

$testError = ['{"success":false,"error":"ACESSO_NEGADO"}'];
print_r(processPowerShellOutput($testError));

$testRaw = ['Erro: Host nÃ£o encontrado'];
print_r(processPowerShellOutput($testRaw));

