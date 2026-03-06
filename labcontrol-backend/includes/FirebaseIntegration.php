<?php
/**
 * LabControl - Integração com Firebase
 * 
 * Gerencia autenticação e Firestore via Firebase Admin SDK
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Database.php';

class FirebaseIntegration {
    private $enabled;
    private $projectId;
    private $accessToken = null;
    private $tokenExpiry = 0;
    private $db;
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->enabled = FIREBASE_ENABLED && file_exists(FIREBASE_CREDENTIALS_PATH);
        $this->projectId = FIREBASE_PROJECT_ID;
        
        if ($this->enabled) {
            $this->getAccessToken();
        }
    }

    private function getDb() {
        if (!$this->db) {
            $this->db = Database::getInstance();
        }
        return $this->db;
    }
    
    /**
     * Verifica se Firebase está habilitado e conectado
     */
    public function isEnabled() {
        if (!$this->enabled) return false;
        
        // Testa conectividade
        $connected = @fsockopen('firebase.googleapis.com', 443, $errno, $errstr, 2);
        if ($connected) {
            fclose($connected);
            return true;
        }
        return false;
    }
    
    /**
     * Obtém token de acesso OAuth2
     */
    private function getAccessToken() {
        if ($this->accessToken && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }
        
        try {
            $credentials = json_decode(file_get_contents(FIREBASE_CREDENTIALS_PATH), true);
            
            $jwtHeader = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $now = time();
            $jwtClaim = json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/cloud-platform https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/datastore',
                'aud' => $credentials['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600
            ]);
            
            $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($jwtHeader));
            $base64Claim = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($jwtClaim));
            
            $signature = '';
            $privateKey = openssl_pkey_get_private($credentials['private_key']);
            openssl_sign($base64Header . '.' . $base64Claim, $signature, $privateKey, 'SHA256');
            $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
            
            $jwt = $base64Header . '.' . $base64Claim . '.' . $base64Signature;
            
            $ch = curl_init($credentials['token_uri']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);
            
            if (isset($data['access_token'])) {
                $this->accessToken = $data['access_token'];
                $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60;
                return $this->accessToken;
            }
            
            return null;
        } catch (Exception $e) {
            logError('Erro ao obter token Firebase', ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    /**
     * Faz requisição à API do Firestore
     */
    private function firestoreRequest($method, $path, $data = null) {
        $token = $this->getAccessToken();
        if (!$token) return false;
        
        $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents{$path}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }
        
        logError('Erro na requisição Firestore', [
            'url' => $url,
            'code' => $httpCode,
            'response' => $response
        ]);
        
        return false;
    }
    
    /**
     * Converte dados para formato Firestore
     */
    private function toFirestoreFormat($data) {
        $fields = [];
        foreach ($data as $key => $value) {
            if (is_null($value)) {
                $fields[$key] = ['nullValue' => null];
            } elseif (is_bool($value)) {
                $fields[$key] = ['booleanValue' => $value];
            } elseif (is_int($value)) {
                $fields[$key] = ['integerValue' => $value];
            } elseif (is_float($value)) {
                $fields[$key] = ['doubleValue' => $value];
            } elseif (is_array($value)) {
                $fields[$key] = ['arrayValue' => ['values' => array_map([$this, 'toFirestoreValue'], $value)]];
            } elseif (is_object($value) || $this->isJson($value)) {
                $fields[$key] = ['mapValue' => ['fields' => $this->toFirestoreFormat((array)$value)]];
            } else {
                $fields[$key] = ['stringValue' => (string)$value];
            }
        }
        return $fields;
    }
    
    /**
     * Converte valor único para formato Firestore
     */
    private function toFirestoreValue($value) {
        if (is_null($value)) return ['nullValue' => null];
        if (is_bool($value)) return ['booleanValue' => $value];
        if (is_int($value)) return ['integerValue' => $value];
        if (is_float($value)) return ['doubleValue' => $value];
        return ['stringValue' => (string)$value];
    }
    
    /**
     * Verifica se string é JSON
     */
    private function isJson($string) {
        if (!is_string($string)) return false;
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    /**
     * Converte do formato Firestore
     */
    private function fromFirestoreFormat($fields) {
        $data = [];
        if (!isset($fields['fields'])) return $data;
        
        foreach ($fields['fields'] as $key => $value) {
            if (isset($value['stringValue'])) {
                $data[$key] = $value['stringValue'];
            } elseif (isset($value['integerValue'])) {
                $data[$key] = (int)$value['integerValue'];
            } elseif (isset($value['doubleValue'])) {
                $data[$key] = $value['doubleValue'];
            } elseif (isset($value['booleanValue'])) {
                $data[$key] = $value['booleanValue'];
            } elseif (isset($value['nullValue'])) {
                $data[$key] = null;
            } elseif (isset($value['timestampValue'])) {
                $data[$key] = $value['timestampValue'];
            } elseif (isset($value['mapValue'])) {
                $data[$key] = $this->fromFirestoreFormat($value['mapValue']);
            } elseif (isset($value['arrayValue'])) {
                $data[$key] = array_map(function($v) {
                    return $this->fromFirestoreValue($v);
                }, $value['arrayValue']['values'] ?? []);
            }
        }
        return $data;
    }
    
    /**
     * Converte valor único do Firestore
     */
    private function fromFirestoreValue($value) {
        if (isset($value['stringValue'])) return $value['stringValue'];
        if (isset($value['integerValue'])) return (int)$value['integerValue'];
        if (isset($value['doubleValue'])) return $value['doubleValue'];
        if (isset($value['booleanValue'])) return $value['booleanValue'];
        if (isset($value['nullValue'])) return null;
        return null;
    }
    
    // =====================================================
    // OPERAÇÕES COM USUÁRIOS
    // =====================================================
    
    /**
     * Sincroniza usuário com Firebase Auth
     */
    public function syncUser($userData) {
        if (!$this->isEnabled()) return false;
        
        // Cria/atualiza no Firestore
        $path = "/users/{$userData['id']}";
        $data = [
            'fields' => $this->toFirestoreFormat([
                'id' => $userData['id'],
                'email' => $userData['email'],
                'role' => $userData['role'],
                'is_active' => (bool)$userData['is_active'],
                'last_login' => $userData['last_login'],
                'created_at' => $userData['created_at'],
                'synced_at' => date('Y-m-d H:i:s')
            ])
        ];
        
        return $this->firestoreRequest('PATCH', $path, $data);
    }
    
    /**
     * Obtém usuário do Firebase
     */
    public function getUser($userId) {
        if (!$this->isEnabled()) return false;
        
        $result = $this->firestoreRequest('GET', "/users/{$userId}");
        if ($result && isset($result['fields'])) {
            return $this->fromFirestoreFormat($result);
        }
        return false;
    }
    
    // =====================================================
    // OPERAÇÕES COM HOSTS
    // =====================================================
    
    /**
     * Sincroniza host com Firestore
     */
    public function syncHost($hostData) {
        if (!$this->isEnabled()) return false;
        
        $path = "/hosts/{$hostData['id']}";
        $data = [
            'fields' => $this->toFirestoreFormat([
                'id' => $hostData['id'],
                'ip' => $hostData['ip'],
                'hostname' => $hostData['hostname'],
                'mac_address' => $hostData['mac_address'],
                'status' => $hostData['status'],
                'os_type' => $hostData['os_type'],
                'location' => $hostData['location'],
                'description' => $hostData['description'],
                'is_active' => (bool)$hostData['is_active'],
                'last_seen' => $hostData['last_seen'],
                'synced_at' => date('Y-m-d H:i:s')
            ])
        ];
        
        $result = $this->firestoreRequest('PATCH', $path, $data);
        
        if ($result) {
            // Marca como sincronizado no MySQL
            $this->getDb()->update('hosts', ['synced' => 1], 'id = :id', [':id' => $hostData['id']]);
        }
        
        return $result;
    }
    
    /**
     * Obtém host do Firestore
     */
    public function getHost($hostId) {
        if (!$this->isEnabled()) return false;
        
        $result = $this->firestoreRequest('GET', "/hosts/{$hostId}");
        if ($result && isset($result['fields'])) {
            return $this->fromFirestoreFormat($result);
        }
        return false;
    }
    
    /**
     * Lista todos os hosts do Firestore
     */
    public function getAllHosts() {
        if (!$this->isEnabled()) return false;
        
        $result = $this->firestoreRequest('GET', '/hosts');
        $hosts = [];
        
        if ($result && isset($result['documents'])) {
            foreach ($result['documents'] as $doc) {
                $host = $this->fromFirestoreFormat($doc);
                $host['firebase_id'] = basename($doc['name']);
                $hosts[] = $host;
            }
        }
        
        return $hosts;
    }
    
    /**
     * Remove host do Firestore
     */
    public function deleteHost($hostId) {
        if (!$this->isEnabled()) return false;
        return $this->firestoreRequest('DELETE', "/hosts/{$hostId}");
    }
    
    // =====================================================
    // OPERAÇÕES COM LOGS
    // =====================================================
    
    /**
     * Sincroniza log com Firestore
     */
    public function syncLog($logData) {
        if (!$this->isEnabled()) return false;
        
        $path = "/logs/{$logData['id']}";
        $data = [
            'fields' => $this->toFirestoreFormat([
                'id' => $logData['id'],
                'user_id' => $logData['user_id'],
                'user_email' => $logData['user_email'],
                'host_id' => $logData['host_id'],
                'host_ip' => $logData['host_ip'],
                'action' => $logData['action'],
                'action_type' => $logData['action_type'],
                'details' => $logData['details'],
                'status' => $logData['status'],
                'timestamp' => $logData['timestamp'],
                'ip_address' => $logData['ip_address'],
                'synced_at' => date('Y-m-d H:i:s')
            ])
        ];
        
        $result = $this->firestoreRequest('PATCH', $path, $data);
        
        if ($result) {
            // Marca como sincronizado no MySQL
            $this->getDb()->update('logs', ['synced' => 1], 'id = :id', [':id' => $logData['id']]);
        }
        
        return $result;
    }
    
    /**
     * Obtém logs do Firestore
     */
    public function getLogs($limit = 100) {
        if (!$this->isEnabled()) return false;
        
        $result = $this->firestoreRequest('GET', '/logs?pageSize=' . $limit);
        $logs = [];
        
        if ($result && isset($result['documents'])) {
            foreach ($result['documents'] as $doc) {
                $log = $this->fromFirestoreFormat($doc);
                $log['firebase_id'] = basename($doc['name']);
                $logs[] = $log;
            }
        }
        
        return $logs;
    }
    
    // =====================================================
    // SINCRONIZAÇÃO EM MASSA
    // =====================================================
    
    /**
     * Sincroniza todos os hosts pendentes
     */
    public function syncPendingHosts() {
        if (!$this->isEnabled()) return ['success' => 0, 'failed' => 0];
        
        $hosts = $this->getDb()->select("SELECT * FROM hosts WHERE synced = 0 LIMIT 50");
        $success = 0;
        $failed = 0;
        
        foreach ($hosts as $host) {
            if ($this->syncHost($host)) {
                $success++;
            } else {
                $failed++;
            }
        }
        
        return ['success' => $success, 'failed' => $failed];
    }
    
    /**
     * Sincroniza todos os logs pendentes
     */
    public function syncPendingLogs() {
        if (!$this->isEnabled()) return ['success' => 0, 'failed' => 0];
        
        $logs = $this->getDb()->getPendingLogs(100);
        $success = 0;
        $failed = 0;
        
        foreach ($logs as $log) {
            if ($this->syncLog($log)) {
                $success++;
            } else {
                $failed++;
            }
        }
        
        return ['success' => $success, 'failed' => $failed];
    }
    
    /**
     * Executa sincronização completa
     */
    public function fullSync() {
        $results = [
            'hosts' => $this->syncPendingHosts(),
            'logs' => $this->syncPendingLogs(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Registra log da sincronização
        $this->getDb()->logAction(
            null,
            'system',
            null,
            null,
            'Sincronização completa com Firebase',
            'sync',
            json_encode($results),
            'success'
        );
        
        return $results;
    }
}
