# Análise de Prontidão para Deploy — LabControl

**Data:** 23/08/2026
**Escopo:** Backend PHP, Frontend SPA, Sync-service (Node), Banco de Dados, Infra/Deploy
**Veredito:** 🔴 **NÃO PRONTO PARA PRODUÇÃO** — existem bloqueadores críticos que quebram funcionalidade e expõem segredos/credenciais.

> Nota de contexto: os documentos `docs/SUMARIO_EXECUTIVO.md` e `docs/AUDITORIA_PLATAFORMA.md` afirmam "100% seguro / aprovado para produção local". Já o `docs/CHECKLIST_DEPLOYMENT.md` diz "🔴 Não Pronto". Esta análise confirma, com evidência no código, que o checklist está correto: há itens funcionais e de segurança abertos que impedem um deploy seguro.

---

## 1. Bloqueadores Críticos (P0 — impedem deploy seguro)

### B1. Endpoints de hosts quebrados por métodos ausentes na `Database`
`hosts.php` chama métodos que **não existem** na classe `Database` ativa:
- `set-credentials` → `$db->saveDefaultCredentials(...)` (hosts.php ~linha 470)
- `get-credentials` → `$db->getAllCredentials()` (hosts.php ~linha 510)
- `set-host-credentials` → `$db->updateHostCredentials(...)` (hosts.php ~linha 560)
- `import` (importação em massa) → `$db->beginTransaction() / commit() / rollback()` (hosts.php ~linha 410)

Nenhum desses métodos está definido em `labcontrol-backend/includes/Database.php` (grep confirmado: `defined=0`). Resultado: **erro fatal 500** em runtime nesses 4 endpoints. A funcionalidade de credenciais padrão, credenciais por host e importação em massa **não funciona**.

**Causa raiz:** os métodos existem em `labcontrol-backend/includes/Database_backup.php` (linhas 167–181 e 408–437), mas foram removidos num refactor da classe ativa e `hosts.php` não foi atualizado. `Database_backup.php` não é incluído em lugar nenhum.

**Correção:** portar `beginTransaction/commit/rollback/saveDefaultCredentials/updateHostCredentials/getAllCredentials` de volta para `Database.php` (ou apontar `hosts.php` para a classe completa). Adicionar um smoke test que exercite cada endpoint.

### B2. Scripts de debug em `tests/` são web-acessíveis e perigosos
A pasta `tests/` está na **raiz do repo** (`/tests/`), fora de `labcontrol-backend/`. O `.htaccess` de proteção vive em `labcontrol-backend/` e só bloqueia `config/`, `bootstrap/`, `middleware/`, `classes/`, `cache/`, `firebase/`, `.env`. Seguindo o `GUIA_INSTALACAO.md` (copiar projeto para `htdocs/labcontrol`), `tests/` fica em `htdocs/labcontrol/tests/` — **acessível via web**.

Vários scripts são destrutivos/info-disclosure:
- `tests/backend/debug-password.php` (linhas 77–80) **(re)insere `admin@labcontrol.local` com a senha `admin123`** e imprime hashes.
- `tests/backend/debug-login.php`, `tests/root/debug-password-check.php`, `tests/root/fix-passwords.php` expõem/validam senhas.
- `tests/backend/reset-rate-limit.php`, `force-clear-cache.php`, `fix-database.php`, `migrate-tables.php` executam ações de infra.

**Impacto:** um atacante que encontre `/tests/...` pode resetar a senha de admin para `admin123` (takeover), enumerar usuários ou disparar manutenção destrutiva.

**Correção:** nunca implantar a pasta `tests/` em produção (ou mover para fora do docroot e adicionar `.htaccess` de negação). Remover scripts que recreiam credenciais conhecidas.

### B3. Credenciais padrão semeadas no banco (`database.sql`)
`labcontrol-backend/sql/database.sql` insere usuários com senhas conhecidas e comprometidas no repositório:
- `admin@labcontrol.local` / `admin123`
- `operator1@labcontrol.local` / `operator123`
- `operator2@labcontrol.local` / `operator123`

