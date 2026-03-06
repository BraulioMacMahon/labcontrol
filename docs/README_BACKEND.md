# LabControl - Backend

Sistema híbrido de controle de laboratório com sincronização MySQL ↔ Firebase.

## 📋 Funcionalidades

- **Autenticação**: Login offline com MySQL + opção de Firebase Auth online
- **Gerenciamento de Hosts**: CRUD completo de máquinas do laboratório
- **Controle Remoto**: Shutdown, restart, Wake-on-LAN, monitoramento de processos
- **Sincronização**: Dados sincronizados automaticamente entre MySQL local e Firebase
- **Logs de Auditoria**: Registro completo de todas as ações
- **Modo Offline**: Funciona 100% offline com sincronização quando reconectar

## 🛠️ Requisitos

- PHP 7.4+
- MySQL 5.7+ ou MariaDB 10.3+
- XAMPP/WAMP (ambiente de desenvolvimento)
- PowerShell 5.1+ (para controle remoto Windows)
- WinRM habilitado nos hosts remotos

## 📁 Estrutura do Projeto

```
labcontrol-backend/
├── api/                    # Endpoints da API
│   ├── auth.php           # Autenticação (login, logout, verify)
│   ├── hosts.php          # Gerenciamento de hosts
│   ├── control.php        # Controle remoto (shutdown, restart, WoL)
│   ├── sync.php           # Sincronização com Firebase
│   └── logs.php           # Logs e auditoria
├── config/
│   └── config.php         # Configurações do sistema
├── includes/
│   ├── Database.php       # Classe de conexão MySQL
│   └── FirebaseIntegration.php  # Integração com Firebase
├── firebase/
│   └── service-account.json     # Credenciais Firebase
├── powershell/            # Scripts PowerShell
│   ├── Shutdown-Host.ps1
│   ├── Restart-Host.ps1
│   ├── Get-Processes.ps1
│   ├── Kill-Process.ps1
│   ├── Get-SystemInfo.ps1
│   └── Execute-Command.ps1
├── sql/
│   └── database.sql       # Script de criação do banco
├── logs/                  # Logs do sistema
└── .htaccess             # Configuração Apache
```

## 🚀 Instalação

### 1. Configurar Banco de Dados

```bash
# Acesse o MySQL
mysql -u root -p

# Execute o script
source sql/database.sql
```

Ou use o phpMyAdmin para importar `sql/database.sql`.

### 2. Configurar Firebase

1. Coloque o arquivo `service-account.json` na pasta `firebase/`
2. Verifique se o arquivo tem permissões de leitura

### 3. Configurar PHP

Edite `config/config.php` e ajuste:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'labcontrol');
define('DB_USER', 'root');
define('DB_PASS', 'sua_senha');
```

### 4. Configurar Credenciais de Acesso Remoto

Como todos os hosts têm o mesmo usuário administrador, configure as credenciais padrão:

**Opção 1: Via API (Recomendado)**

```bash
curl -X POST http://localhost/labcontrol-backend/api/hosts.php?action=set-credentials \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer SEU_TOKEN_ADMIN" \
  -d '{
    "username": "AdminLab17",
    "password": "Insert@into17",
    "description": "Credenciais padrão do domínio"
  }'
```

**Opção 2: Via Configuração (config/config.php)**

```php
define('DEFAULT_REMOTE_USER', 'Administrador');
define('DEFAULT_REMOTE_PASSWORD', 'sua_senha_aqui');
```

> ⚠️ **IMPORTANTE**: As senhas são criptografadas com AES-256-CBC antes de serem armazenadas no banco de dados.

### 5. Configurar PowerShell Remoto

Nos hosts Windows que serão controlados, execute como Administrador:

```powershell
# Habilitar WinRM
Enable-PSRemoting -Force

# Configurar trusted hosts (ajuste conforme sua rede)
Set-Item WSMan:\localhost\Client\TrustedHosts -Value "*" -Force

