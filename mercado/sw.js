/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - SERVICE WORKER (PWA)
 * ══════════════════════════════════════════════════════════════════════════════
 */

const CACHE_NAME = 'superbora-v1.0.7';
const STATIC_CACHE = 'superbora-static-v8';
const DYNAMIC_CACHE = 'superbora-dynamic-v8';
const IMAGE_CACHE = 'superbora-images-v8';

// Arquivos para cache estatico (NÃO incluir páginas dinâmicas!)
const STATIC_ASSETS = [
  '/mercado/assets/css/mercado-new.css',
  '/mercado/assets/css/superbora-design-system.css',
  '/mercado/assets/img/no-image.png',
  '/mercado/manifest.json',
  'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap'
];

// URLs que sempre devem buscar da rede (NUNCA cachear)
const NETWORK_ONLY = [
  '/mercado/',
  '/mercado/index.php',
  '/mercado/api/',
  '/mercado/painel/',
  '/mercado/checkout.php',
  '/mercado/components/'
];

// Instalacao do Service Worker
self.addEventListener('install', (event) => {
  console.log('[SW] Installing...');

  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => {
        console.log('[SW] Pre-caching static assets');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => self.skipWaiting())
      .catch((err) => console.error('[SW] Pre-cache failed:', err))
  );
});

// Ativacao do Service Worker
self.addEventListener('activate', (event) => {
  console.log('[SW] Activating...');

  event.waitUntil(
    caches.keys()
      .then((cacheNames) => {
        return Promise.all(
          cacheNames
            .filter((name) => name !== STATIC_CACHE && name !== DYNAMIC_CACHE && name !== IMAGE_CACHE)
            .map((name) => {
              console.log('[SW] Deleting old cache:', name);
              return caches.delete(name);
            })
        );
      })
      .then(() => self.clients.claim())
  );
});

// Interceptar requisicoes
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Ignorar requisicoes que nao sao GET
  if (request.method !== 'GET') return;

  // Ignorar requisicoes de extensoes do navegador
  if (!url.protocol.startsWith('http')) return;

  // Network only para APIs e checkout
  if (NETWORK_ONLY.some(path => url.pathname.includes(path))) {
    event.respondWith(networkOnly(request));
    return;
  }

  // Imagens - cache first com fallback
  if (request.destination === 'image' || url.pathname.match(/\.(jpg|jpeg|png|gif|webp|svg|ico)$/i)) {
    event.respondWith(cacheFirstWithFallback(request, IMAGE_CACHE));
    return;
  }

  // CSS e JS - stale while revalidate
  if (request.destination === 'style' || request.destination === 'script' ||
      url.pathname.match(/\.(css|js)$/i)) {
    event.respondWith(staleWhileRevalidate(request, STATIC_CACHE));
    return;
  }

  // Paginas HTML - network first com cache fallback
  if (request.destination === 'document' || url.pathname.endsWith('.php')) {
    event.respondWith(networkFirstWithCache(request, DYNAMIC_CACHE));
    return;
  }

  // Outros recursos - stale while revalidate
  event.respondWith(staleWhileRevalidate(request, DYNAMIC_CACHE));
});

// Estrategia: Network Only
async function networkOnly(request) {
  try {
    return await fetch(request);
  } catch (error) {
    console.error('[SW] Network only failed:', error);
    return new Response('Offline', { status: 503 });
  }
}

// Estrategia: Cache First com Fallback
async function cacheFirstWithFallback(request, cacheName) {
  const cachedResponse = await caches.match(request);
  if (cachedResponse) {
    return cachedResponse;
  }

  try {
    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (error) {
    // Retornar imagem placeholder se for uma imagem
    if (request.destination === 'image') {
      return caches.match('/mercado/assets/img/no-image.png');
    }
    throw error;
  }
}

// Estrategia: Stale While Revalidate
async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cachedResponse = await cache.match(request);

  const fetchPromise = fetch(request)
    .then((networkResponse) => {
      if (networkResponse.ok) {
        cache.put(request, networkResponse.clone());
      }
      return networkResponse;
    })
    .catch(() => cachedResponse);

  return cachedResponse || fetchPromise;
}

// Estrategia: Network First com Cache Fallback
async function networkFirstWithCache(request, cacheName) {
  try {
    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (error) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    // Retornar pagina offline
    return caches.match('/mercado/offline.html') ||
           new Response('Voce esta offline. Por favor, verifique sua conexao.', {
             status: 503,
             headers: { 'Content-Type': 'text/html; charset=utf-8' }
           });
  }
}

// Push Notifications (parceiro + cliente)
self.addEventListener('push', (event) => {
  console.log('[SW] Push received');

  let data = {
    title: 'SuperBora',
    body: 'Voce tem uma nova notificacao!',
    icon: '/mercado/assets/img/icon-192.png',
    badge: '/mercado/assets/img/badge-72.png',
    tag: 'superbora-notification'
  };

  if (event.data) {
    try {
      data = { ...data, ...event.data.json() };
    } catch (e) {
      data.body = event.data.text();
    }
  }

  // Notificar todas as janelas abertas (para tocar som no painel)
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then((clients) => {
        clients.forEach(client => {
          client.postMessage({
            type: 'PUSH_RECEIVED',
            title: data.title,
            body: data.body,
            tag: data.tag
          });
        });

        return self.registration.showNotification(data.title, {
          body: data.body,
          icon: data.icon,
          badge: data.badge,
          tag: data.tag,
          data: data.data || {},
          actions: data.actions || [
            { action: 'open', title: 'Abrir' },
            { action: 'close', title: 'Fechar' }
          ],
          vibrate: [200, 100, 200, 100, 200],
          requireInteraction: data.tag && data.tag.includes('parceiro')
        });
      })
  );
});

// Clique em notificacao
self.addEventListener('notificationclick', (event) => {
  console.log('[SW] Notification clicked');

  event.notification.close();

  const urlToOpen = event.notification.data?.url || '/mercado/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then((windowClients) => {
        // Verificar se ja tem uma janela aberta
        for (const client of windowClients) {
          if (client.url.includes('/mercado/') && 'focus' in client) {
            client.navigate(urlToOpen);
            return client.focus();
          }
        }
        // Abrir nova janela
        if (clients.openWindow) {
          return clients.openWindow(urlToOpen);
        }
      })
  );
});

// Background Sync
self.addEventListener('sync', (event) => {
  console.log('[SW] Background sync:', event.tag);

  if (event.tag === 'sync-cart') {
    event.waitUntil(syncCart());
  }
});

async function syncCart() {
  // Implementar sincronizacao do carrinho quando voltar online
  console.log('[SW] Syncing cart...');
}

console.log('[SW] Service Worker loaded');
