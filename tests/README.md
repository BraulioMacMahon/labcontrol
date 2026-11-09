# Bancada de testes (`/labcontrol/tests`)

Porta separada para os utilitários de diagnóstico e manutenção do LabControl.
Abre no endereço **`http://localhost/labcontrol/tests`** — sem `index.php` no URL,
sem pasta extra ao lado do projeto — e não depende de nenhum segredo novo:
o acesso é feito com a própria conta admin da aplicação.

## Por que é uma área separada

Os scripts em `root/`, `backend/` e `frontend/` apagam caches, alteram schemas e
podem recriar contas. Enquanto estiveram acessíveis por URL direto eram um risco
(derrubar a senha do admin a partir do navegador). Hoje:

- o `.htaccess` nega `root/`, `backend/` e `frontend/` e serve tudo por `index.php`;
- `index.php` exige uma sessão própria da bancada antes de mostrar ou executar qualquer coisa;
- a sessão da bancada **não** é a sessão da aplicação: o backend continua sem cookies,
  e um login aqui não dá acesso ao resto do LabControl (nem o contrário).

## Como entrar

1. `http://localhost/labcontrol/tests` mostra apenas o portão de entrada.
2. Informe o e-mail + senha da conta LabControl (perfil `admin`).
3. O portão entrega o token ao `api.php?action=handshake` desta pasta, que o valida com o
   `validateJWT()` do backend (ou replicando HS256 com o mesmo `JWT_SECRET`), confirma o
   papel e abre uma sessão PHP chamada `labcontrol_tests`.
4. O cookie fica restrito a `Path=/labcontrol/tests`, com `HttpOnly` e `SameSite=Lax`.

Nada da aplicação é alterado: nenhum `session_start()` no backend, nenhuma tabela nova.
O token da app é usado apenas no aperto de mão e não fica salvo nesta área.

## O que a página mostra

| Bloco | Conteúdo |
| :--- | :--- |
| Estado do servidor | PHP, extensões, `.env`, banco de dados, cache, logs, PowerShell/WinRM, CORS, portões — com botão «corrigir» que abre o utilitário certo. |
| Diagnóstico de conectividade | As três sondas que viviam na aba *Testes* do app: API, WinRM (leitura de processos) e WOL manual. Continuam passando pela API normal. |
| Utilitários | Os 38 scripts, agrupados, com o que fazem, o que se espera ver e que efeitos têm. Clique para executar e ler a saída ao lado. |

Modos e o que cada um exige:

| Modo | Execução direta? |
| :--- | :--- |
| `read` | sim, somente leitura |
| `sensitive` | sim, mas a saída só aparece no painel (nunca em página nova) |
| `write` | exige clique de confirmação + bilhete de 60 s |
| `destructive` | exige digitar `EXECUTAR`, **reinserir a senha** e um bilhete de 60 s |
| `cli` | bloqueado no navegador; o painel mostra o comando `php ...` para rodar no terminal |

O bilhete é assinado (HMAC), dura 60 segundos, liga o script à sessão e é consumido na
primeira utilização. Tentar abrir `index.php?run=1&view=...` manualmente sem bilhete
retorna uma página que explica o motivo — não executa nada.

## Interruptores e limites

- `tests/DISABLED` (mesmo vazio) ou `TESTS_ENABLED=false` → a área responde 503 a tudo.
- `TESTS_ALLOWED_IPS=127.0.0.1,192.168.20.0/24` → restringe por origem do pedido.
- `TESTS_ALLOWED_ROLES=admin` (padrão) → papel exigido no aperto de mão.
- `TESTS_SESSION_MINUTES=480`, `TESTS_IDLE_MINUTES=90` → validade e inatividade da sessão.
- `TESTS_GRANT_SECONDS=60`, `TESTS_REAUTH_MAX_AGE=300` → validade do bilhete e frescura
  exigida do token usado na reconfirmação de senha.

Os nomes de variáveis e os valores estão documentados em `.env.example`.

## Estrutura

```
tests/
  index.php      entrada humana: painel, execução de scripts, deep link ?view=
  api.php        JSON do painel (bootstrap, checks, script, handshake, grant, logout)
  gate.php       sessão, portões, CSRF, bilhetes, resolução segura de caminho
  registry.php   catálogo curado + scripts descobertos no disco
  checks.php     sondas de ambiente (com cache de 45 s)
  catalog.json   ficha curada dos 38 utilitários
  ui/            shell.html, style.css, app.js
  root/ backend/ frontend/   os utilitários
```

`gate.php`, `registry.php`, `checks.php`, `catalog.json` e o `.state/` não são servíveis por
URL; apenas `index.php` e `api.php` respondem, e sempre depois dos portões.

## Produção

Para uma instalação de produção o recomendado continua sendo não publicar a pasta.
Se for preciso deixá-la no disco, ela fica inofensiva com `tests/DISABLED` criado
e/ou `TESTS_ENABLED=false`, mais `TESTS_ALLOWED_IPS=127.0.0.1`.
