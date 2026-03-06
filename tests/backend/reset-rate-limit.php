<?php
/**
 * LabControl - Reset Rate Limiting
 * Limpa o cache de rate limiting para permitir novos logins
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../labcontrol-backend/config/config.php';

echo json_encode([
    'step' => '1',
    'message' => 'Verificando diretório de cache...'
], JSON_PRETTY_PRINT) . "\n";

try {
    $cacheDir = __DIR__ . '/cache';
    
    // Verificar se diretório existe
    if (!is_dir($cacheDir)) {
        echo json_encode([
            'step' => '2',
            'message' => 'Diretório de cache não existe, criando...',
            'path' => $cacheDir
        ], JSON_PRETTY_PRINT) . "\n";
        
        @mkdir($cacheDir, 0755, true);
    }
    
    echo json_encode([
        'step' => '3',
        'message' => 'Procurando arquivos de rate limiting...'
    ], JSON_PRETTY_PRINT) . "\n";
    
    // Listar arquivos no cache
    $files = @scandir($cacheDir);
    if (!$files) {
        echo json_encode([
            'step' => '4',
            'message' => 'Nenhum arquivo de cache encontrado'
        ], JSON_PRETTY_PRINT) . "\n";
    } else {
        // Filtrar e deletar arquivos relacionados ao login
        $deleted = 0;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $fullPath = $cacheDir . '/' . $file;
            
            // Deletar TODOS os arquivos de cache (limpeza completa)
            if (@unlink($fullPath)) {
                $deleted++;
                echo json_encode([
                    'step' => '4_delete',
                    'file' => $file,
                    'status' => 'deletado'
                ], JSON_PRETTY_PRINT) . "\n";
            }
        }
        
        echo json_encode([
            'step' => '5',
            'message' => 'Arquivos de cache deletados',
            'count' => $deleted
        ], JSON_PRETTY_PRINT) . "\n";
    }
    
    // Status final
    echo json_encode([
        'status' => 'SUCCESS',
        'message' => 'Rate limiting foi resetado!',
        'next_step' => 'Você pode fazer login novamente. As próximas 5 tentativas estão permitidas.',
        'rate_limit_config' => [
            'max_attempts' => RATE_LIMIT_LOGIN,
            'window_seconds' => RATE_LIMIT_WINDOW,
            'message' => 'Máximo de ' . RATE_LIMIT_LOGIN . ' tentativas a cada ' . RATE_LIMIT_WINDOW . ' segundos'
        ]
    ], JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'ERROR',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT) . "\n";
}
?>


