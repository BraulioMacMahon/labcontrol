<?php
require_once __DIR__ . '/../../labcontrol-backend/includes/Database.php';
require_once __DIR__ . '/../../labcontrol-backend/config/config.php';

$db = Database::getInstance();
$host = $db->selectOne("SELECT * FROM hosts WHERE hostname = :h", [':h' => 'LAB17-PC18']);
echo "Host info:\n";
print_r($host);

$powershellPath = __DIR__ . '/../../labcontrol-backend/powershell/';
echo "Powershell path: $powershellPath\n";
$scriptPath = $powershellPath . 'Get-Processes.ps1';
echo "Script path: $scriptPath\n";
echo "Exists: " . (file_exists($scriptPath) ? "YES" : "NO") . "\n";

