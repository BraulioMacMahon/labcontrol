# Atualizações em Tempo Real — LabControl

**Aplicado em:** 09/11/2026 · **Ficheiros:** `labcontrol-backend/api/state.php`, `labcontrol-frontend/realtime.js`, `app.js`, `api-service.js`, `index.html`, `sync-service/src/pollHosts.js`

---

## 1. Resposta curta à pergunta

> «O sistema fica sempre a carregar e a página pisca. Dá para aplicar WebSocket ou outra forma de manter a comunicação ativa, sem recarregar a página?»

**Sim, e já está aplicado.** Foi implementado **Server-Sent Events (SSE)** com `EventSource`: uma ligação HTTP que fica aberta e pela qual o servidor avisa o browser **apenas quando os dados mudam**. O browser nunca recarrega a página; atualiza só os elementos afetados.

WebSocket também seria possível, mas neste projeto **SSE é a escolha mais simples e correta**: o backend é PHP/Apache (sem loop de eventos nem `Upgrade` nativo) e o fluxo de dados é **unidirecional** (servidor → painel). Os comandos do operador (shutdown, WOL, processos) continuam a ser `POST`/`fetch` normais e não precisam de socket. A secção 7 explica quando e como migrar para WebSocket se um dia for necessário.

---

## 2. Diagnóstico: o que causava o "carregar" e o piscar

| # | Causa (antes) | Consequência |
|---|---|---|
| 1 | `startNetworkMonitor()` → `setInterval(checkAllHosts, 10000)` | A cada 10 s, `hosts.php?action=check-all` fazia **ping sequencial a todos os hosts dentro do pedido HTTP** (até 2 s de timeout por máquina). |
| 2 | Sem guarda de concorrência | Se o PHP demorasse mais de 10 s, novos pedidos entravam na fila e empilhavam-se; o Apache ficava com workers ocupados e a UI parecia "sempre a carregar". |
| 3 | Cada ciclo chamava `loadDashboardData()` (2 pedidos) + `renderSidebar()` + `renderDashboard()` + `updateMainChart()` | Re-render completo a cada 10 s, mesmo sem alterações. |
| 4 | `updateMainChart()` usava `Math.random()` (a *view* `v_host_stats` não fornece `avg_cpu`/`avg_mem`) e `chart.update('active')` | O gráfico animava-se sozinho a cada ciclo = piscar visível. |
| 5 | `startSyncTimer()` reescrevia o relógio do cabeçalho a cada **1 s** | Mudança constante de DOM. |
| 6 | `navigate()` escondia **todas** as views e mostrava o skeleton em cada navegação | Flash branco/fade ao clicar em qualquer menu. |
| 7 | `updateHostDetailDynamic()` abria uma sessão **WinRM** a cada 10 s na vista de detalhe | Operação remota cara repetida sem necessidade. |
| 8 | `check-all` inseria um log de auditoria em cada ciclo | A tabela `logs` crescia sem motivo, alimentando ainda mais o ciclo. |

---

## 3. Arquitetura atual

```
 ┌────────────────────┐   UPDATE hosts.status / last_seen    ┌──────────┐
 │ sync-service (Node)│ ────────────────────────────────────> │          │
 │  SYNC_INTERVAL=60s │                                        │  MySQL   │
 └────────────────────┘   INSERT logs (API / operador) ──────> │          │
                                                               └────┬─────┘
                                        1 query leve a cada 2 s     │
                                                               ┌────▼─────────────┐
  ┌──────────────┐  GET state.php?action=version (ETag/304)   │ state.php (PHP)  │
  │  realtime.js │ <────────────────────────────────────────> │                  │
  │  EventSource │  GET state.php?action=stream (SSE)         │ hello/state/bye  │
  └──────┬───────┘      event: state  (só quando muda)        └──────────────────┘
         │ onState({ changed: ['hosts'] })
  ┌──────▼───────────────────────────────────────────────┐
  │ app.js.applyStateChange()                             │
  │  · getHosts() / getLogs() só do grupo alterado        │
  │  · compara assinaturas → evita re-render se igual     │
  │  · safeUpdateHTML() em KPIs, sidebar, feed de logs    │
  │  · patch do badge de status (terminal intacto)        │
  │  · chart.update('none') — sem animação                │
  └───────────────────────────────────────────────────────┘
```

**Fingerprint do estado** (uma só `SELECT`, sargable):

```sql
SELECT
  COALESCE((SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM hosts), 0) AS hosts_ts,
  (SELECT COUNT(*) FROM hosts)                                      AS hosts_count,
  COALESCE((SELECT MAX(id) FROM logs), 0)                           AS logs_max_id,
  (SELECT COUNT(*) FROM logs)                                       AS logs_count
```

