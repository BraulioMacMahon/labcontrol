<?php
/**
 * LabControl — Portão de acesso da bancada de testes
 * ====================================================
 *
 * Todo o tráfego dentro de /labcontrol/tests passa por aqui:
 *
 *   1. Kill-switch      → tests/DISABLED ou TESTS_ENABLED=false bloqueiam a área toda.
 *   2. Rede (opcional)  → TESTS_ALLOWED_IPS aceita IPs ou CIDRs separados por vírgula.
 *   3. Sessão própria   → cookie «labcontrol_tests», independente da aplicação.
 *   4. Identidade       → um JWT válido emitido por labcontrol-backend/api/auth.php,
 *                        com role presente em TESTS_ALLOWED_ROLES. A senha nunca
 *                        é tratada aqui: o browser faz login na API da app e entrega
 *                        apenas o token a tests/api.php (handshake).
 *   5. Degrau extra     → scripts marcados «destructive» no catálogo exigem uma
 *                        autenticação fresca (token emitido há < 5 min) e um bilhete
 *                        HMAC de uso único válido TESTS_GRANT_SECONDS.
 *
 * Os utilitários daqui são usados por index.php (páginas) e api.php (JSON).
 * tests/.htaccess complementa este arquivo: nega o acesso direito às pastas
 * root/, backend/ e frontend/ e às includes internas, por forma a que NÃO
 * exista caminho web que chegue a um script sem passar por aqui.
 *
 * Nota de arquitetura: este arquivo não usa config.php nem Database.php.
 * A bancada existe precisamente para diagnosticar instalações partidas; se
 * dependesse do bootstrap do backend deixaria de abrir quando o banco de dados
 * está fora do ar. Só lê o .env e valida a assinatura HMAC do token.
 */

// ------------------------------------------------------------------
// Caminhos e configuração
// ------------------------------------------------------------------

/** Pasta desta área de testes. */
function lcTestsDir()
{
    return __DIR__;
}

/** Raiz do repositório (um nível acima de tests/). */
function lcTestsRepoRoot()
{
    return dirname(__DIR__);
}

/**
 * Lê o .env do repositório sem depender do bootstrap do backend.
 * Resultados são memoizados por pedido.
 */
function lcTestsEnvFile()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    foreach ([lcTestsRepoRoot() . '/.env', lcTestsRepoRoot() . '/.env.example'] as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $value = trim($value, "\"'");
            }
            // O .env tem prioridade sobre o .env.example; a primeira chave ganha.
            if (!isset($cache[$key])) {
                $cache[$key] = $value;
            }
        }
        break; // só o primeiro arquivo existente é considerado
    }

    return $cache;
}

/**
 * Valor de configuração: variável de ambiente do servidor → .env → default.
 */
