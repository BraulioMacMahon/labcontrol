<?php
/**
 * LabControl - Sistema de Notificações de Segurança
 * 
 * Envia alertas por email sobre:
 * - Múltiplas tentativas de login falhadas
 * - Atividade suspeita
 * - Acesso a contas desativadas
 * 
 * Execute periodicamente (ex: a cada 30 minutos via cron)
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/Database.php';

$db = Database::getInstance();

// =====================================================
// 1. VERIFICAR TENTATIVAS DE LOGIN SUSPEITAS (Última hora)
// =====================================================
$failedLogins = $db->select(
    "SELECT 
        user_email,
        COUNT(*) as attempt_count,
        MAX(created_at) as last_attempt,
        GROUP_CONCAT(DISTINCT JSON_EXTRACT(details, '$.ip') SEPARATOR ', ') as ips,
        action
     FROM logs 
     WHERE action_type = 'auth' AND status = 'failed' 
     AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
     GROUP BY user_email
     HAVING count(*) >= 3
     ORDER BY attempt_count DESC"
);

// =====================================================
// 2. OBTER EMAILS DOS ADMINISTRADORES
// =====================================================
$admins = $db->select(
    "SELECT id, email FROM users WHERE role = 'admin' AND is_active = 1"
);

if (empty($admins)) {
    echo "Nenhum administrador ativo encontrado.\n";
    exit(1);
}

$adminEmails = array_map(function($admin) { return $admin['email']; }, $admins);

// =====================================================
// 3. SE HÁ ATIVIDADE SUSPEITA, ENVIAR ALERTA
// =====================================================
if (!empty($failedLogins)) {
    $alertCount = count($failedLogins);
    
    // Construir relatório
    $reportBody = "🚨 ALERTA DE SEGURANÇA - LabControl\n";
    $reportBody .= "================================\n\n";
    $reportBody .= "Foram detectadas " . $alertCount . " conta(s) com múltiplas tentativas de login falhadas na última hora.\n\n";
    
    $reportBody .= "DETALHES:\n";
    $reportBody .= "--------\n";
    
    foreach ($failedLogins as $login) {
        $reportBody .= "\n📧 Email: " . $login['user_email'] . "\n";
        $reportBody .= "   Tentativas: " . $login['attempt_count'] . "\n";
        $reportBody .= "   IPs: " . $login['ips'] . "\n";
        $reportBody .= "   Última tentativa: " . $login['last_attempt'] . "\n";
    }
    
    $reportBody .= "\n\nAÇÕES RECOMENDADAS:\n";
    $reportBody .= "-------------------\n";
    $reportBody .= "1. Visite o painel de administração para verificar os detalhes\n";
    $reportBody .= "2. Considere bloquear os IPs suspeitos\n";
    $reportBody .= "3. Redefina as senhas das contas afetadas\n";
    $reportBody .= "4. Implemente autenticação de dois fatores (2FA)\n\n";
    $reportBody .= "URL do Painel: " . CORS_ALLOWED_ORIGINS . "/labcontrol/labcontrol-frontend/\n";
    
    // Enviar para cada administrador
    foreach ($adminEmails as $adminEmail) {
        $subject = "[ALERTA] LabControl - Tentativas de Login Suspeitas (" . $alertCount . ")";
        
        // Headers para email HTML
        $headers = "From: security@labcontrol.local\r\n";
        $headers .= "Reply-To: security@labcontrol.local\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        // Tentar enviar email
        $emailSent = @mail($adminEmail, $subject, $reportBody, $headers);
        
        if ($emailSent) {
            logError("Security alert email sent to: " . $adminEmail, [
                'alert_type' => 'suspicious_login_attempts',
                'affected_accounts' => $alertCount,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            echo "✅ Alerta enviado para: " . $adminEmail . "\n";
        } else {
            logError("Failed to send security alert email to: " . $adminEmail, [
                'alert_type' => 'suspicious_login_attempts',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            echo "❌ Falha ao enviar para: " . $adminEmail . "\n";
        }
    }
    
    // Registar que notificações foram enviadas
    $db->insert('logs', [
        'user_id' => null,
        'user_email' => 'system@labcontrol.local',
        'host_id' => null,
        'host_ip' => null,
        'action' => 'Security alert notifications sent for ' . $alertCount . ' suspicious login attempts',
        'action_type' => 'security',
        'details' => json_encode([
            'affected_accounts' => $alertCount,
            'recipients' => $adminEmails,
            'timestamp' => date('Y-m-d H:i:s')
        ]),
        'status' => 'success',
        'ip_address' => '127.0.0.1',
        'synced' => 0
    ]);
    
} else {
    echo "✅ Nenhuma atividade suspeita detectada na última hora.\n";
}

// =====================================================
// 4. LIMPAR LOGS ANTIGOS (Mais de 90 dias)
// =====================================================
$oldLogs = $db->delete(
    'logs',
    'created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
);

if ($oldLogs) {
    echo "🗑️  Limpeza de logs antigos concluída.\n";
}

echo "\n✅ Verificação de segurança concluída em " . date('Y-m-d H:i:s') . "\n";
?>
