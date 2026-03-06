<?php
echo "Temp dir: " . sys_get_temp_dir() . "\n";
echo "Separator: " . DIRECTORY_SEPARATOR . "\n";
$tempScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_' . time() . '.ps1';
echo "Full path: " . $tempScript . "\n";
$res = file_put_contents($tempScript, "test");
echo "Result: " . ($res !== false ? "success" : "failed") . "\n";
@unlink($tempScript);

