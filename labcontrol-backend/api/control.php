<?php
/**
 * LabControl - API de Controle Remoto
 * 
 * Endpoints: shutdown, restart, wol, status, processes, killprocess
 * Suporta credenciais de autenticação para PowerShell remoto
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/FirebaseIntegration.php';

// Headers CORS
header('Access-Control-Allow-Origin: ' . CORS_ALLOWED_ORIGINS);
header('Access-Control-Allow-Methods: ' . CORS_ALLOWED_METHODS);
header('Access-Control-Allow-Headers: ' . CORS_ALLOWED_HEADERS);
header('Content-Type: application/json; charset=utf-8');

// Responder a preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar autenticação
$headers = getallheaders();
$token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
$payload = validateJWT($token);

if (!$payload) {
    jsonResponse(false, 'Token inválido ou expirado', null, 401);
}

// Obter ação
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$db = Database::getInstance();
$firebase = new FirebaseIntegration();

// Caminho para scripts PowerShell
$powershellPath = __DIR__ . '/../powershell/';

try {
    switch ($action) {
    // =====================================================
    // DESLIGAR HOST (SHUTDOWN)
    // =====================================================
    case 'shutdown':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $ip = $input['ip'] ?? '';
        $hostname = $input['hostname'] ?? '';
        
        if (empty($ip) && empty($hostname)) {
            jsonResponse(false, 'IP ou Hostname do host é obrigatório', null, 400);
        }
        
        $host = !empty($hostname) ? $db->selectOne("SELECT * FROM hosts WHERE hostname = :h AND is_active = 1", [':h' => $hostname]) : $db->getHostByIP($ip);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        $ip = $host['ip']; // Garantir IP para logs se necessário
        $credentials = getHostCredentials($host);
        
        // Criar um script temporário para evitar problemas de escape de caracteres na senha
        $tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shutdown_' . time() . '.ps1';
        $psContent = '$pass = "' . str_replace('"', '`"', $credentials['password']) . '" | ConvertTo-SecureString -AsPlainText -Force; ' . "\r\n" .
                     '$cred = New-Object System.Management.Automation.PSCredential("' . str_replace('"', '`"', $credentials['username']) . '", $pass); ' . "\r\n" .
                     'Invoke-Command -ComputerName ' . $host['hostname'] . ' -Credential $cred -Authentication Basic -ScriptBlock { shutdown.exe /s /f /t 0 }';
        
        file_put_contents($tempScript, $psContent);
        
        $fullCommand = "powershell.exe -ExecutionPolicy Bypass -File \"$tempScript\" 2>&1";
        
        $output = [];
        $returnCode = 0;
        exec($fullCommand, $output, $returnCode);
        
        // Apagar script temporário
        @unlink($tempScript);
        
        $success = ($returnCode === 0);
        
        if ($success) {
            $db->updateHostStatus($host['id'], 'offline');
        }
        
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            $host['id'],
            $ip,
            'Desligamento remoto (Script Temp)',
            'control',
            json_encode(['output' => $output]),
            $success ? 'success' : 'failed'
        );
        
        if ($success) {
            jsonResponse(true, 'Comando de desligamento enviado com sucesso', [
                'host' => $host['hostname'],
                'ip' => $ip
            ]);
        } else {
            jsonResponse(false, 'Falha ao executar desligamento. Verifique se o WinRM aceita Basic Auth e se o usuário tem permissão.', [
                'error' => implode("\n", $output)
            ], 500);
        }
        break;
    
    // =====================================================
    // REINICIAR HOST (RESTART)
    // =====================================================
    case 'restart':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $ip = $input['ip'] ?? '';
        $hostname = $input['hostname'] ?? '';
        
        if (empty($ip) && empty($hostname)) {
            jsonResponse(false, 'IP ou Hostname do host é obrigatório', null, 400);
        }
        
        $host = !empty($hostname) ? $db->selectOne("SELECT * FROM hosts WHERE hostname = :h AND is_active = 1", [':h' => $hostname]) : $db->getHostByIP($ip);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        $ip = $host['ip'];
        $credentials = getHostCredentials($host);
        
        // Criar um script temporário para evitar problemas de escape de caracteres na senha
        $tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restart_' . time() . '.ps1';
        $psContent = '$pass = "' . str_replace('"', '`"', $credentials['password']) . '" | ConvertTo-SecureString -AsPlainText -Force; ' . "\r\n" .
                     '$cred = New-Object System.Management.Automation.PSCredential("' . str_replace('"', '`"', $credentials['username']) . '", $pass); ' . "\r\n" .
                     'Invoke-Command -ComputerName ' . $host['hostname'] . ' -Credential $cred -Authentication Basic -ScriptBlock { shutdown.exe /r /f /t 0 }';
        
        file_put_contents($tempScript, $psContent);
        
        $fullCommand = "powershell.exe -ExecutionPolicy Bypass -File \"$tempScript\" 2>&1";
        
        $output = [];
        $returnCode = 0;
        exec($fullCommand, $output, $returnCode);
        
        // Apagar script temporário
        @unlink($tempScript);
        
        $success = ($returnCode === 0);
        
        if ($success) {
            $db->updateHostStatus($host['id'], 'rebooting');
        }
        
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            $host['id'],
            $ip,
            'Reinício remoto (Script Temp)',
            'control',
            json_encode(['output' => $output]),
            $success ? 'success' : 'failed'
        );
        
        if ($success) {
            jsonResponse(true, 'Comando de reinício enviado com sucesso', [
                'host' => $host['hostname'],
                'ip' => $ip
            ]);
        } else {
            jsonResponse(false, 'Falha ao executar reinício. Verifique se o WinRM no computador alvo aceita Basic Auth e se o usuário tem permissão.', [
                'error' => implode("\n", $output)
            ], 500);
        }
        break;
    
    // =====================================================
    // WAKE ON LAN (WOL)
    // =====================================================
    case 'wol':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $ip = $input['ip'] ?? '';
        $macAddress = $input['mac_address'] ?? '';
        
        if (empty($ip)) {
            jsonResponse(false, 'IP do host é obrigatório', null, 400);
        }
        
        $host = $db->getHostByIP($ip);
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        if (empty($macAddress)) {
            $macAddress = $host['mac_address'];
        }
        
        if (empty($macAddress)) {
            jsonResponse(false, 'Endereço MAC é obrigatório para Wake-on-LAN', null, 400);
        }
        
        if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $macAddress)) {
            jsonResponse(false, 'Endereço MAC inválido', null, 400);
        }
        
        $result = sendWOL($macAddress, $ip);
        
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            $host['id'],
            $ip,
            'Wake-on-LAN',
            'control',
            json_encode(['mac_address' => $macAddress, 'result' => $result]),
            $result ? 'success' : 'failed'
        );
        
        if ($result) {
            jsonResponse(true, 'Pacote Wake-on-LAN enviado com sucesso', [
                'host' => $host['hostname'],
                'ip' => $ip,
                'mac_address' => $macAddress
            ]);
        } else {
            jsonResponse(false, 'Falha ao enviar pacote Wake-on-LAN', null, 500);
        }
        break;
    
    // =====================================================
    // WAKE ON LAN EM MASSA (WOL ALL)
    // =====================================================
    case 'wol-all':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }

        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }

        $offlineHosts = $db->getAllHosts('offline');
        
        if (empty($offlineHosts)) {
            jsonResponse(true, 'Nenhum host offline para ligar.');
        }

        $successCount = 0;
        $failedCount = 0;

        foreach ($offlineHosts as $host) {
            if (!empty($host['mac_address'])) {
                $result = sendWOL($host['mac_address'], $host['ip']);
                if ($result) {
                    $successCount++;
                } else {
                    $failedCount++;
                }
            } else {
                $failedCount++;
            }
        }

        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            null,
            null,
            'Wake-on-LAN em massa',
            'control',
            json_encode(['success_count' => $successCount, 'failed_count' => $failedCount]),
            $failedCount === 0 ? 'success' : 'partial_failure'
        );

        jsonResponse(true, "Comando WOL enviado para {$successCount} hosts.", [
            'success_count' => $successCount,
            'failed_count' => $failedCount
        ]);
        break;
    
    // =====================================================
    // OBTER STATUS DO HOST
    // =====================================================
    case 'status':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $ip = $_GET['ip'] ?? '';
        $hostname = $_GET['hostname'] ?? '';
        
        if (empty($ip) && empty($hostname)) {
            jsonResponse(false, 'IP ou Hostname do host é obrigatório', null, 400);
        }
        
        $host = !empty($hostname) ? $db->selectOne("SELECT * FROM hosts WHERE hostname = :h AND is_active = 1", [':h' => $hostname]) : $db->getHostByIP($ip);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        $ip = $host['ip'];
        $isOnline = pingHost($ip);
        $status = $isOnline ? 'online' : 'offline';
        
        if ($host['status'] !== $status) {
            $db->updateHostStatus($host['id'], $status);
        }
        
        $details = null;
        if ($isOnline) {
            $details = getHostDetails($host);
        }
        
        jsonResponse(true, 'Status obtido', [
            'host' => [
                'id' => $host['id'],
                'hostname' => $host['hostname'],
                'ip' => $ip,
                'mac_address' => $host['mac_address'],
                'status' => $status,
                'last_seen' => $host['last_seen']
            ],
            'details' => $details
        ]);
        break;
    
    // =====================================================
    // LISTAR PROCESSOS DO HOST
    // =====================================================
    case 'processes':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $ip = $_GET['ip'] ?? '';
        $hostname = $_GET['hostname'] ?? '';
        
        if (empty($ip) && empty($hostname)) {
            jsonResponse(false, 'IP ou Hostname do host é obrigatório', null, 400);
        }
        
        $host = !empty($hostname) ? $db->selectOne("SELECT * FROM hosts WHERE hostname = :h AND is_active = 1", [':h' => $hostname]) : $db->getHostByIP($ip);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        $ip = $host['ip'];
        if (!pingHost($ip)) {
            jsonResponse(false, 'Host está offline', null, 400);
        }
        
        $credentials = getHostCredentials($host);
        
        $scriptPath = $powershellPath . 'Get-Processes.ps1';
        
        $tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'get_proc_' . time() . '.ps1';
        
        if (empty($tempScript)) {
            throw new Exception("Falha ao gerar caminho do script temporário");
        }
        
        // Usar aspas simples no PowerShell para evitar expansão de variáveis ($) na senha
        $psContent = 'powershell.exe -ExecutionPolicy Bypass -File \'' . str_replace("'", "''", $scriptPath) . '\' ' .
                     '-ComputerName \'' . str_replace("'", "''", $host['hostname']) . '\' ' .
                     '-Username \'' . str_replace("'", "''", $credentials['username']) . '\' ' .
                     '-Password \'' . str_replace("'", "''", $credentials['password']) . '\'';
        
        if (empty($psContent)) {
            throw new Exception("Conteúdo do comando PowerShell está vazio");
        }
        
        if (file_put_contents($tempScript, $psContent) === false) {
            logError("Falha ao escrever script temporário", [
                'tempScript' => $tempScript,
                'psContent_length' => strlen($psContent),
                'sys_temp' => sys_get_temp_dir()
            ]);
            throw new Exception("Falha ao escrever script temporário em: " . $tempScript);
        }
        
        $fullCommand = "powershell.exe -ExecutionPolicy Bypass -File \"$tempScript\" 2>&1";
        
        $output = [];
        $returnCode = 0;
        exec($fullCommand, $output, $returnCode);
        
        // Apagar script temporário
        @unlink($tempScript);
        
        $result = processPowerShellOutput($output);
        
        if (isset($result['error']) && strpos($result['error'], 'ACESSO_NEGADO') !== false) {
            jsonResponse(false, 'Credenciais inválidas ou usuário sem permissões administrativas.', [
                'error_type' => 'authentication_failed'
            ], 403);
        }
        
        if (isset($result['error']) && strpos($result['error'], 'WINRM_ERRO') !== false) {
            jsonResponse(false, 'Não foi possível conectar ao host. Verifique se o WinRM está habilitado.', [
                'error_type' => 'winrm_error'
            ], 503);
        }
        
        if ($returnCode !== 0 || (isset($result['success']) && !$result['success'])) {
            jsonResponse(false, 'Falha ao obter processos', [
                'error' => $result['error'] ?? 'Erro desconhecido'
            ], 500);
        }
        
        // Processos podem vir direto ou em $result['processes']
        $processes = $result['processes'] ?? $result;
        
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            $host['id'],
            $ip,
            'Listagem de processos',
            'monitor',
            json_encode(['process_count' => count($processes)]),
            'success'
        );
        
        jsonResponse(true, 'Processos obtidos', [
            'host' => $host['hostname'],
            'ip' => $ip,
            'process_count' => count($processes),
            'processes' => $processes
        ]);
        break;
    
    // =====================================================
    // ENCERRAR PROCESSO
    // =====================================================
    case 'killprocess':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $ip = $input['ip'] ?? '';
        $hostname = $input['hostname'] ?? '';
        $pid = intval($input['pid'] ?? 0);
        $processName = $input['process_name'] ?? '';
        $force = $input['force'] ?? false;
        
        if (empty($ip) && empty($hostname)) {
            jsonResponse(false, 'IP ou Hostname do host é obrigatório', null, 400);
        }
        
        $host = !empty($hostname) ? $db->selectOne("SELECT * FROM hosts WHERE hostname = :h AND is_active = 1", [':h' => $hostname]) : $db->getHostByIP($ip);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        $ip = $host['ip'];
        if (!pingHost($ip)) {
            jsonResponse(false, 'Host está offline', null, 400);
        }
        
        $credentials = getHostCredentials($host);
        
        $scriptPath = $powershellPath . 'Kill-Process.ps1';
        
        $targetParam = "";
        if ($pid) {
            $targetParam = "-ProcessId {$pid}";
        } else {
            $targetParam = "-ProcessName '" . str_replace("'", "''", $processName) . "'";
        }
        
        $forceParam = $force ? '-Force' : '';
        
        // Criar um script temporário para evitar problemas de escape de caracteres na senha
        $tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kill_' . time() . '.ps1';
        
        // Usar aspas simples no PowerShell para evitar expansão de variáveis ($) na senha
        $psContent = 'powershell.exe -ExecutionPolicy Bypass -File \'' . str_replace("'", "''", $scriptPath) . '\' ' .
                     '-ComputerName \'' . str_replace("'", "''", $host['hostname']) . '\' ' .
                     '-Username \'' . str_replace("'", "''", $credentials['username']) . '\' ' .
                     '-Password \'' . str_replace("'", "''", $credentials['password']) . '\' ' .
                     $targetParam . ' ' . $forceParam;
        
        file_put_contents($tempScript, $psContent);
        
        $fullCommand = "powershell.exe -ExecutionPolicy Bypass -File \"$tempScript\" 2>&1";
        
        $output = [];
        $returnCode = 0;
        exec($fullCommand, $output, $returnCode);
        
        // Apagar script temporário
        @unlink($tempScript);
        
        $result = processPowerShellOutput($output);
        
        if (isset($result['error']) && strpos($result['error'], 'ACESSO_NEGADO') !== false) {
            jsonResponse(false, 'Credenciais inválidas ou usuário sem permissões administrativas.', [
                'error_type' => 'authentication_failed'
            ], 403);
        }
        
        $success = $returnCode === 0 && isset($result['success']) && $result['success'];
        
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            $host['id'],
            $ip,
            'Processo encerrado',
            'control',
            json_encode([
                'pid' => $pid,
                'process_name' => $processName,
                'force' => $force,
                'result' => $result
            ], JSON_PARTIAL_OUTPUT_ON_ERROR),
            $success ? 'success' : 'failed'
        );
        
        if ($success) {
            jsonResponse(true, 'Processo encerrado com sucesso', [
                'host' => $host['hostname'],
                'ip' => $ip,
                'pid' => $pid,
                'process_name' => $processName,
                'message' => $result['message'] ?? null
            ]);
        } else {
            jsonResponse(false, 'Falha ao encerrar processo', [
                'error' => $result['error'] ?? 'Erro desconhecido'
            ], 500);
        }
        break;
    
    // =====================================================
    // EXECUTAR COMANDO PERSONALIZADO
    // =====================================================
    case 'execute':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $ip = $input['ip'] ?? '';
        $hostname = $input['hostname'] ?? '';
        $command = $input['command'] ?? '';
        
        if ((empty($ip) && empty($hostname)) || empty($command)) {
            jsonResponse(false, 'IP/Hostname e comando são obrigatórios', null, 400);
        }
        
        $host = !empty($hostname) ? $db->selectOne("SELECT * FROM hosts WHERE hostname = :h AND is_active = 1", [':h' => $hostname]) : $db->getHostByIP($ip);
        
        if (!$host) {
            jsonResponse(false, 'Host não encontrado', null, 404);
        }
        
        $ip = $host['ip'];
        if (!pingHost($ip)) {
            jsonResponse(false, 'Host está offline', null, 400);
        }
        
        $credentials = getHostCredentials($host);
        
        $scriptPath = $powershellPath . 'Execute-Command.ps1';
        
        // Usar script temporário para evitar problemas de escape de caracteres na senha
        $tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exec_cmd_' . time() . '.ps1';
        $psContent = 'powershell.exe -ExecutionPolicy Bypass -File \'' . str_replace("'", "''", $scriptPath) . '\' ' .
                     '-ComputerName \'' . str_replace("'", "''", $host['hostname']) . '\' ' .
                     '-Username \'' . str_replace("'", "''", $credentials['username']) . '\' ' .
                     '-Password \'' . str_replace("'", "''", $credentials['password']) . '\' ' .
                     '-Command \'' . str_replace("'", "''", $command) . '\'';
        
        file_put_contents($tempScript, $psContent);
        
        $fullCommand = "powershell.exe -ExecutionPolicy Bypass -File \"$tempScript\" 2>&1";
        
        $output = [];
        $returnCode = 0;
        exec($fullCommand, $output, $returnCode);
        
        // Apagar script temporário
        @unlink($tempScript);
        
        $result = processPowerShellOutput($output);
        
        if (isset($result['error']) && strpos($result['error'], 'ACESSO_NEGADO') !== false) {
            jsonResponse(false, 'Credenciais inválidas ou usuário sem permissões administrativas.', [
                'error_type' => 'authentication_failed'
            ], 403);
        }
        
        $success = $returnCode === 0 && isset($result['success']) && $result['success'];
        
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            $host['id'],
            $ip,
            'Comando personalizado executado',
            'control',
            json_encode(['command' => $command, 'result' => $result]),
            $success ? 'success' : 'failed'
        );
        
        jsonResponse($success, $success ? 'Comando executado' : 'Erro na execução', [
            'host' => $host['hostname'],
            'ip' => $ip,
            'command' => $command,
            'output' => $result['output'] ?? null,
            'exit_code' => $result['exit_code'] ?? $returnCode
        ]);
        break;
    
    // =====================================================
    // DESLIGAR TODOS OS HOSTS ATIVOS (SHUTDOWN ALL)
    // =====================================================
    case 'shutdown-all':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }

        if ($payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }

        $activeHosts = $db->getAllHosts('online');
        
        if (empty($activeHosts)) {
            jsonResponse(true, 'Nenhum host online para desligar.');
        }

        // Agrupar hosts por credenciais para execução paralela via Invoke-Command
        $groups = [];
        foreach ($activeHosts as $host) {
            $creds = getHostCredentials($host);
            $key = base64_encode($creds['username'] . '|' . $creds['password']);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'username' => $creds['username'],
                    'password' => $creds['password'],
                    'hostnames' => [],
                    'ids' => []
                ];
            }
            $groups[$key]['hostnames'][] = $host['hostname'];
            $groups[$key]['ids'][] = $host['id'];
        }

        $successCount = 0;
        $failedCount = 0;
        $failedHosts = [];

        foreach ($groups as $group) {
            $hostList = '"' . implode('","', $group['hostnames']) . '"';
            
            $tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shutdown_bulk_' . uniqid() . '.ps1';
            $psContent = sprintf(
                '$pass = "%s" | ConvertTo-SecureString -AsPlainText -Force; ' . "\r\n" .
                '$cred = New-Object System.Management.Automation.PSCredential("%s", $pass); ' . "\r\n" .
                'Invoke-Command -ComputerName %s -Credential $cred -Authentication Basic -ScriptBlock { shutdown.exe /s /f /t 0 } -ErrorAction SilentlyContinue',
                str_replace('"', '`"', $group['password']),
                str_replace('"', '`"', $group['username']),
                $hostList
            );
            
            file_put_contents($tempScript, $psContent);
            
            $fullCommand = "powershell.exe -ExecutionPolicy Bypass -File \"$tempScript\" 2>&1";
            exec($fullCommand, $output, $returnCode);
            @unlink($tempScript);
            
            // Como Invoke-Command pode ter sucesso parcial, o returnCode nem sempre é confiável para o grupo todo.
            // Mas para fins de log simplificado, consideramos o retorno do comando principal.
            if ($returnCode === 0) {
                $successCount += count($group['ids']);
                foreach ($group['ids'] as $id) {
                    $db->updateHostStatus($id, 'offline');
                }
            } else {
                $failedCount += count($group['ids']);
                foreach ($group['hostnames'] as $index => $name) {
                    $failedHosts[] = ['hostname' => $name, 'id' => $group['ids'][$index]];
                }
            }
        }

        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            null,
            null,
            'Desligamento em massa de hosts',
            'control',
            json_encode(['success_count' => $successCount, 'failed_count' => $failedCount]),
            $failedCount === 0 ? 'success' : 'partial_failure'
        );

        if ($failedCount === 0) {
            jsonResponse(true, "Comando de desligamento enviado para {$successCount} hosts.", ['success_count' => $successCount]);
        } else {
            jsonResponse(true, "Comando enviado para {$successCount} hosts. Falha em {$failedCount}.", [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'failed_hosts' => $failedHosts
            ]);
        }
        break;

    default:
        jsonResponse(false, 'Ação não encontrada', null, 404);
}
} catch (Throwable $e) {
    logError('Erro na API de Controle: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'action' => $action
    ]);
    jsonResponse(false, 'Erro interno no servidor: ' . $e->getMessage(), null, 500);
}

// =====================================================
// FUNÇÕES AUXILIARES
// =====================================================

/**
 * Processa a saída do PowerShell e retorna array
 */