`hosts_rev = hosts_ts-hosts_count`, `logs_rev = logs_max_id-logs_count`, `version = md5(hosts_rev|logs_rev)`. As contagens detetam eliminações (que não alterariam os `MAX`). Como `hosts.updated_at` tem `ON UPDATE CURRENT_TIMESTAMP`, **todas** as fontes de escrita são detetadas automaticamente: `sync-service`, CRUD da API, `check-all` manual e logs de auditoria.

> **Escritas espúrias eliminadas:** o `pollHosts.js` atualizava `last_seen` de *todos* os hosts online em cada ciclo de 60 s, o que mudava `updated_at` e emitiria um `state` por minuto sem qualquer alteração visível. Agora `needsUpdate()` só escreve quando o status muda ou quando `last_seen` passa de `LAST_SEEN_REFRESH_MS` (5 min por omissão). Testes em `tests/sync-service/pollHosts.test.mjs`.

---

## 4. Comportamento do cliente (`realtime.js`)

| Situação | Comportamento |
|---|---|
| Arranque | `seed()` lê `action=version` para estabelecer a baseline sem notificar; só depois abre o SSE (`&since=<versão>`). |
| `hello` | Compara com a baseline: se a versão divergir (mudanças enquanto a página esteve fechada), atualiza. |
| `state` | Notifica `changed: ['hosts']`, `['logs']` ou ambos. Versão repetida = **silêncio**. |
| `bye` | Fim do `SSE_MAX_DURATION`: fecha e reconecta imediatamente (liberta o worker Apache). |
| Queda de rede | `EventSource` reconecta sozinho com `retry: 3000`; backoff e indicador `A RELIGAR`. |
| SSE bloqueado (proxy com buffering, 404, browser antigo) | Após 2 falhas → **polling leve** de 15 s em `action=version` (1 query, `304` quando nada mudou). Tenta voltar a SSE a cada 60 s. **Nunca** cai em `check-all`. |
| Aba oculta / `api:offline` | `pause()` fecha a ligação e limpa os timers. Ao voltar, `seed(true)` recupera o que mudou e religa. |
| **Backend antigo (404 em `state.php`)** | `disable()` sinaliza `onUnsupported` e a app ativa o **modo de compatibilidade**: `check-all` a **30 s** (não 10 s), com guarda contra pedidos empilhados e o mesmo refresco incremental. Um único 404, sem retries em loop. |
| Indicador no cabeçalho | `LIVE · SSE` (verde), `POLL · 15s` / `LEGACY · 30s` (âmbar), `OFFLINE` (vermelho), `PAUSADO`. O `SYNC:` só muda quando há dados novos. |

Não há `location.reload()`, `meta refresh`, `navigate()` automático nem skeleton em atualizações de fundo.

---

## 5. Configuração

### `.env` (raiz do repositório)
```ini
SSE_POLL_INTERVAL=2       # segundos entre leituras do fingerprint no servidor
SSE_HEARTBEAT=15          # keep-alive (comentário SSE : keep-alive)
SSE_MAX_DURATION=120      # segundos até emitir `bye` e libertar o worker
SSE_RETRY_MS=3000         # reconexão automática do EventSource
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Requested-With,If-None-Match
```

### `sync-service/.env`
```ini
SYNC_INTERVAL=60          # quem atualiza mesmo o status dos hosts
```
> Se o `sync-service` não estiver a correr, o SSE continua vivo mas não haverá mudanças de status. Use o botão de sincronização do cabeçalho para um `check-all` manual, ou coloque o serviço a correr (NSSM / `npm start`).

### `index.html` (opcional)
```js
window.LABCONTROL_REALTIME_FALLBACK_MS = 15000; // polling de fallback
window.LABCONTROL_PROCESS_REFRESH_MS  = 60000; // WinRM: caro, não é "push"
window.LABCONTROL_CHART_SIMULATION    = false; // gráfico 100% real (0% sem métricas na BD)
```

### Apache (`labcontrol-backend/.htaccess`)
Já aplicado: `SetEnvIfNoCase Content-Type ^text/event-stream$ no-gzip dont-vary` e, em `mod_php`, `output_buffering off` / `zlib.output_compression off` para `state.php`. **Comprimir ou fazer buffer de um stream SSE é a causa nº 1 de o `EventSource` parecer morto.** Em nginx, mantenha `proxy_buffering off;` para `state.php`.

---

## 6. Como verificar

