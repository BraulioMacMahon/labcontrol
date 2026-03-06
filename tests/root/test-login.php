<?php
ob_start();

$_GET['action'] = 'login';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Simulate POST data
$post_data = '{
    "email": "admin@labcontrol.local",
    "password": "admin123"
}';

// Mock php://input
$GLOBALS['php_input'] = $post_data;

// Override file_get_contents for testing
if (!function_exists('file_get_contents_original')) {
    $GLOBALS['file_get_contents_original'] = 'file_get_contents';
}

include '../../labcontrol-backend/api/auth.php';
ob_end_clean();
?>