function processPowerShellOutput($output) {
    $jsonOutput = implode('', $output);
    $result = json_decode($jsonOutput, true);
    
    if (json_last_error() === JSON_ERROR_NONE) {
        return $result;
    }
    
    // Se não for JSON válido, retorna como texto
    return [
        'success' => false,
        'raw_output' => $jsonOutput,
        'error' => 'Resposta não é JSON válido'
    ];
}

/**
 * Envia pacote Wake-on-LAN
 */
function sendWOL($macAddress, $ip = null) {
    $mac = str_replace([':', '-'], '', $macAddress);
    
    $packet = sprintf(
        '%s%s',
        str_repeat(chr(255), 6),
        str_repeat(pack('H*', $mac), 16)
    );
    
    if ($ip) {
        $parts = explode('.', $ip);
        $broadcast = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.255';
    } else {
        $broadcast = '255.255.255.255';
    }
    
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$socket) {
        return false;
    }
    
    socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
    $result = socket_sendto($socket, $packet, strlen($packet), 0, $broadcast, WOL_PORT);
    socket_close($socket);
    
    return $result !== false;
}

/**
 * Obtém detalhes do host via PowerShell com credenciais
 */
function getHostDetails($host) {
    $scriptPath = __DIR__ . '/../powershell/Get-SystemInfo.ps1';
    $credentials = getHostCredentials($host);

    // Usar script temporário para evitar problemas de escape de caracteres na senha
    $tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'get_details_' . time() . '.ps1';
    $psContent = 'powershell.exe -ExecutionPolicy Bypass -File \'' . str_replace("'", "''", $scriptPath) . '\' ' .
                 '-ComputerName \'' . str_replace("'", "''", $host['ip']) . '\' ' .
                 '-Username \'' . str_replace("'", "''", $credentials['username']) . '\' ' .
                 '-Password \'' . str_replace("'", "''", $credentials['password']) . '\'';

    file_put_contents($tempScript, $psContent);

    $fullCommand = "powershell.exe -ExecutionPolicy Bypass -File \"$tempScript\" 2>&1";

    $output = [];
    $returnCode = 0;
    exec($fullCommand, $output, $returnCode);

    // Apagar script temporário
    @unlink($tempScript);

    if ($returnCode !== 0) {
        return null;
    }

    $result = processPowerShellOutput($output);

    return isset($result['success']) && $result['success'] ? $result : null;
}
