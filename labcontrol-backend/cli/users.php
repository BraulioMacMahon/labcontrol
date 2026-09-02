<?php
/**
 * LabControl - Gestão de utilizadores e diagnóstico de login (APENAS CLI)
 *
 * Uso (na raiz do projeto):
 *   php labcontrol-backend/cli/users.php list
 *   php labcontrol-backend/cli/users.php create        --email=x@y.z --password=SenhaForte12+ [--role=admin|operator]
 *   php labcontrol-backend/cli/users.php set-password  --email=x@y.z --password=NovaSenhaForte12+
 *   php labcontrol-backend/cli/users.php activate      --email=x@y.z
 *   php labcontrol-backend/cli/users.php deactivate    --email=x@y.z
 *   php labcontrol-backend/cli/users.php check-login   --email=x@y.z --password=...   (simula o login SEM tocar nos dados)
 *   php labcontrol-backend/cli/users.php check-config                                  (diagnostica .env, JWT, BD, rate limit)
 *   php labcontrol-backend/cli/users.php reset-rate-limit [--ip=127.0.0.1]
 *
 * Substitui os antigos scripts de tests/ (fix-passwords.php, debug-password.php, ...)
 * que eram acessíveis via web e recriavam contas com senhas conhecidas.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: este script só pode ser executado via linha de comandos.\n";
    exit(1);
}

const LC_MIN_PASSWORD = 12;

$argvCopy = $argv;
array_shift($argvCopy);
$command = $argvCopy[0] ?? 'help';
$opts = [];
foreach (array_slice($argvCopy, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/i', $arg, $m)) {
        $opts[strtolower($m[1])] = $m[2] ?? true;
    }
}

function out($msg = '') { fwrite(STDOUT, $msg . "\n"); }
function fail($msg, $code = 1) { fwrite(STDERR, "ERRO: {$msg}\n"); exit($code); }
function okmark($b) { return $b ? '✅' : '❌'; }

if ($command === 'help' || $command === '--help' || $command === '-h') {
    $lines = file(__FILE__);
    foreach (array_slice($lines, 2, 15) as $l) out(rtrim(preg_replace('/^\s*\*\s?/', '', $l)));
    exit(0);
}

// ---------------------------------------------------------------------------
// check-config: pode ser útil MESMO quando o config.php falha, por isso lê o
// .env diretamente antes de carregar o resto.
// ---------------------------------------------------------------------------
if ($command === 'check-config') {
    $root = dirname(__DIR__, 2);
    $envPath = $root . DIRECTORY_SEPARATOR . '.env';
    out("== Ficheiro .env ==");
    out(okmark(file_exists($envPath)) . " existe: {$envPath}");
    if (file_exists($envPath)) {
        out(okmark(is_readable($envPath)) . " legível");
        out(okmark(is_writable($envPath)) . " gravável (necessário só para self-bootstrap de segredos)");
        $raw = (string) file_get_contents($envPath);
        $vals = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
                $v = trim($m[2]);
                if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) $v = trim($v, "\"'");
                $vals[$m[1]] = isset($vals[$m[1]]) ? $vals[$m[1]] . ' (DUPLICADA!)' : $v;
            }
        }
        foreach (['JWT_SECRET', 'ENCRYPTION_KEY'] as $k) {
            $v = $vals[$k] ?? null;
            $weak = $v === null || strlen($v) < 16 || stripos($v, 'change') !== false || stripos($v, 'labcontrol_secure_key') !== false;
            out(okmark(!$weak) . " {$k}: " . ($v === null ? 'AUSENTE' : (strlen($v) . ' chars' . ($weak ? ' — FRACO/PLACEHOLDER (será regenerado; se o .env não for gravável o login falha)' : ''))));
        }
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'APP_TIMEZONE', 'CORS_ALLOWED_ORIGINS', 'DEBUG_MODE'] as $k) {
            out("   {$k} = " . ($vals[$k] ?? '(não definido)'));
        }
        if (isset($vals['REMOTE_PASSWORD']) && $vals['REMOTE_PASSWORD'] === 'Insert@into17') {
            out("❌ REMOTE_PASSWORD ainda é a senha antiga que esteve pública no GitHub — rode-a nas estações!");
        }
    }
    out();
    out("== PHP ==");
    out("   versão: " . PHP_VERSION . " | SAPI: " . PHP_SAPI);
    foreach (['pdo_mysql', 'openssl', 'json', 'curl', 'sockets'] as $ext) {
        out(okmark(extension_loaded($ext)) . " extensão {$ext}" . ($ext === 'sockets' ? ' (só para Wake-on-LAN)' : ''));
    }
    out();
    out("== Backend ==");
    try {
        require_once __DIR__ . '/../config/config.php';
        require_once __DIR__ . '/../includes/Database.php';
        out("✅ config.php carregou (timezone efetiva: " . date_default_timezone_get() . ")");
        out(okmark(strlen(JWT_SECRET) >= 32) . " JWT_SECRET em uso: " . strlen(JWT_SECRET) . " chars");
        $probe = generateJWT(['user_id' => 0, 'email' => 'probe@local', 'role' => 'operator']);
        out(okmark(validateJWT($probe) !== false) . " assinar+validar JWT no mesmo processo");
        $db = Database::getInstance();
        out("✅ ligação MySQL OK (" . DB_HOST . "/" . DB_NAME . ")");
        foreach (['users', 'hosts', 'logs', 'remote_credentials'] as $t) {
            $r = $db->selectOne("SHOW TABLES LIKE :t", [':t' => $t]);
            out(okmark((bool) $r) . " tabela {$t}");
        }
        $users = $db->select("SELECT id, email, role, is_active, LEFT(password_hash, 4) AS hp, LENGTH(password_hash) AS hl FROM users ORDER BY id");
        out("   utilizadores: " . count($users));
        foreach ($users as $u) {
            $hashOk = preg_match('/^\$2[aby]\$/', $u['hp']) && (int) $u['hl'] === 60;
            out("   - #{$u['id']} {$u['email']} [{$u['role']}] " . ($u['is_active'] ? 'ativo' : 'INATIVO') . ' | hash ' . ($hashOk ? 'bcrypt OK' : "INVÁLIDO ({$u['hp']}…, {$u['hl']} chars) → use set-password"));
        }
        $cacheDir = realpath(__DIR__ . '/../cache') ?: (__DIR__ . '/../cache');
        $n = is_dir($cacheDir) ? count(glob($cacheDir . '/*.json') ?: []) : 0;
        out("   rate-limit cache: {$n} ficheiro(s) em {$cacheDir}" . ($n ? ' (use reset-rate-limit se estiver bloqueado)' : ''));
    } catch (Throwable $e) {
        out("❌ " . $e->getMessage());
    }
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../middleware/RateLimiter.php';

try {
    $db = Database::getInstance();
} catch (Throwable $e) {
    fail('Sem ligação à base de dados: ' . $e->getMessage());
}

$email = isset($opts['email']) ? trim((string) $opts['email']) : '';
$password = isset($opts['password']) ? (string) $opts['password'] : '';

switch ($command) {
    case 'list':
        $users = $db->select("SELECT id, email, role, is_active, last_login, created_at FROM users ORDER BY id");
        if (!$users) { out("Nenhum utilizador. Crie um com: create --email=... --password=... --role=admin"); break; }
        out(str_pad('ID', 5) . str_pad('EMAIL', 40) . str_pad('ROLE', 10) . str_pad('ATIVO', 7) . 'ÚLTIMO LOGIN');
        foreach ($users as $u) {
            out(str_pad($u['id'], 5) . str_pad($u['email'], 40) . str_pad($u['role'], 10) . str_pad($u['is_active'] ? 'sim' : 'NÃO', 7) . ($u['last_login'] ?? '-'));
        }
        break;

    case 'create':
        $role = strtolower((string) ($opts['role'] ?? 'operator'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('--email inválido');
        if (strlen($password) < LC_MIN_PASSWORD) fail('--password deve ter pelo menos ' . LC_MIN_PASSWORD . ' caracteres');
        if (!in_array($role, ['admin', 'operator'], true)) fail('--role deve ser admin ou operator');
        if ($db->selectOne("SELECT id FROM users WHERE email = :e", [':e' => $email])) fail('Já existe um utilizador com esse email (use set-password / activate)');
        $id = $db->insert('users', [
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => $role,
            'is_active' => 1,
        ]);
        if (!$id) fail('Falha ao inserir: ' . $db->getLastError());
        $db->logAction(null, 'cli', null, null, "Utilizador criado via CLI: {$email}", 'user', json_encode(['role' => $role]), 'success');
        out("✅ Utilizador #{$id} {$email} [{$role}] criado.");
        break;

    case 'set-password':
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('--email inválido');
        if (strlen($password) < LC_MIN_PASSWORD) fail('--password deve ter pelo menos ' . LC_MIN_PASSWORD . ' caracteres');
        $u = $db->selectOne("SELECT id FROM users WHERE email = :e", [':e' => $email]);
        if (!$u) fail('Utilizador não encontrado');
        $ok = $db->update('users', ['password_hash' => password_hash($password, PASSWORD_BCRYPT)], 'id = :id', [':id' => $u['id']]);
        if ($ok === false) fail('Falha ao atualizar: ' . $db->getLastError());
        $db->logAction(null, 'cli', null, null, "Senha redefinida via CLI: {$email}", 'auth', null, 'success');
        out("✅ Senha de {$email} redefinida.");
        break;

    case 'activate':
    case 'deactivate':
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('--email inválido');
        $u = $db->selectOne("SELECT id FROM users WHERE email = :e", [':e' => $email]);
        if (!$u) fail('Utilizador não encontrado');
        $active = $command === 'activate' ? 1 : 0;
        $db->update('users', ['is_active' => $active], 'id = :id', [':id' => $u['id']]);
        $db->logAction(null, 'cli', null, null, ($active ? 'Ativado' : 'Desativado') . " via CLI: {$email}", 'user', null, 'success');
        out("✅ {$email} " . ($active ? 'ativado' : 'desativado') . '.');
        break;

    case 'check-login':
        // Reproduz passo a passo o que auth.php?action=login faz, sem efeitos secundários.
        if ($email === '' || $password === '') fail('Indique --email e --password');
        out("1) Formato do email: " . okmark(filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
        $u = $db->selectOne("SELECT * FROM users WHERE email = :e", [':e' => $email]);
        out("2) Existe na tabela users: " . okmark((bool) $u));
        if (!$u) { out("   → O login responde 'Credenciais inválidas'. Crie com: create --email=... --password=..."); break; }
        out("3) is_active = 1: " . okmark((bool) $u['is_active']) . ($u['is_active'] ? '' : '   → getUserByEmail() filtra por is_active=1 → 401. Use: activate --email=' . $email));
        $hashOk = preg_match('/^\$2[aby]\$/', (string) $u['password_hash']) && strlen((string) $u['password_hash']) === 60;
        out("4) Hash bcrypt válido: " . okmark($hashOk) . ($hashOk ? '' : '   → hash corrompido/truncado (' . strlen((string) $u['password_hash']) . ' chars). Use: set-password'));
        $verify = password_verify($password, (string) $u['password_hash']);
        out("5) password_verify: " . okmark($verify) . ($verify ? '' : '   → senha diferente da guardada. Use: set-password --email=' . $email . ' --password=NOVA'));
        out("6) Role: {$u['role']}");
        $probe = generateJWT(['user_id' => $u['id'], 'email' => $u['email'], 'role' => $u['role']]);
        out("7) Token gerado e validado com o JWT_SECRET atual: " . okmark(validateJWT($probe) !== false));
        out();
        out($verify && $u['is_active'] ? "✅ Este par email/senha PASSA no login do backend. Se o browser continua no ecrã de login, o problema é o token ser rejeitado a seguir (header Authorization no Apache, JWT_SECRET a mudar entre requests) — veja check-config e a consola do browser (F12 → Network → verify)." : "❌ Este par email/senha NÃO passa no login do backend.");
        break;

    case 'reset-rate-limit':
        $ip = (string) ($opts['ip'] ?? '');
        $cacheDir = __DIR__ . '/../cache/';
        if ($ip !== '') {
            $f = $cacheDir . md5("login:{$ip}") . '.json';
            out(file_exists($f) && unlink($f) ? "✅ Rate limit de {$ip} limpo." : "ℹ️ Não havia bloqueio para {$ip}.");
        } else {
            $n = 0;
            foreach (glob($cacheDir . '*.json') ?: [] as $f) { if (@unlink($f)) $n++; }
            out("✅ {$n} ficheiro(s) de rate limit removidos.");
        }
        break;

    default:
        fail("Comando desconhecido: {$command}. Use 'help'.");
}