# Verificar status
Test-WSMan
```

### 5. Configurar Wake-on-LAN

Nos hosts que precisam de WoL:

1. Acesse a BIOS e habilite Wake-on-LAN
2. No Windows, configure o driver da placa de rede:
   - Gerenciador de Dispositivos → Placa de Rede → Propriedades
   - Guia "Gerenciamento de Energia" → Permitir que o dispositivo acorde o computador

## 📡 API Endpoints

### Autenticação

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/api/auth.php?action=login` | Login |
| POST | `/api/auth.php?action=logout` | Logout |
| GET | `/api/auth.php?action=verify` | Verificar token |
| POST | `/api/auth.php?action=register` | Registrar usuário (admin) |

**Exemplo de Login:**
```bash
curl -X POST http://localhost/labcontrol-backend/api/auth.php?action=login \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@labcontrol.local", "password": "admin123"}'
```

### Hosts

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/hosts.php?action=list` | Listar todos os hosts |
| GET | `/api/hosts.php?action=get&id=1` | Obter host por ID |
| POST | `/api/hosts.php?action=create` | Criar host |
| PUT | `/api/hosts.php?action=update&id=1` | Atualizar host |
| DELETE | `/api/hosts.php?action=delete&id=1` | Excluir host |
| GET | `/api/hosts.php?action=stats` | Estatísticas |

### Credenciais (Apenas Admin)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/api/hosts.php?action=set-credentials` | Configurar credenciais padrão |
| GET | `/api/hosts.php?action=get-credentials` | Listar credenciais configuradas |
| POST | `/api/hosts.php?action=set-host-credentials&id=1` | Configurar credenciais de host específico |

**Exemplo de Configuração de Credenciais:**
```bash
curl -X POST http://localhost/labcontrol-backend/api/hosts.php?action=set-credentials \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer SEU_TOKEN_ADMIN" \
  -d '{
    "username": "Administrador",
    "password": "sua_senha_admin"
  }'
```

### Controle Remoto

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/api/control.php?action=shutdown` | Desligar host |
| POST | `/api/control.php?action=restart` | Reiniciar host |
| POST | `/api/control.php?action=wol` | Wake-on-LAN |
| GET | `/api/control.php?action=status&ip=192.168.1.1` | Verificar status |
| GET | `/api/control.php?action=processes&ip=192.168.1.1` | Listar processos |
| POST | `/api/control.php?action=killprocess` | Encerrar processo |

**Exemplo de Shutdown:**
```bash
curl -X POST http://localhost/labcontrol-backend/api/control.php?action=shutdown \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer SEU_TOKEN" \
  -d '{"ip": "192.168.1.100", "timeout": 30, "message": "Desligamento programado"}'
```

### Sincronização

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/sync.php?action=status` | Status da sincronização |
| POST | `/api/sync.php?action=sync` | Sincronizar dados |
| POST | `/api/sync.php?action=force-sync` | Forçar sincronização completa |

### Logs

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/api/logs.php?action=list` | Listar logs |
| GET | `/api/logs.php?action=stats` | Estatísticas |
| GET | `/api/logs.php?action=export&format=csv` | Exportar logs |

## 🔐 Autenticação

O sistema usa JWT (JSON Web Tokens) para autenticação:

1. Faça login para obter o token
2. Inclua o token em todas as requisições:
   ```
   Authorization: Bearer SEU_TOKEN
   ```
3. O token expira em 1 hora (configurável em `config.php`)

## 🔄 Sincronização Offline/Online

### Modo Offline
- Quando Firebase não está disponível, todos os dados são gravados apenas no MySQL
- Campos `synced = 0` indicam registros pendentes

### Sincronização Automática
- Ao reconectar, chame `/api/sync.php?action=sync`
- Hosts e logs pendentes são sincronizados
- A fila de sincronização processa operações pendentes

### Forçar Sincronização
```bash
curl -X POST http://localhost/labcontrol-backend/api/sync.php?action=force-sync \
  -H "Authorization: Bearer SEU_TOKEN"
