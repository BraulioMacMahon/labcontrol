<?php
/**
 * LabControl — Verificações de ambiente da bancada de testes
 * ==========================================================
 *
 * São os cartões do painel «Estado do sistema». Ao contrário dos 30 e poucos
 * scripts de debug espalhados por tests/, estas verificações vivem aqui porque
 * têm de ser:
 *
 *   • rápidas e seguras — nenhuma escreve em lado nenhum; o banco de dados é
 *     sondada com PDO ATTR_TIMEOUT curto e os comandos de sistema executam com
 *     um limite de segundos (proc_terminate) para a página nunca pendurar;
 *   • independentes do backend — não include config.php nem Database.php, para
 *     continuarem a responder quando o próprio backend está partido (é exatamente
 *     aí que alguém abre esta página);
 *   • acionáveis — cada verificação com remédio aponta o script que o executa,
 *     e o painel mostra o botão «corrigir» ao lado.
 */

require_once __DIR__ . '/registry.php';

/**
 * Corre um comando com limite de tempo real (exec()/shell_exec() não têm timeout).
 *
 * @return array{code:int,output:string,timeout:bool}
 */
function lcTestsRunCommand($command, $timeoutSeconds = 6)
{
    $result = ['code' => -1, 'output' => '', 'timeout' => false];
    if (!function_exists('proc_open')) {
        $result['output'] = 'proc_open desativado no php.ini';
        return $result;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes, lcTestsRepoRoot());
    if (!is_resource($process)) {
        $result['output'] = 'não foi possível iniciar o processo';
        return $result;
    }

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }
    fclose($pipes[0]);

    $output = '';
    $error = '';
    $deadline = microtime(true) + max(1, (int)$timeoutSeconds);

    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = $except = null;
        $remaining = (int)max(0, ($deadline - microtime(true)) * 1000000);
        if ($remaining <= 0) {
            $result['timeout'] = true;
            break;
        }
        if (@stream_select($read, $write, $except, 0, $remaining) === false) {
            break;
        }
        if (empty($read)) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            continue;
        }
        foreach ($read as $stream) {
            $chunk = fread($stream, 4096);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            if ($stream === $pipes[2]) {
                $error .= $chunk;
            } else {
                $output .= $chunk;
            }
        }
    }

    $output .= (string)stream_get_contents($pipes[1]);
    $error .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if ($result['timeout']) {
        $descriptors = null;
        @proc_terminate($process, 9);
    }
    $code = @proc_close($process);

    $result['code'] = (int)$code;
    $result['output'] = trim($output !== '' ? $output : $error);
    return $result;
}

/** Cache simples em sessão, para a página não re-sondar o servidor a cada render. */
function lcTestsChecksCached($forceRefresh = false, $ttl = 45)
{
    lcTestsSessionStart();
    $cache = $_SESSION['lc_checks'] ?? null;
    if (!$forceRefresh && is_array($cache) && (time() - $cache['at']) < $ttl) {
        $cache['cached'] = true;
        return $cache;
    }

    $checks = lcTestsEnvironmentChecks();
    $summary = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
    foreach ($checks as $check) {
        if (isset($summary[$check['state']])) {
            $summary[$check['state']]++;
        }
    }

    $payload = [
        'at' => time(),
        'cached' => false,
        'ttl' => $ttl,
        'checks' => $checks,
        'summary' => $summary,
        'overall' => $summary['fail'] > 0 ? 'fail' : ($summary['warn'] > 0 ? 'warn' : 'ok'),
    ];
    $_SESSION['lc_checks'] = $payload;

    return $payload;
}

/**
 * Executa todas as verificações.
 *
 * @return array<int, array{id:string,label:string,group:string,state:string,value:string,detail:string,fix:?string}>
 */