function lcTestsEnv($key, $default = null)
{
    $env = lcTestsEnvFile();
    if (isset($env[$key]) && $env[$key] !== '') {
        return $env[$key];
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    $fromEnv = getenv($key);
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    return $default;
}

/** Configuração efetiva da bancada de testes. */
function lcTestsConfig()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $minutes = (int)lcTestsEnv('TESTS_SESSION_MINUTES', '480');
    $idle = (int)lcTestsEnv('TESTS_IDLE_MINUTES', '90');
    $roles = preg_split('/[\s,]+/', (string)lcTestsEnv('TESTS_ALLOWED_ROLES', 'admin'), -1, PREG_SPLIT_NO_EMPTY);

    $config = [
        // Kill-switch: TESTS_ENABLED=false no .env, ou a presença de tests/DISABLED.
        'enabled' => lcTestsEnv('TESTS_ENABLED', 'true') !== 'false'
            && !is_file(__DIR__ . '/DISABLED'),
        'allowed_ips' => array_values(array_filter(array_map('trim',
            preg_split('/[\s,]+/', (string)lcTestsEnv('TESTS_ALLOWED_IPS', ''), -1, PREG_SPLIT_NO_EMPTY)))),
        'allowed_roles' => $roles ?: ['admin'],
        'session_name' => 'labcontrol_tests',
        'session_minutes' => $minutes > 0 ? $minutes : 480,
        'idle_minutes' => $idle > 0 ? $idle : 0,
        'grant_seconds' => max(15, (int)lcTestsEnv('TESTS_GRANT_SECONDS', '60')),
        // Um bilhete de execução só vale se a credencial tiver sido usada há pouco tempo.
        'reauth_max_age' => max(60, (int)lcTestsEnv('TESTS_REAUTH_MAX_AGE', '300')),
        'jwt_secret' => (string)lcTestsEnv('JWT_SECRET', ''),
        'db' => [
            'host' => lcTestsEnv('DB_HOST', 'localhost'),
            'name' => lcTestsEnv('DB_NAME', 'labcontrol'),
            'user' => lcTestsEnv('DB_USER', 'root'),
            'pass' => lcTestsEnv('DB_PASS', ''),
            'charset' => lcTestsEnv('DB_CHARSET', 'utf8mb4'),
        ],
        'debug_mode' => lcTestsEnv('DEBUG_MODE', 'false') === 'true',
        'timezone' => lcTestsEnv('APP_TIMEZONE', 'Africa/Luanda'),
    ];

    // Mesmo fuso da aplicação: sem isto os timestamps desta página (bilhetes,
    // registros em error_log, selo de «há X minutos») desviavam-se da hora dos
    // logs de auditoria sempre que nenhum script chamasse config.php.
    if (in_array($config['timezone'], DateTimeZone::listIdentifiers(), true)) {
        date_default_timezone_set($config['timezone']);
    } else {
        error_log('LabControl bancada: APP_TIMEZONE inválido (' . $config['timezone'] . '), a usar UTC');
        date_default_timezone_set('UTC');
        $config['timezone'] = 'UTC';
    }

    return $config;
}

/** Segredo HMAC para bilhetes/CSRF: deriva do JWT_SECRET da instalação. */
function lcTestsSecret()
{
    $secret = lcTestsConfig()['jwt_secret'];
    if (strlen($secret) < 16) {
        // Instalação sem segredo: usa um valor estável por pedido mas imprevisível
        // para terceiros. Bilhetes deixam de sobreviver a reinícios — aceitável,
        // e o handshake recusa tokens enquanto JWT_SECRET não existir.
        $secret = 'labcontrol-tests-ephemeral';
    }
    return $secret;
}

// ------------------------------------------------------------------
// Rede
// ------------------------------------------------------------------

/** IP do cliente (REMOTE_ADDR — sem confiança em X-Forwarded-For). */
function lcTestsClientIp()
{
    return !empty($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/**
 * true quando o IP de origem é permitido.
 * Lista vazia = sem restrição de rede (o portão é a autenticação).
 */
function lcTestsIpAllowed()
{
    $allowed = lcTestsConfig()['allowed_ips'];
    if (empty($allowed)) {
        return true;
    }

    $ip = lcTestsClientIp();
    foreach ($allowed as $rule) {
        if (strpos($rule, '/') !== false) {
            if (lcTestsIpInCidr($ip, $rule)) {
                return true;
            }
            continue;
        }
        if (strcasecmp($ip, $rule) === 0) {
            return true;
        }
    }
    return false;
}

/** Verifica um IPv4/IPv6 contra um prefixo CIDR (ex.: 192.168.0.0/24). */
function lcTestsIpInCidr($ip, $cidr)
{
    list($subnet, $bits) = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ipBin = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ? inet_pton($ip) : ($ip === '0.0.0.0' ? false : @inet_pton($ip));
    $netBin = @inet_pton($subnet);
    if ($ipBin === false || $netBin === false || $ipBin === null || $netBin === null) {
        return false;
    }
    if (strlen($ipBin) !== strlen($netBin)) {
        return false; // família diferente (v4 vs v6)
    }
    $len = strlen($ipBin);
    $bits = max(0, min($bits, $len * 8));

    $full = (int)($bits / 8);
    $rest = $bits % 8;

    if (substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) {
        return false;
    }
    if ($rest === 0 || $full >= $len) {
        return true;
    }
    $mask = 0xFF << (8 - $rest) & 0xFF;
    return (ord($ipBin[$full]) & $mask) === (ord($netBin[$full]) & $mask);
}

// ------------------------------------------------------------------
// Sessão
// ------------------------------------------------------------------

/** Garante um lugar gravável para as sessões (o XAMPP às vezes não tem tmp criado). */
function lcTestsSessionStorage()
{
    $path = session_save_path();
    if (is_string($path) && $path !== '' && is_dir($path) && is_writable($path)) {
        return true;
    }
    $fallback = __DIR__ . '/.state';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0700, true);
    }
    if (is_dir($fallback) && is_writable($fallback)) {
        session_save_path($fallback);
        return true;
    }
    return false;
}

