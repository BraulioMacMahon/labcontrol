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

### Passo 4: Instalação das Dependências do Worker (Node.js)
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
npm start
```
*Para produção, utilize o arquivo `run-worker.bat` ou configure como serviço via NSSM.*

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
