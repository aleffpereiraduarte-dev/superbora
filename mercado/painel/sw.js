// SuperBora Partner Panel - Service Worker for Push Notifications
// Version 2.0 - Enhanced with thermal printing support

const SW_VERSION = '2.0.0';
const CACHE_NAME = 'superbora-cache-v2';

// Install event
self.addEventListener('install', function(event) {
  console.log('[SW] Installing version:', SW_VERSION);
  self.skipWaiting();
});

// Activate event
self.addEventListener('activate', function(event) {
  console.log('[SW] Activating version:', SW_VERSION);
  event.waitUntil(
    caches.keys().then(function(cacheNames) {
      return Promise.all(
        cacheNames.filter(function(name) {
          return name !== CACHE_NAME;
        }).map(function(name) {
          return caches.delete(name);
        })
      );
    }).then(function() {
      return clients.claim();
    })
  );
});

// Push notification event
self.addEventListener('push', function(event) {
  let data = {
    title: 'SuperBora',
    body: 'Nova notificacao',
    type: 'notification'
  };

  if (event.data) {
    try {
      data = event.data.json();
    } catch (e) {
      data.body = event.data.text();
    }
  }

  const options = {
    body: data.body || data.message || '',
    icon: '/mercado/painel-next/icon-192.png',
    badge: '/mercado/painel-next/icon-192.png',
    tag: data.tag || 'superbora-notification',
    data: {
      url: data.url || data.click_action || '/mercado/painel-next/',
      order_id: data.order_id || null,
      order_data: data.order || null,
      type: data.type || 'notification',
      auto_print: data.auto_print || false,
    },
    vibrate: [200, 100, 200, 100, 200],
    requireInteraction: data.type === 'new_order',
    actions: data.type === 'new_order' ? [
      { action: 'accept', title: 'Aceitar' },
      { action: 'view', title: 'Ver Detalhes' },
    ] : [],
  };

  event.waitUntil(
    self.registration.showNotification(data.title || 'SuperBora', options)
  );

  // If it's a new order, notify all clients for auto-print
  if (data.type === 'new_order' && data.order) {
    event.waitUntil(
      clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
        clientList.forEach(function(client) {
          client.postMessage({
            type: 'NEW_ORDER',
            order: data.order,
            auto_print: data.auto_print,
          });
        });
      })
    );
  }
});

// Notification click event
self.addEventListener('notificationclick', function(event) {
  event.notification.close();

  const data = event.notification.data || {};
  const url = data.url || '/mercado/painel-next/';
  const action = event.action;

  // Handle different actions
  if (action === 'accept' && data.order_id) {
    // Notify client to accept order
    event.waitUntil(
      clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
        // Focus existing window and send message
        for (const client of clientList) {
          if (client.url.includes('/mercado/painel-next') && 'focus' in client) {
            client.postMessage({
              type: 'ACCEPT_ORDER',
              order_id: data.order_id,
            });
            return client.focus();
          }
        }
        // No window open, open new one
        return clients.openWindow(url + 'pedidos');
      })
    );
  } else if (action === 'view' || !action) {
    event.waitUntil(
      clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
        for (const client of clientList) {
          if (client.url.includes('/mercado/painel-next') && 'focus' in client) {
            // Notify client to show order
            if (data.order_id) {
              client.postMessage({
                type: 'VIEW_ORDER',
                order_id: data.order_id,
              });
            }
            return client.focus();
          }
        }
        return clients.openWindow(data.order_id ? url + 'pedidos' : url);
      })
    );
  }
});

// Message event - for communication with main app
self.addEventListener('message', function(event) {
  console.log('[SW] Message received:', event.data);

  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  // Trigger print from main app
  if (event.data && event.data.type === 'TRIGGER_PRINT') {
    // Broadcast to all clients
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
      clientList.forEach(function(client) {
        client.postMessage({
          type: 'PRINT_ORDER',
          order: event.data.order,
        });
      });
    });
  }
});

// Background sync for offline order queue
self.addEventListener('sync', function(event) {
  if (event.tag === 'sync-orders') {
    event.waitUntil(syncOrders());
  }
});

async function syncOrders() {
  // Future: sync pending order actions when back online
  console.log('[SW] Syncing orders...');
}

// Periodic background sync for notifications
self.addEventListener('periodicsync', function(event) {
  if (event.tag === 'check-new-orders') {
    event.waitUntil(checkNewOrders());
  }
});

async function checkNewOrders() {
  // Future: periodic check for new orders
  console.log('[SW] Checking for new orders...');
}
