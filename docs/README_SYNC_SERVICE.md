Sync Service (labcontrol)

This Node.js service periodically polls active hosts, tests connectivity (ICMP ping + PowerShell Test-Connection fallback), and updates `status` and `last_seen` in the MySQL `hosts` table.

Quick start

1. Install dependencies

```bash
cd sync-service
npm install
```

2. Copy env example and adjust values

```bash
cp .env.example .env
# edit .env with DB credentials
```

3. Run

```bash
npm start
```

Notes
- This is a scaffold: Firebase sync and more advanced error handling should be implemented as needed.
- On Windows, set `POWERSHELL_PATH` in `.env` if PowerShell is not in PATH.
- `SYNC_INTERVAL` controls the polling (60 s by default). This worker — not the browser — is the source of truth for host status. The frontend receives changes over SSE from `api/state.php`; see [Documentação de Tempo Real](DOCUMENTACAO_TEMPO_REAL.md).
- `LAST_SEEN_REFRESH_MS` (default 300000 ms) prevents rewriting `last_seen` on every poll. Each write touches `hosts.updated_at`, which is the SSE state fingerprint; without this throttle every online host would trigger a spurious refresh in every open browser every minute.
- Run the unit tests with `npm test` (no MySQL or PHP required).
