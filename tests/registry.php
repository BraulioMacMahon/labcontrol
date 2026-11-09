<?php
/**
 * LabControl — Catálogo da bancada de testes
 * ==========================================
 *
 * Junta duas fontes:
 *   • tests/catalog.json → fichas curadas (título, grupo, resumo, risco, expetativas).
 *   • O sistema de arquivos → o que realmente existe em tests/{root,backend,frontend}.
 *
 * Um script novo basta colocá-lo numa dessas pastas para aparecer no painel: entra no
 * grupo «sem ficha», com a descrição lida do bloco de comentários e o risco inferido
 * por uma varredura estática (INSERT/UPDATE/DELETE/ALTER/DROP/password_hash). Quando o
 * catálogo referir um arquivo que já lá não está, é marcado como «em falta» em vez de
 * ser escondido — um índice mentiroso é pior que um índice incompleto.
 */

require_once __DIR__ . '/gate.php';

/** Catálogo curado (JSON), com erro legível quando o arquivo está inválido. */
function lcTestsLoadCatalog()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $path = __DIR__ . '/catalog.json';
    $cache = [
        'path' => $path,
        'readable' => is_file($path) && is_readable($path),
        'error' => null,
        'version' => null,
        'updated' => null,
        'groups' => [],
        'scripts' => [],
    ];

    if (!$cache['readable']) {
        $cache['error'] = 'catalog.json não foi encontrado ou não é legível.';
        return $cache;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        $cache['error'] = 'catalog.json não é JSON válido: ' . json_last_error_msg();
        return $cache;
    }

    $cache['version'] = $decoded['version'] ?? null;
    $cache['updated'] = $decoded['updated'] ?? null;
    foreach ((array)($decoded['groups'] ?? []) as $group) {
        if (!empty($group['id'])) {
            $cache['groups'][$group['id']] = $group;
        }
    }
    foreach ((array)($decoded['scripts'] ?? []) as $rel => $meta) {
        if (is_array($meta)) {
            $cache['scripts'][$rel] = $meta;
        }
    }

    return $cache;
}

/** Ficheiros executáveis existentes na área de testes, por caminho relativo. */
function lcTestsScanFiles()
{
    $found = [];
    foreach (lcTestsViewDirs() as $dir) {
        $absolute = lcTestsDir() . '/' . $dir;
        if (!is_dir($absolute)) {
            continue;
        }
        foreach (scandir($absolute) ?: [] as $entry) {
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }
            $file = $absolute . '/' . $entry;
            if (!is_file($file) || !preg_match('/\.(php|html)$/i', $entry)) {
                continue;
            }
            $stat = @stat($file);
            $found[$dir . '/' . $entry] = [
                'bytes' => $stat ? (int)$stat['size'] : null,
                'modified' => $stat ? (int)$stat['mtime'] : null,
            ];
        }
    }
    ksort($found);
    return $found;
}

/**
 * Primeira frase útil de um bloco de comentários do script.
 * Usada apenas para arquivos sem ficha no catálogo.
 */
function lcTestsDocblockSummary($absolute)
{
    $handle = @fopen($absolute, 'r');
    if (!$handle) {
        return null;
    }
    $chunk = (string)fread($handle, 2048);
    fclose($handle);

    if (!preg_match('#/\*\*?\s*(.*?)\*/#s', $chunk, $match)) {
        return null;
    }

    $lines = preg_split('/\R/', trim($match[1]));
    $best = '';
    foreach ($lines as $line) {
        $line = trim(preg_replace('/^\s*\*?\s?/', '', $line));
        if ($line === '' || strpos($line, '@') === 0) {
            continue;
        }
        if (preg_match('/^LabControl\s*[-–—]\s*(.+)$/iu', $line, $m)) {
            return trim($m[1]);
        }
        if ($best === '') {
            $best = $line;
        }
        if (strlen($best) > 40) {
            break;
        }
    }

    return $best !== '' ? $best : null;
}

/**
 * Risco inferido por varredura estática, para arquivos sem ficha.
 * Conservador: na dúvida, «write» (e portanto com passo de confirmação).
 */
