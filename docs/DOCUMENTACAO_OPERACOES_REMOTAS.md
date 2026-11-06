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

## 7. Resolução de problemas: `action=processes`

### Sintoma
```
GET .../api/control.php?action=processes&hostname=LAB17-PC33 → 500 (Internal Server Error)
Erro: Falha ao obter processos
```
Repetido a cada 10 s porque o `startNetworkMonitor()` do frontend atualiza o painel de processos
em cada ciclo de `checkAllHosts()`.

O `ping` responde (o host aparece *online*), mas o WinRM falha. Historicamente **qualquer**
falha nessa fase era convertida num `500` sem detalhe, e `Get-Processes.ps1` não emitia os
prefixos `ACESSO_NEGADO`/`WINRM_ERRO` que o PHP esperava.

### Códigos devolvidos agora

| HTTP | `error_type` | Causa provável | Acção |
|------|--------------|----------------|-------|
| 400 | — | Host offline (ping falhou) | Ligar o PC / verificar rede |
| 412 | `missing_credentials` | `.env` sem `REMOTE_USER`/`REMOTE_PASSWORD` (ou ainda com `CHANGE_ME_*`) e host sem credenciais próprias | Preencher o `.env` do servidor e reiniciar o Apache |
| 403 | `authentication_failed` | Utilizador/password errados ou sem privilégios de administrador | Corrigir credenciais; testar `Enter-PSSession` manual |
| 503 | `winrm_error` | WinRM desligado, firewall 5985/5986, DNS ou `TrustedHosts` | Ver abaixo |
| 502 | `powershell_error` / `invalid_output` / `script_missing` | Erro no script, `Get-Processes.ps1` em falta ou saída não-JSON | Ver o log do servidor |
| 504 | `timeout` | Sessão WinRM expirou | Verificar latência/estado do alvo |

O frontend deixa de ficar preso em "Carregando processos..." e passa a mostrar
**"Processos indisponíveis"** com a mensagem devolvida pela API.

### Diagnosticar no servidor
Todas as falhas são agora registadas em `labcontrol-backend/logs/error_AAAA-MM-DD.log`:
```json
{"message":"Falha ao obter processos","context":{"host":"LAB17-PC33","ip":"192.168.21.40",
 "exit_code":1,"error_type":"winrm_error","error":"WINRM_ERRO: ...","output":"..."}}
```
A password é substituída por `***` antes de ser gravada ou devolvida ao browser.

Testes rápidos (no **servidor** LabControl, PowerShell como administrador):
```powershell
Test-WSMan LAB17-PC33
Test-NetConnection LAB17-PC33 -Port 5985
Get-Item WSMan:\localhost\Client\TrustedHosts
# Em grupo de trabalho, confiar nas estações no CLIENTE (o servidor):
Set-Item WSMan:\localhost\Client\TrustedHosts -Value 'LAB17-*' -Force
Enter-PSSession -ComputerName LAB17-PC33 -Credential (Get-Credential)
```
Na estação alvo:
```powershell
Enable-PSRemoting -Force
# ou: winrm quickconfig
```
Se o `.env` foi alterado, reiniciar o Apache (as variáveis são lidas em cada pedido, mas o
`ENCRYPTION_KEY`/`JWT_SECRET` são persistidos no primeiro arranque).

### Contrato de saída de `Get-Processes.ps1`
O script escreve **sempre um único objecto JSON** e sai com `0` apenas em sucesso:
```json
{"success":true,"processes":[{"Id":4,"ProcessName":"explorer","CPU":1.5,...}]}
{"success":false,"error":"WINRM_ERRO: ..."}
```
`processes` é sempre uma lista (mesmo com 0 ou 1 processo). A saída é UTF-8 e escrita por
`[Console]::Out` para evitar quebras de linha do formatador do PowerShell. O parser PHP
(`includes/PowerShell.php`) tolera avisos do stderr, BOM, linhas partidas e codepage OEM.

Teste unitário do parser (não precisa de Windows, MySQL ou Apache):
```bash
php tests/backend/test-powershell-output.php
```

---
*Documentação Gerada em: 06/03/2026*
