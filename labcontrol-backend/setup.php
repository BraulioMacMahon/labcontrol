<?php
/**
 * LabControl - Setup de primeiro acesso (CLI ou web, somente localhost)
 *
 * O que faz:
 *   1. Gera o arquivo .env (a partir de .env.example) e cria JWT_SECRET + ENCRYPTION_KEY
 *      fortes, caso ausentes (Ponto 2: a app não sobe sem esses segredos).
 *   2. Cria o PRIMEIRO usuário admin com a senha informada (mín. 12 caracteres).
 *      Só funciona quando NÃO existem usuários (Ponto 1: instalação nova precisa de admin).
 *
 * Uso (web, localhost):
 *   http://localhost/labcontrol/labcontrol-backend/setup.php?email=admin@labcontrol.local&password=SUA_SENHA_FORTE_16+
 * Uso (CLI):
 *   php setup.php --email=admin@labcontrol.local --password=SUA_SENHA_FORTE_16+
 *
 * Após o setup, recomenda-se remover/excluir este arquivo.
 */

// =====================================================
// 1. Garantir .env com segredos fortes (Ponto 2)
// =====================================================
function lcGetEnvValue($content, $key) {
    if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*)$/m', $content, $m)) {
        return rtrim($m[1]);
    }
    return null;
}

function lcSetEnvValue(&$content, $key, $value) {
    $line = $key . '=' . $value;
    if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=.*$/m', $content)) {
        $content = preg_replace('/^\s*' . preg_quote($key, '/') . '\s*=.*$/m', $line, $content);
    } else {
        $content .= "\n" . $line . "\n";
    }
}

function lcEnsureEnvSecrets() {
    $root = dirname(__DIR__); // repo root (labcontrol-backend/..)
    $envPath = $root . '/.env';
    $examplePath = $root . '/.env.example';

    if (!file_exists($envPath)) {
        if (file_exists($examplePath)) {
            copy($examplePath, $envPath);
        } else {
            file_put_contents($envPath, "");
        }
    }

    $content = (string) file_get_contents($envPath);
    $changed = false;

    // JWT_SECRET: ausente, curto ou igual ao placeholder fraco do exemplo -> gera forte
    $jwt = lcGetEnvValue($content, 'JWT_SECRET');
    if ($jwt === null || strlen($jwt) < 32 ||
        strpos($jwt, 'change_this') !== false || strpos($jwt, 'labcontrol_secure_key') !== false) {
        lcSetEnvValue($content, 'JWT_SECRET', bin2hex(random_bytes(32)));
        $changed = true;
    }

    // ENCRYPTION_KEY: ausente ou curto -> gera forte
    $enc = lcGetEnvValue($content, 'ENCRYPTION_KEY');
    if ($enc === null || strlen($enc) < 16) {
        lcSetEnvValue($content, 'ENCRYPTION_KEY', bin2hex(random_bytes(32)));
        $changed = true;
    }

    if ($changed) {
        file_put_contents($envPath, $content);
    }
}

lcEnsureEnvSecrets();

// Agora o config.php consegue carregar sem die()
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/Database.php';

// =====================================================
// 2. Restringir a localhost (exceto CLI)
// =====================================================
$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
$clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$allowedIPs = ['127.0.0.1', '::1', 'localhost'];

if (!$isCli && !in_array($clientIP, $allowedIPs, true)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Setup permitido apenas do localhost', 'ip' => $clientIP]);
    exit;
}

// =====================================================
// 3. Obter email/senha
// =====================================================
if ($isCli) {
    $opts = getopt('', ['email:', 'password:']);
    $email = $opts['email'] ?? '';
    $password = $opts['password'] ?? '';
} else {
    $email = $_REQUEST['email'] ?? '';
    $password = $_REQUEST['password'] ?? '';
}

if (empty($email)) {
    $email = 'admin@labcontrol.local'; // default, mas a senha é obrigatória e forte
}

header('Content-Type: application/json; charset=utf-8');

// =====================================================
// 4. Criar admin (somente first-run)
// =====================================================
try {
    $db = Database::getInstance();

    $count = $db->selectOne("SELECT COUNT(*) as c FROM users");
    if ((int)($count['c'] ?? 0) > 0) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Sistema já configurado (já existem usuários). Nenhuma ação realizada.'
        ]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email inválido. Informe ?email=valido@exemplo.com']);
        exit;
    }

    if (empty($password) || strlen($password) < 12) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Senha obrigatória e forte (mín. 12 caracteres). Informe ?password=SUA_SENHA_FORTE']);
        exit;
    }

    $adminId = $db->insert('users', [
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'role' => 'admin',
        'is_active' => 1
    ]);

    if (!$adminId) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao criar o usuário admin.']);
        exit;
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Administrador criado com sucesso. Faça login e troque a senha periodicamente.',
        'user_id' => $adminId,
        'email' => $email,
        'role' => 'admin'
    ]);
} catch (Throwable $e) {
    error_log('LabControl setup error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno durante o setup. Verifique os logs do servidor.']);
}
