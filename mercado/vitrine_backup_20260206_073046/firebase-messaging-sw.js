/**
 * ====================================================================
 * Firebase Cloud Messaging Service Worker
 * ====================================================================
 * Handles background push notifications for SuperBora delivery app.
 * This service worker is registered alongside the main sw.js for PWA
 * caching, but specifically handles Firebase messaging events.
 *
 * Deployed at: /mercado/vitrine/firebase-messaging-sw.js
 * ====================================================================
 */

// Import Firebase scripts (compat version for service worker compatibility)
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js');

/**
 * Firebase configuration
 *
 * Bug fix #4: IMPORTANT - These are PUBLIC Firebase keys and are meant to be exposed.
 * Firebase security is handled through Security Rules, not key secrecy.
 * These keys are designed to be included in client-side code.
 *
 * - apiKey: Identifies the Firebase project (not a secret)
 * - authDomain: OAuth redirect domain
 * - projectId: Firebase project identifier
 * - storageBucket: Cloud Storage bucket
 * - messagingSenderId: Cloud Messaging sender ID (required for push notifications)
 * - appId: Firebase app identifier
 * - measurementId: Google Analytics identifier
 *
 * Security is enforced via:
 * - Firebase Security Rules (Firestore, Storage, Realtime Database)
 * - App Check (optional, for additional protection)
 * - Authentication state requirements in rules
 */
firebase.initializeApp({
  apiKey: 'AIzaSyC5943EdhMUcoDX4cX15UBnsO1Xihuf_sE',
  authDomain: 'onemundo-52ca6.firebaseapp.com',
  projectId: 'onemundo-52ca6',
  storageBucket: 'onemundo-52ca6.firebasestorage.app',
  messagingSenderId: '782929446226',
  appId: '1:782929446226:web:90a36e056b392a6294268b',
  measurementId: 'G-TCHEBQZD73',
});

const messaging = firebase.messaging();

/**
 * Handle background messages (when the app is not in focus)
 * Foreground messages are handled by the React hook usePushNotifications.
 */
messaging.onBackgroundMessage((payload) => {
  console.log('[firebase-messaging-sw] Background message received:', payload);

  // Bug fix #12: Add try-catch around notification display
  try {
    const notificationTitle = payload.notification?.title || payload.data?.title || 'SuperBora';
    const notificationOptions = {
      body: payload.notification?.body || payload.data?.body || '',
      icon: payload.notification?.icon || '/mercado/vitrine/icons/icon-192.png',
      badge: payload.notification?.badge || '/mercado/vitrine/icons/icon-192.png',
      tag: payload.data?.tag || 'superbora-notification-' + Date.now(),
      data: {
        url: payload.data?.url || payload.fcmOptions?.link || '/mercado/vitrine/',
        order_id: payload.data?.order_id || null,
        type: payload.data?.type || 'general',
        ...payload.data,
      },
      // Vibration pattern for delivery notifications
      vibrate: [200, 100, 200],
      // Renotify even if same tag (important for order updates)
      renotify: true,
      // Keep notification until user interacts
      requireInteraction: payload.data?.type === 'new_order' || payload.data?.type === 'order_delivered',
      // Action buttons based on notification type
      actions: getActionsForType(payload.data?.type),
    };

    return self.registration.showNotification(notificationTitle, notificationOptions);
  } catch (error) {
    console.error('[firebase-messaging-sw] Failed to display notification:', error);
    // Attempt to show a basic notification as fallback
    try {
      return self.registration.showNotification('SuperBora', {
        body: 'Nova notificacao recebida',
        icon: '/mercado/vitrine/icons/icon-192.png',
      });
    } catch (fallbackError) {
      console.error('[firebase-messaging-sw] Fallback notification also failed:', fallbackError);
    }
  }
});

/**
 * Handle notification click - open the relevant page
 */
self.addEventListener('notificationclick', (event) => {
  console.log('[firebase-messaging-sw] Notification clicked:', event);

  event.notification.close();

  const urlPath = event.notification.data?.url || '/mercado/vitrine/';
  const fullUrl = self.location.origin + urlPath;

  // Handle action button clicks
  if (event.action === 'view_order') {
    const orderId = event.notification.data?.order_id;
    const orderUrl = orderId
      ? self.location.origin + '/mercado/vitrine/pedidos/?id=' + orderId
      : self.location.origin + '/mercado/vitrine/pedidos/';

    event.waitUntil(openOrFocusWindow(orderUrl));
    return;
  }

  if (event.action === 'rate_order') {
    const orderId = event.notification.data?.order_id;
    const rateUrl = self.location.origin + '/mercado/vitrine/pedidos/?id=' + orderId + '&avaliar=1';
    event.waitUntil(openOrFocusWindow(rateUrl));
    return;
  }

  // Default: open the URL from the notification data
  event.waitUntil(openOrFocusWindow(fullUrl));
});

/**
 * Handle notification close (for analytics/tracking)
 */
self.addEventListener('notificationclose', (event) => {
  console.log('[firebase-messaging-sw] Notification dismissed:', event.notification.tag);
});

/**
 * Open a window or focus an existing one with the given URL
 */
async function openOrFocusWindow(url) {
  const windowClients = await self.clients.matchAll({
    type: 'window',
    includeUncontrolled: true,
  });

  // Try to find an existing window/tab with a matching URL
  for (const client of windowClients) {
    if (client.url.includes('/mercado/vitrine') && 'focus' in client) {
      await client.focus();
      // Navigate to the specific URL
      if (client.url !== url) {
        await client.navigate(url);
      }
      return;
    }
  }

  // No existing window found - open a new one
  if (self.clients.openWindow) {
    return self.clients.openWindow(url);
  }
}

/**
 * Return action buttons based on notification type
 */
function getActionsForType(type) {
  switch (type) {
    case 'new_order':
    case 'order_confirmed':
    case 'order_status_confirmar':
    case 'order_status_preparando':
    case 'order_status_pronto':
      return [
        { action: 'view_order', title: 'Ver pedido', icon: '/mercado/vitrine/icons/icon-192.png' },
      ];

    case 'order_delivered':
      return [
        { action: 'view_order', title: 'Ver pedido', icon: '/mercado/vitrine/icons/icon-192.png' },
        { action: 'rate_order', title: 'Avaliar', icon: '/mercado/vitrine/icons/icon-192.png' },
      ];

    default:
      return [];
  }
}
