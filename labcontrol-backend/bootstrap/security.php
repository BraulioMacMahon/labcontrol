<?php
/**
 * LabControl - Bootstrap de Segurança
 * 
 * Define headers de segurança e validações iniciais
 */

function setSecurityHeaders() {
    // Check if headers have already been sent
    if (headers_sent()) {
        return;
    }
    
    // ✅ Content Security Policy
    @header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;");
    
    // ✅ Anti-clickjacking
    @header("X-Frame-Options: SAMEORIGIN");
    
    // ✅ Anti MIME sniffing
    @header("X-Content-Type-Options: nosniff");
    
    // ✅ XSS Protection (legacy, para browsers antigos)
    @header("X-XSS-Protection: 1; mode=block");
    
    // ✅ Referrer Policy
    @header("Referrer-Policy: strict-origin-when-cross-origin");
    
    // ✅ Permissions Policy
    @header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    
    // ✅ Charset
    @header("Content-Type: application/json; charset=utf-8");
}

/**
 * Valida a origem CORS
 */
function validateCorsOrigin() {
    // Check if headers have already been sent
    if (headers_sent()) {
        return false;
    }
    
    $allowedOrigins = explode(',', CORS_ALLOWED_ORIGINS);
    $allowedOrigins = array_map('trim', $allowedOrigins);
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Se origin está na lista de permitidas (ou * permite tudo)
    if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
        if ($origin) {
            @header("Access-Control-Allow-Origin: $origin");
        } else {
            @header("Access-Control-Allow-Origin: " . CORS_ALLOWED_ORIGINS);
        }
        
        @header("Access-Control-Allow-Methods: " . CORS_ALLOWED_METHODS);
        @header("Access-Control-Allow-Headers: " . CORS_ALLOWED_HEADERS);
        @header("Access-Control-Allow-Credentials: true");
        @header("Access-Control-Max-Age: 3600");
        
        return true;
    }
    
    return false;
}

/**
 * Responde a preflight requests
 */
function handlePreflight() {
    if (!headers_sent() && ($_SERVER['REQUEST_METHOD'] === 'OPTIONS')) {
        validateCorsOrigin();
        http_response_code(200);
        exit;
    }
}

// Aplicar headers de segurança
setSecurityHeaders();

// Validar CORS
validateCorsOrigin();

// Handel preflight
handlePreflight();
