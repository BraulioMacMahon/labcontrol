const db = require('./db');
const hostChecker = require('./hostChecker');

async function runOnce() {
  console.log(new Date().toISOString(), 'pollHosts: fetching hosts');
  const hosts = await db.getActiveHosts();
  console.log('found hosts:', hosts.length);

  for (const h of hosts) {
    try {
      const res = await hostChecker.checkHost(h.ip);
      const newStatus = res.online ? 'online' : 'offline';
      const lastSeen = res.online ? new Date() : null;

      if (h.status !== newStatus || (res.online && (!h.last_seen || new Date(h.last_seen) < lastSeen))) {
        console.log(`host ${h.hostname} (${h.ip}) status changed: ${h.status} -> ${newStatus}`);
        await db.updateHostStatus(h.id, newStatus, lastSeen);
      }
    } catch (err) {
      console.error('error checking host', h.ip, err.message || err);
    }
  }

  // Optionally: sync to Firebase (not implemented here)
}

module.exports = { runOnce };
