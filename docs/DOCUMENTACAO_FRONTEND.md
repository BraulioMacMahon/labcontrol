# Documentação de Frontend - LabControl

## 1. Visão Geral
O frontend do LabControl é uma **Single Page Application (SPA)** construída com tecnologias web modernas (HTML5, CSS3, JavaScript ES6+) sem a necessidade de frameworks pesados como React ou Vue, priorizando performance e simplicidade de manutenção local.

## 2. Estrutura de Pastas e Arquivos

*   `index.html`: Ponto de entrada único. Contém a estrutura base (layout), as "views" principais e o container para componentes dinâmicos.
*   `app.js`: O "cérebro" da aplicação. Gerencia o estado (state), rotas internas, monitoramento de rede e renderização de componentes.
*   `api-service.js`: Camada de comunicação com o backend PHP. Gerencia tokens JWT, retentativas e tratamento de erros globais.
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

### 4.1. Monitoramento de Rede (Network Monitor)
O `app.js` inicia um loop de monitoramento automático (`startNetworkMonitor`) que executa a cada 10 segundos:
1.  Chama `api.checkAllHosts()`.
2.  O backend faz o ping em todas as máquinas.
3.  O frontend recebe o status atualizado e re-renderiza apenas as partes necessárias (Dashboard ou Detalhes do Host) sem recarregar a página.

### 4.2. Navegação Interna
A navegação é gerenciada pela função `navigate(view, id)`:
1.  Esconde todas as seções `.page-view`.
2.  Carrega os dados necessários via API.
3.  Renderiza o componente correspondente no DOM.
4.  Aplica animações de transição (`fade-in-up`, `slide-in`).

## 5. Exemplos de Telas e Funcionalidades

| Tela | Funcionalidade Principal |
| :--- | :--- |
| **Login** | Autenticação JWT com persistência em LocalStorage. |
| **Dashboard** | Visão geral da saúde do laboratório (KPIs de máquinas online/offline). |
| **Lista de Hosts** | Inventário completo com filtros de busca e status em tempo real. |
| **Detalhes do Host** | Gráficos de CPU/RAM em tempo real, lista de processos e botões de ação (Desligar, Reiniciar). |
| **Audit Log** | Histórico de todas as ações administrativas realizadas no sistema. |

---
*Documentação Gerada em: 06/03/2026*
