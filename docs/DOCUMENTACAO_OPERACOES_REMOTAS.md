# Documentação de Operações Remotas - LabControl

## 1. Visão Geral
O LabControl utiliza o **Windows Remote Management (WinRM)** e o **PowerShell** para realizar operações de administração em estações de trabalho Windows na rede local. Esta camada é responsável por traduzir requisições da API web em ações nativas do sistema operacional.

## 2. Fluxo de Execução
Todas as operações remotas seguem este ciclo:
1.  **Validação de Conectividade:** O backend realiza um Ping (ICMP) no host alvo.
2.  **Preparação de Credenciais:** O sistema recupera as credenciais (específicas do host ou padrão) e descriptografa a senha.
3.  **Geração de Script Temporário:** Um arquivo `.ps1` volátil é criado em tempo real para evitar problemas de escape de caracteres especiais.
4.  **Invocação via WinRM:** O script é executado via `powershell.exe -ExecutionPolicy Bypass`.
5.  **Processamento de Resposta:** O output do PowerShell (em formato JSON) é capturado, o arquivo temporário é deletado e o resultado é retornado à API.

## 3. Scripts PowerShell Principais

Os scripts estão localizados em `labcontrol-backend/powershell/`:

| Script | Função | Parâmetros Principais |
| :--- | :--- | :--- |
| `Get-Processes.ps1` | Obtém lista de processos ativos. | `-ComputerName`, `-Username`, `-Password` |
| `Kill-Process.ps1` | Encerra processos por PID ou Nome. | `-ProcessId`, `-ProcessName`, `-Force` |
| `Shutdown-Host.ps1` | Desliga a máquina remotamente. | `-Timeout`, `-Message`, `-Force` |
| `Restart-Host.ps1` | Reinicia a máquina remotamente. | `-Timeout`, `-Message`, `-Force` |
| `Get-SystemInfo.ps1`| Obtém detalhes de HW/SW. | `-ComputerName` |

## 4. Gerenciamento de Credenciais

### Aplicação das Credenciais
O sistema aplica credenciais em dois níveis:
*   **Default:** Configurado no `.env` do servidor (variáveis `REMOTE_USER` e `REMOTE_PASSWORD`).
*   **Per-Host:** Credenciais específicas cadastradas para uma máquina no banco de dados (tabela `hosts`). Estas têm prioridade sobre as padrão.

### Segurança
As senhas dos hosts são armazenadas no banco de dados utilizando criptografia **AES-256-CBC**. Elas são descriptografadas apenas em memória no momento da execução do comando PowerShell.

## 5. Testes de Conectividade

### Ping (Disponibilidade Básica)
Antes de qualquer comando WinRM, o sistema executa um Ping rápido para verificar se o host está respondendo na rede.
*   **Sucesso:** Prossegue para a execução do comando.
*   **Falha:** Retorna erro `400 Host Offline` imediatamente para economizar tempo de timeout do WinRM.

### WinRM (Acesso Administrativo)
A conectividade WinRM requer que o host alvo tenha:
1.  Serviço WinRM rodando.
2.  Regra de firewall permitindo porta 5985 (HTTP) ou 5986 (HTTPS).
3.  O servidor do LabControl deve estar na lista de `TrustedHosts` do alvo se não estiverem no mesmo domínio.

## 6. Auditoria de Operações

Cada execução remota gera um registro na tabela `logs` com os seguintes detalhes:
*   **Action Type:** `control` ou `monitor`.
*   **Status:** `success` ou `error`.
*   **Details:** Contém informações como o comando exato enviado ou o erro retornado pelo PowerShell (ex: `ACESSO_NEGADO`, `WINRM_ERRO`).

### Exemplo de Log de Encerramento de Processo:
*   **Ação:** "Encerramento de processo remoto"
*   **Detalhes:** `{"pid": 1234, "process": "notepad.exe", "force": true}`
*   **Host:** `192.168.21.40`
*   **Usuário:** `admin@labcontrol.local`

---
*Documentação Gerada em: 06/03/2026*
