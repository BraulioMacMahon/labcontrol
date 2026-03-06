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
define('SESSION_TIMEOUT', (int)env('SESSION_TIMEOUT', 3600));
define('MAX_LOGIN_ATTEMPTS', (int)env('MAX_LOGIN_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_TIME', (int)env('LOGIN_LOCKOUT_TIME', 900));

// JWT Secret - CRÍTICO!
$jwtSecret = env('JWT_SECRET', null);
if (empty($jwtSecret) || strlen($jwtSecret) < 16) {
    die('❌ ERRO CRÍTICO: JWT_SECRET inválido ou muito curto! Use: openssl rand -hex 32');
}
define('JWT_SECRET', $jwtSecret);

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
define('ENCRYPTION_KEY', env('ENCRYPTION_KEY', 'labcontrol_encryption_key_32chars_long_1234567890'));
define('ENCRYPTION_METHOD', 'AES-256-CBC');

// =====================================================
// CREDENCIAIS REMOTAS (Windows/Network)
// =====================================================
define('REMOTE_USER', env('REMOTE_USER', 'AdminLab17'));
define('REMOTE_PASSWORD', env('REMOTE_PASSWORD', ''));

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
$timezone = env('APP_TIMEZONE', 'America/Sao_Paulo');
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
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) return $_SERVER[$key];
    }
    return '0.0.0.0';
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

function validateJWT($token, $ignoreExpiration = false) {
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
