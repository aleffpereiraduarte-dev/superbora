/**
 * SuperBora WebSocket Server v1.0
 * Real-time communication for vitrine app
 */

const WebSocket = require('ws');
const http = require('http');
const mysql = require('mysql2/promise');
const crypto = require('crypto');

const PORT = process.env.WS_PORT || 8080;

// Database config
let dbPool = null;

async function initDB() {
  try {
    dbPool = mysql.createPool({
      host: '147.93.12.236',
      user: 'love1',
      password: 'Aleff2009@',
      database: 'love1',
      waitForConnections: true,
      connectionLimit: 5,
    });
    console.log('[DB] Pool created');
  } catch (err) {
    console.error('[DB] Error:', err.message);
  }
}

// Client storage
const clients = new Map();
const channels = new Map();

function generateId() {
  return crypto.randomBytes(8).toString('hex');
}

function sendTo(clientId, data) {
  const client = clients.get(clientId);
  if (client && client.ws.readyState === WebSocket.OPEN) {
    client.ws.send(JSON.stringify(data));
  }
}

function broadcast(channel, data, excludeId = null) {
  const subs = channels.get(channel);
  if (!subs) return 0;

  const payload = JSON.stringify(data);
  let count = 0;
  subs.forEach(id => {
    if (id !== excludeId) {
      const client = clients.get(id);
      if (client && client.ws.readyState === WebSocket.OPEN) {
        client.ws.send(payload);
        count++;
      }
    }
  });
  return count;
}

function subscribe(clientId, channel) {
  if (!channels.has(channel)) {
    channels.set(channel, new Set());
  }
  channels.get(channel).add(clientId);

  const client = clients.get(clientId);
  if (client) client.channels.add(channel);
}

function unsubscribe(clientId, channel) {
  const subs = channels.get(channel);
  if (subs) {
    subs.delete(clientId);
    if (subs.size === 0) channels.delete(channel);
  }
  const client = clients.get(clientId);
  if (client) client.channels.delete(channel);
}

function handleDisconnect(clientId) {
  const client = clients.get(clientId);
  if (!client) return;

  client.channels.forEach(ch => {
    const subs = channels.get(ch);
    if (subs) {
      subs.delete(clientId);
      if (subs.size === 0) channels.delete(ch);
    }
  });
  clients.delete(clientId);
  console.log(`[WS] Disconnected: ${clientId} (${clients.size} active)`);
}

function handleMessage(clientId, data) {
  try {
    const msg = JSON.parse(data);
    const client = clients.get(clientId);

    switch (msg.type) {
      case 'auth':
        client.userId = msg.user_id;
        client.authenticated = true;
        subscribe(clientId, `user_${msg.user_id}`);
        sendTo(clientId, { type: 'auth_success', channels: Array.from(client.channels) });
        console.log(`[Auth] ${msg.user_id}`);
        break;

      case 'subscribe':
        // Public channels (stores:*) don't require auth
        if (client.authenticated || (msg.channel && msg.channel.startsWith('stores:'))) {
          subscribe(clientId, msg.channel);
          sendTo(clientId, { type: 'subscribed', channel: msg.channel });
        }
        break;

      case 'unsubscribe':
        unsubscribe(clientId, msg.channel);
        sendTo(clientId, { type: 'unsubscribed', channel: msg.channel });
        break;

      case 'ping':
        sendTo(clientId, { type: 'pong', ts: Date.now() });
        break;

      case 'chat_message':
        if (client.authenticated && msg.order_id) {
          broadcast(`order_${msg.order_id}`, {
            type: 'chat_message',
            data: {
              order_id: msg.order_id,
              sender_id: client.userId,
              message: msg.message,
              timestamp: new Date().toISOString(),
            }
          }, clientId);
        }
        break;

      case 'typing':
        if (client.authenticated && msg.order_id) {
          broadcast(`order_${msg.order_id}`, {
            type: 'typing',
            data: { order_id: msg.order_id, sender_id: client.userId, is_typing: msg.is_typing }
          }, clientId);
        }
        break;
    }
  } catch (err) {
    console.error('[WS] Message error:', err.message);
  }
}

// HTTP + WebSocket server
const server = http.createServer((req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-Key');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    return res.end();
  }

  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify({ status: 'ok', clients: clients.size, channels: channels.size }));
  }

  if (req.method === 'POST' && req.url === '/broadcast') {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      try {
        const data = JSON.parse(body);
        if (req.headers['x-api-key'] !== 'superbora-ws-key-2024') {
          res.writeHead(401);
          return res.end('Unauthorized');
        }
        const count = broadcast(data.channel, data.message);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: true, delivered: count }));
      } catch (err) {
        res.writeHead(400);
        res.end('Bad Request');
      }
    });
    return;
  }

  // Store status endpoint: broadcast store open/close/busy to stores:{city} channel
  if (req.method === 'POST' && req.url === '/store-status') {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      try {
        const data = JSON.parse(body);
        if (req.headers['x-api-key'] !== 'superbora-ws-key-2024') {
          res.writeHead(401);
          return res.end('Unauthorized');
        }
        // Expect: { partner_id, city, is_open, busy_mode, nome, ... }
        const city = (data.city || '').toLowerCase().trim();
        if (!city || !data.partner_id) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({ success: false, error: 'city and partner_id required' }));
        }
        const channel = `stores:${city}`;
        const message = {
          type: 'store_status',
          data: {
            partner_id: data.partner_id,
            is_open: data.is_open ?? null,
            busy_mode: data.busy_mode ?? null,
            nome: data.nome || null,
            updated_at: new Date().toISOString(),
          }
        };
        const count = broadcast(channel, message);
        // Also broadcast to generic "stores:all" channel
        broadcast('stores:all', message);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: true, channel, delivered: count }));
      } catch (err) {
        res.writeHead(400);
        res.end('Bad Request');
      }
    });
    return;
  }

  res.writeHead(404);
  res.end('Not Found');
});

const wss = new WebSocket.Server({ server });

wss.on('connection', (ws, req) => {
  const clientId = generateId();
  const ip = req.headers['x-forwarded-for'] || req.socket.remoteAddress;

  clients.set(clientId, {
    ws,
    ip,
    userId: null,
    authenticated: false,
    channels: new Set(),
  });

  console.log(`[WS] Connected: ${clientId} from ${ip} (${clients.size} active)`);

  sendTo(clientId, { type: 'welcome', clientId });

  ws.on('message', (data) => handleMessage(clientId, data));
  ws.on('close', () => handleDisconnect(clientId));
  ws.on('error', (err) => {
    console.error(`[WS] Error ${clientId}:`, err.message);
    handleDisconnect(clientId);
  });

  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });
});

// Heartbeat
setInterval(() => {
  wss.clients.forEach(ws => {
    if (!ws.isAlive) return ws.terminate();
    ws.isAlive = false;
    ws.ping();
  });
}, 30000);

// Start
initDB();
server.listen(PORT, '0.0.0.0', () => {
  console.log(`[WS] Server running on port ${PORT}`);
});

process.on('SIGTERM', () => process.exit(0));
process.on('SIGINT', () => process.exit(0));