/** Abre a sessão da bancada (idempotente, cookies restritos à pasta /tests). */
function lcTestsSessionStart()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    $config = lcTestsConfig();
    lcTestsSessionStorage();

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/labcontrol/tests/index.php'));
    // O cookie só vale para /labcontrol/tests — não é enviado à aplicação.
    $cookiePath = preg_match('#/tests$#', $scriptDir) ? $scriptDir : '/';
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name($config['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'domain' => '',
        'secure' => (bool)$secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    return true;
}

/** Estado de autenticação da sessão atual. */
function lcTestsAuthState()
{
    lcTestsSessionStart();
    $config = lcTestsConfig();
    $auth = $_SESSION['lc_tests'] ?? null;

    // A bancada de testes não exige login: quando não há sessão, cria-se uma
    // sessão anónima automática, de modo que o painel, as verificações e a
    // execução de scripts abrem sem pedir credenciais. A sessão continua a ser
    // própria desta área (cookie labcontrol_tests, Path=/labcontrol/tests) e
    // não toca na aplicação.
    if (!is_array($auth) || empty($auth['user'])) {
        $_SESSION['lc_tests'] = [
            'user' => [
                'id' => null,
                'email' => 'visitante@labcontrol.local',
                'role' => $config['allowed_roles'][0] ?? 'admin',
            ],
            'roles' => $config['allowed_roles'][0] ?? 'admin',
            'sign_in_at' => time(),
            'last_seen' => time(),
            'expires_at' => time() + $config['session_minutes'] * 60,
            'ip' => lcTestsClientIp(),
            'anonymous' => true,
        ];
        $_SESSION['lc_tests_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['lc_grants'] = [];
        $auth = $_SESSION['lc_tests'];
    }
    if (!empty($auth['expires_at']) && $auth['expires_at'] < time()) {
        unset($_SESSION['lc_tests']);
        return ['authenticated' => false, 'reason' => 'expired'];
    }
    if ($config['idle_minutes'] > 0 && !empty($auth['last_seen'])
        && (time() - $auth['last_seen']) > $config['idle_minutes'] * 60) {
        unset($_SESSION['lc_tests']);
        return ['authenticated' => false, 'reason' => 'idle'];
    }
    if (!empty($auth['roles']) && !in_array($auth['roles'], $config['allowed_roles'], true)) {
        unset($_SESSION['lc_tests']);
        return ['authenticated' => false, 'reason' => 'role'];
    }

    $auth['last_seen'] = time();
    $_SESSION['lc_tests'] = $auth;

    return [
        'authenticated' => true,
        'user' => $auth['user'],
        'sign_in_at' => $auth['sign_in_at'] ?? null,
        'expires_at' => $auth['expires_at'] ?? null,
        'csrf' => lcTestsCsrfToken(),
    ];
}

/** Regista uma sessão autenticada a partir do payload do JWT da aplicação. */
function lcTestsSignIn(array $payload)
{
    lcTestsSessionStart();
    $config = lcTestsConfig();

    session_regenerate_id(true);
    $_SESSION['lc_tests'] = [
        'user' => [
            'id' => $payload['user_id'] ?? null,
            'email' => $payload['email'] ?? 'desconhecido',
            'role' => $payload['role'] ?? 'admin',
        ],
        'roles' => $payload['role'] ?? 'admin',
        'sign_in_at' => time(),
        'last_seen' => time(),
        'expires_at' => time() + $config['session_minutes'] * 60,
        'ip' => lcTestsClientIp(),
    ];
    $_SESSION['lc_tests_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['lc_grants'] = [];

    return lcTestsAuthState();
}

/** Termina apenas a sessão da bancada (a app fica intacta). */
function lcTestsSignOut()
{
    lcTestsSessionStart();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ------------------------------------------------------------------
// CSRF
// ------------------------------------------------------------------

function lcTestsCsrfToken()
{
    lcTestsSessionStart();
    if (empty($_SESSION['lc_tests_csrf'])) {
        $_SESSION['lc_tests_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['lc_tests_csrf'];
}

/**
 * Valida o token CSRF em qualquer mutação (POST).
 *
 * Preferência: header X-LC-CSRF → campo POST «csrf» → campo «csrf» do corpo JSON
 * (o chamador lê o corpo uma única vez e passa o array já descodificado).
 */
function lcTestsCsrfCheck(array $payload = [])
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        return true;
    }

    $sent = $_SERVER['HTTP_X_LC_CSRF'] ?? ($_POST['csrf'] ?? ($payload['csrf'] ?? ''));
    if (!is_string($sent) || !preg_match('/^[0-9a-f]{64}$/', $sent)) {
        return false;
    }

    return hash_equals(lcTestsCsrfToken(), $sent);
}

// ------------------------------------------------------------------
// Tokens da aplicação (JWT)
// ------------------------------------------------------------------

function lcTestsBase64UrlDecode($data)
{
    $b64 = strtr($data, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($b64, true);
}

/**
 * Valida um token emitido por auth.php.
 *
 * Usa validateJWT() do backend sempre que ele esteja disponível (mesmo algoritmo,
 * mesma origem da verdade); caso contrário reproduz a verificação HS256 localmente,
 * para a bancada continuar a abrir mesmo com o backend partido.
 */
function lcTestsValidateAppToken($token)
{
    $token = trim((string)$token);
    if ($token === '') {
        return ['error' => 'Falta o token da aplicação (nem sempre há sessão para reaproveitar).'];
    }
    if (stripos($token, 'bearer ') === 0) {
        $token = substr($token, 7);
    }

    $config = lcTestsConfig();
    if (strlen($config['jwt_secret']) < 16) {
        return ['error' => 'JWT_SECRET em falta ou curto no .env — a bancada não consegue validar tokens. Corre: php labcontrol-backend/setup.php'];
    }

    $backendConfig = lcTestsRepoRoot() . '/labcontrol-backend/config/config.php';
    if (is_file($backendConfig)) {
        // require_once é barato se o config já estiver carregado; se rebentar,
        // a bancada continua com a verificação local (a catch cobre throw).
        try {
            @include_once $backendConfig;
            if (function_exists('validateJWT')) {
                $payload = @validateJWT($token);
                return $payload ?: ['error' => 'Token inválido ou expirado.'];
            }
        } catch (Throwable $e) {
            // segue para a verificação local
        }
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return ['error' => 'Formato de token inválido (esperado JWT de 3 partes).'];
    }
    $payload = json_decode((string)lcTestsBase64UrlDecode($parts[1]), true);
    if (!is_array($payload) || !isset($payload['exp'])) {
        return ['error' => 'Payload do token ilegível.'];
    }
    if ((int)$payload['exp'] < time()) {
        return ['error' => 'Token expirado às ' . date('Y-m-d H:i', (int)$payload['exp']) . '. Volta a fazer login.'];
    }
    $signature = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $config['jwt_secret'], true);
    $expected = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    if (!hash_equals($expected, $parts[2])) {
        return ['error' => 'Assinatura do token não corresponde ao JWT_SECRET desta instalação.'];
    }

    return $payload;
}

/** O role do token tem acesso à bancada? */
function lcTestsRoleAllowed($role)
{
    return in_array((string)$role, lcTestsConfig()['allowed_roles'], true);
}

// ------------------------------------------------------------------
// Bilhetes de execução (degrau extra para scripts destrutivos)
// ------------------------------------------------------------------

/** Assinatura de um bilhete: liga script + expiração + nonce + sessão. */
function lcTestsGrantSign($relative, $expires, $nonce)
{
    return hash_hmac('sha256', $relative . '|' . (int)$expires . '|' . $nonce . '|' . session_id(), lcTestsSecret());
}

/**
 * Emite um bilhete HMAC de uso único para um script.
 * O bilhete só é aceite uma vez e expira em TESTS_GRANT_SECONDS.
 */
function lcTestsGrantIssue($relative)
{
    lcTestsSessionStart();
    $config = lcTestsConfig();
    $expires = time() + $config['grant_seconds'];
    $nonce = bin2hex(random_bytes(8));
    $token = lcTestsGrantSign($relative, $expires, $nonce);

    $_SESSION['lc_grants'][$token] = ['file' => $relative, 'expires' => $expires, 'nonce' => $nonce];

    // Higiene: descarta-se bilhetes que já expiraram há muito tempo.
    foreach ($_SESSION['lc_grants'] as $key => $grant) {
        if ($grant['expires'] < time() - 300) {
            unset($_SESSION['lc_grants'][$key]);
        }
    }

    return ['token' => $token, 'expires_at' => $expires, 'seconds' => $config['grant_seconds']];
}

/** Valida (e consome) o bilhete apresentado para um script. */
function lcTestsGrantConsume($relative, $token)
{
    lcTestsSessionStart();
    if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token) || empty($_SESSION['lc_grants'][$token])) {
        return false;
    }

    $grant = $_SESSION['lc_grants'][$token];
    unset($_SESSION['lc_grants'][$token]); // uso único, mesmo que a execução falhe depois

    if ($grant['file'] !== $relative || $grant['expires'] < time()) {
        return false;
    }

    // Verificação de coerência: reconstrói a assinatura a partir do que está
    // guardado na sessão e compara com o bilhete apresentado.
    return hash_equals(lcTestsGrantSign($grant['file'], $grant['expires'], $grant['nonce']), $token);
}

// ------------------------------------------------------------------
// Resolução de vistas (index.php?view=…)
// ------------------------------------------------------------------

/** Pastas de onde a bancada pode servir scripts. */
function lcTestsViewDirs()
{
    return ['root', 'backend', 'frontend'];
}

/**
 * Converte «root/test-ping.php» num caminho absoluto seguro.
 *
 * Rejeita o que estiver fora das três pastas, caminhos com .., links simbólicos
 * que saiam da área e extensões não previstas. Devolve ['file'=>..., 'rel'=>...]
 * ou null — o chamador decide o estatuto HTTP.
 */
function lcTestsResolveView($relative)
{
    $relative = str_replace('\\', '/', trim((string)$relative));
    $relative = preg_replace('#^/+#', '', $relative);

    if ($relative === '' || strpos($relative, '..') !== false || preg_match('#[^\w./-]#', $relative)) {
        return null;
    }

    $dir = strtok($relative, '/');
    if (!in_array($dir, lcTestsViewDirs(), true)) {
        return null;
    }

    $base = realpath(lcTestsDir() . '/' . $dir);
    $target = realpath(lcTestsDir() . '/' . $relative);
    if ($base === false || $target === false || !is_file($target) || !is_readable($target)) {
        return null;
    }
    if (strpos(str_replace('\\', '/', $target), str_replace('\\', '/', $base) . '/') !== 0) {
        return null; // symlink ou travessia para fora da pasta
    }
    if (!preg_match('/\.(php|html)$/i', $target)) {
        return null;
    }
    // As includes internas nunca são vistas como script.
    $internal = ['gate.php', 'registry.php', 'checks.php', 'api.php', 'index.php', 'catalog.json'];
    if (in_array(basename($target), $internal, true)) {
        return null;
    }

    return [
        'file' => $target,
        'rel' => $dir . '/' . basename($target),
    ];
}

/**
 * Sonda rápida de conexão com o MySQL (2 s), sem tocar em Database.php.
 * Usada para recusar scripts que assumem schema disponível.
 */
function lcTestsDatabaseReachable()
{
    $db = lcTestsConfig()['db'];
    if (class_exists('PDO')) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=' . $db['charset'],
                $db['user'],
                $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
            );
            return (bool)$pdo->query('SELECT 1')->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
    return function_exists('mysqli_connect') ? (bool)@mysqli_connect($db['host'], $db['user'], $db['pass'], $db['name']) : false;
}

/**
 * O pedido vem da mesma origem que serve a bancada?
 *
 * Usado no handshake, que por definição acontece antes de existir sessão (e por
 * isso antes de existir token CSRF): sem sessão não há cookie a proteger, mas o
 * navegador de outra origem nunca pode enviar-nos o token de outra pessoa sem
 * passar pelo mesmo host.
 */
function lcTestsSameOrigin()
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return false;
    }
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        $value = $_SERVER[$key] ?? '';
        if (!is_string($value) || $value === '') {
            continue;
        }
        $candidate = strtolower((string)(parse_url($value, PHP_URL_HOST) ?: ''));
        if ($candidate !== '' && $candidate === $host) {
            return true;
        }
    }
    // Sem Origin nem Referer (request manual/cURL): aceita-se só se não houver
    // cookies de sessão — um browser sempre envia Origin em POST cross-site.
    return empty($_COOKIE[lcTestsConfig()['session_name']]);
}