Qualquer pessoa com acesso ao repo (ou ao banco após rodar o script) conhece as credenciais de admin/operador. O `CHECKLIST_DEPLOYMENT.md` exige "Senha forte (16+ caracteres)" — não atendido.

**Correção:** remover seeds de senha do SQL; exigir criação de admin no primeiro acesso com senha forte; ou documentar explicitamente que `admin123` deve ser trocado imediatamente (e falhar o deploy se a senha padrão ainda existir).

### B4. `ENCRYPTION_KEY` padrão hardcoded no código (fallback)
`labcontrol-backend/config/config.php`:
```php
define('ENCRYPTION_KEY', env('ENCRYPTION_KEY', 'labcontrol_encryption_key_32chars_long_1234567890'));
```
Se o `.env` não carregar (permissão, caminho, deploy errado), **todas as senhas de hosts remotos são criptografadas com uma chave pública, versionada no repo**. Isso contradiz diretamente o claim da auditoria "nenhuma senha administrativa exposta no código". Também há `REMOTE_USER` padrão `AdminLab17` no fallback.

**Correção:** `die()` se `ENCRYPTION_KEY` não estiver no `.env` (como já é feito para `JWT_SECRET`), nunca usar fallback hardcoded para chaves de criptografia.

### B5. Frontend com URL de API hardcoded em `http://localhost` e sem HTTPS/HSTS
`labcontrol-frontend/api-service.js`:
```js
baseURL: 'http://localhost/labcontrol/labcontrol-backend/api',
```
- Não é configurável → **quebra fora de `localhost`** (deploy real).
- Tráfego em **HTTP puro**: o token JWT e, pior, as **credenciais de admin do Windows (`REMOTE_PASSWORD`)** trafegam em texto claro pela rede. Para uma ferramenta que executa comandos remotos (RCE) em estações Windows, isso é inaceitável fora de uma rede isolada.
- `connect-src 'self' http://localhost` no `.htaccess` e ausência de `Strict-Transport-Security` (HSTS) confirmam que HTTPS não é obrigado.

**Correção:** tornar `baseURL` configurável (arquivo de config do frontend ou env); forçar HTTPS; adicionar HSTS no `.htaccess`.

---

## 2. Severos (P1)

### S1. CORS quebrado em `control.php`, `sync.php`, `logs.php`
Esses endpoints enviam literalmente a lista de origens como header:
```php
header('Access-Control-Allow-Origin: ' . CORS_ALLOWED_ORIGINS); // ex.: "http://localhost,http://127.0.0.1"
```
Browsers **rejeitam múltiplas origens** num `Access-Control-Allow-Origin`, então o CORS cross-origin não funciona (apenas `hosts.php` usa `getCorsOrigin()` corretamente). Inconsistente e quebra o claim "CORS restrito via whitelist". Em produção com front/back em origens distintas, o controle remoto para de funcionar.

### S2. Vazamento de detalhes de erro ao cliente (info disclosure)
`auth.php`, `api/security.php`, `sync.php`, `control.php` configuram handlers que devolvem `file`, `line` e/ou `message` de exceções em JSON ao cliente (ex.: `auth.php` retorna `'file' => $errfile, 'line' => $errline`). Isso contradiz o claim da auditoria "retornando apenas mensagens amigáveis". Em produção vaza caminhos do servidor e mensagens internas.

### S3. Rate limiting contornável (spoof de IP)
`getClientIP()` (`config.php`) confia em `X-Forwarded-For` / `CF-Connecting-IP` **antes** de `REMOTE_ADDR`. O `RateLimiter` de login usa esse IP como chave. Um atacante envia um `X-Forwarded-For` diferente a cada requisição e **nunca atinge o limite de 5 tentativas** → brute-force de login efetivo. Também permite spoof dos logs de auditoria.

