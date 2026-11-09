const db = require('./db');
const hostChecker = require('./hostChecker');

// `last_seen` é informativo: reescrevê-lo a cada ciclo (60 s) fazia o
// `updated_at` de todos os hosts online mudar sem qualquer alteração visível,
// o que gerava eventos SSE e pedidos de refresh em cada browser sem necessidade.
// Só atualizamos quando o status muda ou quando o registo já é antigo.
const LAST_SEEN_REFRESH_MS = parseInt(process.env.LAST_SEEN_REFRESH_MS || '300000', 10);

/**
 * Decisão pura (testável sem MySQL) sobre se vale a pena escrever na BD.
 * Menos escritas = menos eventos SSE espúrios e menos carga no MySQL.
 */
function needsUpdate(host, newStatus, isOnline, now = Date.now(), refreshMs = LAST_SEEN_REFRESH_MS) {
  if (host.status !== newStatus) return true;
  if (!isOnline) return false;
  if (!host.last_seen) return true;

  const age = now - new Date(host.last_seen).getTime();
  return !Number.isFinite(age) || age >= refreshMs;
}

async function runOnce() {
  console.log(new Date().toISOString(), 'pollHosts: fetching hosts');
  const hosts = await db.getActiveHosts();
  console.log('found hosts:', hosts.length);

  for (const h of hosts) {
    try {
      const res = await hostChecker.checkHost(h.ip);
      const newStatus = res.online ? 'online' : 'offline';

      if (needsUpdate(h, newStatus, res.online)) {
        if (h.status !== newStatus) {
          console.log(`host ${h.hostname} (${h.ip}) status changed: ${h.status} -> ${newStatus}`);
        }
        await db.updateHostStatus(h.id, newStatus, res.online ? new Date() : null);
      }
    } catch (err) {
      console.error('error checking host', h.ip, err.message || err);
    }
  }

  // Optionally: sync to Firebase (not implemented here)
}

module.exports = { runOnce, needsUpdate, LAST_SEEN_REFRESH_MS };
