<?php
/**
 * LabControl - Configuração do Sistema
 * Carrega variáveis do arquivo .env
 */

if (!defined('LABCONTROL')) {
    define('LABCONTROL', true);
}

// =====================================================
// CARREGAR VARIÁVEIS DE AMBIENTE
// =====================================================
require_once __DIR__ . '/../bootstrap/env.php';

/**
 * Persiste uma chave no .env (self-bootstrap), criando o arquivo se necessário.
 *
 * IMPORTANTE: substitui o valor se a chave já existir (mesmo vazia ou fraca) e
 * remove linhas duplicadas. A versão anterior só ACRESCENTAVA quando a chave não
 * existia; com `JWT_SECRET=` vazio no .env, cada request gerava um segredo novo
 * sem o gravar → o token do login era rejeitado no request seguinte e o frontend
 * voltava ao ecrã de login em ciclo ("não passa da tela de login").
 */
function labcontrolPersistEnv($key, $value) {
    $envFile = __DIR__ . '/../../.env';
    $exampleFile = __DIR__ . '/../../.env.example';

    if (!file_exists($envFile)) {
        $base = file_exists($exampleFile) ? (string) file_get_contents($exampleFile) : '';
        if (@file_put_contents($envFile, $base, LOCK_EX) === false) {
            error_log("❌ LabControl: não foi possível criar {$envFile} para gravar {$key}.");
            return false;
        }
    }
    if (!is_writable($envFile)) {
        error_log("❌ LabControl: {$envFile} não é gravável pelo servidor web; não foi possível persistir {$key}.");
        return false;
    }

    $content = (string) file_get_contents($envFile);
    $line = $key . '=' . $value;
    $pattern = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=.*$/m';
    $count = 0;
    $content = preg_replace_callback($pattern, function () use ($line, &$count) {
        $count++;
        return $count === 1 ? $line : ''; // 1.ª ocorrência: substitui; duplicadas: remove
    }, $content);
    if ($count === 0) {
        $content = rtrim($content) . "\n" . $line . "\n";
    }

    return @file_put_contents($envFile, $content, LOCK_EX) !== false;
}

/**
 * Um segredo é "fraco" se estiver vazio, for curto ou for um placeholder do
 * repositório (nunca assinar tokens com um valor público).
 */