### S4. XSS armazenado via `hostname`/nome de processo + CSP com `unsafe-inline`
O frontend renderiza `host.name` (hostname) e nomes de processo diretamente em `innerHTML` sem escape (`app.js`: `HostItem`, `renderProcessTable`). O backend (`hosts.php`) **não valida/normaliza o hostname** (só o IP). Um hostname como `<img src=x onerror=...>` vira XSS persistente. O CSP em `.htaccess` permite `script-src 'self' 'unsafe-inline'`, facilitando a execução.
**Cadeia de risco:** XSS → roubo do JWT em `localStorage` (app.js grava token em `localStorage`) → chamada `api.executeCommand` (RCE em estações Windows).

### S5. `DEBUG_MODE=true` no `.env.example`
O template copiado para produção liga modo de debug. O default em `config.php` é `false`, mas o risco é o operador copiar o exemplo.

---

## 3. Moderados (P2)

- **Headers de segurança inconsistentes:** só `auth.php` e `api/security.php` carregam `bootstrap/security.php` (CSP, X-Frame-Options, etc.). `hosts.php`, `control.php`, `logs.php`, `sync.php` **não** definem esses headers.
- **Sem HSTS** (item do checklist não atendido).
- **`env()` lê `$_SERVER`** (`bootstrap/env.php`): colisão teórica com headers HTTP; prefira só `$_ENV`.
- **`RateLimiter` sem limpeza:** `cleanupOldCache()` é estático e nunca chamado → cache cresce indefinidamente.
- **Bug em `loadEnv`** (`bootstrap/env.php`): remove apenas aspas iniciais (`$value[0]`), não as finais → valores entre aspas ficam truncados.
- **Sem testes automatizados:** o checklist exige 80% de cobertura PHPUnit. O repo tem ~30 scripts de *debug* em `tests/`, nenhum teste automatizado.
- **sync-service:** depende de `ping@^0.4.0` (não mantido) e de `POWERSHELL_PATH` Windows; não roda em Linux sem ajustes. `package.json` ok, mas sem healthcheck/restart policy documentado.
- **Documentação inconsistente:** SUMARIO/AUDITORIA dizem "aprovado"; CHECKLIST diz "não pronto". Gerado por IA e não reflete o código.

---

## 4. O que já está correto (pontos fortes)

- ✅ `.env` está no `.gitignore` (segredos não vazam via git).
- ✅ `.htaccess` protege `config/`, `bootstrap/`, `middleware/`, `classes/`, `cache/`, `firebase/`, `.env`.
- ✅ Prepared statements (PDO) em todos os métodos da `Database` ativa → sem SQLi nos callers atuais.
- ✅ `password_hash`/`password_verify` (bcrypt) para senhas de usuário.
- ✅ RBAC presente (verificações de `role === 'admin'` em endpoints sensíveis).
- ✅ Trilha de auditoria (`logs`) com IP/usuário/ação.
- ✅ Schema SQL razoável (views, FK, índices em `synced`/`status`/`timestamp`).
- ✅ `validateJWT` usa `hash_equals` (resistente a timing attack) e verifica `exp`.

---

## 5. Plano de Ação Priorizado

### P0 (bloqueadores — antes de qualquer deploy)
1. Restaurar métodos ausentes em `Database.php` (ver `Database_backup.php`) e testar os 4 endpoints de `hosts.php`. (B1)
2. Remover/excluir `tests/` do deploy de produção; proibir scripts que recreiam `admin123`. (B2)
3. Remover credenciais semeadas do `database.sql`; forçar troca de senha padrão no primeiro acesso. (B3)
4. Eliminar fallback hardcoded de `ENCRYPTION_KEY` (e `REMOTE_USER`); `die()` se ausente. (B4)
5. Tornar `baseURL` do frontend configurável e **obrigar HTTPS + HSTS**. (B5)

