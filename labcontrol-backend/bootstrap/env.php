<?php
/**
 * LabControl - Bootstrap para Variáveis de Ambiente
 * 
 * Carrega e valida variáveis do arquivo .env
 */

function loadEnv($path) {
    if (!file_exists($path)) {
        error_log("❌ AVISO: Arquivo .env não encontrado em: $path");
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Pular comentários
        if (strpos(trim($line), '#') === 0) continue;
        
        // Parse: KEY=value
        if (strpos($line, '=') === false) continue;
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Remover aspas, se houver
        if (in_array($value[0] ?? null, ['"', "'"])) {
            $value = substr($value, 1, -1);
        }
        
        // Setar como variável de ambiente
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    
    return true;
}

/**
 * Helper para obter variável de ambiente com valor padrão
 */
function env($key, $default = null) {
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}