function lcTestsEnvironmentChecks()
{
    $config = lcTestsConfig();
    $root = lcTestsRepoRoot();
    $checks = [];

    $add = function ($id, $group, $label, $state, $value, $detail = '', $fix = null) use (&$checks) {
        $checks[] = [
            'id' => $id,
            'group' => $group,
            'label' => $label,
            'state' => $state,
            'value' => (string)$value,
            'detail' => (string)$detail,
            'fix' => $fix,
        ];
    };

    // 1 — PHP -------------------------------------------------------------
    $php = PHP_VERSION;
    $phpMajor = (int)substr($php, 0, 1);
    $add(
        'php',
        'site',
        'PHP',
        $phpMajor >= 8 ? 'ok' : ($phpMajor === 7 ? 'warn' : 'fail'),
        $php . ' · ' . PHP_OS . ' · ' . (PHP_ZTS ? 'TS' : 'NTS'),
        $phpMajor >= 8 ? 'Versão suportada.' : 'O backend assume PHP 7.4+/8.x — em PHP 7 alguns operadores e funções de sessão falham.',
        null
    );

    $required = ['pdo_mysql', 'curl', 'mbstring', 'json', 'openssl'];
    $missing = array_values(array_filter($required, function ($ext) {
        return !extension_loaded($ext);
    }));
    $add(
        'extensions',
        'site',
        'Extensões obrigatórias',
        empty($missing) ? 'ok' : 'fail',
        empty($missing) ? implode(', ', $required) . ' presentes' : 'em falta: ' . implode(', ', $missing),
        $missing ? 'Ativa as extensões no php.ini (C:\\xampp\\php\\php.ini) e reinicia o Apache.' : 'curl é usado pelo Firebase, pdo_mysql pelo banco de dados.',
        null
    );

    // 2 — Configuração ----------------------------------------------------
    $envPath = $root . '/.env';
    $envExists = is_file($envPath);
    $add(
        'env',
        'site',
        'Ficheiro .env',
        $envExists ? (is_readable($envPath) ? 'ok' : 'warn') : 'fail',
        $envExists ? basename($envPath) . ' · ' . number_format(filesize($envPath) / 1024, 1) . ' KB' : 'não encontrado',
        $envExists
            ? 'Lido a partir de ' . str_replace('\\', '/', $envPath)
            : 'Cria a partir do .env.example. Sem ele a app usa defaults e a bancada não consegue validar tokens.',
        null
    );

    $secretLength = strlen($config['jwt_secret']);
    $add(
        'jwt_secret',
        'auth',
        'JWT_SECRET',
        $secretLength >= 32 ? 'ok' : ($secretLength > 0 ? 'warn' : 'fail'),
        $secretLength . ' caracteres',
        $secretLength >= 32
            ? 'Obrigatório para a bancada conseguir validar o token com que entras.'
            : 'Curto ou ausente. Gera um segredo forte com: php labcontrol-backend/setup.php',
        'backend/test-auth.php'
    );

    $add(
        'debug_mode',
        'site',
        'DEBUG_MODE',
        $config['debug_mode'] ? 'warn' : 'ok',
        $config['debug_mode'] ? 'true' : 'false',
        $config['debug_mode'] ? 'Em produção deixa escapar caminhos e mensagens PHP pelas APIs de teste.' : 'Erros vão apenas para o log do servidor.',
        null
    );

    $timezone = function_exists('date_default_timezone_get') ? date_default_timezone_get() : 'UTC';
    $add(
        'timezone',
        'site',
        'Fuso horário',
        'ok',
        $timezone,
        'Define a hora escrita nos logs de auditoria (APP_TIMEZONE no .env).',
        null
    );

    // 3 — Banco de dados ----------------------------------------------------
    $dbState = 'fail';
    $dbValue = 'sem conexão';
    $dbDetail = '';
    $pdo = null;
    try {
        $dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['name'] . ';charset=' . $config['db']['charset'];
        $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $config['db']['charset'],
        ]);
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $dbState = 'ok';
        $dbValue = $config['db']['name'] . ' · MySQL ' . $version;
        $dbDetail = 'Ligação em ' . $config['db']['host'] . ' como ' . $config['db']['user'] . '.';
    } catch (Throwable $e) {
        $pdo = null;
        $dbDetail = $e->getMessage();
    }
    $add('database', 'db', 'Banco de dados', $dbState, $dbValue, $dbDetail, $dbState === 'ok' ? null : 'backend/test-connection.php');

    if ($pdo instanceof PDO) {
        $tables = ['users' => 'contas', 'hosts' => 'máquinas', 'logs' => 'auditoria', 'sync_queue' => 'fila de sincronização', 'system_config' => 'parâmetros'];
        $present = [];
        $absent = [];
        $counts = [];
        foreach ($tables as $table => $purpose) {
            try {
                $found = $pdo->query(
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote($table)
                )->fetchColumn();
                if ((int)$found > 0) {
                    $present[] = $table;
                    $rowCount = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
                    $counts[$table] = $rowCount;
                } else {
                    $absent[] = $table . ' (' . $purpose . ')';
                }
            } catch (Throwable $e) {
                $absent[] = $table . ' ilegível';
            }
        }
        $add(
            'tables',
            'db',
            'Tabelas críticas',
            empty($absent) ? 'ok' : 'fail',
            implode(' · ', array_map(function ($table) use ($counts) {
                return $table . ': ' . ($counts[$table] ?? '?');
            }, $present)) ?: 'nenhuma',
            empty($absent) ? 'users/hosts/logs/sync_queue/system_config presentes e legíveis.' : 'Em falta: ' . implode(', ', $absent),
            'backend/migrate-tables.php'
        );

        // Estrutura da tabela logs — origem de metade dos «fix-logs-table» deste repositório.
        try {
            $columns = $pdo->query(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote('logs')
            )->fetchAll(PDO::FETCH_COLUMN);
            $columns = is_array($columns) ? array_map('strtolower', $columns) : [];
            $expected = ['id', 'user_id', 'user_email', 'hostname', 'action', 'category', 'details', 'status'];
            $missingCols = array_values(array_diff($expected, $columns));
            $add(
                'logs_columns',
                'db',
                'Colunas da tabela logs',
                empty($columns) ? 'fail' : (empty($missingCols) ? 'ok' : 'warn'),
                empty($columns) ? 'tabela ausente' : count($columns) . ' colunas',
                empty($missingCols) ? 'Compatível com logAction() do Database.php.' : 'Em falta: ' . implode(', ', $missingCols),
                'backend/fix-logs-table.php'
            );
        } catch (Throwable $e) {
            $add('logs_columns', 'db', 'Colunas da tabela logs', 'warn', 'não inspecionada', $e->getMessage(), 'root/debug-logs-table.php');
        }

        // Contas ainda acessíveis com as senhas de fábrica do repositório.
        try {
            if (in_array('users', $present, true)) {
                $weak = (int)$pdo->query(
                    "SELECT COUNT(*) FROM users WHERE is_active = 1 AND (password_hash = '' OR LEFT(password_hash, 4) <> '\$2y\$')"
                )->fetchColumn();
                $totalAccounts = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
                $add(
                    'accounts',
                    'users',
                    'Contas ativas',
                    $totalAccounts === 0 ? 'fail' : ($weak > 0 ? 'warn' : 'ok'),
                    $totalAccounts . ' conta(s)',
                    $totalAccounts === 0
                        ? 'Nenhuma conta ativa — é por isso que o login falha. Cria a primeira com: php labcontrol-backend/setup.php --email=... --password=...'
                        : ($weak > 0
                            ? $weak . ' conta(s) sem hash bcrypt válido. Verifica com root/debug-password-check.php antes de executar qualquer fix-.'
                            : 'Todas as contas têm hash bcrypt. Corre root/debug-password-check.php para confirmar que não aceitam senhas de fábrica.'),
                    'root/debug-list-users.php'
                );
            }
        } catch (Throwable $e) {
            $add('accounts', 'users', 'Contas ativas', 'warn', 'não inspecionada', $e->getMessage(), 'root/debug-list-users.php');
        }
    } else {
        $add('tables', 'db', 'Tabelas críticas', 'fail', 'não inspecionada', 'Sem conexão não é possível listar tabelas.', 'backend/full-setup.php');
        $add('logs_columns', 'db', 'Colunas da tabela logs', 'fail', 'não inspecionada', 'Sem conexão não é possível inspecionar a estrutura.', 'root/fix-logs-table.php');
        $add('accounts', 'users', 'Contas ativas', 'fail', 'não inspecionada', 'Sem conexão com o banco de dados, a bancada não consegue validar seu token.', 'backend/debug-login.php');
    }

    // 4 — Sistema de arquivos --------------------------------------------
    $logDir = $root . '/labcontrol-backend/logs';
    $add(
        'logs_dir',
        'site',
        'Pasta de logs',
        is_dir($logDir) && is_writable($logDir) ? 'ok' : 'fail',
        is_dir($logDir) ? (is_writable($logDir) ? 'gravável' : 'só leitura') : 'inexistente',
        'logError() escreve aqui; sem escrita os erros do PHP desaparecem silenciosamente. Caminho: ' . str_replace('\\', '/', $logDir),
        null
    );

    $cacheDir = $root . '/labcontrol-backend/cache';
    $cacheOk = is_dir($cacheDir) && is_writable($cacheDir);
    $add(
        'cache_dir',
        'site',
        'Cache do RateLimiter',
        $cacheOk ? 'ok' : 'warn',
        $cacheOk ? 'gravável' : (is_dir($cacheDir) ? 'só leitura' : 'criado no primeiro uso'),
        'labcontrol-backend/cache/ guarda os contadores de tentativas de login (middleware/RateLimiter.php).',
        'backend/reset-rate-limit.php'
    );

    $savePath = function_exists('session_save_path') ? session_save_path() : '';
    $add(
        'session_storage',
        'auth',
        'Armazenamento de sessões PHP',
        lcTestsSessionStorage() ? 'ok' : 'warn',
        ($savePath !== '' && is_dir($savePath)) ? str_replace('\\', '/', $savePath) : str_replace('\\', '/', __DIR__ . '/.state'),
        $savePath === '' ? 'php.ini não define session.save_path — a bancada usa tests/.state (só sessões dela própria).' : 'Usado apenas pela sessão da bancada; a app não guarda sessões.',
        null
    );

    // 5 — PowerShell / rede ------------------------------------------------
    $execDisabled = !function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
    $add(
        'exec',
        'powershell',
        'Execução de comandos',
        $execDisabled ? 'fail' : 'ok',
        $execDisabled ? 'exec() desativada' : 'exec() disponível',
        'Todo o controlo remoto (WinRM, ping, WOL) depende de o PHP conseguir lançar o PowerShell.',
        'root/test-path.php'
    );

    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if (!$isWindows) {
        $add('powershell', 'powershell', 'PowerShell no servidor', 'info', 'não aplicável', 'O servidor executa ' . PHP_OS . '; os scripts .ps1 e o WinRM assumem Windows.', null);
    } elseif ($execDisabled) {
        $add('powershell', 'powershell', 'PowerShell no servidor', 'fail', 'indisponível', 'exec() está desativada, por isso o PHP não consegue chamar powershell.exe.', 'root/test-control-path.php');
    } else {
        $run = lcTestsRunCommand('powershell -NoProfile -NonInteractive -Command "$PSVersionTable.PSVersion.ToString()"', 8);
        $state = ($run['code'] === 0 && !$run['timeout'] && $run['output'] !== '') ? 'ok' : 'fail';
        $add(
            'powershell',
            'powershell',
            'PowerShell no servidor',
            $state,
            $state === 'ok' ? 'v' . $run['output'] : ($run['timeout'] ? 'sem resposta em 8 s' : 'código ' . $run['code']),
            $state === 'ok' ? 'Pronto para receber os scripts de labcontrol-backend/powershell/.' : $run['output'],
            'root/test-control-path.php'
        );
    }

    $expectedScripts = ['Get-Processes.ps1', 'Execute-Command.ps1', 'Kill-Process.ps1', 'Restart-Host.ps1', 'Shutdown-Host.ps1'];
    $absentScripts = array_values(array_filter($expectedScripts, function ($file) use ($root) {
        return !is_file($root . '/labcontrol-backend/powershell/' . $file);
    }));
    $add(
        'ps_scripts',
        'powershell',
        'Scripts .ps1',
        empty($absentScripts) ? 'ok' : 'fail',
        (count($expectedScripts) - count($absentScripts)) . '/' . count($expectedScripts) . ' em labcontrol-backend/powershell',
        empty($absentScripts) ? 'Todos presentes.' : 'Em falta: ' . implode(', ', $absentScripts),
        'root/test-control-path.php'
    );

    $syncRoot = $root . '/sync-service';
    $syncReady = is_file($syncRoot . '/index.js');
    $syncDeps = is_dir($syncRoot . '/node_modules');
    $add(
        'sync_service',
        'site',
        'Sync service (Node.js)',
        !$syncReady ? 'fail' : ($syncDeps ? 'ok' : 'warn'),
        !$syncReady ? 'ausente' : ($syncDeps ? 'dependências instaladas' : 'sem node_modules'),
        $syncReady && !$syncDeps ? 'Corre: cd sync-service && npm install (o serviço fala com o backend via sync.php).' : 'Serviço opcional de sincronização Firebase.',
        'backend/test-firebase.php'
    );

    $origins = array_values(array_filter(array_map('trim', explode(',', (string)lcTestsEnv('CORS_ALLOWED_ORIGINS', 'http://localhost,http://127.0.0.1')))));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $origin = $scheme . '://' . $host;
    $originOk = in_array('*', $origins, true) || in_array($origin, $origins, true);
    $add(
        'cors',
        'auth',
        'CORS da API',
        $originOk ? 'ok' : 'warn',
        $origins ? implode(', ', $origins) : 'vazio',
        $originOk
            ? 'A origem atual (' . $origin . ') pode chamar a API — é a origem usada por esta bancada.'
            : 'Acrescenta ' . $origin . ' a CORS_ALLOWED_ORIGINS no .env, senão o browser bloqueia o login desta página.',
        null
    );

    // 6 — Integração com a app --------------------------------------------
    $authApi = $root . '/labcontrol-backend/api/auth.php';
    $add(
        'auth_api',
        'auth',
        'API de autenticação',
        is_file($authApi) && is_readable($authApi) ? 'ok' : 'fail',
        is_file($authApi) ? 'auth.php disponível' : 'auth.php em falta',
        'A bancada autentica-se por aqui (auth.php?action=login) em vez de tratar senhas.',
        'backend/test-auth.php'
    );

    $catalog = lcTestsCatalog();
    $catalogIssue = !$catalog['catalog']['readable'] ? 'fail' : ($catalog['counts']['undocumented'] > 0 || !empty($catalog['missing']) ? 'warn' : 'ok');
    $add(
        'catalog',
        'site',
        'Catálogo de testes',
        $catalogIssue,
        $catalog['counts']['total'] . ' scripts · ' . $catalog['counts']['undocumented'] . ' sem ficha',
        $catalog['catalog']['error']
            ?: ('Ficheiros em catalog.json que já não existem: ' . (empty($catalog['missing']) ? 'nenhum' : implode(', ', $catalog['missing']))
                . '. Total: ' . $catalog['counts']['read'] . ' leitura, ' . $catalog['counts']['write'] . ' escrita, '
                . $catalog['counts']['destructive'] . ' destrutivos.'),
        null
    );

    $htaccess = @file_get_contents(__DIR__ . '/.htaccess');
    $indexed = is_string($htaccess) && strpos($htaccess, 'DirectoryIndex index.php') !== false;
    $sealed = [];
    $leaked = [];
    foreach (lcTestsViewDirs() as $folder) {
        $rules = @file_get_contents(__DIR__ . '/' . $folder . '/.htaccess');
        if (is_string($rules) && preg_match('/Require all denied|Deny from all/i', $rules)) {
            $sealed[] = $folder;
        } else {
            $leaked[] = $folder . '/';
        }
    }
    $guardOk = $indexed && empty($leaked);
    $add(
        'guard',
        'site',
        'Portão de acesso',
        $guardOk ? 'ok' : 'fail',
        $guardOk
            ? 'root/, backend/ e frontend/ negados; painel servido por DirectoryIndex'
            : ($indexed ? 'pastas sem negação: ' . implode(', ', $leaked) : 'sem DirectoryIndex em tests/.htaccess'),
        'Sem estas negações os utilitários ficariam acessíveis por URL direto, contornando o portão — '
            . 'por exemplo backend/debug-password.php recria o admin com senha de fábrica. '
            . ($config['enabled'] ? 'Área ativa; crie tests/DISABLED para desligá-la em produção.' : 'Área desativada pelo kill-switch.'),
        $guardOk ? null : 'backend/test-connection.php'
    );

    return $checks;
}
