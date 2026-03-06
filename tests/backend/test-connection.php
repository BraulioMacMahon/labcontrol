<?php
/**
 * LabControl - Teste de Conexão
 * 
 * Script para verificar se todas as conexões estão funcionando
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LabControl - Teste de Conexão</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
            font-size: 2.5em;
        }
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .card h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.3em;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        .status {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .status.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .status-icon {
            font-size: 24px;
            margin-right: 15px;
        }
        .status-text {
            flex: 1;
        }
        .status-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .status-detail {
            font-size: 0.9em;
            opacity: 0.8;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
        }
        .info-label {
            font-size: 0.85em;
            color: #666;
            margin-bottom: 5px;
        }
        .info-value {
            font-weight: bold;
            color: #333;
        }
        .btn {
            display: inline-block;
            padding: 12px 25px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 15px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #5a6fd6;
        }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 LabControl - Teste de Conexão</h1>
        
        <?php
        // =====================================================
        // TESTE 1: PHP Version
        // =====================================================
        $phpVersion = phpversion();
        $phpOk = version_compare($phpVersion, '7.4.0', '>=');
        ?>
        <div class="card">
            <h2>📦 Versão do PHP</h2>
            <div class="status <?php echo $phpOk ? 'success' : 'error'; ?>">
                <span class="status-icon"><?php echo $phpOk ? '✅' : '❌'; ?></span>
                <div class="status-text">
                    <div class="status-title">PHP <?php echo $phpVersion; ?></div>
                    <div class="status-detail">
                        <?php echo $phpOk ? 'Versão compatível' : 'Versão mínima requerida: 7.4.0'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        // =====================================================
        // TESTE 2: Extensões PHP
        // =====================================================
        $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'curl', 'openssl', 'sockets'];
        $missingExtensions = [];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $missingExtensions[] = $ext;
            }
        }
        $extensionsOk = empty($missingExtensions);
        ?>
        <div class="card">
            <h2>🔌 Extensões PHP</h2>
            <div class="status <?php echo $extensionsOk ? 'success' : 'error'; ?>">
                <span class="status-icon"><?php echo $extensionsOk ? '✅' : '❌'; ?></span>
                <div class="status-text">
                    <div class="status-title">
                        <?php echo $extensionsOk ? 'Todas as extensões instaladas' : 'Extensões faltando'; ?>
                    </div>
                    <div class="status-detail">
                        <?php 
                        if (!$extensionsOk) {
                            echo 'Faltando: ' . implode(', ', $missingExtensions);
                        } else {
                            echo 'Extensões: ' . implode(', ', $requiredExtensions);
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        // =====================================================
        // TESTE 3: Conexão MySQL
        // =====================================================
        $mysqlOk = false;
        $mysqlError = '';
        $mysqlStats = [];
        
        try {
            require_once __DIR__ . '/../../labcontrol-backend/config/config.php';
            require_once __DIR__ . '/../../labcontrol-backend/includes/Database.php';
            
            $db = Database::getInstance();
            $connection = $db->getConnection();
            $mysqlOk = true;
            
            // Obter estatísticas
            $mysqlStats = $db->getHostStats();
        } catch (Exception $e) {
            $mysqlError = $e->getMessage();
        }
        ?>
        <div class="card">
            <h2>🗄️ Conexão MySQL</h2>
            <div class="status <?php echo $mysqlOk ? 'success' : 'error'; ?>">
                <span class="status-icon"><?php echo $mysqlOk ? '✅' : '❌'; ?></span>
                <div class="status-text">
                    <div class="status-title">
                        <?php echo $mysqlOk ? 'Conectado ao MySQL' : 'Erro de conexão'; ?>
                    </div>
                    <div class="status-detail">
                        <?php echo $mysqlOk ? 'Banco: ' . DB_NAME : $mysqlError; ?>
                    </div>
                </div>
            </div>
            <?php if ($mysqlOk && $mysqlStats): ?>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Total de Hosts</div>
                    <div class="info-value"><?php echo $mysqlStats['total_hosts']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Online</div>
                    <div class="info-value" style="color: #28a745;"><?php echo $mysqlStats['online_count']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Offline</div>
                    <div class="info-value" style="color: #dc3545;"><?php echo $mysqlStats['offline_count']; ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php
        // =====================================================
        // TESTE 4: Conexão Firebase
        // =====================================================
        $firebaseOk = false;
        $firebaseError = '';
        
        if ($mysqlOk) {
            try {
                require_once __DIR__ . '/../../labcontrol-backend/includes/FirebaseIntegration.php';
                $firebase = new FirebaseIntegration();
                $firebaseOk = $firebase->isEnabled();
                if (!$firebaseOk) {
                    $firebaseError = 'Firebase não está disponível ou credenciais inválidas';
                }
            } catch (Exception $e) {
                $firebaseError = $e->getMessage();
            }
        }
        ?>
        <div class="card">
            <h2>🔥 Conexão Firebase</h2>
            <div class="status <?php echo $firebaseOk ? 'success' : 'warning'; ?>">
                <span class="status-icon"><?php echo $firebaseOk ? '✅' : '⚠️'; ?></span>
                <div class="status-text">
                    <div class="status-title">
                        <?php echo $firebaseOk ? 'Firebase conectado' : 'Firebase indisponível'; ?>
                    </div>
                    <div class="status-detail">
                        <?php echo $firebaseOk ? 'Sincronização online habilitada' : ($firebaseError . ' - Sistema funcionará em modo offline'); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        // =====================================================
        // TESTE 5: Permissões de Diretórios
        // =====================================================
        $logsWritable = is_writable(__DIR__ . '/logs');
        $firebaseReadable = is_readable(__DIR__ . '/firebase/service-account.json');
        $permissionsOk = $logsWritable;
        ?>
        <div class="card">
            <h2>📁 Permissões de Diretórios</h2>
            <div class="status <?php echo $permissionsOk ? 'success' : 'warning'; ?>">
                <span class="status-icon"><?php echo $permissionsOk ? '✅' : '⚠️'; ?></span>
                <div class="status-text">
                    <div class="status-title">Permissões do Sistema</div>
                    <div class="status-detail">
                        Logs: <?php echo $logsWritable ? 'Gravável ✅' : 'Não gravável ❌'; ?> | 
                        Firebase: <?php echo $firebaseReadable ? 'Legível ✅' : 'Não legível ❌'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        // =====================================================
        // TESTE 6: PowerShell
        // =====================================================
        $psOk = false;
        $psVersion = '';
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec('powershell -Command "$PSVersionTable.PSVersion.Major" 2>&1', $output, $returnCode);
            $psOk = $returnCode === 0 && !empty($output[0]) && intval($output[0]) >= 5;
            $psVersion = $output[0] ?? 'Desconhecido';
        }
        ?>
        <div class="card">
            <h2>💻 PowerShell</h2>
            <div class="status <?php echo $psOk ? 'success' : 'warning'; ?>">
                <span class="status-icon"><?php echo $psOk ? '✅' : '⚠️'; ?></span>
                <div class="status-text">
                    <div class="status-title">
                        <?php 
                        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                            echo $psOk ? "PowerShell $psVersion detectado" : 'PowerShell não detectado ou versão antiga';
                        } else {
                            echo 'Sistema não-Windows';
                        }
                        ?>
                    </div>
                    <div class="status-detail">
                        <?php 
                        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                            echo $psOk ? 'Pronto para controle remoto de hosts Windows' : 'PowerShell 5.1+ necessário para controle remoto';
                        } else {
                            echo 'Controle remoto de hosts Windows requer servidor Windows';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        // =====================================================
        // RESUMO
        // =====================================================
        $allOk = $phpOk && $extensionsOk && $mysqlOk;
        ?>
        <div class="card">
            <h2>📊 Resumo</h2>
            <div class="status <?php echo $allOk ? 'success' : 'error'; ?>">
                <span class="status-icon"><?php echo $allOk ? '✅' : '❌'; ?></span>
                <div class="status-text">
                    <div class="status-title">
                        <?php echo $allOk ? 'Sistema pronto para uso!' : 'Problemas detectados'; ?>
                    </div>
                    <div class="status-detail">
                        <?php 
                        if ($allOk) {
                            echo 'Todos os testes passaram. O sistema está configurado corretamente.';
                        } else {
                            echo 'Corrija os problemas acima antes de usar o sistema.';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <a href="index.php" class="btn">Ver API</a>
                <a href="README.md" class="btn">Documentação</a>
            </div>
        </div>
        
        <div class="card">
            <h2>🔑 Credenciais de Teste</h2>
            <pre>
Admin:     admin@labcontrol.local / admin123
Operador:  operator1@labcontrol.local / operator123
Operador:  operator2@labcontrol.local / operator123
            </pre>
        </div>
        
    </div>
</body>
</html>


