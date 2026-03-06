const mysql = require('mysql2/promise');

let pool;

function getPool() {
  if (pool) return pool;
  pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    port: process.env.DB_PORT ? parseInt(process.env.DB_PORT, 10) : 3306,
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'labcontrol',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
  });
  return pool;
}

async function getActiveHosts() {
  const p = getPool();
  const [rows] = await p.query('SELECT * FROM hosts WHERE is_active = 1 ORDER BY hostname ASC');
  return rows;
}

async function updateHostStatus(hostId, status, lastSeen = null) {
  const p = getPool();
  const fields = ['status = ?'];
  const params = [status];
  if (lastSeen) {
    fields.push('last_seen = ?');
    params.push(lastSeen);
  }
  params.push(hostId);
  const sql = `UPDATE hosts SET ${fields.join(', ')}, synced = 0 WHERE id = ?`;
  await p.execute(sql, params);
}

module.exports = {
  getPool,
  getActiveHosts,
  updateHostStatus
};
