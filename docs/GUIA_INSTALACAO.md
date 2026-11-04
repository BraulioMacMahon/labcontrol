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

### Passo 3b: Acesso direto, sem setup de administrador

O LabControl funciona agora em **modo laboratório sem login**. Não execute `setup.php`, não crie utilizador administrador e não configure tokens para utilizar o painel ou as APIs.

A `ENCRYPTION_KEY` continua necessária para proteger credenciais Windows guardadas por host. O backend gera e tenta persistir uma chave única quando ela está ausente, mas recomenda-se criá-la explicitamente conforme o [Guia de Funcionamento no Laboratório](../GUIA_FUNCIONAMENTO_LABORATORIO.md).

> Este modo deve ser limitado à rede interna autorizada: quem conseguir aceder ao servidor poderá utilizar as operações de gestão e controlo remoto.

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
| `ENCRYPTION_KEY` | Chave única para encriptar senhas Windows dos hosts (recomendado: 64 caracteres hexadecimais). |
| `CORS_ALLOWED_ORIGINS` | Endereços HTTP autorizados a aceder à API (localhost e IP do servidor). |
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

O Dashboard abre diretamente, sem login, senha ou token. Para acesso por outras máquinas e o procedimento completo de validação, consulte [GUIA_FUNCIONAMENTO_LABORATORIO.md](../GUIA_FUNCIONAMENTO_LABORATORIO.md).

---

## 6. Preparação dos Hosts (Estações)
Em cada estação, execute no PowerShell como Administrador:

```powershell
Enable-PSRemoting -Force
Set-Service WinRM -StartupType Automatic
Start-Service WinRM
```

Em redes sem domínio, configure `TrustedHosts` **no servidor/controlador**, indicando as estações de destino (e não o IP do servidor):

```powershell
Set-Item WSMan:\localhost\Client\TrustedHosts -Value "PC-LAB01,PC-LAB02" -Force
Restart-Service WinRM
```

Consulte o guia principal para testar as credenciais, configurar a Firewall e ativar Wake-on-LAN.

---
*Documentação Gerada em: 06/03/2026*
