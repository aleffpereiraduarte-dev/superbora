// SuperBora Service Worker v12.0.0
// Cache-first for static assets, network-first for API, offline fallback

// Bug fix #1: Add build timestamp to cache version for proper cache invalidation
const BUILD_TIMESTAMP = '1770319300571'; // 2026-02-05T19:21:40Z
const CACHE_VERSION = `superbora-v13-${BUILD_TIMESTAMP}`;
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const DYNAMIC_CACHE = `${CACHE_VERSION}-dynamic`;
const BASE_PATH = '/mercado/vitrine';

// Bug fix #3: Cache size limit configuration
const MAX_CACHE_ENTRIES = 50;

// Bug fix #14: Max retry count for background sync
const MAX_SYNC_RETRIES = 3;

// Assets to pre-cache on install
const PRECACHE_ASSETS = [
  `${BASE_PATH}/`,
  `${BASE_PATH}/offline.html`,
  `${BASE_PATH}/manifest.json`,
  `${BASE_PATH}/icons/icon-192.png`,
  `${BASE_PATH}/icons/icon-512.png`,
];

// Install event - pre-cache essential assets
self.addEventListener('install', (event) => {
  console.log('[SW] Installing service worker v13.0.0');
  // Bug fix #2: Chain cache operations with Promise.all in a single waitUntil
  // Bug fix #13: Add validation that offline.html exists during precache
  event.waitUntil(
    (async () => {
      // First, clear all old caches
      const names = await caches.keys();
      await Promise.all(names.map(name => caches.delete(name)));

      // Then, open cache and add assets
      const cache = await caches.open(STATIC_CACHE);
      console.log('[SW] Pre-caching essential assets');

      // Validate and precache assets, log warnings for missing files
      const precacheResults = await Promise.allSettled(
        PRECACHE_ASSETS.map(async (asset) => {
          try {
            await cache.add(asset);
            return { asset, status: 'cached' };
          } catch (error) {
            console.warn(`[SW] Failed to precache ${asset}:`, error.message);
            return { asset, status: 'failed', error };
          }
        })
      );

      // Check if critical offline.html was cached
      const offlineResult = precacheResults.find(r =>
        r.status === 'fulfilled' && r.value.asset.includes('offline.html')
      );
      if (!offlineResult || offlineResult.value?.status === 'failed') {
        console.error('[SW] Critical: offline.html could not be cached. Offline fallback may not work.');
      }
    })()
  );
  // Activate immediately without waiting for existing SW to finish
  self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  console.log('[SW] Activating service worker');
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name.startsWith('superbora-') && name !== STATIC_CACHE && name !== DYNAMIC_CACHE)
          .map((name) => {
            console.log('[SW] Deleting old cache:', name);
            return caches.delete(name);
          })
      );
    })
  );
  // Take control of all open pages immediately
  self.clients.claim();
});

// Fetch event - routing strategies
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Only handle same-origin requests
  if (url.origin !== self.location.origin) {
    return;
  }

  // Skip non-GET requests
  if (request.method !== 'GET') {
    return;
  }

  // Strategy: Network-first for API calls
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/mercado/vitrine/api/')) {
    event.respondWith(networkFirst(request));
    return;
  }

  // Strategy: Cache-first for static assets (_next/static/*)
  if (url.pathname.includes('/_next/static/')) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Strategy: Cache-first for icon and font files
  if (url.pathname.match(/\.(png|jpg|jpeg|svg|gif|ico|woff2?|ttf|eot)$/)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Strategy: Cache-first for CSS and JS bundles
  if (url.pathname.match(/\.(css|js)$/) && url.pathname.includes('/_next/')) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Strategy: Network-first for HTML pages (navigation)
  if (request.headers.get('accept')?.includes('text/html') || request.mode === 'navigate') {
    event.respondWith(networkFirstWithOfflineFallback(request));
    return;
  }

  // Default: Network-first for everything else
  event.respondWith(networkFirst(request));
});

