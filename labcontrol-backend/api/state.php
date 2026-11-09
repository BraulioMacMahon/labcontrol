<?php
/**
 * LabControl - Estado em tempo real (Server-Sent Events)
 * =====================================================
 *
 * Substitui o polling pesado do frontend (`hosts.php?action=check-all` a cada
 * 10 s, que fazia ping a todas as máquinas dentro de um pedido HTTP).
 *
 * Dois modos, ambos GET e muito leves (uma única consulta ao MySQL):
 *
 *   GET state.php?action=version  -> JSON com um fingerprint do estado.
 *                                    Suporta If-None-Match / ETag (304).
 *                                    Usado no arranque e como fallback.
 *
 *   GET state.php?action=stream   -> text/event-stream (SSE). Mantém a ligação
 *                                    aberta e emite `hello`, `state` e `bye`.
 *                                    O browser reconecta sozinho via EventSource.
 *
 * O fingerprint é calculado a partir de `hosts.updated_at` (o sync-service e a
 * API já atualizam esta coluna), `MAX(logs.id)` e as contagens das tabelas.
 * Assim, QUALQUER escrita — mudança de status de um host, novo host, novo log
 * de auditoria, eliminação — é detetada sem que o browser tenha de perguntar
 * repetidamente "houve alterações?" com pedidos caros.
 *
 * Não é necessário WebSocket aqui: o fluxo é unidirecional (servidor -> browser)
 * e os comandos continuam a ser POST/fetch normais. WebSocket exigiria um
 * processo Node dedicado com `ws` e mod_proxy_wstunnel no Apache.
 *
 * Limitação conhecida: em Apache mod_php/prefork cada ligação SSE ocupa um
 * worker enquanto dura. Por isso a ligação é encerrada ao fim de
 * SSE_MAX_DURATION segundos com um evento `bye` (o cliente reconecta de imediato),
 * e o frontend fecha a ligação quando a aba fica invisível.
 *
 * @see docs/DOCUMENTACAO_API.md
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Database.php';

$action = $_GET['action'] ?? 'version';
$method = $_SERVER['REQUEST_METHOD'];

// Mesmos headers CORS dos restantes endpoints.
header('Access-Control-Allow-Origin: ' . getCorsOrigin());
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: ' . CORS_ALLOWED_HEADERS);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
// Desativa o buffering de proxies reversos (nginx, Cloudflare, ...).
header('X-Accel-Buffering: no');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method !== 'GET') {
    jsonResponse(false, 'Método não permitido', null, 405);
}

/**
 * Lê o fingerprint do estado numa só query.
 *
 * UNIX_TIMESTAMP(MAX(coluna)) mantém a pesquisa sargable (usa índice), ao
 * contrário de MAX(UNIX_TIMESTAMP(coluna)), que obrigaria a varrer a tabela.
 * As contagens detetam eliminações, que não alterariam os MAX().
 */
function labcontrolReadState($db)
{
    $row = $db->selectOne(
        "SELECT
            COALESCE((SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM hosts), 0) AS hosts_ts,
            (SELECT COUNT(*) FROM hosts) AS hosts_count,
            COALESCE((SELECT MAX(id) FROM logs), 0) AS logs_max_id,
            (SELECT COUNT(*) FROM logs) AS logs_count"
    );

    if (!is_array($row)) {
        // Sem BD (ou schema antigo): não deixar o SSE morrer em silêncio.
        // O tick muda periodicamente e o frontend cai para um refresh leve.
        // Logar uma vez por pedido, não a cada iteração do loop.
        static $logged = false;
        if (!$logged) {
            logError('state.php: falha ao ler o fingerprint do estado');
            $logged = true;
        }
        $tick = (string) intdiv(time(), 15);
        return [
            'version'   => 'degraded-' . $tick,
            'hosts_rev' => 'degraded-' . $tick,
            'logs_rev'  => 'degraded-' . $tick,
            'degraded'  => true,
        ];
    }

    $hostsRev = $row['hosts_ts'] . '-' . $row['hosts_count'];
    $logsRev  = $row['logs_max_id'] . '-' . $row['logs_count'];

    return [
        'version'   => md5($hostsRev . '|' . $logsRev),
        'hosts_rev' => $hostsRev,
        'logs_rev'  => $logsRev,
        'degraded'  => false,
    ];
}

