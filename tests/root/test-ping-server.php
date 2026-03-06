<?php
require_once __DIR__ . '/../../labcontrol-backend/config/config.php';

$ip = '192.168.20.169';
echo "Pinging $ip...\n";
$result = pingHost($ip);
echo "Result: " . ($result ? "ONLINE" : "OFFLINE") . "\n";

exec("ping -n 1 -w 2000 $ip", $output, $returnCode);
echo "Raw Ping Output:\n";
print_r($output);
echo "Return Code: $returnCode\n";

