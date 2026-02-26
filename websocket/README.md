# SuperBora WebSocket Server

Real-time notification system for partner panel.

## Quick Start

### Option 1: With Ratchet (Recommended for Production)

```bash
cd /var/www/html/websocket
composer install
php server.php
```

### Option 2: Simple Server (No Dependencies)

```bash
php /var/www/html/websocket/simple-server.php
```

## Installation as Service

```bash
# Copy service file
sudo cp superbora-ws.service /etc/systemd/system/

# Reload systemd
sudo systemctl daemon-reload

# Enable and start
sudo systemctl enable superbora-ws
sudo systemctl start superbora-ws

# Check status
sudo systemctl status superbora-ws
```

## Usage

### From PHP (Server-side)

```php
require_once '/var/www/html/websocket/WebSocketNotifier.php';

// New order notification
WebSocketNotifier::newOrder($partnerId, [
    'id' => 123,
    'numero_pedido' => 'ORD-001',
    'cliente_nome' => 'João Silva',
    'total' => 89.90,
    'tipo_entrega' => 'delivery'
]);

// Order status update
WebSocketNotifier::orderUpdate($partnerId, [
    'id' => 123,
    'numero_pedido' => 'ORD-001',
    'status' => 'preparing',
    'status_label' => 'Em Preparo'
]);

// Chat message
WebSocketNotifier::chatMessage($partnerId, [
    'order_id' => 123,
    'from' => 'customer',
    'from_name' => 'Cliente',
    'message' => 'Olá, posso trocar o refrigerante?'
]);

// System notification
WebSocketNotifier::notify($partnerId, 'Título', 'Mensagem', 'info');

// Alert (high priority)
WebSocketNotifier::alert($partnerId, 'Atenção!', 'Mensagem urgente');

// Broadcast to all
WebSocketNotifier::broadcast('maintenance', [
    'message' => 'Sistema entrará em manutenção em 10 minutos'
]);
```

### Via API

```bash
# Send to specific partner
curl -X POST http://localhost/api/mercado/partner/ws-broadcast.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: superbora_ws_secret_2024" \
  -d '{
    "partner_id": 1,
    "type": "new_order",
    "data": {
      "order_id": 123,
      "order_number": "ORD-001",
      "customer_name": "João Silva",
      "total": 89.90
    }
  }'

# Broadcast to all
curl -X POST http://localhost/api/mercado/partner/ws-broadcast.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: superbora_ws_secret_2024" \
  -d '{
    "broadcast": true,
    "type": "notification",
    "data": {
      "title": "Manutenção",
      "message": "Sistema será atualizado em 5 minutos"
    }
  }'
```

### From React (Client-side)

```jsx
import { useWebSocket, MESSAGE_TYPES } from '@/app/hooks/useWebSocket';

function MyComponent() {
  const {
    isConnected,
    send,
    subscribe,
    unsubscribe
  } = useWebSocket({
    partnerId: 1,
    token: 'your-auth-token',
    onMessage: (message) => {
      if (message.type === MESSAGE_TYPES.NEW_ORDER) {
        console.log('New order:', message.data);
      }
    }
  });

  return (
    <div>
      Status: {isConnected ? 'Connected' : 'Disconnected'}
    </div>
  );
}
```

### With Context Provider

```jsx
// In layout.js
import { WebSocketProvider } from '@/app/contexts/WebSocketContext';

export default function Layout({ children }) {
  return (
    <WebSocketProvider partnerId={1} token="auth-token">
      {children}
    </WebSocketProvider>
  );
}

// In any component
import { useWebSocketContext, useNewOrders } from '@/app/contexts/WebSocketContext';

function OrdersPanel() {
  const { newOrders, acknowledgeOrder } = useNewOrders();

  return (
    <div>
      {newOrders.map(order => (
        <div key={order.order_id}>
          New order: {order.order_number}
          <button onClick={() => acknowledgeOrder(order.order_id)}>
            Acknowledge
          </button>
        </div>
      ))}
    </div>
  );
}
```

## Message Types

| Type | Description |
|------|-------------|
| `new_order` | New order received |
| `order_update` | Order status changed |
| `chat_message` | New chat message |
| `notification` | System notification |

## Configuration

### Environment Variables

- `WS_PORT`: WebSocket server port (default: 8080)
- `WS_API_SECRET`: API authentication secret
- `NEXT_PUBLIC_WS_URL`: WebSocket URL for frontend

### Nginx Proxy (Optional)

```nginx
location /ws {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 86400;
}
```

## Troubleshooting

### Server won't start
- Check if port 8080 is available: `netstat -tlnp | grep 8080`
- Check PHP socket extension: `php -m | grep sockets`

### Clients can't connect
- Ensure firewall allows port 8080
- Check WebSocket URL in client config
- Verify SSL if using wss://

### Messages not delivered
- Check server logs: `journalctl -u superbora-ws -f`
- Verify partner authentication
- Check message queue: `ls /tmp/superbora_ws_queue/`
