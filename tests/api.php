<?php
/**
 * LabControl — API da bancada de testes
 * =====================================
 *
 * JSON para o painel (tests/ui/app.js). Nada aqui retorna HTML, exceto erros.
 *
 *   GET  ?action=bootstrap          estado + catálogo + verificações
 *   GET  ?action=checks[&fresh=1]   só as verificações de ambiente
 *   GET  ?action=script&file=…      ficha de um script (deep link sem recarregar)
 *   POST ?action=handshake          troca um JWT da app por uma sessão da bancada
 *   POST ?action=grant              bilhete de 60 s para scripts que escrevem/destróem
 *   POST ?action=logout             fecha a sessão da bancada (a app fica como está)
 *
 * A senha nunca passa por aqui: o painel faz login em
 * ../labcontrol-backend/api/auth.php?action=login e entrega apenas o token.
 * Assim o rate limiting, o lockout e os registros de auditoria continuam a ser
 * os da aplicação, e a bancada não copia lógica de autenticação.
 */

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/checks.php';

lcTestsSendHeaders('json');

/** Corpo do pedido: JSON (o painel envia sempre JSON) ou form. */
function lcTestsRequestBody()
{
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return is_array($_POST ?? null) ? $_POST : [];
}

/** JSON de resposta uniforme. */
function lcTestsReply($payload, $status = 200)
{
    if (ob_get_length()) {
        @ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function lcTestsFail($message, $status = 400, $extra = [])
{
    lcTestsReply(array_merge(['success' => false, 'status' => $status, 'message' => $message], $extra), $status);
}

$action = (string)($_GET['action'] ?? 'bootstrap');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$config = lcTestsConfig();

if (!$config['enabled']) {
    lcTestsFail('A bancada de testes está desativada nesta instalação (tests/DISABLED ou TESTS_ENABLED=false).', 503);
}
if (!lcTestsIpAllowed()) {
    lcTestsFail('O endereço ' . lcTestsClientIp() . ' não está em TESTS_ALLOWED_IPS.', 403);
}
if (!in_array($action, ['handshake'], true) && $method === 'POST' && !lcTestsCsrfCheck(lcTestsRequestBody())) {
    // handshake é o único endpoint legítimo pré-sessão.
    lcTestsFail('Token CSRF em falta ou inválido. Recarrega a página.', 403);
}

switch ($action) {
    // -----------------------------------------------------------------
    case 'bootstrap':
        $auth = lcTestsAuthState();
        $payload = [
            'success' => true,
            'authenticated' => !empty($auth['authenticated']),
            'reason' => $auth['reason'] ?? null,
            'user' => $auth['user'] ?? null,
            'expires_at' => $auth['expires_at'] ?? null,
            'sign_in_at' => $auth['sign_in_at'] ?? null,
            'csrf' => $auth['csrf'] ?? null,
            'server_time' => time(),
            'config' => [
                'api_base' => '../labcontrol-backend/api',
                'app_url' => '../labcontrol-frontend/',
                'allowed_roles' => $config['allowed_roles'],
                'grant_seconds' => $config['grant_seconds'],
                'session_minutes' => $config['session_minutes'],
                'idle_minutes' => $config['idle_minutes'],
                'reauth_max_age' => $config['reauth_max_age'],
                'ip_restricted' => !empty($config['allowed_ips']),
                'debug_mode' => $config['debug_mode'],
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'os' => PHP_OS,
                'timezone' => date_default_timezone_get(),
            ],
            'modes' => lcTestsModeLabels(),
        ];

        if (empty($auth['authenticated'])) {
            $payload['catalog'] = null;
            $payload['checks'] = null;
            $payload['note'] = 'Catálogo e verificações ficam visíveis depois de entrar.';
            lcTestsReply($payload);
        }

        $payload['catalog'] = lcTestsCatalog();
        $payload['checks'] = lcTestsChecksCached(isset($_GET['checks']) && $_GET['checks'] === 'fresh');
        lcTestsReply($payload);
        // no break (lcTestsReply termina o pedido)

    // -----------------------------------------------------------------
    case 'checks':
        $auth = lcTestsAuthState();
        if (empty($auth['authenticated'])) {
            lcTestsFail('Sessão terminada — entre novamente para rever o estado do sistema.', 401);
        }
        lcTestsReply([
            'success' => true,
            'checks' => lcTestsChecksCached($method === 'GET' && ($_GET['fresh'] ?? '') === '1'),
        ]);
        // no break

    // -----------------------------------------------------------------
    case 'script':
        $auth = lcTestsAuthState();
        if (empty($auth['authenticated'])) {
            lcTestsFail('Sessão terminada.', 401, ['reason' => $auth['reason'] ?? 'no_session']);
        }
        $rel = (string)($_GET['file'] ?? '');
        $resolved = lcTestsResolveView($rel);
        if ($resolved === null) {
            lcTestsFail('«' . $rel . '» não é um script executável desta bancada.', 404);
        }
        $meta = lcTestsScriptMeta($resolved['rel']);
        lcTestsReply([
            'success' => true,
            'script' => $meta,
            'confirm_tier' => lcTestsConfirmTier($meta),
            'database' => in_array('database', $meta['requires'], true) ? lcTestsDatabaseReachable() : null,
        ]);
        // no break

    // -----------------------------------------------------------------
    case 'handshake':
        if ($method !== 'POST') {
            lcTestsFail('Handshake exige POST.', 405);
        }
        if (!lcTestsSameOrigin()) {
            lcTestsFail('Origem não reconhecida: abre a bancada a partir de ' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '.', 403);
        }

        $body = lcTestsRequestBody();
        $token = (string)($body['token'] ?? '');

        // Throttle: o handshake não conhece senhas, mas mesmo assim limita
        // tentativas de adivinhação de JWT por IP (o RateLimiter já é usado pela app).
        $limiterClass = lcTestsRepoRoot() . '/labcontrol-backend/middleware/RateLimiter.php';
        if (is_file($limiterClass)) {
            require_once $limiterClass;
            if (class_exists('RateLimiter')) {
                $limiter = new RateLimiter('tests-handshake:' . lcTestsClientIp(), 12, 300);
                if (!$limiter->isAllowed()) {
                    $info = method_exists($limiter, 'getInfo') ? $limiter->getInfo() : ['reset_in' => 300];
                    lcTestsFail('Demasiadas tentativas de entrada. Tenta de novo em ' . (int)($info['reset_in'] ?? 300) . ' s.', 429);
                }
            }
        }

        $payload = lcTestsValidateAppToken($token);
        if (!is_array($payload) || isset($payload['error'])) {
            lcTestsFail($payload['error'] ?? 'Não foi possível validar o token.', 401);
        }
        $role = (string)($payload['role'] ?? '');
        if (!lcTestsRoleAllowed($role)) {
            lcTestsFail(
                'A conta ' . ($payload['email'] ?? '?') . ' tem o perfil «' . ($role !== '' ? $role : 'sem perfil')
                    . '», e a bancada aceita: ' . implode(', ', $config['allowed_roles']) . '.',
                403,
                ['role' => $role, 'allowed_roles' => $config['allowed_roles']]
            );
        }

        // Se o banco de dados responder, confirma-se que a conta continua ativa —
        // um token com 8 h de validade não pode dar acesso a uma conta desativada.
        $dbChecked = false;
        $dbReachable = lcTestsDatabaseReachable();
        if ($dbReachable) {
            $dbCfg = $config['db'];
            try {
                $pdo = new PDO(
                    'mysql:host=' . $dbCfg['host'] . ';dbname=' . $dbCfg['name'] . ';charset=' . $dbCfg['charset'],
                    $dbCfg['user'],
                    $dbCfg['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
                );
                $stmt = $pdo->prepare('SELECT is_active, role FROM users WHERE id = :id OR email = :email LIMIT 1');
                $stmt->execute([':id' => $payload['user_id'] ?? 0, ':email' => $payload['email'] ?? '']);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $dbChecked = true;
                if (!$row) {
                    lcTestsFail('A conta do token já não existe na tabela users.', 403);
                }
                if (empty($row['is_active'])) {
                    lcTestsFail('A conta ' . ($payload['email'] ?? '') . ' está desativada.', 403);
                }
                if (!lcTestsRoleAllowed((string)$row['role'])) {
                    lcTestsFail('O perfil atual da conta («' . $row['role'] . '») já não tem acesso à bancada.', 403);
                }
                $payload['role'] = $row['role'];
            } catch (Throwable $e) {
                error_log('LabControl bancada (handshake): ' . $e->getMessage());
                $dbChecked = false;
            }
        }

        $auth = lcTestsSignIn($payload);
        error_log(sprintf(
            'LabControl bancada: entrada concedida a %s (%s) a partir de %s%s',
            $payload['email'] ?? '?',
            $payload['role'] ?? '?',
            lcTestsClientIp(),
            $dbChecked ? '' : ' [contas não verificadas: banco de dados inacessível]'
        ));

        lcTestsReply([
            'success' => true,
            'message' => 'Sessão da bancada iniciada.',
            'user' => $auth['user'],
            'csrf' => $auth['csrf'],
            'expires_at' => $auth['expires_at'],
            'accounts_verified' => $dbChecked,
            'catalog' => lcTestsCatalog(),
            'checks' => lcTestsChecksCached(true),
        ]);
        // no break

    // -----------------------------------------------------------------
    case 'grant':
        $auth = lcTestsAuthState();
        if (empty($auth['authenticated'])) {
            lcTestsFail('Sessão terminada — entre novamente para executar este script.', 401, ['reason' => $auth['reason'] ?? 'no_session']);
        }
        if ($method !== 'POST') {
            lcTestsFail('Concessão de bilhete exige POST.', 405);
        }

        $body = lcTestsRequestBody();
        $rel = (string)($body['file'] ?? '');
        $resolved = lcTestsResolveView($rel);
        if ($resolved === null) {
            lcTestsFail('«' . $rel . '» não é um script executável desta bancada.', 404);
        }
        $meta = lcTestsScriptMeta($resolved['rel']);
        if (!empty($meta['blocked'])) {
            lcTestsFail((string)$meta['blocked'], 403, ['cli' => $meta['cli'] ?? null]);
        }

        $tier = lcTestsConfirmTier($meta);
        if ($tier === 'none') {
            lcTestsFail('Este script não precisa de bilhete — executa direto.', 400, ['confirm_tier' => $tier]);
        }

        // «reauth»: a conta tem de provar a senha AGORA. O token tem de ter
        // sido emitido há pouco tempo (iat fresco), o que impossibilita reusar o
        // token guardado na sessão da bancada para validar operações destrutivas.
        if ($tier === 'reauth') {
            $fresh = lcTestsValidateAppToken((string)($body['token'] ?? ''));
            if (!is_array($fresh) || isset($fresh['error'])) {
                lcTestsFail('Confirmação recusada: ' . ($fresh['error'] ?? 'token inválido') , 403);
            }
            $age = time() - (int)($fresh['iat'] ?? 0);
            if ($age > $config['reauth_max_age']) {
                lcTestsFail(
                    'Este script exige uma autenticação fresca: a sessão usada foi aberta há '
                        . number_format($age / 60) . ' min (limite: ' . number_format($config['reauth_max_age'] / 60) . ' min).',
                    403,
                    ['needs_fresh_login' => true]
                );
            }
            if (strcasecmp((string)($fresh['email'] ?? ''), (string)($auth['user']['email'] ?? '')) !== 0) {
                lcTestsFail('A confirmação foi feita com outra conta (' . ($fresh['email'] ?? '?') . ').', 403);
            }
            if (!lcTestsRoleAllowed((string)($fresh['role'] ?? ''))) {
                lcTestsFail('A conta usada para confirmar não tem o perfil exigido.', 403);
            }
        }

        $grant = lcTestsGrantIssue($resolved['rel']);
        error_log(sprintf(
            'LabControl bancada: bilhete para %s concedido a %s (%s) a partir de %s',
            $resolved['rel'],
            $auth['user']['email'] ?? '?',
            $auth['user']['role'] ?? '?',
            lcTestsClientIp()
        ));

        lcTestsReply([
            'success' => true,
            'file' => $resolved['rel'],
            'confirm_tier' => $tier,
            'grant' => $grant,
            'url' => 'index.php?run=1&view=' . rawurlencode($resolved['rel']) . '&_g=' . $grant['token'],
        ]);
        // no break

    // -----------------------------------------------------------------
    case 'logout':
        if ($method !== 'POST') {
            lcTestsFail('Logout exige POST.', 405);
        }
        lcTestsSignOut();
        lcTestsReply(['success' => true, 'message' => 'Sessão da bancada encerrada. A aplicação não foi tocada.']);
        // no break

    default:
        lcTestsFail('Ação desconhecida: ' . $action, 404, [
            'actions' => ['bootstrap', 'checks', 'script', 'handshake', 'grant', 'logout'],
        ]);
}