// Cache-first strategy: check cache, fallback to network
async function cacheFirst(request) {
  try {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(STATIC_CACHE);
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (error) {
    console.log('[SW] Cache-first failed for:', request.url);
    // Return a basic error response if both fail
    return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
  }
}

// Bug fix #5: Helper function to add timeout to fetch requests
function fetchWithTimeout(request, timeout = 10000) {
  return Promise.race([
    fetch(request),
    new Promise((_, reject) =>
      setTimeout(() => reject(new Error('Request timeout')), timeout)
    )
  ]);
}

// Bug fix #3: Helper function to cleanup old cache entries and enforce size limit
async function cleanupCache(cacheName, maxEntries = MAX_CACHE_ENTRIES) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();

  if (keys.length > maxEntries) {
    // Delete oldest entries (first in the list)
    const entriesToDelete = keys.slice(0, keys.length - maxEntries);
    await Promise.all(entriesToDelete.map(key => cache.delete(key)));
    console.log(`[SW] Cleaned up ${entriesToDelete.length} old cache entries from ${cacheName}`);
  }
}

// Network-first strategy: try network, fallback to cache
async function networkFirst(request) {
  try {
    // Bug fix #5: Add timeout to fetch requests (10 seconds)
    const networkResponse = await fetchWithTimeout(request, 10000);
    // Bug fix #6: Don't cache error responses
    if (networkResponse.ok) {
      const cache = await caches.open(DYNAMIC_CACHE);
      await cache.put(request, networkResponse.clone());
      // Bug fix #3: Cleanup old entries after adding new one
      await cleanupCache(DYNAMIC_CACHE);
    }
    return networkResponse;
  } catch (error) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }
    return new Response(JSON.stringify({ error: 'offline' }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}

// Network-first with offline HTML fallback for navigation requests
async function networkFirstWithOfflineFallback(request) {
  try {
    // Bug fix #5: Add timeout to fetch requests (10 seconds)
    const networkResponse = await fetchWithTimeout(request, 10000);
    // Bug fix #6: Don't cache error responses
    if (networkResponse.ok) {
      const cache = await caches.open(DYNAMIC_CACHE);
      await cache.put(request, networkResponse.clone());
      // Bug fix #3: Cleanup old entries after adding new one
      await cleanupCache(DYNAMIC_CACHE);
    }
    return networkResponse;
  } catch (error) {
    // Try to return cached version of the page
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    // Try to return cached home page
    const cachedHome = await caches.match(`${BASE_PATH}/`);
    if (cachedHome) {
      return cachedHome;
    }

    // Last resort: return offline fallback page
    const offlinePage = await caches.match(`${BASE_PATH}/offline.html`);
    if (offlinePage) {
      return offlinePage;
    }

    return new Response('<html><body><h1>Offline</h1><p>Sem conexao com a internet.</p></body></html>', {
      status: 503,
      headers: { 'Content-Type': 'text/html; charset=utf-8' },
    });
  }
}

// Listen for messages from the main thread
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  // Bug fix #7: Await the cache clearing promise
  if (event.data && event.data.type === 'CLEAR_CACHE') {
    event.waitUntil(
      caches.keys().then((cacheNames) => {
        return Promise.all(cacheNames.map((name) => caches.delete(name)));
      }).then(() => {
        console.log('[SW] All caches cleared');
      })
    );
  }
});

// ===== ADVANCED PWA FEATURES =====

// Background Sync - Sync cart and orders when back online
// Bug fix #14: Add max retry count for background sync
self.addEventListener('sync', (event) => {
  console.log('[SW] Background sync triggered:', event.tag);

  if (event.tag === 'sync-cart') {
    event.waitUntil(syncWithRetry(() => syncCart(), 'sync-cart'));
  }

  if (event.tag === 'sync-orders') {
    event.waitUntil(syncWithRetry(() => syncPendingOrders(), 'sync-orders'));
  }

  if (event.tag === 'sync-reviews') {
    event.waitUntil(syncWithRetry(() => syncPendingReviews(), 'sync-reviews'));
  }
});

// Bug fix #14: Wrapper function for sync with retry logic and max retry count
async function syncWithRetry(syncFn, syncTag) {
  const retryKey = `sync-retry-${syncTag}`;
  let retryCount = 0;

  try {
    // Get current retry count from IndexedDB
    const storedCount = await getFromIndexedDB(retryKey);
    retryCount = storedCount || 0;

    if (retryCount >= MAX_SYNC_RETRIES) {
      console.warn(`[SW] Max retries (${MAX_SYNC_RETRIES}) reached for ${syncTag}. Giving up.`);
      await deleteFromIndexedDB(retryKey);
      return;
    }

    await syncFn();
    // Success - reset retry count
    await deleteFromIndexedDB(retryKey);
  } catch (error) {
    console.error(`[SW] Sync ${syncTag} failed (attempt ${retryCount + 1}/${MAX_SYNC_RETRIES}):`, error);
    // Increment retry count
    await saveToIndexedDB(retryKey, retryCount + 1);
    // Re-throw to signal sync failure (browser will retry)
    throw error;
  }
}

