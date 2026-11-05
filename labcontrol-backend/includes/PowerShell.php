<?php
/**
 * LabControl - Utilitários de integração com PowerShell
 *
 * Funções partilhadas por control.php para interpretar a saída dos scripts
 * .ps1. Mantidas num ficheiro separado para poderem ser testadas sem base de
 * dados, sem PowerShell e sem servidor web (tests/backend/test-powershell-output.php).
 *
 * Histórico do bug que motivou este ficheiro:
 *   - processPowerShellOutput() fazia implode('', $output) + json_decode().
 *     Qualquer aviso do PowerShell no stderr (o exec usa 2>&1), BOM, quebra de
 *     linha num JSON longo ou saída vazia fazia o decode falhar;
 *   - o endpoint `processes` transformava esse falhanço (e qualquer erro de
 *     WinRM/credenciais) num `500 "Falha ao obter processos"` sem diagnóstico,
 *     repetido a cada 10 s pelo polling do frontend.
 */

if (!function_exists('psRedactSecrets')) {
    /**
     * Remove segredos (password remota) de texto que vai para logs ou para a
     * resposta HTTP. Cobre o valor em claro, a variante escapada para aspas
     * simples do PowerShell (psQuote) e a variante escapada para JSON.
     */
    function psRedactSecrets($text, $secrets = []) {
        $text = (string) $text;
        foreach ((array) $secrets as $secret) {
            if (!is_scalar($secret)) {
                continue;
            }
            $secret = (string) $secret;
            if ($secret === '') {
                continue;
            }
            $variants = [
                $secret,
                str_replace("'", "''", $secret),
            ];
            $encoded = json_encode($secret);
            if ($encoded !== false) {
                $variants[] = trim($encoded, '"');
            }
            $text = str_replace($variants, '***', $text);
        }
        return $text;
    }
}

if (!function_exists('psExtractJsonPayloads')) {
    /**
     * Extrai todos os objectos/arrays JSON completos existentes num texto que
     * pode conter avisos do PowerShell antes/depois do payload.
     * Ignora chavetas dentro de strings JSON (com escapes).
     */
    function psExtractJsonPayloads($text) {
        $payloads = [];
        $text = (string) $text;
        $len = strlen($text);
        // Limite defensivo: a saída de 50 processos tem poucos KB.
        if ($len > 262144) {
            $text = substr($text, 0, 262144);
            $len = strlen($text);
        }

        for ($start = 0; $start < $len; $start++) {
            $first = $text[$start];
            if ($first !== '{' && $first !== '[') {
                continue;
            }

            $depth = 0;
            $inString = false;
            $escaped = false;
            for ($i = $start; $i < $len; $i++) {
                $ch = $text[$i];
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($ch === '\\') {
                        $escaped = true;
                    } elseif ($ch === '"') {
                        $inString = false;
                    }
                    continue;
                }
                if ($ch === '"') {
                    $inString = true;
                    continue;
                }
                if ($ch === '{' || $ch === '[') {
                    $depth++;
                    continue;
                }
                if ($ch === '}' || $ch === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $payloads[] = substr($text, $start, $i - $start + 1);
                        $start = $i; // continuar depois deste payload
                        break;
                    }
                    if ($depth < 0) {
                        break;
                    }
                }
            }
        }
        return $payloads;
    }
}

