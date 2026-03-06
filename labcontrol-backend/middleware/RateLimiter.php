<?php
/**
 * LabControl - Middleware de Rate Limiting
 * 
 * Implementa proteção contra brute force e abuso de API
 */

class RateLimiter {
    private $identifier;
    private $maxAttempts;
    private $windowSeconds;
    private $cacheDir;
    
    public function __construct($identifier, $maxAttempts = 100, $windowSeconds = 60) {
        $this->identifier = $identifier;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->cacheDir = __DIR__ . '/../cache/';
        
        // Criar diretório de cache se não existir
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Verifica se o identificador está dentro do limite
     */
    public function isAllowed() {
        $cacheFile = $this->getCacheFile();
        $now = time();
        
        // Ler dados existentes
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            
            // Verificar se a janela expirou
            if ($data['window_end'] > $now) {
                // Dentro da janela: incrementar tentativas
                $data['attempts']++;
                $result = $data['attempts'] <= $this->maxAttempts;
            } else {
                // Janela expirou: resetar
                $data = $this->createNewWindow($now);
                $result = true;
            }
        } else {
            // Primeiro acesso
            $data = $this->createNewWindow($now);
            $result = true;
        }
        
        // Salvar dados atualizados
        file_put_contents($cacheFile, json_encode($data), LOCK_EX);
        
        return $result;
    }
    
    /**
     * Obtém informações sobre tentativas restantes
     */
    public function getInfo() {
        $cacheFile = $this->getCacheFile();
        $now = time();
        
        if (!file_exists($cacheFile)) {
            return [
                'attempts' => 0,
                'remaining' => $this->maxAttempts,
                'reset_in' => $this->windowSeconds
            ];
        }
        
        $data = json_decode(file_get_contents($cacheFile), true);
        
        if ($data['window_end'] <= $now) {
            return [
                'attempts' => 0,
                'remaining' => $this->maxAttempts,
                'reset_in' => $this->windowSeconds
            ];
        }
        
        return [
            'attempts' => $data['attempts'],
            'remaining' => max(0, $this->maxAttempts - $data['attempts']),
            'reset_in' => max(0, $data['window_end'] - $now)
        ];
    }
    
    /**
     * Reseta o contador
     */
    public function reset() {
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    private function getCacheFile() {
        return $this->cacheDir . md5($this->identifier) . '.json';
    }
    
    private function createNewWindow($now) {
        return [
            'identifier' => $this->identifier,
            'attempts' => 1,
            'window_start' => $now,
            'window_end' => $now + $this->windowSeconds
        ];
    }
    
    /**
     * Limpar cache antigo (chamado periodicamente)
     */
    public static function cleanupOldCache($maxAge = 86400) {
        $cacheDir = __DIR__ . '/../cache/';
        if (!is_dir($cacheDir)) return;
        
        $now = time();
        foreach (scandir($cacheDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filepath = $cacheDir . $file;
            if (is_file($filepath) && (time() - filemtime($filepath) > $maxAge)) {
                @unlink($filepath);
            }
        }
    }
}
