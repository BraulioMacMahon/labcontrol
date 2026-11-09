# Documentação da API - LabControl

## 1. Visão Geral
A API do LabControl é uma interface RESTful baseada em PHP que fornece endpoints para gerenciamento de hosts, execução de comandos remotos, visualização de logs e um canal **SSE** de atualizações em tempo real. As respostas são **JSON** (exceto exportações CSV e `state.php?action=stream`, que é `text/event-stream`).

### Base URL
`http://seu-servidor/labcontrol/labcontrol-backend/api/`

---

## 2. Acesso local sem autenticação
Os endpoints operacionais **não exigem login, JWT nem header `Authorization`**. As ações são registadas na auditoria com a identidade local `operador@labcontrol.local`, apenas para rastreabilidade.

Este modo destina-se exclusivamente à rede interna autorizada do laboratório. Não exponha a API à Internet. As credenciais Windows usadas pelo WinRM continuam obrigatórias para operações remotas e são independentes do acesso à API.

---

## 3. Endpoints Principais

### 3.1. Controle Remoto (`control.php`)
| Ação | Método | Parâmetros | Descrição |
| :--- | :--- | :--- | :--- |
| `status` | GET | `hostname` ou `ip` | Retorna o status detalhado do host (online/offline). |
| `processes` | GET | `hostname` ou `ip` | Lista processos ativos via PowerShell. |
| `shutdown` | POST | `hostname` ou `ip` | Desliga a máquina remotamente. |
| `restart` | POST | `hostname` ou `ip` | Reinicia a máquina remotamente. |
| `wol` | POST | `mac_address` | Envia o "Magic Packet" para Wake-on-LAN. |
| `killprocess`| POST | `pid`, `hostname` | Encerra um processo específico. |

### 3.2. Gerenciamento de Hosts (`hosts.php`)
| Ação | Método | Descrição |
| :--- | :--- | :--- |
| `list` | GET | Lista todos os hosts cadastrados no inventário. |
| `discover` | GET | Descobre o IP (`ping -4 "<hostname>"`) e o MAC (`arp -a "<ip>"`) de um hostname. |
| `create` | POST | Adiciona um novo host (IP, MAC, Hostname). |
| `update` | PUT | Atualiza dados de um host existente. |
| `delete` | DELETE | Remove um host do sistema. |
| `stats` | GET | Retorna estatísticas de disponibilidade (quantos online/offline). |

### 3.3. Auditoria e Logs (`logs.php`)
| Ação | Método | Descrição |
| :--- | :--- | :--- |
| `list` | GET | Retorna o histórico de ações realizadas no sistema. |
| `search` | GET | Filtra logs por período, host ou usuário. |
| `export` | GET | Exporta os logs em formato compatível com planilhas. |

### 3.4. Estado em tempo real (`state.php`) — **SSE**

Substitui o polling de `hosts.php?action=check-all` no browser. Ambos os modos são **GET**, de leitura apenas, e executam **uma única consulta** ao MySQL. Ver detalhes em [**Documentação de Tempo Real**](DOCUMENTACAO_TEMPO_REAL.md).

| Ação | Método | Content-Type | Descrição |
| :--- | :--- | :--- | :--- |
| `version` | GET | `application/json` | Fingerprint do estado. Suporta `If-None-Match` → `304` sem corpo. |
| `stream` | GET | `text/event-stream` | Ligação SSE persistente; emite `hello`, `state`, `bye`. |

Fingerprint: `hosts_rev = UNIX_TIMESTAMP(MAX(hosts.updated_at)) + COUNT(hosts)` e `logs_rev = MAX(logs.id) + COUNT(logs)`; `version = md5(hosts_rev|logs_rev)`. Qualquer escrita (status, CRUD, log de auditoria) o altera.