function labcontrolIsWeakSecret($value) {
    $v = trim((string) $value);
    if (strlen($v) < 16) {
        return true;
    }
    foreach (['change_this', 'labcontrol_secure_key', 'change_me', 'your_secret', 'example'] as $placeholder) {
        if (stripos($v, $placeholder) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Erro de configuração irrecuperável: responde de forma clara (JSON na web,
 * stderr na CLI) em vez de continuar com um estado que só falha mais à frente.
 */
function labcontrolFatalConfig($message) {
    error_log('❌ LabControl configuração: ' . $message);
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        fwrite(STDERR, "ERRO DE CONFIGURAÇÃO: {$message}\n");
        exit(1);
    }
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Erro de configuração: ' . $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Garante que um segredo existe, é forte e está PERSISTIDO (estável entre requests).
 * Se não for possível gravar, falha de forma explícita — um segredo aleatório por
 * request seria pior (login "funciona" e tudo o resto dá 401).
 */
function labcontrolEnsureSecret($key) {
    $value = env($key, null);
    if (!labcontrolIsWeakSecret($value)) {
        return (string) $value;
    }
    $value = bin2hex(random_bytes(32));
    if (!labcontrolPersistEnv($key, $value)) {
        labcontrolFatalConfig(
            "{$key} ausente/fraco e o ficheiro .env não é gravável pelo servidor web. " .
            "Defina {$key} no .env (ex.: php labcontrol-backend/cli/users.php check-config) ou dê permissão de escrita."
        );
    }
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
    error_log("ℹ️ LabControl: {$key} gerado e gravado no .env (instalação nova ou valor fraco substituído).");
    return $value;
}

$envPath = __DIR__ . '/../../.env';
if (file_exists($envPath)) {
    loadEnv($envPath);
} else {
    // Fallback: usar valores padrão (apenas desenvolvimento local)
    error_log("⚠️ AVISO: Arquivo .env não encontrado. Usando configuração padrão.");
}

// =====================================================
// CONFIGURAÇÕES DO BANCO DE DADOS
// =====================================================
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'labcontrol'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// Validar banco de dados configurado
if (empty(DB_HOST) || empty(DB_NAME)) {
    die('❌ ERRO: Banco de dados não configurado! Configure .env');
}

// =====================================================
// CONFIGURAÇÕES DO FIREBASE
// =====================================================
define('FIREBASE_ENABLED', env('FIREBASE_ENABLED', 'false') === 'true');
define('FIREBASE_PROJECT_ID', env('FIREBASE_PROJECT_ID', ''));
define('FIREBASE_CREDENTIALS_PATH', __DIR__ . '/../' . env('FIREBASE_CREDENTIALS_PATH', 'firebase/service-account.json'));

// =====================================================
// CONFIGURAÇÕES DE SEGURANÇA
// =====================================================
// Duração do token JWT. Um valor vazio/0 no .env (ex.: "SESSION_TIMEOUT=") fazia
// (int)'' = 0 → exp = agora → o token expirava no segundo seguinte ao login.
$sessionTimeout = (int) env('SESSION_TIMEOUT', 3600);
if ($sessionTimeout < 60) {
    error_log("⚠️ LabControl: SESSION_TIMEOUT inválido ({$sessionTimeout}s); a usar 3600s.");
    $sessionTimeout = 3600;
}
define('SESSION_TIMEOUT', $sessionTimeout);
define('MAX_LOGIN_ATTEMPTS', (int)env('MAX_LOGIN_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_TIME', (int)env('LOGIN_LOCKOUT_TIME', 900));

// JWT Secret - CRÍTICO! Tem de ser forte E estável entre requests (assina as sessões).
// Se ausente/fraco/placeholder, gera um e grava-o no .env; se não conseguir gravar, falha
// explicitamente (nunca usar um segredo diferente por request).
define('JWT_SECRET', labcontrolEnsureSecret('JWT_SECRET'));

// =====================================================
// CONFIGURAÇÕES DE REDE
// =====================================================
define('PING_TIMEOUT', 2);
define('WOL_PORT', 9);
define('PS_EXEC_TIMEOUT', 30);
define('API_RATE_LIMIT', (int)env('API_RATE_LIMIT', 100));
define('API_TIMEOUT', (int)env('API_TIMEOUT', 15000));

// =====================================================
// CRIPTOGRAFIA
// =====================================================
// Sem fallback hardcoded no código (evita chave pública no repositório).
// Se ausente/inválida, gera uma CHAVE ÚNICA por instalação e persiste-a no .env.
// Tal como o JWT_SECRET, tem de ser estável: uma chave nova por request tornaria
// ilegíveis todas as senhas de hosts já guardadas.
define('ENCRYPTION_KEY', labcontrolEnsureSecret('ENCRYPTION_KEY'));
define('ENCRYPTION_METHOD', 'AES-256-CBC');

// =====================================================
// CREDENCIAIS REMOTAS (Windows/Network)
// =====================================================
// Sem fallback hardcoded: o nome da conta de administrador das estações é
// informação sensível e deve vir exclusivamente do .env (ou das credenciais
// por host guardadas encriptadas na base de dados).
$remoteUser = env('REMOTE_USER', '');
$remotePassword = env('REMOTE_PASSWORD', '');
if ($remoteUser === '' || strpos($remoteUser, 'CHANGE_ME') === 0) {
    $remoteUser = '';
}
if ($remotePassword === '' || strpos($remotePassword, 'CHANGE_ME') === 0) {
    $remotePassword = '';
}
define('REMOTE_USER', $remoteUser);
define('REMOTE_PASSWORD', $remotePassword);

// =====================================================
// CONFIGURAÇÕES DE LOG
// =====================================================
define('LOG_ENABLED', env('LOG_ENABLED', 'true') === 'true');
define('LOG_RETENTION_DAYS', (int)env('LOG_RETENTION_DAYS', 90));
define('LOG_FILE_PATH', __DIR__ . '/../logs/');
define('DEBUG_MODE', env('DEBUG_MODE', 'false') === 'true');

// =====================================================
// CORS - IMPORTANTE PARA FRONTEND
// =====================================================
// Carregar de .env com fallback seguro
$corsOrigins = env('CORS_ALLOWED_ORIGINS', 'http://localhost,http://127.0.0.1');

// Em produção, avisar se CORS é muito permissivo
if (strpos($corsOrigins, '*') !== false && !DEBUG_MODE) {
    error_log('⚠️ ALERTA: CORS com * em produção é inseguro!');
}

define('CORS_ALLOWED_ORIGINS', $corsOrigins);
define('CORS_ALLOWED_METHODS', env('CORS_ALLOWED_METHODS', 'GET, POST, PUT, DELETE, OPTIONS'));
define('CORS_ALLOWED_HEADERS', env('CORS_ALLOWED_HEADERS', 'Content-Type, Authorization, X-Requested-With'));

// =====================================================
// RATE LIMITING
// =====================================================
define('RATE_LIMIT_LOGIN', (int)env('RATE_LIMIT_LOGIN', 5));
define('RATE_LIMIT_WINDOW', (int)env('RATE_LIMIT_WINDOW', 300));

// =====================================================
// TIMEZONE
// =====================================================
$timezone = env('APP_TIMEZONE', 'Africa/Luanda');
if (!in_array($timezone, DateTimeZone::listIdentifiers())) {
    $timezone = 'UTC';
    error_log("⚠️ AVISO: Timezone inválido, usando UTC");
}
date_default_timezone_set($timezone);

// =====================================================
// FUNÇÕES UTILITÁRIAS
// =====================================================

function jsonResponse($success, $message = '', $data = null, $code = 200) {
    // Limpar qualquer saída anterior (avisos, espaços em branco, etc)
    if (ob_get_length()) {
        ob_clean();
    }
    
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . getCorsOrigin());
    header('Access-Control-Allow-Methods: ' . CORS_ALLOWED_METHODS);
    header('Access-Control-Allow-Headers: ' . CORS_ALLOWED_HEADERS);
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $data
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function getClientIP() {
    // Por padrão usamos REMOTE_ADDR para evitar spoofing de cabeçalhos HTTP
    // (essencial para o rate limiting de login e para a integridade dos logs de auditoria).
    // Se estiver atrás de um proxy reverso confiável, estenda aqui validando o IP de origem.
    return !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

function generateJWT($payload) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload['iat'] = time();
    $payload['exp'] = time() + SESSION_TIMEOUT;
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, JWT_SECRET, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

/**
 * Obtém o token Bearer do request de forma robusta.
 *
 * O padrão antigo `getallheaders()['Authorization']` falha em vários cenários
 * comuns no XAMPP/Apache: o header chega em minúsculas, é removido pelo Apache
 * em CGI/FastCGI (só disponível via REDIRECT_HTTP_AUTHORIZATION com a regra do
 * .htaccess), ou getallheaders() nem existe. Resultado típico: o login devolve
 * um token válido, mas `verify`/`hosts.php` respondem 401 → o frontend limpa o
 * token e volta ao ecrã de login ("o botão recarrega mas não avança").
 */
function getBearerToken() {
    $candidates = [];

    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) {
            $candidates[] = $_SERVER[$k];
        }
    }

    if (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        if (is_array($h)) {
            foreach ($h as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0 && !empty($value)) {
                    $candidates[] = $value;
                }
            }
        }
    } elseif (function_exists('getallheaders')) {
        $h = getallheaders();
        if (is_array($h)) {
            foreach ($h as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0 && !empty($value)) {
                    $candidates[] = $value;
                }
            }
        }
    }

    foreach ($candidates as $header) {
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return $m[1];
        }
    }
    return '';
}