if (!function_exists('processPowerShellOutput')) {
    /**
     * Converte a saída (array de linhas) de exec('powershell.exe ... 2>&1')
     * num array PHP.
     *
     * Tenta, por ordem: texto completo, texto sem quebras de linha (o
     * powershell.exe pode quebrar linhas longas na largura da consola) e, por
     * fim, cada payload JSON completo encontrado no meio de avisos/erros.
     * JSON com bytes inválidos é aceite com substituição (PHP >= 7.2), o que
     * resolve a diferença de codepage entre o Windows e o PHP.
     *
     * @param array|string $output  Linhas devolvidas por exec().
     * @param array        $secrets Valores a ocultar (ex.: password remota).
     * @return array Em caso de insucesso devolve success=false com error e raw_output.
     */
    function processPowerShellOutput($output, $secrets = []) {
        if (!is_array($output)) {
            $output = [$output];
        }
        $lines = [];
        foreach ($output as $line) {
            $lines[] = (string) $line;
        }

        $raw = implode("\n", $lines);
        $raw = str_replace("\xEF\xBB\xBF", '', $raw); // BOM UTF-8
        $raw = psRedactSecrets($raw, $secrets);

        $clean = trim($raw);
        // Une linhas partidas sem introduzir espaços: dentro de JSON as quebras
        // de linha reais estão sempre escapadas (\n), logo removê-las é seguro.
        $compact = trim(preg_replace('/\R\s*/', '', $raw));

        $texts = [];
        if ($clean !== '') {
            $texts[] = $clean;
        }
        if ($compact !== '' && $compact !== $clean) {
            $texts[] = $compact;
        }

        $flags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

        $candidates = [];
        foreach ($texts as $text) {
            $candidates[] = $text;
            // Preferir o último payload: é o resultado final do script.
            foreach (array_reverse(psExtractJsonPayloads($text)) as $payload) {
                $candidates[] = $payload;
            }
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true, 512, $flags);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'success'    => false,
            'raw_output' => substr($raw, 0, 1000),
            'error'      => 'Resposta não é JSON válido',
        ];
    }
}

if (!function_exists('psClassifyFailure')) {
    /**
     * Traduz uma falha do PowerShell num erro HTTP accionável.
     * Os scripts .ps1 usam os prefixos ACESSO_NEGADO / WINRM_ERRO / ERRO,
     * mas versões antigas (e mensagens do Windows) não os usam, daí os regex.
     *
     * @return array code, error_type, message, error
     */
    function psClassifyFailure($result, $returnCode = 0, $defaultMessage = 'Falha na operação remota') {
        $errorText = '';
        if (is_array($result)) {
            if (isset($result['error'])) {
                $errorText = (string) $result['error'];
            } elseif (isset($result['raw_output'])) {
                $errorText = 'Saída não JSON: ' . (string) $result['raw_output'];
            } elseif (isset($result['message'])) {
                $errorText = (string) $result['message'];
            }
        }
        $errorText = trim($errorText);
        if ($errorText === '') {
            $errorText = 'O PowerShell terminou com o código ' . (int) $returnCode . ' sem mensagem de erro.';
        }

        $code = 502;
        $type = 'powershell_error';
        $message = $defaultMessage;

        if (stripos($errorText, 'ACESSO_NEGADO') !== false
            || preg_match('/(access is denied|acesso negado|logon failure|unknown user name|bad password|utilizador ou palavra-passe|credenciais inv[aá]lidas|permission denied)/i', $errorText)) {
            $code = 403;
            $type = 'authentication_failed';
            $message = 'Credenciais inválidas ou usuário sem permissões administrativas no host.';
        } elseif (stripos($errorText, 'WINRM_ERRO') !== false
            || preg_match('/(winrm|trustedhosts|cannot connect|could not connect|connection refused|n[aã]o foi poss[ií]vel conectar|the client cannot connect)/i', $errorText)) {
            $code = 503;
            $type = 'winrm_error';
            $message = 'Não foi possível conectar ao host. Verifique se o WinRM está habilitado e se o host está nos TrustedHosts.';
        } elseif (stripos($errorText, 'Resposta não é JSON válido') !== false
            || stripos($errorText, 'Saída não JSON') !== false) {
            $code = 502;
            $type = 'invalid_output';
            $message = 'O PowerShell não devolveu uma resposta JSON válida.';
        } elseif (preg_match('/(tempo limite|timed out|timeout|operation timeout)/i', $errorText)) {
            $code = 504;
            $type = 'timeout';
            $message = 'A consulta remota expirou (timeout).';
        }

        return [
            'code'        => $code,
            'error_type'  => $type,
            'message'     => $message,
            'error'       => substr($errorText, 0, 500),
        ];
    }
}
