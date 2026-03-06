<?php
/**
 * LabControl - Configuração do Sistema
 * 
 * Arquivo de configuração principal do backend
 */

// Prevenir acesso direto
if (!defined('LABCONTROL')) {
    define('LABCONTROL', true);
}

// =====================================================
// CONFIGURAÇÕES DO BANCO DE DADOS
// =====================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'labcontrol');
define('DB_USER', 'root');
define('DB_PASS', ''); // Altere conforme sua configuração do XAMPP
define('DB_CHARSET', 'utf8mb4');

// =====================================================
// CONFIGURAÇÕES DO FIREBASE
// =====================================================
define('FIREBASE_ENABLED', true);
define('FIREBASE_PROJECT_ID', 'labcontrol-bd504');
define('FIREBASE_CREDENTIALS_PATH', __DIR__ . '/../firebase/service-account.json');

// =====================================================
// CONFIGURAÇÕES DE SEGURANÇA
// =====================================================
define('SESSION_TIMEOUT', 3600); // 1 hora em segundos
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutos
define('JWT_SECRET', 'labcontrol_secret_key_change_in_production');

// =====================================================
// CONFIGURAÇÕES DE REDE
// =====================================================
define('PING_TIMEOUT', 2); // segundos
define('WOL_PORT', 9);
define('PS_EXEC_TIMEOUT', 30); // segundos para execução PowerShell
define('API_RATE_LIMIT', 100); // requisições por minuto

// =====================================================
// CONFIGURAÇÕES DE CRIPTOGRAFIA (CREDENCIAIS)
// =====================================================
// CHAVE IMPORTANTE: Altere esta chave em produção!
// Use: openssl rand -base64 32
// Esta chave é usada para criptografar/descriptografar senhas de hosts
// =====================================================
define('ENCRYPTION_KEY', 'labcontrol_encryption_key_change_this_in_production_12345');
define('ENCRYPTION_METHOD', 'AES-256-CBC');

// =====================================================
// CREDENCIAIS PADRÃO PARA ACESSO REMOTO
// =====================================================
// Como todos os hosts têm o mesmo admin, defina aqui as credenciais padrão
// Estas serão usadas quando o host não tiver credenciais específicas
// =====================================================
define('DEFAULT_REMOTE_USER', 'AdminLab17'); // ou o nome do usuário admin da rede
define('DEFAULT_REMOTE_PASSWORD', 'Insert@into17'); // Defina a senha padrão aqui ou configure via API

// =====================================================
// CONFIGURAÇÕES DE LOG
// =====================================================
define('LOG_ENABLED', true);
define('LOG_RETENTION_DAYS', 90);
define('LOG_FILE_PATH', __DIR__ . '/../logs/');

// =====================================================
// CONFIGURAÇÕES DE CORS
// =====================================================
define('CORS_ALLOWED_ORIGINS', '*'); // Em produção, especifique os domínios
define('CORS_ALLOWED_METHODS', 'GET, POST, PUT, DELETE, OPTIONS');
define('CORS_ALLOWED_HEADERS', 'Content-Type, Authorization, X-Requested-With');

// =====================================================
// MODO DEBUG
// =====================================================
define('DEBUG_MODE', true); // Altere para false em produção

// =====================================================
// TIMEZONE
// =====================================================
date_default_timezone_set('America/Sao_Paulo');

// =====================================================
// FUNÇÕES UTILITÁRIAS
// =====================================================

/**
 * Retorna resposta JSON padronizada
 */
function jsonResponse($success, $message = '', $data = null, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $data
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Verifica se a requisição é AJAX
 */
function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Obtém IP do cliente
 */
function getClientIP() {
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            return $_SERVER[$key];
        }
    }
    return '0.0.0.0';
}

/**
 * Gera token JWT simples
 */
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
 * Valida token JWT
 */
function validateJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) != 3) return false;
    
    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
        return false;
    }
    
    $signature = hash_hmac('sha256', $parts[0] . "." . $parts[1], JWT_SECRET, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return hash_equals($base64Signature, $parts[2]) ? $payload : false;
}

/**
 * Log de erros
 */
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

/**
 * Sanitiza string
 */
function sanitize($string) {
    return htmlspecialchars(strip_tags(trim($string)), ENT_QUOTES, 'UTF-8');
}

/**
 * Verifica se está em modo offline
 */
function isOfflineMode() {
    // Verifica conectividade com Firebase
    if (!FIREBASE_ENABLED) return true;
    
    $connected = @fsockopen('firebase.googleapis.com', 443, $errno, $errstr, 2);
    if ($connected) {
        fclose($connected);
        return false;
    }
    return true;
}

// =====================================================
// FUNÇÕES DE CRIPTOGRAFIA PARA CREDENCIAIS
// =====================================================

/**
 * Criptografa uma string usando AES-256-CBC
 */
function encryptString($string) {
    if (empty($string)) return null;
    
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    
    $encrypted = openssl_encrypt($string, ENCRYPTION_METHOD, $key, OPENSSL_RAW_DATA, $iv);
    
    if ($encrypted === false) {
        logError('Erro ao criptografar string');
        return null;
    }
    
    // Retorna IV + dados criptografados em base64
    return base64_encode($iv . $encrypted);
}

/**
 * Descriptografa uma string criptografada com encryptString
 */
function decryptString($encryptedString) {
    if (empty($encryptedString)) return null;
    
    $key = hash('sha256', ENCRYPTION_KEY, true);
    $data = base64_decode($encryptedString);
    
    if ($data === false) {
        return null;
    }
    
    $ivLength = openssl_cipher_iv_length(ENCRYPTION_METHOD);
    $iv = substr($data, 0, $ivLength);
    $encrypted = substr($data, $ivLength);
    
    $decrypted = openssl_decrypt($encrypted, ENCRYPTION_METHOD, $key, OPENSSL_RAW_DATA, $iv);
    
    return $decrypted !== false ? $decrypted : null;
}

/**
 * Obtém credenciais de acesso remoto para um host
 * Retorna array com 'username' e 'password'
 */
function getHostCredentials($host) {
    // Se o host tem credenciais específicas, use-as
    if (!empty($host['remote_user']) && !empty($host['remote_password_encrypted'])) {
        return [
            'username' => $host['remote_user'],
            'password' => decryptString($host['remote_password_encrypted'])
        ];
    }
    
    // Senão, use as credenciais padrão do sistema
    return [
        'username' => DEFAULT_REMOTE_USER,
        'password' => DEFAULT_REMOTE_PASSWORD
    ];
}