```

## 👥 Usuários Padrão

| Email | Senha | Perfil |
|-------|-------|--------|
| admin@labcontrol.local | admin123 | Admin |
| operator1@labcontrol.local | operator123 | Operador |
| operator2@labcontrol.local | operator123 | Operador |

## 📝 Logs

Todos os logs são armazenados em:
- **MySQL**: Tabela `logs`
- **Firebase**: Coleção `logs`
- **Arquivos**: Pasta `logs/` (erros do sistema)

## 🔧 Configurações Importantes

### `config/config.php`

```php
// Banco de dados
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');

// Firebase
define('FIREBASE_ENABLED', true);
define('FIREBASE_PROJECT_ID', 'labcontrol-bd504');

// Segurança
define('SESSION_TIMEOUT', 3600); // 1 hora
define('MAX_LOGIN_ATTEMPTS', 5);

// Rede
define('PING_TIMEOUT', 2); // segundos
define('WOL_PORT', 9);
```

## 🐛 Debug

Habilite o modo debug em `config.php`:
```php
define('DEBUG_MODE', true);
```

Isso exibirá erros detalhados. **Desative em produção!**

## 🔒 Segurança

1. **Altere as senhas padrão** imediatamente após a instalação
2. **Proteja o arquivo** `firebase/service-account.json`
3. **Use HTTPS** em produção
4. **Configure o firewall** para permitir apenas IPs confiáveis
5. **Monitore os logs** regularmente

## 📊 Monitoramento

### Verificar status de todos os hosts:
```bash
curl http://localhost/labcontrol-backend/api/hosts.php?action=check-all \
  -H "Authorization: Bearer SEU_TOKEN"
```

### Estatísticas:
```bash
curl http://localhost/labcontrol-backend/api/hosts.php?action=stats \
  -H "Authorization: Bearer SEU_TOKEN"
```

## 🆘 Troubleshooting

### Erro de conexão com MySQL
- Verifique se o MySQL está rodando
- Confirme as credenciais em `config.php`
- Verifique permissões do usuário

### PowerShell remoto falha
- Verifique se WinRM está habilitado: `Test-WSMan`
- Confirme se o firewall permite conexões remotas
- Verifique as credenciais de acesso

### Erro "Credenciais inválidas" ou "ACESSO_NEGADO"
Este erro ocorre quando:
1. **Usuário/senha incorretos**: Verifique as credenciais configuradas
2. **Usuário não é administrador**: O usuário precisa ter privilégios administrativos
3. **WinRM não configurado**: Execute nos hosts remotos:
   ```powershell
   Enable-PSRemoting -Force
   Set-Item WSMan:\localhost\Client\TrustedHosts -Value "*" -Force
   ```
4. **Firewall bloqueando**: Abra a porta 5985 (HTTP) ou 5986 (HTTPS)

**Para testar manualmente:**
```powershell
# No servidor LabControl, teste a conexão:
$cred = Get-Credential -Username "Administrador" -Message "Senha"
Enter-PSSession -ComputerName "192.168.1.100" -Credential $cred
```

### Firebase não sincroniza
- Verifique se `service-account.json` está correto
- Confirme conectividade: `ping firebase.googleapis.com`
- Verifique logs em `logs/error_*.log`

### Wake-on-LAN não funciona
- Verifique se MAC address está correto
- Confirme configuração da BIOS
- Verifique se a placa de rede suporta WoL

## 📄 Licença

Este projeto é proprietário. Uso apenas autorizado.

## 🤝 Suporte

Para suporte técnico, entre em contato com a equipe de TI.

---

**Versão**: 1.0.0  
**Data**: 2025  
**Desenvolvido por**: Equipe LabControl
