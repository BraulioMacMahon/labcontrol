require('dotenv').config();
const pollHosts = require('./src/pollHosts');

const intervalSec = parseInt(process.env.SYNC_INTERVAL || '60', 10);

console.log(`sync-service starting — interval ${intervalSec}s`);

(async () => {
  try {
    // Roda uma vez; falhas pontuais (ex.: MySQL indisponível) não derrubam o worker.
    await pollHosts.runOnce();
  } catch (err) {
    console.error('initial poll error', err);
  }

  // Agenda as próximas execuções; erros por host já são tratados dentro de pollHosts.
  setInterval(() => {
    pollHosts.runOnce().catch(err => console.error('poll error', err));
  }, intervalSec * 1000);
})();