function validateJWT($token, $ignoreExpiration = false) {
    if (!is_string($token) || $token === '') return false;
    $parts = explode('.', $token);
    if (count($parts) != 3) return false;
    
    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
    if (!$payload || !isset($payload['exp'])) {
        return false;
    }

    if (!$ignoreExpiration && $payload['exp'] < time()) {
        return false;
    }
    
    $signature = hash_hmac('sha256', $parts[0] . "." . $parts[1], JWT_SECRET, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return hash_equals($base64Signature, $parts[2]) ? $payload : false;
}

function logError($message, $context = []) {
    if (!LOG_ENABLED) return;
    $logFile = LOG_FILE_PATH . 'error_' . date('Y-m-d') . '.log';
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context,
        'ip' => getClientIP()
    ];
    @file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
}

function encryptString($string) {
    if (empty($string)) return null;
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($string, ENCRYPTION_METHOD, $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) return null;
    return base64_encode($iv . $encrypted);
}

function decryptString($encryptedString) {
    if (empty($encryptedString)) return null;
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $data = base64_decode($encryptedString);
    if ($data === false) return null;
    $ivLength = openssl_cipher_iv_length(ENCRYPTION_METHOD);
    $iv = substr($data, 0, $ivLength);
    $encrypted = substr($data, $ivLength);
    $decrypted = openssl_decrypt($encrypted, ENCRYPTION_METHOD, $key, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : null;
}

function getHostCredentials($host) {
    if (!empty($host['remote_user']) && !empty($host['remote_password_encrypted'])) {
        return [
            'username' => $host['remote_user'],
            'password' => decryptString($host['remote_password_encrypted'])
        ];
    }
    return [
        'username' => REMOTE_USER,
        'password' => REMOTE_PASSWORD
    ];
}

function getCorsOrigin() {
    // Normalize configured origins into an array
    $origins = array_map('trim', explode(',', CORS_ALLOWED_ORIGINS));
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['ORIGIN'] ?? '');

    if (empty($requestOrigin)) {
        // Fallback to first allowed origin
        return $origins[0] ?? '*';
    }

    // If wildcard is allowed, return request origin (but avoid '*' in production)
    if (in_array('*', $origins, true)) {
        return $requestOrigin;
    }

    // If request origin is explicitly allowed, echo it back
    foreach ($origins as $o) {
        if (strcasecmp($o, $requestOrigin) === 0) return $requestOrigin;
    }

    // Not allowed - return first configured origin as safe fallback
    return $origins[0] ?? '*';
}

