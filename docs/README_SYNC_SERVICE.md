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
