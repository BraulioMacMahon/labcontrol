<?php
require_once __DIR__ . '/../../labcontrol-backend/config/config.php';

$action = 'processes';
$hostname = 'LAB17-PC18';
$powershellPath = __DIR__ . '/../../labcontrol-backend/powershell/';
$scriptPath = $powershellPath . 'Get-Processes.ps1';

echo "Script Path: $scriptPath\n";
echo "Exists: " . (file_exists($scriptPath) ? "YES" : "NO") . "\n";

$tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'get_proc_' . time() . '.ps1';
echo "Temp Script Path: $tempScript\n";

$psContent = "Test content";
$result = file_put_contents($tempScript, $psContent);

if ($result === false) {
    echo "FAILED to write to $tempScript\n";
    $error = error_get_last();
    print_r($error);
} else {
    echo "SUCCESSfully wrote $result bytes to $tempScript\n";
    @unlink($tempScript);
}

