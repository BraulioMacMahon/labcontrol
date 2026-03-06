<?php
// Limpa cache e reinicia sessão
session_start();
session_unset();
session_destroy();

// Limpa cookies de sessão
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Cabeçalhos para evitar cache
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

echo "Cache limpo e sessão encerrada.";