/**
 * Cita um valor como literal PowerShell entre aspas SIMPLES.
 *
 * Dentro de aspas simples o PowerShell não expande `$var`, `$(expr)` nem
 * sequências com crase — o único carácter especial é a própria aspa, que se
 * escapa duplicando-a. É a forma segura de passar senhas, hostnames e
 * argumentos vindos de fora para um script gerado dinamicamente.
 */
function psQuote($value) {
    return "'" . str_replace("'", "''", (string) $value) . "'";
}

/**
 * Valida um nome de computador antes de o usar em -ComputerName.
 * Aceita hostnames NetBIOS/DNS e endereços IPv4/IPv6 (defesa em profundidade:
 * mesmo que o registo tenha entrado na BD sem passar pela API, nunca chega ao
 * PowerShell um valor com espaços, `;`, `|`, `$`, etc.).
 */
function isSafeComputerName($name) {
    if (!is_string($name) || $name === '' || strlen($name) > 253) {
        return false;
    }
    if (filter_var($name, FILTER_VALIDATE_IP)) {
        return true;
    }
    return (bool) preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9\-_.]*[A-Za-z0-9])?$/', $name);
}

/**
 * Cria um ficheiro .ps1 temporário com nome imprevisível e permissões restritas.
 *
 * Substitui o padrão `'prefix_' . time() . '.ps1'`, que colidia quando duas
 * requisições chegavam no mesmo segundo (uma apagava o script da outra) e
 * tornava o caminho adivinhável. Devolve o caminho ou null em caso de falha.
 */
function createTempPsScript($prefix, $content) {
    $dir = rtrim(sys_get_temp_dir(), '/\\');
    $safePrefix = preg_replace('/[^a-z0-9_]/i', '', (string) $prefix);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $path = $dir . DIRECTORY_SEPARATOR . 'lc_' . $safePrefix . '_' . bin2hex(random_bytes(12)) . '.ps1';

        // Modo 'x': cria o ficheiro em exclusivo e falha se já existir — sem janela
        // de corrida entre "verificar" e "criar" (ao contrário de tempnam()+rename()).
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            continue; // colisão (astronomicamente improvável) → novo nome
        }

        @chmod($path, 0600);
        // BOM UTF-8: o Windows PowerShell 5.1 lê .ps1 sem BOM como ANSI, o que
        // corromperia senhas/hostnames com caracteres acentuados.
        $ok = fwrite($fh, "\xEF\xBB\xBF" . $content) !== false;
        fclose($fh);

        if ($ok) {
            return $path;
        }
        @unlink($path);
        return null;
    }
    return null;
}

