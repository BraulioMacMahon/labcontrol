# Documentação de Frontend - LabControl

## 1. Visão Geral
O frontend do LabControl é uma **Single Page Application (SPA)** construída com tecnologias web modernas (HTML5, CSS3, JavaScript ES6+) sem a necessidade de frameworks pesados como React ou Vue, priorizando performance e simplicidade de manutenção local.

## 2. Estrutura de Pastas e Arquivos

*   `index.html`: Ponto de entrada único. Contém a estrutura base (layout), as "views" principais e o container para componentes dinâmicos.
*   `app.js`: O "cérebro" da aplicação. Gerencia o estado (state), rotas internas, monitoramento de rede e renderização de componentes.
*   `api-service.js`: Camada de comunicação com o backend PHP, timeouts e tratamento de erros. Não utiliza tokens nem dados de sessão.
*   `realtime.js`: Cliente **Server-Sent Events** (`EventSource`) com reconexão, pausa em abas ocultas e fallback para polling leve. Não toca no DOM: comunica por callbacks `onState` / `onStatus`.
*   `package.json`: Marca o diretório como ES modules e expõe `npm test` (testes de `realtime.js` em `tests/frontend/`).
*   `vendor/`: Contém bibliotecas e ativos locais para funcionamento offline:
    *   `output.css`: Arquivo compilado do Tailwind CSS.
    *   `chart.js`: Biblioteca para gráficos de performance.
    *   `fonts.css` e `.woff2`: Fontes locais (Plus Jakarta Sans).
*   `img/`: Logotipos e ícones estáticos.

## 3. Uso do Tailwind CSS

A interface utiliza **Tailwind CSS v3** para estilização através de classes utilitárias.

### Instalação e Build Offline
Para garantir que o sistema funcione em redes isoladas (laboratórios sem internet), o Tailwind é compilado durante o desenvolvimento e o resultado é salvo em `vendor/output.css`.

**Comando de Build:**
```bash
npx tailwindcss -i ./src/input.css -o ./vendor/output.css --watch
```

### Componentes de UI Customizados
Além das classes padrão, foram criadas classes de design "Glassmorphism":
*   `.glass-panel`: Painéis com desfoque de fundo e bordas sutis.
*   `.glass-card`: Cartões interativos para métricas e hosts.
*   `.active-nav`: Indicador visual de navegação ativa no menu lateral.

## 4. Fluxo de Interação e Monitoramento

### 4.1. Atualizações em tempo real (SSE, sem recarregar)
O loop antigo (`startNetworkMonitor` → `checkAllHosts()` a cada 10 s) foi **removido**. Ele fazia ping a todas as máquinas dentro de um pedido HTTP, empilhava pedidos quando o PHP demorava, redesenhava Dashboard + sidebar + gráfico e abria uma sessão WinRM por ciclo — era a origem do "carregamento" constante e do piscar da página.

Modelo atual:

```
sync-service (Node, SYNC_INTERVAL)  ──UPDATE hosts──>  MySQL
API / comandos do operador          ──INSERT logs───>  MySQL
                                                         │
                          state.php lê 1 fingerprint leve │ (2 s)
                                                         ▼
        state.php?action=stream  ──event: state──>  realtime.js (EventSource)
                                                         │ onState({changed})
                                                         ▼
        app.js.applyStateChange() → getHosts()/getLogs() → safeUpdateHTML()
                                   → só os nós que mudaram
```

1. **`state.php?action=version`** dá o fingerprint inicial (`version`, `hosts_rev`, `logs_rev`) com suporte a `ETag`/`304`.
2. **`state.php?action=stream`** mantém a ligação aberta e emite `hello`, `state` (só quando o fingerprint muda), `bye` e keep-alives. `EventSource` reconecta sozinho.
3. **`applyStateChange()`** pede **apenas** o grupo alterado e compara assinaturas antes de escrever no DOM. Sem `navigate()`, sem skeleton, sem `location.reload()`.
4. **Fallback**: se o SSE não abrir (proxy com buffering, browser antigo), passa a um polling de 15 s contra `action=version` (1 query, 304 quando nada mudou) e volta a tentar SSE a cada 60 s. Nunca volta a `check-all`. Se o backend ainda não tiver `state.php` (404), entra em **modo de compatibilidade**: `check-all` a 30 s com guarda de concorrência e o mesmo refresco incremental — funcional e sem piscar até o backend ser atualizado.
5. **Indicador** `LIVE · SSE` / `POLL · 15s` / `OFFLINE` no cabeçalho; o relógio `SYNC:` só muda quando há dados novos (antes era um `setInterval` de 1 s).
6. **Processos WinRM** ficam num timer separado de 60 s, só com a aba visível e a vista de detalhe aberta, com guarda contra sessões sobrepostas.
7. O botão de sincronização do cabeçalho executa **um** `check-all` manual, a pedido do operador.

Afinações em `index.html`: `window.LABCONTROL_REALTIME_FALLBACK_MS`, `window.LABCONTROL_PROCESS_REFRESH_MS`, `window.LABCONTROL_CHART_SIMULATION`. Detalhes em [**Documentação de Tempo Real**](DOCUMENTACAO_TEMPO_REAL.md).

### 4.2. Navegação Interna
A navegação é gerenciada pela função `navigate(view, id)`:
1.  Esconde todas as seções `.page-view`.
2.  Carrega os dados necessários via API.
3.  Renderiza o componente correspondente no DOM.
4.  Aplica animações de transição (`fade-in-up`, `slide-in`).

## 5. Exemplos de Telas e Funcionalidades

| Tela | Funcionalidade Principal |
| :--- | :--- |
| **Acesso local** | Entrada direta no Dashboard, sem login, senha, token ou expiração de sessão. |
| **Dashboard** | Visão geral da saúde do laboratório (KPIs de máquinas online/offline). |
| **Lista de Hosts** | Inventário completo com filtros de busca e status em tempo real. |
| **Detalhes do Host** | Gráficos de CPU/RAM em tempo real, lista de processos e botões de ação (Desligar, Reiniciar). |
| **Audit Log** | Histórico de todas as ações administrativas realizadas no sistema. |

---
*Documentação Gerada em: 06/03/2026*