function lcTestsGuessMode($absolute)
{
    $source = (string)@file_get_contents($absolute);
    if ($source === '') {
        return 'read';
    }
    if (preg_match('/\bsession_(destroy|unset)\s*\(/i', $source)) {
        return 'cli';
    }
    if (preg_match('/\b(DROP\s+TABLE|TRUNCATE\s+TABLE|ALTER\s+TABLE|DELETE\s+FROM)\b/i', $source)) {
        return 'destructive';
    }
    if (preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|password_hash\s*\()\b/i', $source)) {
        return 'write';
    }
    return 'read';
}

/** Grupo por defeito dos prefixos de nome de arquivo. */
function lcTestsGuessGroup($relative)
{
    $name = basename($relative);
    if (preg_match('/^(fix-|migrate-|full-setup|setup|force-)/', $name)) {
        return 'maint';
    }
    if (preg_match('/(login|auth|password|credential)/', $name)) {
        return 'auth';
    }
    if (preg_match('/(logs|database|db|schema|table|queries)/', $name)) {
        return 'db';
    }
    if (preg_match('/(ping|host|power|wol|control)/', $name)) {
        return 'net';
    }
    if (preg_match('/(powershell|ps1|proc|path)/', $name)) {
        return 'powershell';
    }
    return 'site';
}

/**
 * Catálogo completo e ordenado: [grupo => [entradas...]], mais a lista de
 * entradas do catálogo cujo arquivo desapareceu.
 */
function lcTestsCatalog()
{
    static $built = null;
    if ($built !== null) {
        return $built;
    }

    $catalog = lcTestsLoadCatalog();
    $files = lcTestsScanFiles();
    $entries = [];
    $known = [];

    foreach ($files as $rel => $stat) {
        $meta = $catalog['scripts'][$rel] ?? null;
        $absolute = lcTestsDir() . '/' . $rel;

        if (is_array($meta)) {
            $known[$rel] = true;
            $title = $meta['title'] ?? preg_replace('/\.(php|html)$/', '', basename($rel));
            $summary = $meta['summary'] ?? '';
            $group = isset($catalog['groups'][$meta['group'] ?? '']) ? $meta['group'] : 'site';
            $mode = $meta['mode'] ?? lcTestsGuessMode($absolute);
            $documented = true;
        } else {
            $title = preg_replace('/\.(php|html)$/', '', basename($rel));
            $title = ucwords(str_replace(['-', '_'], ' ', $title));
            $summary = (string)(lcTestsDocblockSummary($absolute) ?? '');
            $group = lcTestsGuessGroup($rel);
            $mode = lcTestsGuessMode($absolute);
            $meta = [];
            $documented = false;
        }

        $entries[$rel] = [
            'rel' => $rel,
            'folder' => substr($rel, 0, strpos($rel, '/')),
            'file' => basename($rel),
            'url' => 'index.php?view=' . rawurlencode($rel),
            'title' => $title,
            'summary' => $summary,
            'expect' => $meta['expect'] ?? '',
            'mode' => $mode,
            'group' => $group,
            'documented' => $documented,
            'lang' => preg_match('/\.html$/i', $rel) ? 'html' : 'php',
            'runner' => $meta['runner'] ?? (preg_match('/\.html$/i', $rel) ? 'frame' : 'embed'),
            'side_effects' => $meta['side_effects'] ?? '',
            'warn' => $meta['warn'] ?? '',
            'cli' => $meta['cli'] ?? null,
            'blocked' => $meta['blocked'] ?? null,
            'deprecated' => $meta['deprecated'] ?? null,
            'requires' => array_values(array_filter((array)($meta['requires'] ?? []))),
            'fixes' => array_values(array_filter((array)($meta['fixes'] ?? []), function ($rel2) use ($files) {
                return isset($files[$rel2]);
            })),
            'bytes' => $stat['bytes'],
            'modified' => $stat['modified'],
        ];
    }

    $missing = [];
    foreach (array_keys($catalog['scripts']) as $rel) {
        if (!isset($known[$rel])) {
            $missing[] = $rel;
        }
    }

    $groups = [];
    foreach ($catalog['groups'] as $id => $group) {
        $groups[$id] = [
            'id' => $id,
            'label' => $group['label'] ?? $id,
            'hint' => $group['hint'] ?? '',
            'icon' => $group['icon'] ?? 'pulse',
            'tone' => $group['tone'] ?? 'blue',
            'items' => [],
        ];
    }
    foreach ($entries as $rel => $entry) {
        $groupId = isset($groups[$entry['group']]) ? $entry['group'] : 'site';
        $entry['group'] = $groupId;
        $groups[$groupId]['items'][] = $entry;
    }
    foreach ($groups as $id => $group) {
        usort($groups[$id]['items'], function ($a, $b) {
            return strcmp($a['file'], $b['file']);
        });
        // Grupos sem nada ficam de fora do painel.
        if (empty($group['items'])) {
            unset($groups[$id]);
        }
    }

    $built = [
        'groups' => array_values($groups),
        'scripts' => $entries,
        'missing' => $missing,
        'catalog' => [
            'readable' => $catalog['readable'],
            'error' => $catalog['error'],
            'version' => $catalog['version'],
            'updated' => $catalog['updated'],
        ],
        'counts' => [
            'total' => count($entries),
            'read' => count(array_filter($entries, function ($e) { return $e['mode'] === 'read'; })),
            'write' => count(array_filter($entries, function ($e) { return $e['mode'] === 'write'; })),
            'destructive' => count(array_filter($entries, function ($e) { return $e['mode'] === 'destructive'; })),
            'undocumented' => count(array_filter($entries, function ($e) { return !$e['documented']; })),
        ],
    ];

    return $built;
}