/**
 * Gera o conteúdo de um script PowerShell que executa um comando remoto
 * simples (shutdown/restart) via Invoke-Command com credenciais.
 *
 * - Senha e utilizador em aspas simples (sem expansão de `$` / crase).
 * - Autenticação por Negotiate (Kerberos/NTLM). O antigo `-Authentication Basic`
 *   enviava a senha do administrador praticamente em texto claro pela rede a
 *   cada operação, e exige `AllowUnencrypted` nos alvos.
 * - Código de saída fiável: antes, um Invoke-Command falhado devolvia 0 e a
 *   operação era registada como "success" nos logs. Agora os erros são
 *   recolhidos por computador (sem abortar os restantes num envio em massa) e o
 *   script imprime `FAILED_HOSTS: a,b` e termina com 1 se algum falhou.
 *
 * @param string[] $computerNames Lista já validada com isSafeComputerName().
 */
function buildRemoteCommandScript(array $computerNames, $username, $password, $remoteCommand) {
    $targets = implode(',', array_map('psQuote', $computerNames));
    return
        '$ErrorActionPreference = \'Continue\'' . "\r\n" .
        '$pass = ConvertTo-SecureString -String ' . psQuote($password) . ' -AsPlainText -Force' . "\r\n" .
        '$cred = New-Object System.Management.Automation.PSCredential(' . psQuote($username) . ', $pass)' . "\r\n" .
        '$errs = @()' . "\r\n" .
        'try {' . "\r\n" .
        '    Invoke-Command -ComputerName @(' . $targets . ') -Credential $cred -Authentication Negotiate -ScriptBlock { ' . $remoteCommand . ' } -ErrorAction SilentlyContinue -ErrorVariable errs | Out-Null' . "\r\n" .
        '} catch {' . "\r\n" .
        '    $errs += $_' . "\r\n" .
        '}' . "\r\n" .
        'if ($errs.Count -gt 0) {' . "\r\n" .
        '    $failed = @($errs | ForEach-Object {' . "\r\n" .
        '        if ($_.OriginInfo -and $_.OriginInfo.PSComputerName) { [string]$_.OriginInfo.PSComputerName }' . "\r\n" .
        '        elseif ($_.TargetObject -is [string]) { $_.TargetObject }' . "\r\n" .
        '        else { \'\' }' . "\r\n" .
        '    } | Where-Object { $_ } | Select-Object -Unique)' . "\r\n" .
        '    Write-Output ("FAILED_HOSTS: " + ($failed -join \',\'))' . "\r\n" .
        '    $errs | ForEach-Object { Write-Output ("ERRO: " + $_.Exception.Message) }' . "\r\n" .
        '    exit 1' . "\r\n" .
        '}' . "\r\n" .
        'exit 0' . "\r\n";
}

/**
 * Extrai da saída do script gerado por buildRemoteCommandScript() a lista de
 * computadores que falharam (linha `FAILED_HOSTS: a,b`). Devolve [] se não
 * conseguir determinar (nesse caso o chamador deve tratar o grupo todo como falhado).
 */
function parseFailedHostsFromOutput(array $output) {
    foreach ($output as $line) {
        if (preg_match('/^\s*FAILED_HOSTS:\s*(.*)$/i', $line, $m)) {
            $names = array_filter(array_map('trim', explode(',', $m[1])), 'strlen');
            return array_values(array_unique($names));
        }
    }
    return [];
}

/**
 * Função global para ping em host
 */
function pingHost($ip) {
    $timeout = defined('PING_TIMEOUT') ? PING_TIMEOUT : 2;
    
    // Windows
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = "ping -n 1 -w " . ($timeout * 1000) . " " . escapeshellarg($ip);
    } else {
        // Linux/Mac
        $command = "ping -c 1 -W " . $timeout . " " . escapeshellarg($ip);
    }
    
    exec($command, $output, $returnCode);
    
    return $returnCode === 0;
}
