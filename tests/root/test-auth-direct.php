<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$_GET['action'] = 'login';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_SERVER['HTTP_HOST'] = 'localhost';

echo "Starting test...\n";

try {
    echo "About to include auth.php\n";
    include '../../labcontrol-backend/api/auth.php';
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    echo 'File: ' . $e->getFile() . "\n";
    echo 'Line: ' . $e->getLine() . "\n";
    echo 'Trace:' . "\n" . $e->getTraceAsString() . "\n";
}

echo "Test complete.\n";
?>

