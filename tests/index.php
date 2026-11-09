<?php
/**
 * LabControl — Bancada de testes e diagnóstico
 * ============================================
 *
 * Ponto de entrada único de /labcontrol/tests:
 *
 *   tests/                          → painel (o shell + dados, renderizados no servidor)
 *   tests/index.php?run=1&view=…     → executa um script e retorna a saída dele
 *
 * Não há mais URLs públicos para tests/root/* nem tests/backend/*: o .htaccess
 * reencaminha tudo por aqui, por forma a que o portão (gate.php) seja sempre
 * atravessado — inclusive quando alguém escreve o caminho do script à mão na barra
 * de endereço. Ver tests/README.md.
 */

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/checks.php';

$config = lcTestsConfig();
$action = $_GET['run'] ?? null;
$view = $_GET['view'] ?? '';

// -----------------------------------------------------------------
// 1. Kill-switch e rede
// -----------------------------------------------------------------
if (!$config['enabled']) {
    lcTestsDeny(
        503,
        'Bancada de testes desativada',
        'Esta área está desligada nesta instalação.',
        'Para a reativar: apaga tests/DISABLED e/ou define TESTS_ENABLED=true no .env, depois recarrega.'
    );
    exit;
}

if (!lcTestsIpAllowed()) {
    lcTestsDeny(
        403,
        'Fora da rede autorizada',
        'Seu endereço ' . lcTestsClientIp() . ' não está na lista TESTS_ALLOWED_IPS.',
        'Ajusta TESTS_ALLOWED_IPS no .env (IPs ou CIDRs separados por vírgula) ou remove a variável para liberar o acesso autenticado.'
    );
    exit;
}

// -----------------------------------------------------------------
// 2. Execução de um script (?run=1&view=…)
// -----------------------------------------------------------------
if ($action !== null && $action !== '') {
    if (!is_string($view) || $view === '') {
        lcTestsDeny(400, 'Falta o script', 'Pedido de execução sem o parâmetro view.');
        exit;
    }

    $resolved = lcTestsResolveView($view);
    if ($resolved === null) {
        lcTestsDeny(
            404,
            'Script não encontrado',
            '«' . $view . '» não corresponde a um arquivo dentro de tests/root, tests/backend ou tests/frontend.',
            'Apenas arquivos .php e .html dessas três pastas podem ser executados pela bancada.'
        );
        exit;
    }

    $meta = lcTestsScriptMeta($resolved['rel']);

    if (!empty($meta['blocked'])) {
        lcTestsDeny(403, 'Script indisponível na web', (string)$meta['blocked'], $meta['cli'] ? 'Executar por linha de comandos: ' . $meta['cli'] : '');
        exit;
    }

    // Sem login na bancada: lcTestsAuthState() abre uma sessão anónima quando
    // não há nenhuma, pelo contrário a execução segue sem exigir credenciais.
    lcTestsAuthState();

    // Degrau extra para scripts que escrevem/destróem: bilhete de uso único.
    if (lcTestsNeedsGrant($meta)) {
        $grant = $_GET['_g'] ?? '';
        if (!lcTestsGrantConsume($resolved['rel'], is_string($grant) ? $grant : '')) {
            lcTestsDeny(
                403,
                'Confirmação necessária',
                $meta['mode'] === 'destructive'
                    ? 'Este script altera dados de forma irreversível e só executa através do painel, depois de confirmar a sua senha.'
                    : 'Este script escreve no servidor e tem de ser disparado pelo painel (botão «Executar»).' . "\n"
                        . 'Um bilhete expirado também produz esta mensagem: volta ao painel e pede um novo.',
                $meta['side_effects'] ?: ''
            );
            exit;
        }
    }

    // Scripts que assumem o banco de dados: uma conexão interrompida no meio de um ALTER
    // TABLE é pior que não executar nada, por isso sonda-se antes de entregar o controlo.
    if (in_array('database', $meta['requires'], true) && !lcTestsDatabaseReachable()) {
        lcTestsDeny(
            503,
            'Banco de dados inacessível',
            '«' . $config['db']['name'] . '» em ' . $config['db']['host'] . ' não respondeu. Este script precisa da conexão completa antes de começar.',
            'Veja o cartão «Banco de dados» no painel, confirma o MySQL no XAMPP Control Panel e tente novamente.'
        );
        exit;
    }

    // A partir daqui o controlo passa ao script: headers dele, saída dele.
    lcTestsSendHeaders('view');
    $isHtml = preg_match('/\.html$/i', $resolved['file']);

    if ($isHtml) {
        // O HTML dos scripts de frontend usa caminhos relativos que só fazem
        // sentido a partir de tests/frontend/. Como a resposta sai de index.php,
        // injeta-se um <base> para que fetch()/links continuem a funcionar.
        $document = (string)file_get_contents($resolved['file']);
        $base = htmlspecialchars(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? 'index.php'), '/') . '/' . dirname($resolved['rel']) . '/', ENT_QUOTES, 'UTF-8');
        $tag = '<base href="' . $base . '">';
        if (stripos($document, '<head') !== false) {
            $document = preg_replace('/<head\b[^>]*>/i', '$0' . $tag, $document, 1);
        } else {
            $document = $tag . $document;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $document;
        exit;
    }

    // .php → executa no contexto do pedido (o script trata dos seus headers).
    // Os includes internos dos scripts usam __DIR__, por isso continuam a
    // encontrar labcontrol-backend/ a partir da sua própria pasta.
    $cwdBackup = getcwd();
    @chdir(dirname($resolved['file']));
    try {
        include $resolved['file'];
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "O script rebentou antes de produzir resposta.\n\n"
            . get_class($e) . ': ' . $e->getMessage() . "\n"
            . ' em ' . str_replace('\\', '/', $e->getFile()) . ':' . $e->getLine() . "\n";
        error_log('LabControl bancada: ' . $e->getMessage());
    } finally {
        if ($cwdBackup !== false) {
            @chdir($cwdBackup);
        }
    }
    exit;
}

