# `tests/root/` — utilitários de raiz

Os 23 scripts curtos herdados das primeiras sessões de instalação do LabControl:
testes de caminho, de ping, de sessão PHP e inspeção/repair de `logs` e de contas.
Vivem aqui porque foram escritos antes de `tests/` ter qualquer organização — **não**
porque pertençam ao produto.

## Como são apresentados

A página `/labcontrol/tests` não lê este diretório de forma arbitrária: a ficha de cada arquivo está
em `../catalog.json` (chave `root/<nome>.php`), com `mode`, o que se espera ver (`expect`)
e os efeitos colaterais. Um `.php` novo nesta pasta aparece no painel mesmo sem ficha,
marcado como «sem catálogo» e com o modo estimado a partir das palavras-chave do código.

## Convenções para quem acrescentar um script

- assume `cwd = tests/root/` (o painel faz `chdir` para a pasta do script antes do `include`),
  por isso os `require_once '../../labcontrol-backend/...'` existentes continuam válidos;
- **não** uses `session_*`: partilharias a sessão PHP com a própria bancada e o script
  rebentaria o portão. Se for preciso testar sessões, marca `mode: "cli"` no catálogo;
- escreve em saída de texto plano ou HTML simples — o painel normaliza e mostra ao lado
  da ficha; evita `<script>`, porque é removido quando a saída é embutida;
- se alteras dados, sê idempotente e declara `mode: "write"` ou `"destructive"`. É isso
  que obriga à confirmação (e, em `destructive`, à senha outra vez + bilhete de 60 s);
- precisa do MySQL? declara `requires: ["database"]`: o painel bloqueia com 503 antes de
  entrar no script, em vez de apanhar um fatal no meio de um `ALTER TABLE`.

## Scripts que já não deviam ser precisos

| Ficheiro | Estado |
| :--- | :--- |
| `fix-passwords.php` | Reescreve as três contas de arranque. Preferir `labcontrol-backend/setup.php` (first-run) ou a UI de Utilizadores. |
| `manual-check.php`, `test-all-logins.php`, `test-invalid-credentials.php`, `test-failed-login-tracking.php` | Exercitam o `auth.php` à mão; a página `/labcontrol/tests` já mostra o mesmo no cartão *API de autenticação*. |
| `backend/force-clear-cache.php` (não `root/`) | Chamá-lo do browser faz `session_destroy()` na sessão da própria bancada; ficou `mode: "cli"` precisamente por isso. |

Nada disto foi apagado — são o registro de como o problema foi descoberto, e ainda servem
quando é preciso isolar uma variável fora do painel.