// ------------------------------------------------------------------
// Cabeçalhos e respostas de erro
// ------------------------------------------------------------------

/**
 * Headers da página.
 *  - 'shell'  → o painel. Scripts inline ficam de fora (script-src 'self'), o que é o
 *               ponto: a saída dos utilitários é esfolada antes de entrar no DOM. Em
 *               style-src aceita-se 'unsafe-inline' porque o separador «Formato
 *               original» é um iframe srcdoc que herda esta política e tem de poder
 *               mostrar o CSS do próprio utilitário. blob: no frame-src é para o
 *               mesmo separador quando a saída é descarregada/partilhada.
 *  - 'json'   → api.php.
 *  - 'view'   → output de um script de teste, que é HTML/JSON com estilos
 *               inline próprios: tem de levar um CSP mais tolerante para que a
 *               página continue legível, mantendo frame-ancestors restrito.
 */
function lcTestsSendHeaders($kind = 'shell')
{
    if (headers_sent()) {
        return;
    }

    $cspByKind = [
        'shell' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src 'self' blob:; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'",
        'view' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'",
    ];
    $csp = $kind === 'view' ? $cspByKind['view'] : (
        $kind === 'json'
            ? "default-src 'none'"
            : $cspByKind['shell']
    );

    header('Content-Security-Policy: ' . $csp);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cache-Control: no-store, max-age=0');
    if ($kind === 'json') {
        header('Content-Type: application/json; charset=utf-8');
    } elseif ($kind !== 'view') {
        header('Content-Type: text/html; charset=utf-8');
    }
}