**Resposta `action=version`:**
```json
{
  "success": true,
  "message": "Versão do estado",
  "timestamp": "2026-11-09 10:15:00",
  "data": {
    "version": "9f2c...",
    "hosts_rev": "1762683300-24",
    "logs_rev": "812-812",
    "degraded": false,
    "server_time": "2026-11-09T10:15:00+01:00",
    "stream": "state.php?action=stream"
  }
}
```

**Stream (`curl -N` para ver os eventos em tempo real):**
```bash
curl -N "http://localhost/labcontrol/labcontrol-backend/api/state.php?action=stream"
```
```
retry: 3000

event: hello
data: {"version":"9f2c...","hosts_rev":"1762683300-24","logs_rev":"812-812","poll_interval":2,"max_duration":120}

event: state
data: {"version":"ab41...","hosts_rev":"1762683312-24","logs_rev":"812-812"}

: keep-alive

event: bye
data: {"reason":"max_duration"}
```

`bye` é emitido ao fim de `SSE_MAX_DURATION` segundos para libertar o worker Apache; o `EventSource` reconecta de imediato. Configuração em `.env`: `SSE_POLL_INTERVAL`, `SSE_HEARTBEAT`, `SSE_MAX_DURATION`, `SSE_RETRY_MS`.

> **Não use `check-all` em loops.** Esse endpoint faz ping sequencial a todos os hosts dentro do pedido HTTP. Está reservado à verificação manual do operador; o estado corrente vem do `sync-service` + `state.php`.

---

## 4. Estrutura de Resposta (JSON)

Todas as respostas seguem o padrão abaixo:

```json
{
  "success": true,
  "message": "Operação realizada com sucesso",
  "timestamp": "2026-03-06 11:30:00",
  "data": { ... }
}
```

**Erros:**
Em caso de erro (HTTP 4xx ou 5xx), o campo `success` será `false`.

---

## 5. Exemplos Práticos (cURL)

### Listar Processos de um PC
```bash
curl -X GET "http://localhost/labcontrol/labcontrol-backend/api/control.php?action=processes&hostname=PC-LAB01"
```
Resposta de sucesso (a `data.processes` é sempre uma lista, mesmo com 0 ou 1 processo):
```json
{
  "success": true,
  "message": "Processos obtidos",
  "data": {
    "host": "PC-LAB01",
    "ip": "192.168.21.40",
    "process_count": 2,
    "processes": [{ "Id": 4, "ProcessName": "explorer", "CPU": 1.5, "MemoryMB": 82.31 }]
  }
}
```
Erros desta acção deixaram de ser `500` genéricos: `412` sem credenciais configuradas,
`403` credenciais inválidas, `503` WinRM indisponível, `502` falha/resposta inválida do
PowerShell, `504` timeout. O corpo inclui `data.error_type` e `data.error` com o diagnóstico
(a password nunca é exposta) e o detalhe completo fica em `logs/error_AAAA-MM-DD.log`.
Consulte `DOCUMENTACAO_OPERACOES_REMOTAS.md` §7 para o passo a passo de resolução.

### Descobrir IP e MAC de um Hostname
```bash
curl -X GET "http://localhost/labcontrol/labcontrol-backend/api/hosts.php?action=discover&hostname=PC-LAB01"
# Resposta: { "success": true, "data": { "hostname": "PC-LAB01", "ip": "192.168.21.40", "mac_address": "00:1A:2B:3C:4D:5E", "warnings": [] } }
```

### Enviar Shutdown Remoto
```bash
curl -X POST http://localhost/labcontrol/labcontrol-backend/api/control.php?action=shutdown \
     -H "Content-Type: application/json" \
     -d '{"hostname": "PC-LAB01"}'
```

---

## 6. Permissões
No modo laboratório não há papéis `admin`/`operator`: todas as funcionalidades estão disponíveis localmente sem sessão. Restrinja o acesso pela rede/Firewall e teste primeiro as ações destrutivas numa única estação.

---
*Documentação Gerada em: 06/03/2026*