// -----------------------------------------------------------------
// 3. O painel
// -----------------------------------------------------------------
$auth = lcTestsAuthState(); // sessão anónima automática quando não há login
$force = isset($_GET['checks']) && $_GET['checks'] === 'fresh';
$checks = lcTestsChecksCached($force);
$catalog = lcTestsCatalog();

$bootstrap = [
    'authenticated' => true,
    'reason' => null,
    'user' => $auth['user'] ?? null,
    'expires_at' => $auth['expires_at'] ?? null,
    'sign_in_at' => $auth['sign_in_at'] ?? null,
    'csrf' => $auth['csrf'] ?? null,
    'server_time' => time(),
    'config' => [
        'api_base' => '../labcontrol-backend/api',
        'app_url' => '../labcontrol-frontend/',
        'docs_url' => '../docs/INDEX.md',
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
        'path' => '/labcontrol/tests',
        'requested' => $view,
    ],
    'modes' => lcTestsModeLabels(),
    // Sem login: o catálogo e as verificações estão visíveis para qualquer
    // pessoa que abra a bancada.
    'catalog' => $catalog,
    'checks' => $checks,
];

$shell = __DIR__ . '/ui/shell.html';
if (!is_file($shell) || !is_readable($shell)) {
    lcTestsDeny(500, 'Interface em falta', 'tests/ui/shell.html não foi encontrado no servidor.');
    exit;
}

$html = (string)file_get_contents($shell);
$json = json_encode(
    $bootstrap,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$replacement = '<script id="lc-bootstrap" type="application/json">' . ($json === false ? '{}' : $json) . '</script>';
$needle = '<!--lc:bootstrap-->';
if (strpos($html, $needle) === false) {
    $needle = '</head>';
    $replacement .= '<script>window.__LC_BOOTSTRAP_MISSING_MARKER__=1;</script>';
}
$html = str_replace($needle, $needle . "\n    " . $replacement, $html);

lcTestsSendHeaders('shell');
echo $html;
exit;