// Sync cart data to server
// Bug fix #9: Add retry logic for failed syncs
async function syncCart() {
  const cartData = await getFromIndexedDB('pending-cart');
  if (cartData) {
    const response = await fetch('/api/cart/sync', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(cartData),
    });
    if (response.ok) {
      await deleteFromIndexedDB('pending-cart');
      console.log('[SW] Cart synced successfully');
    } else {
      // Throw error to trigger retry logic
      throw new Error(`Cart sync failed with status ${response.status}`);
    }
  }
}

// Sync pending orders
// Bug fix #9: Add retry logic for failed syncs
async function syncPendingOrders() {
  const orders = await getFromIndexedDB('pending-orders');
  if (orders && orders.length > 0) {
    const failedOrders = [];
    for (const order of orders) {
      const response = await fetch('/api/orders', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(order),
      });
      if (response.ok) {
        await deleteFromIndexedDB(`order-${order.id}`);
      } else {
        failedOrders.push(order);
      }
    }
    if (failedOrders.length > 0) {
      // Throw error to trigger retry logic
      throw new Error(`${failedOrders.length} orders failed to sync`);
    }
    console.log('[SW] Orders synced successfully');
  }
}

// Sync pending reviews
// Bug fix #9: Add retry logic for failed syncs
async function syncPendingReviews() {
  const reviews = await getFromIndexedDB('pending-reviews');
  if (reviews && reviews.length > 0) {
    const failedReviews = [];
    for (const review of reviews) {
      const response = await fetch('/api/reviews', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(review),
      });
      if (response.ok) {
        await deleteFromIndexedDB(`review-${review.id}`);
      } else {
        failedReviews.push(review);
      }
    }
    if (failedReviews.length > 0) {
      // Throw error to trigger retry logic
      throw new Error(`${failedReviews.length} reviews failed to sync`);
    }
    console.log('[SW] Reviews synced successfully');
  }
}

// Periodic Background Sync - Check for order updates
self.addEventListener('periodicsync', (event) => {
  console.log('[SW] Periodic sync triggered:', event.tag);

  if (event.tag === 'check-order-status') {
    event.waitUntil(checkOrderUpdates());
  }

  if (event.tag === 'refresh-promotions') {
    event.waitUntil(refreshPromotions());
  }
});

async function checkOrderUpdates() {
  try {
    const response = await fetch('/api/orders/status');
    const data = await response.json();

    if (data.updates && data.updates.length > 0) {
      for (const update of data.updates) {
        await showOrderNotification(update);
      }
    }
  } catch (error) {
    console.error('[SW] Order status check failed:', error);
  }
}

async function refreshPromotions() {
  try {
    const response = await fetch('/api/promotions');
    const cache = await caches.open(DYNAMIC_CACHE);
    cache.put('/api/promotions', response.clone());
  } catch (error) {
    console.error('[SW] Promotions refresh failed:', error);
  }
}

// Push Notifications with Actions
self.addEventListener('push', (event) => {
  console.log('[SW] Push notification received');

  let data = { title: 'SuperBora', body: 'Nova notificacao!' };

  if (event.data) {
    try {
      data = event.data.json();
    } catch (e) {
      data.body = event.data.text();
    }
  }

  const options = {
    body: data.body,
    icon: `${BASE_PATH}/icons/icon-192.png`,
    badge: `${BASE_PATH}/icons/icon-192.png`,
    vibrate: [100, 50, 100],
    data: data.data || {},
    tag: data.tag || 'default',
    renotify: true,
    requireInteraction: data.requireInteraction || false,
    actions: getNotificationActions(data.type),
  };

  event.waitUntil(self.registration.showNotification(data.title, options));
});

// Get notification actions based on type
// Bug fix #10: Remove references to non-existent icons, use the main icon as fallback
// Note: Notification action icons are optional and many browsers don't display them.
// Using the main app icon as a safe fallback for all actions.
function getNotificationActions(type) {
  const defaultIcon = `${BASE_PATH}/icons/icon-192.png`;

  switch (type) {
    case 'order_delivered':
      return [
        { action: 'view', title: 'Ver Pedido', icon: defaultIcon },
        { action: 'rate', title: 'Avaliar', icon: defaultIcon },
      ];
    case 'order_ready':
      return [
        { action: 'view', title: 'Ver Pedido', icon: defaultIcon },
        { action: 'track', title: 'Rastrear', icon: defaultIcon },
      ];
    case 'promotion':
      return [
        { action: 'view', title: 'Ver Oferta', icon: defaultIcon },
        { action: 'dismiss', title: 'Ignorar', icon: defaultIcon },
      ];
    case 'chat_message':
      return [
        { action: 'reply', title: 'Responder', icon: defaultIcon },
        { action: 'view', title: 'Abrir Chat', icon: defaultIcon },
      ];
    default:
      return [
        { action: 'view', title: 'Ver', icon: defaultIcon },
      ];
  }
}

