<?php
/**
 * LabControl - API de Autenticação
 * 
 * Endpoints: login, logout, verify, refresh
 */

// Start output buffering before anything else
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set up error handler before anything else
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_end_clean(); // Clear any buffered output
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'PHP Error',
        'error' => $errstr,
        'file' => $errfile,
        'line' => $errline,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit;
}, E_ALL);

// Set up exception handler
set_exception_handler(function($exception) {
    ob_end_clean(); // Clear any buffered output
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Exception: ' . $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit;
});

try {
    require_once __DIR__ . '/../config/config.php';
    
    // Clear any output that might have been generated during config loading
    ob_end_clean();
    ob_start();
    
    require_once __DIR__ . '/../bootstrap/security.php';
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../includes/FirebaseIntegration.php';
    require_once __DIR__ . '/../middleware/RateLimiter.php';
    require_once __DIR__ . '/../classes/Validator.php';

    // Obter ação
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    $db = Database::getInstance();
    $firebase = new FirebaseIntegration();
    
    // Clear output buffer and prepare for JSON response
    ob_end_clean();
} catch (Throwable $e) {
    ob_end_clean(); // Clear any buffered output
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit;
}

switch ($action) {
    // =====================================================
    // LOGIN
    // =====================================================
    case 'login':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // ✅ RATE LIMITING
        $clientIP = getClientIP();
        $limiter = new RateLimiter("login:$clientIP", RATE_LIMIT_LOGIN, RATE_LIMIT_WINDOW);
        
        if (!$limiter->isAllowed()) {
            $info = $limiter->getInfo();
            jsonResponse(false, 'Muitas tentativas de login. Tente novamente em ' . $info['reset_in'] . ' segundos', null, 429);
        }
        
        // Obter dados da requisição
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        
        // ✅ VALIDAR INPUT
        $validator = new Validator();
        $rules = [
            'email' => 'required|email',
            'password' => 'required|min:6'
        ];
        
        if (!$validator->validate(['email' => $email, 'password' => $password], $rules)) {
            jsonResponse(false, 'Validação falhou', $validator->errors(), 400);
        }
        
        // Buscar usuário no banco local
        $user = $db->getUserByEmail($email);
        
        if (!$user) {
            // 📝 REGISTAR TENTATIVA DE LOGIN COM EMAIL INVÁLIDO
            $db->logAction(
                null,
                $email,
                null,
                null,
                'Tentativa de login com email não registado',
                'auth',
                json_encode([
                    'ip' => getClientIP(),
                    'email_attempt' => $email
                ]),
                'failed'
            );
            
            // 🔔 NOTIFICAR ADMINISTRADOR SOBRE ATIVIDADE SUSPEITA
            if (LOG_ENABLED) {
                logError('Login attempt with non-existent email: ' . $email, [
                    'ip' => getClientIP(),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
            
            // Resposta genérica para não revelar se email existe
            jsonResponse(false, 'Credenciais inválidas', null, 401);
        }
        
        // Verificar se usuário está ativo ANTES de verificar senha
        // (evita timing attacks e não revela se conta existe)
        if (!$user['is_active']) {
            // 📝 REGISTAR TENTATIVA DE LOGIN COM CONTA DESATIVADA
            $db->logAction(
                $user['id'],
                $user['email'],
                null,
                null,
                'Tentativa de login com conta desativada',
                'auth',
                json_encode([
                    'ip' => getClientIP(),
                    'account_status' => 'inactive'
                ]),
                'failed'
            );
            
            // Resposta genérica
            jsonResponse(false, 'Credenciais inválidas', null, 401);
        }
        
        // Verificar senha
        if (!password_verify($password, $user['password_hash'])) {
            // 📝 REGISTAR TENTATIVA FALHA COM SENHA INCORRETA
            $db->logAction(
                $user['id'],
                $user['email'],
                null,
                null,
                'Tentativa de login falhou - senha incorreta',
                'auth',
                json_encode([
                    'ip' => getClientIP(),
                    'attempt_number' => $limiter->getInfo()['attempts']
                ]),
                'failed'
            );
            
            // 🔔 AVISAR SE HÁ MÚLTIPLAS TENTATIVAS FALHADAS
            $loginAttempts = $limiter->getInfo()['attempts'];
            if ($loginAttempts >= 3) {
                logError('Multiple failed login attempts for: ' . $user['email'], [
                    'ip' => getClientIP(),
                    'attempts' => $loginAttempts,
                    'email' => $user['email']
                ]);
            }
            
            jsonResponse(false, 'Credenciais inválidas', null, 401);
        }
        
        // ✅ RESET RATE LIMIT após sucesso
        $limiter->reset();
        
        // Atualizar último login
        $db->update('users', 
            ['last_login' => date('Y-m-d H:i:s')], 
            'id = :id', 
            [':id' => $user['id']]
        );
        
        // Gerar token JWT
        $tokenPayload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ];
        $token = generateJWT($tokenPayload);
        
        // Sincronizar com Firebase
        if ($firebase->isEnabled()) {
            $firebase->syncUser($user);
        }
        
        // Registrar log de sucesso
        $db->logAction(
            $user['id'],
            $user['email'],
            null,
            null,
            'Login realizado com sucesso',
            'auth',
            json_encode(['ip' => getClientIP()]),
            'success'
        );
        
        // Retornar resposta
        jsonResponse(true, 'Login realizado com sucesso', [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'last_login' => $user['last_login']
            ],
            'expires_in' => SESSION_TIMEOUT
        ]);
        break;
    
    // =====================================================
    // LOGOUT
    // =====================================================
    case 'logout':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Obter token
        $headers = getallheaders();
        $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
        
        if ($token) {
            $payload = validateJWT($token);
            if ($payload) {
                // Registrar logout
                $db->logAction(
                    $payload['user_id'],
                    $payload['email'],
                    null,
                    null,
                    'Logout realizado',
                    'auth',
                    null,
                    'success'
                );
            }
        }
        
        jsonResponse(true, 'Logout realizado com sucesso');
        break;
    
    // =====================================================
    // VERIFICAR TOKEN
    // =====================================================
    case 'verify':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Obter token
        $headers = getallheaders();
        $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
        
        if (empty($token)) {
            jsonResponse(false, 'Token não fornecido', null, 401);
        }
        
        $payload = validateJWT($token);
        
        if (!$payload) {
            jsonResponse(false, 'Token inválido ou expirado', null, 401);
        }
        
        // Verificar se usuário ainda existe e está ativo
        $user = $db->getUserById($payload['user_id']);
        
        if (!$user || !$user['is_active']) {
            jsonResponse(false, 'Usuário inválido ou desativado', null, 401);
        }
        
        jsonResponse(true, 'Token válido', [
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
        break;
    
    // =====================================================
    // REFRESH TOKEN
    // =====================================================
    case 'refresh':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Obter token
        $headers = getallheaders();
        $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
        
        if (empty($token)) {
            jsonResponse(false, 'Token não fornecido', null, 401);
        }
        
        // No refresh, permitimos validar a assinatura mesmo que expirado
        $payload = validateJWT($token, true);
        
        if (!$payload) {
            jsonResponse(false, 'Token inválido', null, 401);
        }
        
        // Verificar se usuário ainda existe e está ativo
        $user = $db->getUserById($payload['user_id']);
        if (!$user || !$user['is_active']) {
            jsonResponse(false, 'Usuário inválido ou desativado', null, 401);
        }
        
        // Gerar novo token
        $newPayload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ];
        $newToken = generateJWT($newPayload);
        
        jsonResponse(true, 'Token renovado', [
            'token' => $newToken,
            'expires_in' => SESSION_TIMEOUT
        ]);
        break;
    
    // =====================================================
    // REGISTRAR USUÁRIO (APENAS ADMIN)
    // =====================================================
    case 'register':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Verificar autenticação e permissão
        $headers = getallheaders();
        $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
        $payload = validateJWT($token);
        
        if (!$payload || $payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        // Obter dados
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? 'operator';
        
        // Validações
        if (empty($email) || empty($password)) {
            jsonResponse(false, 'Email e senha são obrigatórios', null, 400);
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Email inválido', null, 400);
        }
        
        if (strlen($password) < 6) {
            jsonResponse(false, 'Senha deve ter no mínimo 6 caracteres', null, 400);
        }
        
        if (!in_array($role, ['admin', 'operator'])) {
            jsonResponse(false, 'Perfil inválido', null, 400);
        }
        
        // Verificar se email já existe
        $existingUser = $db->getUserByEmail($email);
        if ($existingUser) {
            jsonResponse(false, 'Email já cadastrado', null, 409);
        }
        
        // Criar hash da senha
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        
        // Inserir usuário
        $userId = $db->insert('users', [
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'is_active' => 1
        ]);
        
        if (!$userId) {
            jsonResponse(false, 'Erro ao criar usuário', null, 500);
        }
        
        // Sincronizar com Firebase
        if ($firebase->isEnabled()) {
            $firebase->syncUser([
                'id' => $userId,
                'email' => $email,
                'role' => $role,
                'is_active' => 1,
                'last_login' => null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Registrar log
        $db->logAction(
            $payload['user_id'],
            $payload['email'],
            null,
            null,
            "Usuário criado: {$email}",
            'user',
            json_encode(['new_user_id' => $userId, 'role' => $role]),
            'success'
        );
        
        jsonResponse(true, 'Usuário criado com sucesso', [
            'user_id' => $userId,
            'email' => $email,
            'role' => $role
        ], 201);
        break;
    
    // =====================================================
    // ALTERAR SENHA
    // =====================================================
    case 'change-password':
        if ($method !== 'POST') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Verificar autenticação
        $headers = getallheaders();
        $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
        $payload = validateJWT($token);
        
        if (!$payload) {
            jsonResponse(false, 'Token inválido', null, 401);
        }
        
        // Obter dados
        $input = json_decode(file_get_contents('php://input'), true);
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        
        // Validações
        if (empty($currentPassword) || empty($newPassword)) {
            jsonResponse(false, 'Senha atual e nova senha são obrigatórias', null, 400);
        }
        
        if (strlen($newPassword) < 6) {
            jsonResponse(false, 'Nova senha deve ter no mínimo 6 caracteres', null, 400);
        }
        
        // Buscar usuário
        $user = $db->getUserById($payload['user_id']);
        
        if (!$user) {
            jsonResponse(false, 'Usuário não encontrado', null, 404);
        }
        
        // Verificar senha atual
        if (!password_verify($currentPassword, $user['password_hash'])) {
            jsonResponse(false, 'Senha atual incorreta', null, 401);
        }
        
        // Atualizar senha
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updated = $db->update('users', 
            ['password_hash' => $newHash], 
            'id = :id', 
            [':id' => $user['id']]
        );
        
        if (!$updated) {
            jsonResponse(false, 'Erro ao alterar senha', null, 500);
        }
        
        // Registrar log
        $db->logAction(
            $user['id'],
            $user['email'],
            null,
            null,
            'Senha alterada',
            'auth',
            null,
            'success'
        );
        
        jsonResponse(true, 'Senha alterada com sucesso');
        break;
    
    // =====================================================
    // LISTAR USUÁRIOS (APENAS ADMIN)
    // =====================================================
    case 'users':
        if ($method !== 'GET') {
            jsonResponse(false, 'Método não permitido', null, 405);
        }
        
        // Verificar autenticação e permissão
        $headers = getallheaders();
        $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
        $payload = validateJWT($token);
        
        if (!$payload || $payload['role'] !== 'admin') {
            jsonResponse(false, 'Acesso negado', null, 403);
        }
        
        $users = $db->select(
            "SELECT id, email, role, is_active, last_login, created_at FROM users ORDER BY created_at DESC"
        );
        
        jsonResponse(true, 'Usuários listados', ['users' => $users]);
        break;
    
    default:
        jsonResponse(false, 'Ação não encontrada', null, 404);
}
