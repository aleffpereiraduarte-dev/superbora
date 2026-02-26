# Sistema de Rastreamento em Tempo Real

## Visao Geral

Sistema de rastreamento de entregas em tempo real usando:
- **Leaflet/OpenStreetMap** - Mapa gratuito e open-source
- **Pusher** - WebSockets para atualizacoes em tempo real
- **PHP Backend** - APIs para atualizar e consultar localizacao

## Endpoints da API

### 1. Atualizar Localizacao (Shopper)

```
POST /api/mercado/shopper/location-update.php
Authorization: Bearer <shopper_token>

Body:
{
  "latitude": -23.5505,
  "longitude": -46.6333,
  "order_id": 123,        // Opcional - dispara Pusher se informado
  "heading": 90,          // Opcional - direcao em graus (0-360)
  "speed": 25.5,          // Opcional - velocidade em km/h
  "accuracy": 10.0        // Opcional - precisao GPS
}

Response:
{
  "success": true,
  "data": {
    "latitude": -23.5505,
    "longitude": -46.6333,
    "order_id": 123,
    "eta_minutes": 8,
    "distance_km": 2.3,
    "status": "em_entrega",
    "pusher_sent": true
  }
}
```

### 2. Consultar Rastreamento (Cliente)

```
GET /api/mercado/vitrine/order-tracking.php?order_id=123
Authorization: Bearer <customer_token>
  ou
  ?tracking_token=ABC123XYZ

Response:
{
  "success": true,
  "data": {
    "order": {
      "id": 123,
      "number": "ORD-2024-00123",
      "status": "em_entrega",
      "status_label": "A caminho"
    },
    "destination": {
      "lat": -23.5600,
      "lng": -46.6400,
      "address": "Rua Example, 123"
    },
    "partner": {
      "id": 1,
      "name": "Mercado XYZ",
      "lat": -23.5500,
      "lng": -46.6300
    },
    "driver": {
      "id": 10,
      "name": "Joao Silva",
      "photo": "https://...",
      "phone": "(**) *****-1234",
      "vehicle": {
        "type": "moto",
        "plate": "ABC-1234",
        "color": "Vermelha"
      },
      "rating": 4.8
    },
    "tracking": {
      "lat": -23.5520,
      "lng": -46.6350,
      "heading": 180,
      "speed": 25.5,
      "eta_minutes": 8,
      "distance_km": 2.3,
      "status": "em_entrega"
    },
    "pusher": {
      "app_key": "1cd7a205ab19e56edcfe",
      "cluster": "sa1",
      "channel": "order-123",
      "events": ["location-update", "status-update", "driver-arriving", "driver-arrived"]
    }
  }
}
```

### 3. Iniciar Tracking

```
POST /api/mercado/shopper/start-tracking.php
Authorization: Bearer <shopper_token>

Body:
{
  "order_id": 123,
  "latitude": -23.5505,
  "longitude": -46.6333
}
```

### 4. Parar Tracking

```
POST /api/mercado/shopper/stop-tracking.php
Authorization: Bearer <shopper_token>

Body:
{
  "order_id": 123
}
```

## Eventos Pusher

Canal: `order-{order_id}`

### location-update
Disparado quando o entregador atualiza sua localizacao.
```json
{
  "order_id": 123,
  "driver": {
    "id": 10,
    "lat": -23.5520,
    "lng": -46.6350,
    "heading": 180,
    "speed": 25.5
  },
  "eta_minutes": 8,
  "distance_km": 2.3,
  "status": "em_entrega",
  "timestamp": "2024-02-04T10:30:00-03:00"
}
```

### driver-arriving
Disparado quando o entregador esta a menos de 300m do destino.
```json
{
  "order_id": 123,
  "message": "O entregador esta chegando!",
  "eta_minutes": 2,
  "timestamp": "2024-02-04T10:30:00-03:00"
}
```

### driver-arrived
Disparado quando o entregador chegou ao destino.
```json
{
  "order_id": 123,
  "message": "O entregador chegou!",
  "timestamp": "2024-02-04T10:30:00-03:00"
}
```

### status-update
Disparado quando o status do pedido muda.
```json
{
  "order_id": 123,
  "status": "delivered",
  "status_label": "Pedido entregue!",
  "timestamp": "2024-02-04T10:30:00-03:00"
}
```

## Frontend - Componentes de Mapa

### Mapa Completo
URL: `/mercado/tracking/map.html?order_id=123&token=<jwt>`

Features:
- Mapa full-screen com Leaflet
- Marcador animado do entregador
- Card flutuante com info do entregador
- ETA e distancia em tempo real
- Notificacao quando entregador chega
- Conexao automatica com Pusher

### Mapa Embed (para iframe)
URL: `/mercado/tracking/embed.html?order_id=123&token=<jwt>`

Features:
- Versao minimalista para embed
- Otimizado para iframe/WebView
- Comunica com parent via postMessage

## Tabelas do Banco

### om_delivery_locations
Historico de localizacoes (para replay e analytics).
```sql
CREATE TABLE om_delivery_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  worker_id INT NOT NULL,
  latitude DECIMAL(10,8),
  longitude DECIMAL(11,8),
  heading INT,
  speed DECIMAL(5,2),
  accuracy DECIMAL(6,2),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### om_delivery_tracking_live
Ultima posicao conhecida (para queries rapidas).
```sql
CREATE TABLE om_delivery_tracking_live (
  order_id INT PRIMARY KEY,
  worker_id INT NOT NULL,
  latitude DECIMAL(10,8),
  longitude DECIMAL(11,8),
  heading INT,
  speed DECIMAL(5,2),
  eta_minutes INT,
  distance_km DECIMAL(6,2),
  status ENUM('coletando', 'em_entrega', 'chegando', 'entregue'),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## Exemplo de Integracao (React Native)

```javascript
import { WebView } from 'react-native-webview';

function TrackingMap({ orderId, authToken }) {
  const uri = `https://superbora.com.br/mercado/tracking/embed.html?order_id=${orderId}&token=${authToken}`;

  return (
    <WebView
      source={{ uri }}
      style={{ flex: 1 }}
      onMessage={(event) => {
        const data = JSON.parse(event.nativeEvent.data);
        if (data.type === 'driver-arriving') {
          Alert.alert('Entregador chegando!');
        }
      }}
    />
  );
}
```

## Exemplo de Integracao (Web/JavaScript)

```javascript
// Conectar ao Pusher
const pusher = new Pusher('1cd7a205ab19e56edcfe', { cluster: 'sa1' });
const channel = pusher.subscribe('order-123');

channel.bind('location-update', (data) => {
  console.log('Driver location:', data.driver.lat, data.driver.lng);
  console.log('ETA:', data.eta_minutes, 'minutos');
});

channel.bind('driver-arriving', () => {
  alert('O entregador esta chegando!');
});
```
