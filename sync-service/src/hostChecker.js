const ping = require('ping');
const { exec } = require('child_process');
const path = require('path');

async function checkByPing(ip, timeoutSec = 2) {
  try {
    const res = await ping.promise.probe(ip, { timeout: timeoutSec });
    return {
      online: res.alive,
      latency: res.time || null,
      method: 'icmp'
    };
  } catch (err) {
    return { online: false, latency: null, method: 'icmp', error: err.message };
  }
}

function checkByPowerShell(ip) {
  return new Promise((resolve) => {
    // Use Test-Connection via PowerShell as a fallback
    const psCmd = `Test-Connection -ComputerName ${ip} -Count 1 -Quiet`;
    const powershell = process.env.POWERSHELL_PATH || 'powershell';
    exec(`"${powershell}" -NoProfile -Command "${psCmd}"`, { timeout: 5000 }, (err, stdout) => {
      if (err) return resolve({ online: false, method: 'winrm', error: err.message });
      const out = String(stdout || '').trim();
      resolve({ online: out === 'True' || out === 'true', method: 'winrm' });
    });
  });
}

async function checkHost(ip) {
  // First try an ICMP ping (fast)
  const icmp = await checkByPing(ip, 2);
  if (icmp.online) return icmp;

  // Fallback to PowerShell Test-Connection for Windows environments
  const ps = await checkByPowerShell(ip);
  return ps;
}

module.exports = { checkHost };
