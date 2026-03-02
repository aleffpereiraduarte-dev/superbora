/**
 * SuperBora WebSocket Server v1.1
 * Real-time communication for vitrine app
 * Hardened: JWT auth, env API key, message size limit, rate limiting
 */

const WebSocket = require('ws');
const http = require('http');
const mysql = require('mysql2/promise');
const crypto = require('crypto');
const jwt = require('jsonwebtoken');

const PORT = process.env.WS_PORT || 8080;
const JWT_SECRET = process.env.JWT_SECRET || null;
const WS_API_KEY = process.env.WS_API_KEY || 'superbora-ws-key-2024';
const MAX_MESSAGE_SIZE = 8 * 1024; // 8KB
const RATE_LIMIT_WINDOW = 1000; // 1 second
const RATE_LIMIT_MAX = 30; // max messages per window

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

/**
 * Rate limiting: check if a client has exceeded the message rate limit.
 * Returns true if the message should be rejected.
 */
function isRateLimited(client) {
  const now = Date.now();
  if (!client.rateLimitWindow || now - client.rateLimitWindow > RATE_LIMIT_WINDOW) {
    client.rateLimitWindow = now;
    client.rateLimitCount = 1;
    return false;
  }
  client.rateLimitCount++;
  return client.rateLimitCount > RATE_LIMIT_MAX;
}

function handleMessage(clientId, data) {
  try {
    // Message size limit: reject messages > 8KB
    const rawLength = typeof data === 'string' ? Buffer.byteLength(data, 'utf8') : data.length;
    if (rawLength > MAX_MESSAGE_SIZE) {
      sendTo(clientId, { type: 'error', message: 'Message too large (max 8KB)' });
      return;
    }

    const client = clients.get(clientId);
    if (!client) return;

    // Rate limiting: reject if > 30 messages/second
    if (isRateLimited(client)) {
      sendTo(clientId, { type: 'error', message: 'Rate limit exceeded (max 30/s)' });
      return;
    }

    const msg = JSON.parse(data);

    switch (msg.type) {
      case 'auth': {
        // JWT validation if JWT_SECRET is configured
        if (JWT_SECRET && msg.token) {
          try {
            const decoded = jwt.verify(msg.token, JWT_SECRET);
            const userId = decoded.uid || decoded.sub || decoded.id;
            if (!userId) {
              sendTo(clientId, { type: 'auth_error', message: 'Invalid token: no user ID in claims' });
              return;
            }
            client.userId = userId;
            client.authenticated = true;
            subscribe(clientId, `user_${userId}`);
            sendTo(clientId, { type: 'auth_success', user_id: userId, channels: Array.from(client.channels) });
            console.log(`[Auth] JWT verified: ${userId}`);
          } catch (jwtErr) {
            sendTo(clientId, { type: 'auth_error', message: 'Invalid or expired token' });
            console.warn(`[Auth] JWT failed: ${jwtErr.message}`);
          }
        } else {
          // Fallback: trust user_id (backwards compat with partner panel when no JWT_SECRET)
          client.userId = msg.user_id;
          client.authenticated = true;
          subscribe(clientId, `user_${msg.user_id}`);
          sendTo(clientId, { type: 'auth_success', channels: Array.from(client.channels) });
          console.log(`[Auth] ${msg.user_id} (no JWT)`);
        }
        break;
      }

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
        if (req.headers['x-api-key'] !== WS_API_KEY) {
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
        if (req.headers['x-api-key'] !== WS_API_KEY) {
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
    rateLimitWindow: 0,
    rateLimitCount: 0,
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
  console.log(`[WS] Server v1.1 running on port ${PORT}`);
  if (JWT_SECRET) {
    console.log('[WS] JWT validation enabled');
  } else {
    console.log('[WS] JWT validation disabled (no JWT_SECRET env var)');
  }
});

process.on('SIGTERM', () => process.exit(0));
process.on('SIGINT', () => process.exit(0));