// Handle notification click actions
self.addEventListener('notificationclick', (event) => {
  console.log('[SW] Notification clicked:', event.action);
  event.notification.close();

  const data = event.notification.data || {};
  let url = `${BASE_PATH}/`;

  switch (event.action) {
    case 'view':
      if (data.orderId) {
        url = `${BASE_PATH}/pedidos?id=${data.orderId}`;
      } else if (data.promotionId) {
        url = `${BASE_PATH}/loja?promo=${data.promotionId}`;
      }
      break;
    case 'rate':
      url = `${BASE_PATH}/pedidos?id=${data.orderId}&rate=true`;
      break;
    case 'track':
      url = `${BASE_PATH}/pedidos?id=${data.orderId}&track=true`;
      break;
    case 'reply':
      url = `${BASE_PATH}/pedidos?id=${data.orderId}&chat=true`;
      break;
    case 'dismiss':
      return; // Just close the notification
    default:
      url = data.url || `${BASE_PATH}/`;
  }

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      // Focus existing window if available
      for (const client of clientList) {
        if (client.url.includes(BASE_PATH) && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      // Open new window
      if (clients.openWindow) {
        return clients.openWindow(url);
      }
    })
  );
});

// Handle notification close
self.addEventListener('notificationclose', (event) => {
  console.log('[SW] Notification closed:', event.notification.tag);
  // Track notification dismissal for analytics
  const data = event.notification.data || {};
  if (data.trackDismissal) {
    fetch('/api/analytics/notification-dismissed', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tag: event.notification.tag, ...data }),
    }).catch(() => {});
  }
});

// Show order notification helper
async function showOrderNotification(order) {
  const options = {
    body: `Pedido #${order.id}: ${order.status}`,
    icon: `${BASE_PATH}/icons/icon-192.png`,
    badge: `${BASE_PATH}/icons/icon-192.png`,
    tag: `order-${order.id}`,
    data: { orderId: order.id, type: order.notificationType },
    actions: getNotificationActions(order.notificationType),
  };

  await self.registration.showNotification('Atualização do Pedido', options);
}

// IndexedDB helpers for offline data
function openDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('superbora-offline', 1);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
    request.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains('pending')) {
        db.createObjectStore('pending', { keyPath: 'key' });
      }
    };
  });
}

async function getFromIndexedDB(key) {
  let db = null;
  try {
    db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction('pending', 'readonly');
      const store = tx.objectStore('pending');
      const request = store.get(key);
      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result?.data);
      // Bug fix #8: Close IndexedDB connection after transaction completes
      tx.oncomplete = () => {
        if (db) db.close();
      };
    });
  } catch (error) {
    console.error('[SW] IndexedDB get error:', error);
    // Bug fix #8: Ensure connection is closed even on error
    if (db) db.close();
    return null;
  }
}

async function deleteFromIndexedDB(key) {
  let db = null;
  try {
    db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction('pending', 'readwrite');
      const store = tx.objectStore('pending');
      const request = store.delete(key);
      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve();
      // Bug fix #8: Close IndexedDB connection after transaction completes
      tx.oncomplete = () => {
        if (db) db.close();
      };
    });
  } catch (error) {
    console.error('[SW] IndexedDB delete error:', error);
    // Bug fix #8: Ensure connection is closed even on error
    if (db) db.close();
  }
}

// Bug fix #14: Add saveToIndexedDB helper for retry count storage
async function saveToIndexedDB(key, data) {
  let db = null;
  try {
    db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction('pending', 'readwrite');
      const store = tx.objectStore('pending');
      const request = store.put({ key, data });
      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve();
      // Bug fix #8: Close IndexedDB connection after transaction completes
      tx.oncomplete = () => {
        if (db) db.close();
      };
    });
  } catch (error) {
    console.error('[SW] IndexedDB save error:', error);
    // Bug fix #8: Ensure connection is closed even on error
    if (db) db.close();
  }
}
