<?php
/**
 * LabControl - Teste unitário do parser de saída do PowerShell.
 *
 * Não precisa de base de dados, Apache, Windows ou PowerShell:
 *   php tests/backend/test-powershell-output.php
 *
 * Cobre as situações que provocavam o "500 Falha ao obter processos":
 * JSON partido pela consola, avisos no stderr (2>&1), BOM, codepage OEM,
 * lista vazia/única e classificação dos erros de WinRM/credenciais.
 */

require_once __DIR__ . '/../../labcontrol-backend/includes/PowerShell.php';

$total = 0;
$failed = 0;

function check($name, $condition, $detail = '') {
    global $total, $failed;
    $total++;
    if ($condition) {
        echo "  OK   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " -> $detail" : '') . "\n";
    }
}

echo "LabControl :: processPowerShellOutput\n";

// 1. Formato actual de Get-Processes.ps1.
$r = processPowerShellOutput(['{"success":true,"processes":[{"Id":4,"ProcessName":"explorer","CPU":1.5}]}']);
check('envelope success/processes', isset($r['success']) && $r['success'] === true && count($r['processes']) === 1);

// 2. Lista crua devolvida por versões antigas do script.
$r = processPowerShellOutput(['[{"Id":1,"ProcessName":"idle"},{"Id":2,"ProcessName":"system"}]']);
check('lista crua (script antigo)', count($r) === 2 && $r[1]['ProcessName'] === 'system');

// 3. Um único processo como objecto (não lista) continua a ser parseável.
$r = processPowerShellOutput(['{"Id":1,"ProcessName":"explorer"}']);
check('objecto único', isset($r['Id']) && $r['Id'] === 1);

// 4. powershell.exe partiu o JSON em várias linhas (largura da consola).
$r = processPowerShellOutput([
    '{"success":true,"processes":[{"Id":4,"ProcessName":"explorer",',
    '"CPU":1.5}]}'
]);
check('JSON quebrado em linhas', isset($r['success']) && $r['success'] === true && $r['processes'][0]['Id'] === 4);

// 5. Avisos do PowerShell misturados com o JSON (exec usa 2>&1).
$r = processPowerShellOutput([
    'WARNING: Could not read StartTime for process Idle',
    '[System.Management.Automation] remote session warning',
    '{"success":false,"error":"WINRM_ERRO: WinRM cannot complete the operation"}'
]);
check('avisos antes do payload', isset($r['error']) && strpos($r['error'], 'WINRM_ERRO') !== false);

// 6. BOM UTF-8 no início.
$r = processPowerShellOutput(["\xEF\xBB\xBF" . '{"success":true,"processes":[]}']);
check('BOM UTF-8', isset($r['success']) && $r['success'] === true);

// 7. Saída vazia (script antigo com 0 processos) -> erro explícito, não fatal.
$r = processPowerShellOutput([]);
check('saída vazia', $r['success'] === false && $r['error'] === 'Resposta não é JSON válido');

// 8. Bytes de codepage OEM dentro de uma string JSON (acento em CP1252).
$r = processPowerShellOutput(['{"success":true,"processes":[{"Id":4,"ProcessName":"Explor' . "\xE9" . '"}]}']);
check('UTF-8 inválido é substituído', isset($r['processes']) && count($r['processes']) === 1);

// 9. A password nunca aparece no texto devolvido/logado.
$r = processPowerShellOutput(
    ["powershell.exe -File Get-Processes.ps1 -Password 'S3cr3t\$P@ss' -ComputerName LAB17-PC33"],
    ['S3cr3t$P@ss']
);
$leak = strpos($r['raw_output'] ?? '', 'S3cr3t') !== false;
check('password redigida', !$leak, $r['raw_output'] ?? '');

echo "LabControl :: psClassifyFailure\n";

$f = psClassifyFailure(['success' => false, 'error' => 'ACESSO_NEGADO: Access is denied'], 1);
check('credenciais -> 403', $f['code'] === 403 && $f['error_type'] === 'authentication_failed');

$f = psClassifyFailure(['success' => false, 'error' => 'WINRM_ERRO: WinRM cannot connect'], 1);
check('WinRM -> 503', $f['code'] === 503 && $f['error_type'] === 'winrm_error');

$f = psClassifyFailure(['success' => false, 'error' => 'Resposta não é JSON válido', 'raw_output' => '……'], 0);
check('JSON inválido -> 502', $f['code'] === 502 && $f['error_type'] === 'invalid_output');

$f = psClassifyFailure(['success' => false, 'error' => 'ERRO: Operation timed out'], 1);
check('timeout -> 504', $f['code'] === 504 && $f['error_type'] === 'timeout');

$f = psClassifyFailure(['success' => false, 'error' => 'ERRO: falha desconhecida'], 1, 'Não foi possível obter os processos do host.');
check('erro genérico -> 502 com mensagem', $f['code'] === 502 && strpos($f['message'], 'processos') !== false);

$f = psClassifyFailure(['success' => false], 1);
check('sem mensagem -> texto de diagnóstico', $f['error'] !== '');

echo "\n" . ($failed === 0 ? "PASS" : "FAIL") . ": {$total} testes, {$failed} falhas\n";
exit($failed === 0 ? 0 : 1);
