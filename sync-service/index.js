require('dotenv').config();
const pollHosts = require('./src/pollHosts');

const intervalSec = parseInt(process.env.SYNC_INTERVAL || '60', 10);

console.log(`sync-service starting — interval ${intervalSec}s`);

(async () => {
  try {
    // run once immediately
    await pollHosts.runOnce();

    // schedule
    setInterval(() => {
      pollHosts.runOnce().catch(err => console.error('poll error', err));
    }, intervalSec * 1000);
  } catch (err) {
    console.error('sync-service error', err);
    process.exit(1);
  }
})();
