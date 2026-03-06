<?php
/**
 * LabControl - Validador de Entrada
 * 
 * Valida dados de entrada com múltiplas regras
 */

class Validator {
    private $errors = [];
    
    /**
     * Valida dados contra um conjunto de regras
     */
    public function validate($data, $rules) {
        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            
            // Dividir múltiplas regras por pipe
            foreach (explode('|', $fieldRules) as $rule) {
                if (strpos($rule, ':') !== false) {
                    list($ruleName, $param) = explode(':', $rule, 2);
                } else {
                    $ruleName = $rule;
                    $param = null;
                }
                
                // Chamar método de validação correspondente
                $method = 'validate' . ucfirst($ruleName);
                if (method_exists($this, $method)) {
                    $this->$method($field, $value, $param);
                }
            }
        }
        
        return empty($this->errors);
    }
    
    /**
     * Obtém todos os erros
     */
    public function errors() {
        return $this->errors;
    }
    
    /**
     * Obtém erro de um campo específico
     */
    public function error($field) {
        return $this->errors[$field] ?? null;
    }
    
    /**
     * Há erros?
     */
    public function hasErrors() {
        return !empty($this->errors);
    }
    
    // =====================================================
    // REGRAS DE VALIDAÇÃO
    // =====================================================
    
    private function validateRequired($field, $value, $param = null) {
        if (empty($value) && $value !== 0 && $value !== '0') {
            $this->errors[$field] = ucfirst($field) . " é obrigatório.";
        }
    }
    
    private function validateEmail($field, $value, $param = null) {
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = ucfirst($field) . " deve ser um email válido.";
        }
    }
    
    private function validateMin($field, $value, $param) {
        if (!empty($value) && strlen((string)$value) < (int)$param) {
            $this->errors[$field] = ucfirst($field) . " deve ter no mínimo $param caracteres.";
        }
    }
    
    private function validateMax($field, $value, $param) {
        if (!empty($value) && strlen((string)$value) > (int)$param) {
            $this->errors[$field] = ucfirst($field) . " pode ter no máximo $param caracteres.";
        }
    }
    
    private function validateIp($field, $value, $param = null) {
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_IP)) {
            $this->errors[$field] = ucfirst($field) . " deve ser um IP válido.";
        }
    }
    
    private function validateHostname($field, $value, $param = null) {
        if (!empty($value)) {
            // Hostname válido: letras, números, hífens, sem espaços
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $value)) {
                $this->errors[$field] = ucfirst($field) . " deve ser um hostname válido.";
            }
        }
    }
    
    private function validateMac($field, $value, $param = null) {
        if (!empty($value)) {
            // MAC address: AA:BB:CC:DD:EE:FF ou AA-BB-CC-DD-EE-FF
            if (!preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $value)) {
                $this->errors[$field] = ucfirst($field) . " deve ser um MAC address válido.";
            }
        }
    }
    
    private function validateIn($field, $value, $param) {
        $allowed = explode(',', $param);
        $allowed = array_map('trim', $allowed);
        
        if (!empty($value) && !in_array($value, $allowed)) {
            $this->errors[$field] = ucfirst($field) . " tem um valor inválido. Valores permitidos: " . $param;
        }
    }
    
    private function validateNumeric($field, $value, $param = null) {
        if (!empty($value) && !is_numeric($value)) {
            $this->errors[$field] = ucfirst($field) . " deve ser numérico.";
        }
    }
    
    private function validateInteger($field, $value, $param = null) {
        if (!empty($value) && !is_numeric($value) || (int)$value != $value) {
            $this->errors[$field] = ucfirst($field) . " deve ser um inteiro.";
        }
    }
    
    private function validateBoolean($field, $value, $param = null) {
        if (!empty($value) && !in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'])) {
            $this->errors[$field] = ucfirst($field) . " deve ser um booleano (true/false).";
        }
    }
    
    private function validateJson($field, $value, $param = null) {
        if (!empty($value)) {
            json_decode($value);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->errors[$field] = ucfirst($field) . " deve ser um JSON válido.";
            }
        }
    }
    
    private function validateUrl($field, $value, $param = null) {
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->errors[$field] = ucfirst($field) . " deve ser uma URL válida.";
        }
    }
}
