# Guia de Instalação e Configuração - LabControl

## 1. Requisitos de Sistema

### Servidor Principal (Controlador)
*   **SO:** Windows 10, 11 ou Windows Server 2016+.
*   **Web Server:** XAMPP (Apache 2.4+, PHP 7.4 ou 8.x).
*   **Banco de Dados:** MySQL 5.7+ ou MariaDB 10.4+ (incluído no XAMPP).
*   **Runtime:** Node.js v16+ e npm v8+.
*   **Shell:** PowerShell 5.1 ou PowerShell Core 7.0+.

### Hosts Alvo (Estações de Trabalho)
*   **SO:** Windows 10/11 Pro ou Enterprise (para suporte a WinRM).
*   **Configuração:** WinRM (Windows Remote Management) habilitado.

---

## 2. Passo a Passo de Instalação

### Passo 1: Preparação do Ambiente Web
1.  Instale o **XAMPP** no diretório padrão (`C:\xampp`).
2.  Clone ou copie a pasta do projeto para `C:\xampp\htdocs\labcontrol`.

### Passo 2: Configuração do Banco de Dados
1.  Inicie o MySQL via XAMPP Control Panel.
2.  Acesse o `phpMyAdmin` ou utilize a linha de comando.
3.  Crie um banco de dados chamado `labcontrol`.
4.  Importe o esquema SQL localizado em `labcontrol-backend/sql/database.sql`.

### Passo 3: Configuração das Variáveis de Ambiente
1.  Na raiz do projeto (`/labcontrol`), renomeie o arquivo `.env.example` para `.env`.
2.  Edite o arquivo `.env` com suas credenciais (veja a seção 4 deste guia).
3.  Repita o processo para a pasta `sync-service/.env`.

### Passo 3b: Setup de Primeiro Acesso (criar administrador + segredos)

O banco **não** vem com usuários nem com segredos (por segurança). Execute o setup **uma única vez**, a partir do localhost:

**Via navegador (localhost):**
```
http://localhost/labcontrol/labcontrol-backend/setup.php?email=admin@labcontrol.local&password=SUA_SENHA_FORTE_16+
```

**Ou via CLI:**
```bash
cd labcontrol-backend
php setup.php --email=admin@labcontrol.local --password=SUA_SENHA_FORTE_16+
```

O script irá:
- Gerar o arquivo `.env` (se ausente) e criar `JWT_SECRET` + `ENCRYPTION_KEY` fortes automaticamente.
- Criar o primeiro usuário **admin** com a senha informada (mín. 12 caracteres). Ele NÃO roda se já existirem usuários (responde `409` e não altera nada).
- Após o uso, remova `labcontrol-backend/setup.php`.

> Nunca reuse senhas fracas (ex.: `admin123`). O setup recusa senhas com menos de 12 caracteres.
> **O `setup.php` não é um comando de login** — ele só cria a primeira conta. Iniciar sessão faz-se sempre pelo browser.

### Gerir contas e diagnosticar problemas de login (CLI)

```bash
# na raiz do projeto
php labcontrol-backend/cli/users.php list
php labcontrol-backend/cli/users.php create --email=operador@lab.local --password=SenhaForte12+ --role=operator
php labcontrol-backend/cli/users.php set-password --email=admin@labcontrol.local --password=NovaSenhaForte12+
php labcontrol-backend/cli/users.php check-login --email=admin@labcontrol.local --password=...
php labcontrol-backend/cli/users.php check-config
php labcontrol-backend/cli/users.php reset-rate-limit
```

**Se o browser fica no ecrã de login (o botão "recarrega" mas não entra):**