/**
 * Envia um evento SSE e esvazia todos os buffers para chegar ao browser
 * imediatamente (sem isto o Apache/proxy acumula a resposta e o SSE "não chega").
 */
function labcontrolSseEvent($event, $data)
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

$db = Database::getInstance();
$state = labcontrolReadState($db);

// ---------------------------------------------------------------------
// MODO 1: versão (JSON + ETag). Usado no arranque e no fallback de polling.
// ---------------------------------------------------------------------
if ($action === 'version') {
    $etag = '"' . $state['version'] . '"';
    header('ETag: ' . $etag);
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    echo json_encode([
        'success'    => true,
        'message'    => 'Versão do estado',
        'timestamp'  => date('Y-m-d H:i:s'),
        'data'       => [
            'version'      => $state['version'],
            'hosts_rev'    => $state['hosts_rev'],
            'logs_rev'     => $state['logs_rev'],
            'degraded'     => $state['degraded'],
            'server_time'  => date('c'),
            'stream'       => 'state.php?action=stream',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'stream') {
    jsonResponse(false, 'Ação não encontrada', null, 404);
}

// ---------------------------------------------------------------------
// MODO 2: stream SSE.
// ---------------------------------------------------------------------

$pollInterval = max(1, min(10, (int) env('SSE_POLL_INTERVAL', 2)));   // segundos
$heartbeat    = max(5, min(60, (int) env('SSE_HEARTBEAT', 15)));      // segundos
$maxDuration  = max(30, min(600, (int) env('SSE_MAX_DURATION', 120))); // segundos
$retryMs      = max(1000, min(30000, (int) env('SSE_RETRY_MS', 3000)));

// Não há sessões PHP nesta aplicação, mas manter isto evita bloqueios caso
// uma instalação tenha session.auto_start ativo.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@set_time_limit(0);
ignore_user_abort(false);

while (ob_get_level() > 0) {
    // Alguns handlers não são removíveis; não ficar preso num loop infinito.
    if (!@ob_end_clean()) break;
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// O EventSource usa este valor para reconectar após uma queda.
echo 'retry: ' . $retryMs . "\n\n";
@flush();

$startedAt = microtime(true);
$lastHeartbeat = $startedAt;
$lastVersion = $state['version'];

// `hello` sincroniza o cliente imediatamente: se houve alterações enquanto a
// página esteve fechada, o cliente compara com a sua versão e atualiza só o
// que mudou. Não é necessário recarregar a página.
labcontrolSseEvent('hello', [
    'version'       => $state['version'],
    'hosts_rev'     => $state['hosts_rev'],
    'logs_rev'      => $state['logs_rev'],
    'degraded'      => $state['degraded'],
    'server_time'   => date('c'),
    'poll_interval' => $pollInterval,
    'max_duration'  => $maxDuration,
]);

while (true) {
    if (connection_aborted()) {
        break;
    }

    $now = microtime(true);

    if (($now - $startedAt) >= $maxDuration) {
        // Libertar o worker Apache; o cliente reconecta ao receber `bye`.
        labcontrolSseEvent('bye', ['reason' => 'max_duration']);
        break;
    }

    $current = labcontrolReadState($db);

    if ($current['version'] !== $lastVersion) {
        labcontrolSseEvent('state', [
            'version'     => $current['version'],
            'hosts_rev'   => $current['hosts_rev'],
            'logs_rev'    => $current['logs_rev'],
            'degraded'    => $current['degraded'],
            'server_time' => date('c'),
        ]);
        $lastVersion = $current['version'];
        $lastHeartbeat = $now;
    } elseif (($now - $lastHeartbeat) >= $heartbeat) {
        // Comentário SSE: mantém a ligação viva sem gerar eventos no browser.
        echo ": keep-alive\n\n";
        @flush();
        $lastHeartbeat = $now;
    }

    // sleep() em vez de busy-wait: consome ~0 CPU por ligação.
    sleep($pollInterval);
}

exit;
