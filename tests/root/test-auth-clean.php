<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

$_GET['action'] = 'login';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_SERVER['HTTP_HOST'] = 'localhost';

include '../../labcontrol-backend/api/auth.php';
ob_end_clean();
?>

