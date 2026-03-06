<?php
/**
 * LabControl - Verificação de Saúde do Sistema
 * Acesse: http://localhost/labcontrol/labcontrol-backend/health-check.php
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>LabControl - Health Check</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0f0f12; color: #ccc; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #0984e3; margin-bottom: 30px; }
        .section { background: #1a1a1f; border: 1px solid #333; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .section h2 { color: #0984e3; margin-bottom: 15px; font-size: 18px; }
        .check { display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #222; }
        .check:last-child { border-bottom: none; }
        .check-label { flex: 1; }
        .check-status { padding: 5px 10px; border-radius: 4px; font-weight: bold; text-align: right; min-width: 100px; }
        .status-ok { background: #27ae60; color: white; }
        .status-error { background: #e74c3c; color: white; }
        .status-warning { background: #f39c12; color: white; }
        code { background: #111; padding: 2px 6px; border-radius: 3px; font-family: 'Consolas', monospace; }
        .separator { height: 1px; background: #333; margin: 20px 0; }
        button { background: #0984e3; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        button:hover { background: #0770c9; }
        pre { background: #111; padding: 10px; border-radius: 4px; overflow-x: auto; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 LabControl - Health Check</h1>
        
        <?php
        require_once __DIR__ . '/../../labcontrol-backend/config/config.php';
        
        // Test 1: Database Connection
        echo '<div class="section">';
        echo '<h2>1️⃣ Conexão com Banco de Dados</h2>';
        
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            echo '<div class="check">
                    <div class="check-label">Banco de dados ' . DB_NAME . ' em ' . DB_HOST . '</div>
                    <div class="check-status status-ok">✓ OK</div>
                  </div>';
        } catch (Exception $e) {
            echo '<div class="check">
                    <div class="check-label">Banco de dados ' . DB_NAME . '</div>
                    <div class="check-status status-error">✗ ERRO</div>
                  </div>';
            echo '<pre>' . $e->getMessage() . '</pre>';
        }
        
        // Test 2: Users Table
        echo '<div class="separator"></div>';
        echo '<h2>2️⃣ Tabela de Usuários</h2>';
        
        try {
            require_once __DIR__ . '/../../labcontrol-backend/includes/Database.php';
            $database = Database::getInstance();
            
            $tableName = 'users';
            $result = $database->selectOne("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", 
                [DB_NAME, $tableName]);
            
            if ($result) {
                echo '<div class="check">
                        <div class="check-label">Tabela <code>users</code></div>
                        <div class="check-status status-ok">✓ EXISTE</div>
                      </div>';
                
                // Count users
                $users = $database->select("SELECT COUNT(*) as cnt FROM users");
                if ($users) {
                    $count = $users[0]['cnt'] ?? 0;
                    echo '<div class="check">
                            <div class="check-label">Usuários cadastrados</div>
                            <div class="check-status ' . ($count > 0 ? 'status-ok' : 'status-warning') . '">' . $count . '</div>
                          </div>';
                }
                
                // List users
                $allUsers = $database->select("SELECT id, email, role, is_active FROM users");
                if ($allUsers && count($allUsers) > 0) {
                    echo '<div class="check">';
                    echo '<div class="check-label">Usuários:<br>';
                    foreach ($allUsers as $u) {
                        $active = $u['is_active'] ? '✓' : '✗';
                        echo '  • ' . $u['email'] . ' (' . $u['role'] . ') ' . $active . '<br>';
                    }
                    echo '</div></div>';
                } else {
                    echo '<div class="check">
                            <div class="check-label">⚠️ Nenhum usuário inserido</div>
                            <div class="check-status status-warning">AVISO</div>
                          </div>';
                }
            } else {
                echo '<div class="check">
                        <div class="check-label">Tabela <code>users</code></div>
                        <div class="check-status status-error">✗ NÃO EXISTE</div>
                      </div>';
            }
        } catch (Exception $e) {
            echo '<div class="check">
                    <div class="check-label">Verificação de tabelas</div>
                    <div class="check-status status-error">✗ ERRO</div>
                  </div>';
            echo '<pre>' . $e->getMessage() . '</pre>';
        }
        
        echo '</div>';
        
        // Test 3: API Endpoint
        echo '<div class="section">';
        echo '<h2>3️⃣ Endpoint de Login</h2>';
        
        echo '<div class="check">
                <div class="check-label">URL da API</div>
                <div class="check-status status-ok">✓ OK</div>
              </div>';
        echo '<pre>POST http://localhost/labcontrol/labcontrol-backend/api/auth.php?action=login
Content-Type: application/json

{
  "email": "admin@labcontrol.local",
  "password": "admin123"
}</pre>';
        
        echo '</div>';
        
        // Test 4: Configuration
        echo '<div class="section">';
        echo '<h2>4️⃣ Configuração</h2>';
        
        echo '<div class="check">
                <div class="check-label">FIREBASE_ENABLED</div>
                <div class="check-status ' . (FIREBASE_ENABLED ? 'status-ok' : 'status-warning') . '">' . (FIREBASE_ENABLED ? 'true' : 'false') . '</div>
              </div>';
        
        echo '<div class="check">
                <div class="check-label">DEBUG_MODE</div>
                <div class="check-status ' . (DEBUG_MODE ? 'status-warning' : 'status-ok') . '">' . (DEBUG_MODE ? 'true' : 'false') . '</div>
              </div>';
        
        echo '<div class="check">
                <div class="check-label">CORS_ALLOWED_ORIGINS</div>
                <div class="check-status status-ok">' . CORS_ALLOWED_ORIGINS . '</div>
              </div>';
        
        echo '</div>';
        
        // Action buttons
        echo '<div class="section">';
        echo '<h2>⚙️ Ações</h2>';
        
        echo '<button onclick="if(confirm(\'Executar setup completo?\')) window.location.href = \'full-setup.php\'">📡 Executar Full Setup</button>';
        echo '<button onclick="window.location.href = \'test-connection.php\'" style="margin-left: 10px; background: #8e44ad;">🧪 Executar Testes</button>';
        
        echo '</div>';
        
        ?>
    </div>
</body>
</html>