1. `check-login` diz se o par email/senha passa no backend (existe, está ativo, hash bcrypt válido, senha confere).
2. Se passa mas o browser não entra, o token está a ser **rejeitado logo após o login**. Causas típicas, todas tratadas nesta versão mas que dependem do servidor:
   - `JWT_SECRET` vazio/fraco no `.env` **e** ficheiro não gravável pelo Apache → o backend agora responde com um erro 500 explícito em vez de gerar um segredo diferente a cada request. Solução: `check-config` e dar permissão de escrita ao `.env`, ou definir `JWT_SECRET` manualmente.
   - Header `Authorization` não chega ao PHP (PHP em CGI/FastCGI). O `.htaccess` do backend já reencaminha o header; confirme que o Apache tem `AllowOverride All` e `mod_rewrite` ativo.
   - Rate limit ativo após várias tentativas: `reset-rate-limit`.
3. A interface passou a mostrar a causa em vez de recarregar em silêncio: "Credenciais corretas, mas o servidor rejeitou a sessão…" significa exatamente o ponto 2.

### Passo 4: Instalação das Dependências do Worker (Node.js)

O sync-service (Node.js) é responsável por atualizar o status dos hosts e sincronizar com o Firebase. **Ele precisa estar em execução** para que a sincronização funcione; sem ele, o restante da plataforma (CRUD, controle remoto, logs) continua operando normalmente.

1.  Abra o terminal na pasta `sync-service`.
2.  Execute o comando:
    ```bash
    npm install
    ```

---

## 3. Estrutura de Diretórios

O projeto está organizado de forma modular:

*   `labcontrol-backend/`: API REST em PHP.
    *   `api/`: Endpoints (auth, control, hosts, logs).
    *   `config/`: Arquivos de configuração e constantes.
    *   `includes/`: Classes de conexão (Database, Firebase).
    *   `powershell/`: Scripts `.ps1` para execução remota.
    *   `sql/`: Scripts de criação do banco de dados.
*   `labcontrol-frontend/`: Interface SPA (HTML/JS/CSS).
*   `sync-service/`: Worker Node.js para sincronização Firebase.
*   `nssm/`: Utilitário para transformar o worker em serviço do Windows.

---

## 4. Configuração do Arquivo .env

Campos críticos que devem ser preenchidos no arquivo `.env` na raiz:

| Variável | Descrição |
| :--- | :--- |
| `DB_PASS` | Senha do MySQL (vazio por padrão no XAMPP). |
| `JWT_SECRET` | Chave aleatória para tokens de segurança. |
| `ENCRYPTION_KEY` | Chave de 32 caracteres para encriptar senhas dos hosts. |
| `REMOTE_USER` | Usuário administrador padrão para comandos remotos. |
| `REMOTE_PASSWORD` | Senha do administrador padrão. |
| `FIREBASE_PROJECT_ID` | ID do seu projeto no Google Firebase. |

---

## 5. Como Iniciar os Servidores

### 1. Servidores Web e DB
Abra o **XAMPP Control Panel** e clique em **Start** para:
*   Apache
*   MySQL

### 2. Serviço de Sincronização (Node.js)
Para fins de desenvolvimento:
```bash
cd sync-service
npm install   # apenas na primeira vez
npm start
```
*Para produção, utilize o arquivo `run-worker.bat` ou configure como serviço via NSSM (o worker deve permanecer em execução para a sincronização funcionar).*

> O worker roda no **controlador Windows** e usa o `powershell.exe` (caminho configurável em `POWERSHELL_PATH` no `sync-service/.env`) para checagem de hosts. Em ambientes puramente Linux, a sincronização com Firebase continua opcional; o status dos hosts pode ser atualizado pelo backend via ping.

### 3. Acesso à Plataforma
Abra o navegador e acesse: `http://localhost/labcontrol/labcontrol-frontend/`

---

## 6. Preparação dos Hosts (Estações)
Para que o LabControl consiga gerenciar as estações, execute o seguinte comando no PowerShell (como Administrador) em cada máquina:

```powershell
Enable-PSRemoting -Force
Set-Service WinRM -StartMode Automatic
Set-Item WSMan:\localhost\Client\TrustedHosts -Value "IP_DO_SERVIDOR" -Force
```

---
*Documentação Gerada em: 06/03/2026*