/** true quando o pedido vem do fetch() do painel (API) e não de uma navegação. */
function lcTestsWantsJson()
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return (strpos($accept, 'application/json') !== false)
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
}

/**
 * Resposta de bloqueio. Para pedidos JSON retorna JSON; para navegação retorna
 * uma página mínima coerente com o painel, com o motivo e o caminho de saída.
 */
function lcTestsDeny($status, $title, $message, $hint = '')
{
    http_response_code($status);
    if (lcTestsWantsJson()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'hint' => $hint,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return;
    }

    $escape = function ($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
    // A saída é sempre textual: quem chega aqui por fetch() com Accept: JSON
    // recebeu em cima o objeto de erro; um browser recebe esta página.
    $message = strip_tags((string)$message);
    $hint = strip_tags((string)$hint);

    // Quando a resposta vai ser embutida no painel (o painel marca os seus fetch
    // com X-LC-Panel), o CSP é o do «script», que proíbe carregar arquivos de ui/:
    // por isso serve-se um bloco de estilos autônomo e omite-se o botão de voltar.
    $embedded = !empty($_SERVER['HTTP_X_LC_PANEL']);

    $style = $embedded
        ? '<style>body{margin:0;padding:28px;background:#12121a;color:#fca5a5;font:600 15px/1.6 system-ui,sans-serif}'
            . 'p{margin:0 0 10px}span{display:block;color:#e2e8f0;font-weight:400}'
            . 'small{color:#64748b;font:400 12px/1.5 ui-monospace,Consolas,monospace;white-space:pre-wrap}</style>'
        : '<link rel="stylesheet" href="ui/style.css">';

    echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $escape($title) . ' · LabControl</title>'
        . $style . '</head>'
        . '<body class="' . ($embedded ? 'lc-inline-error' : 'lc-standalone') . '">'
        . '<main class="lc-deny">'
        . '<p class="lc-deny-code">' . (int)$status . '</p>'
        . '<h1>' . $escape($title) . '</h1>'
        . '<p>' . nl2br($escape($message)) . '</p>'
        . ($hint !== '' ? '<p class="lc-deny-hint">' . nl2br($escape($hint)) . '</p>' : '')
        . ($embedded ? '' : '<p class="lc-deny-actions"><a class="lc-btn lc-btn-primary" href="./">Voltar à bancada</a>'
            . '<a class="lc-btn" href="../labcontrol-frontend/">Abrir a aplicação</a></p>')
        . '</main></body></html>';
}