/** Ficha de um script, com default seguro quando não existe. */
function lcTestsScriptMeta($relative)
{
    $catalog = lcTestsCatalog();
    if (isset($catalog['scripts'][$relative])) {
        return $catalog['scripts'][$relative];
    }
    return [
        'rel' => $relative,
        'title' => basename((string)$relative),
        'mode' => lcTestsGuessMode(lcTestsDir() . '/' . basename((string)$relative)),
        'blocked' => null,
        'requires' => [],
        'runner' => 'embed',
        'summary' => '',
        'expect' => '',
        'warn' => '',
        'documented' => false,
        'group' => 'site',
        'fixes' => [],
        'cli' => null,
        'side_effects' => '',
        'deprecated' => null,
    ];
}

/** Rótulos legíveis por modo de execução. */
function lcTestsModeLabels()
{
    return [
        'read' => ['label' => 'Só leitura', 'tone' => 'green', 'note' => 'Não altera estado no servidor.'],
        'write' => ['label' => 'Escreve', 'tone' => 'amber', 'note' => 'Regista dados ou limpa caches.'],
        'destructive' => ['label' => 'Destrutivo', 'tone' => 'red', 'note' => 'Muda schema ou contas. Precisa de confirmação.'],
        'sensitive' => ['label' => 'Dados sensíveis', 'tone' => 'violet', 'note' => 'A saída pode revelar contas, hashes ou IPs.'],
        'cli' => ['label' => 'Apenas CLI', 'tone' => 'gray', 'note' => 'Bloqueado na web por desencontro com a sessão desta página.'],
    ];
}

/**
 * Degrau de confirmação exigido antes de executar um script.
 *
 *  - «none»    → executa direto (leitura pura, ou leitura de dados sensíveis).
 *  - «confirm» → o painel pede um clique «Executar» (escreve, mas não destrói).
 *  - «reauth»  → pede a senha outra vez e emite bilhete válido 60 s.
 */
function lcTestsConfirmTier(array $meta)
{
    $mode = $meta['mode'] ?? 'read';
    if ($mode === 'destructive') {
        return 'reauth';
    }
    if ($mode === 'write') {
        return 'confirm';
    }
    return 'none';
}

/** Um script exige bilhete de execução assinado? */
function lcTestsNeedsGrant(array $meta)
{
    return lcTestsConfirmTier($meta) !== 'none';
}
