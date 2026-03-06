<?php
require_once __DIR__ . '/../../labcontrol-backend/config/config.php';
require_once __DIR__ . '/../../labcontrol-backend/includes/FirebaseIntegration.php';

echo "--- TESTE DE CONEXÃO FIREBASE ---
";
echo "Projeto ID: " . FIREBASE_PROJECT_ID . "
";

$firebase = new FirebaseIntegration();

if (!$firebase->isEnabled()) {
    echo "ERRO: Firebase não está habilitado ou credenciais inválidas.
";
    exit;
}

echo "Conectando ao Firestore...
";
$users = $firebase->getUser('1'); // Tenta buscar o admin padrão

if ($users !== false) {
    echo "SUCESSO: Conexão estabelecida!
";
    echo "Dados do usuário ID 1 no Firestore:
";
    print_r($users);
} else {
    echo "AVISO: Conexão OK, mas nenhum dado encontrado ou erro na API (verifique se o Firestore foi criado).
";
}