```bash
# 1) Fingerprint (repetir com If-None-Match deve dar 304)
curl -i ".../api/state.php?action=version"
curl -i -H 'If-None-Match: "<etag da resposta anterior>"' ".../api/state.php?action=version"

# 2) Stream: deve emitir `hello` de imediato e `state` só após uma escrita
curl -N ".../api/state.php?action=stream"

# 3) Provocar uma mudança noutro terminal e ver o evento `state` chegar
curl -X POST ".../api/hosts.php?action=create" -H 'Content-Type: application/json' \
     -d '{"hostname":"LAB-TESTE","ip":"192.168.21.250"}'

# 4) Testes unitários (sem PHP/MySQL/browser)
cd labcontrol-frontend && npm test      # 8 testes: realtime.js
cd sync-service && npm test              # 6 testes: pollHosts.needsUpdate
```

No browser: **DevTools → Network → `state.php?action=stream`** deve mostrar `Type: eventsource` e `Status: 200` pendente; o cabeçalho deve exibir `LIVE · SSE`. Mude o status de um host na BD e confirme que apenas os KPIs/sidebar mudam, sem skeleton nem reload.

### Resolução de problemas

| Sintoma | Causa provável | Acção |
|---|---|---|
| Fica em `POLL · 15s` | Proxy/Apache a comprimir ou a fazer buffer do stream; `mod_deflate` sem a regra; nginx sem `proxy_buffering off` | Ver `.htaccess`; testar `curl -N` direto no servidor. |
| `LIVE` mas nada muda | `sync-service` parado, ou `hosts.updated_at` em falta numa BD antiga | Arrancar o worker; confirmar schema `sql/database.sql`. |
| Muitos processos Apache ocupados | `SSE_MAX_DURATION` alto com muitos operadores | Baixar para 60–120 s; fechar abas ocultas (já automático); considerar o servidor Node da secção 7. |
| `OFFLINE` | API/MySQL indisponível | O polling recupera sozinho quando voltar. |
| `LEGACY · 30s` | Backend ainda sem `state.php` (404) | Copiar `labcontrol-backend/api/state.php` para o servidor e limpar cache (Ctrl+F5). Enquanto isso, o sistema continua funcional sem piscar. |
| Ainda parece "piscar" | Cache do browser com `app.js?v=2.1` antigo | Confirmar que `index.html` carrega `app.js?v=3.0`; limpar cache (Ctrl+F5). |

---

## 7. E quando WebSocket fizer sentido?

| | SSE (implementado) | WebSocket |
|---|---|---|
| Direção | servidor → browser | bidirecional |
| Protocolo | HTTP normal (`text/event-stream`) | `Upgrade` + `ws://`/`wss://` |
| Reconexão | nativa no `EventSource` (+ `retry`) | manual com backoff/heartbeat |
| PHP/Apache | funciona com 1 worker por ligação | **não funciona** sem processo dedicado |
| Ideal para | dashboards, status, logs, notificações | chat, presença de operadores, comandos longos com progresso, colaboração |

Para migrar: acrescentar `ws` ao `sync-service` (Node), expor `ws://…/realtime` num servidor HTTP próprio, fazer `mod_proxy` + `mod_proxy_wstunnel` no Apache (ou servir o frontend pelo próprio Node) e trocar `realtime.js` por um `LabControlRealtime` sobre `WebSocket`, mantendo a **mesma interface** (`onState`, `onStatus`, `setPauseReason`) — `app.js` não precisaria de mudar. Enquanto o painel só recebe estado, essa complexidade não traz benefício.

---

## 8. Checklist de aceitação

- [ ] `state.php?action=version` responde JSON com `version`, `hosts_rev`, `logs_rev` e `ETag`.
- [ ] Segundo pedido com `If-None-Match` devolve `304` sem corpo.
- [ ] `curl -N ...?action=stream` emite `hello`, mantém-se aberto e emite `state` após uma escrita.
- [ ] Cabeçalho mostra `LIVE · SSE`; `SYNC:` não muda a cada segundo.
- [ ] Mudar o status de um host atualiza KPIs/sidebar **sem** skeleton, fade ou reload.
- [ ] O histórico do terminal PowerShell não é apagado por atualizações de estado.
- [ ] Matar o stream (bloquear `state.php`) → `POLL · 15s`, sem `check-all` na rede.
- [ ] Esconder/mostrar a aba → `PAUSADO` → `LIVE`, com recuperação das mudanças.
- [ ] `npm test` passa em `labcontrol-frontend` (8 testes) e em `sync-service` (6 testes).