### P1 (severos — antes de expor à rede)
6. Unificar CORS: usar `getCorsOrigin()` em todos os endpoints; nunca enviar lista com vírgula. (S1)
7. Parar de devolver `file`/`line`/`message` ao cliente; logar server-side e retornar mensagem genérica. (S2)
8. `getClientIP()` deve usar `REMOTE_ADDR` (ou IP confiável atrás de proxy conhecido), não header spoofável. (S3)
9. Escapar/validar `hostname` no backend e no frontend (e remover `'unsafe-inline'` do CSP, usar nonce). (S4)
10. `.env.example` com `DEBUG_MODE=false`. (S5)

### P2 (melhorias)
11. Aplicar `bootstrap/security.php` (headers) em todos os endpoints.
12. Adicionar HSTS.
13. `env()` só de `$_ENV`; corrigir stripping de aspas em `loadEnv`.
14. Agendar `RateLimiter::cleanupOldCache()`.
15. Criar suíte mínima de testes (PHPUnit + smoke de endpoints) — substituir scripts de debug.
16. Revisar dependências do sync-service (`ping` → alternativa mantida) e documentar run/restart.
17. Reconciliar documentação (um único status de prontidão, com evidências).

---

## 6. Matriz de Prontidão (checklist vs realidade)

| Item do Checklist | Estado real | Evidência |
|---|---|---|
| Nenhuma senha no código | ❌ | `ENCRYPTION_KEY` fallback hardcoded (config.php); `admin123` no database.sql; `REMOTE_USER=AdminLab17` |
| JWT_SECRET ≥32 chars / obrigatório | ✅ | `config.php` exige e `die()` se curto |
| CORS restrito (não `*`) | ⚠️ | `hosts.php` ok; `control/sync/logs` enviam lista com vírgula (quebrado) |
| X-Frame-Options / nosniff / CSP | ⚠️ | Presentes só em auth/security; ausentes nos demais endpoints |
| HSTS (HTTPS) | ❌ | Ausente; frontend em `http://localhost` |
| Validator / input validado | ⚠️ | IP validado; **hostname não validado** (XSS) |
| Sem injeção SQL | ✅ | Prepared statements nos callers |
| Senhas encriptadas (AES) | ⚠️ | AES ok, mas chave tem fallback público (B4) |
| Logs não expõem credenciais | ⚠️ | `debug-*` expõem hashes (B2); handlers vazam erros (S2) |
| Todos os endpoints validam JWT | ✅ | Presente em todos |
| RBAC funcionando | ✅ | `role === 'admin'` nos sensíveis |
| 2FA | ❌ | Não implementado (conhecido) |
| Testes (80% PHPUnit) | ❌ | Só scripts de debug, zero automatizados |
| Sem código morto | ❌ | `Database_backup.php`, `config_backup.php`, `tests/*` |

---

## 7. Conclusão
O LabControl **não está pronto para produção**. Há um bug funcional crítico (endpoints de credenciais/import quebrados por um refactor incompleto), exposição de scripts de debug web-acessíveis que podem assumir o admin, credenciais padrão conhecidas no seed do banco, uma chave de criptografia com fallback público e arquitetura sem HTTPS. Os pontos fortes (prepared statements, bcrypt, RBAC, trilha de auditoria, `.env` ignorado) são reais, mas insuficientes para um deploy seguro. Recomenda-se tratar todos os P0 antes de qualquer exposição à rede e os P1 antes de uso por usuários reais.

*Análise baseada em leitura direta do código em `labcontrol-backend/`, `labcontrol-frontend/`, `sync-service/` e `docs/` (branch atual).*

---

## 8. Correções aplicadas nesta sessão

As seguintes remediações foram implementadas na branch `arena/01a02d64-labcontrol`:

| ID | Correção | Arquivo(s) |
|----|----------|-----------|
| B1 | Restaurados `beginTransaction/commit/rollback/inTransaction` e `getDefaultCredentials/saveDefaultCredentials/updateHostCredentials/getAllCredentials/deleteCredentials` na `Database.php` ativa (estavam só no `Database_backup.php`) → endpoints `set-credentials`, `get-credentials`, `set-host-credentials` e `import` deixam de dar 500 | `labcontrol-backend/includes/Database.php` |
| B2 | Adicionado `tests/.htaccess` (negação total) para bloquear acesso web aos scripts de debug | `tests/.htaccess` (novo) |
| B3 | Removidas credenciais padrão semeadas (`admin123`/`operator123`) do `database.sql` | `labcontrol-backend/sql/database.sql` |
| B4 | `ENCRYPTION_KEY`/`JWT_SECRET` sem fallback hardcoded no código: se ausentes/fracas, **auto-gera chaves únicas por instalação e persiste no `.env`** (self-bootstrap) — elimina a chave pública E evita que o backend quebre | `labcontrol-backend/config/config.php` |
| B5 | `baseURL` da API agora usa `location.origin` (herda HTTPS/host) e é sobrescrevível via `window.LABCONTROL_API_BASE_URL`; adicionado HSTS no `.htaccess` | `labcontrol-frontend/api-service.js`, `labcontrol-backend/.htaccess` |
| S1 | `Access-Control-Allow-Origin` corrigido para `getCorsOrigin()` em `control.php`, `sync.php`, `logs.php` (antes enviavam a lista com vírgula) | `api/control.php`, `api/sync.php`, `api/logs.php` |
| S2 | Handlers de erro/exceção deixam de expor `file`/`line`/`message`; registram via `error_log` e retornam mensagem genérica | `api/auth.php`, `api/security.php`, `api/sync.php`, `api/control.php` |
| S3 | `getClientIP()` usa `REMOTE_ADDR` (elimina spoof via `X-Forwarded-For`, reforça rate limiting e logs) | `labcontrol-backend/config/config.php` |
| S5 | `DEBUG_MODE=false` no `.env.example` | `.env.example` |
| S4 | Validação de `hostname` no backend (regex) em create/update/import; escape HTML de `host.name` e nome de processo no frontend | `api/hosts.php`, `labcontrol-frontend/app.js` |

**Impedimentos funcionais (pontos 1–3) também resolvidos nesta sessão:**
- **Ponto 1 (instalação nova não loga):** criado `labcontrol-backend/setup.php` (CLI ou web, localhost-only, só first-run) que cria o primeiro admin com senha forte (mín. 12 chars). Substitui o antigo `tests/backend/setup.php` inseguro (recriava `admin123`), hoje bloqueado pelo `.htaccess`.
- **Ponto 2 (requer `.env` completo):** o mesmo `setup.php` gera o `.env` (se ausente) e cria `JWT_SECRET`/`ENCRYPTION_KEY` fortes automaticamente, evitando o `die()` do `config.php`.
- **Ponto 3 (sync-service):** `sync-service/index.js` agora sobrevive a falhas pontuais de DB (não faz `process.exit(1)`); guia de instalação atualizado com o passo de setup e a exigência de o worker estar em execução (Windows/PowerShell).

**Pendências restantes:** 2FA (item conhecido do checklist), suíte de testes automatizados (substituir scripts de debug), `RateLimiter::cleanupOldCache()` agendado, `env()` lendo só `$_ENV`, bug de stripping de aspas em `loadEnv`, e headers de segurança aplicados em todos os endpoints. Recomenda-se tratar antes de expor à rede de produção.

> Regressão corrigida nesta sessão: a exigência estrita de `ENCRYPTION_KEY` (com `die()`) quebrava instalações que copiavam o `.env.example` (que **não** inclui essa chave), fazendo o backend inteiro retornar erro e a página não carregar. O `config.php` agora faz self-bootstrap das chaves (gera e persiste no `.env` quando ausentes), preservando a ausência de chave pública no repositório. Um `.env` de exemplo com chaves fortes também foi criado no ambiente de trabalho (gitignored).
