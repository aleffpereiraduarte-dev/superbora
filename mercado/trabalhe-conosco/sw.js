/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║                    📱 SERVICE WORKER - PUSH NOTIFICATIONS                            ║
 * ║                          OneMundo Worker App                                         ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

const CACHE_NAME = 'onemundo-worker-v1';
const urlsToCache = [
    '/mercado/trabalhe-conosco/app.php',
    '/mercado/trabalhe-conosco/notificacao-pedido.php',
    'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap'
];

// Instalação do Service Worker
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Cache aberto');
                return cache.addAll(urlsToCache);
            })
    );
    self.skipWaiting();
});

// Ativação
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// Push Notification recebida
self.addEventListener('push', event => {
    console.log('Push recebido:', event);
    
    let data = {
        title: '🛒 Novo Pedido!',
        body: 'Você tem um novo pedido disponível',
        icon: '/mercado/trabalhe-conosco/assets/icon-192.png',
        badge: '/mercado/trabalhe-conosco/assets/badge.png',
        tag: 'new-order',
        requireInteraction: true,
        actions: [
            { action: 'accept', title: '✅ Aceitar' },
            { action: 'decline', title: '❌ Recusar' }
        ],
        vibrate: [200, 100, 200, 100, 200],
        data: {
            url: '/mercado/trabalhe-conosco/notificacao-pedido.php'
        }
    };
    
    if (event.data) {
        try {
            const pushData = event.data.json();
            data = { ...data, ...pushData };
        } catch (e) {
            data.body = event.data.text();
        }
    }
    
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: data.icon,
            badge: data.badge,
            tag: data.tag,
            requireInteraction: data.requireInteraction,
            actions: data.actions,
            vibrate: data.vibrate,
            data: data.data
        })
    );
});

// Clique na notificação
self.addEventListener('notificationclick', event => {
    console.log('Notificação clicada:', event);
    
    event.notification.close();
    
    const action = event.action;
    const url = event.notification.data?.url || '/mercado/trabalhe-conosco/app.php';
    
    if (action === 'accept') {
        // Aceitar pedido
        event.waitUntil(
            clients.matchAll({ type: 'window' }).then(clientList => {
                // Se já tem uma janela aberta, foca nela
                for (const client of clientList) {
                    if (client.url.includes('trabalhe-conosco') && 'focus' in client) {
                        client.postMessage({ type: 'ACCEPT_ORDER' });
                        return client.focus();
                    }
                }
                // Senão, abre uma nova
                if (clients.openWindow) {
                    return clients.openWindow(url + '?action=accept');
                }
            })
        );
    } else if (action === 'decline') {
        // Recusar pedido
        event.waitUntil(
            clients.matchAll({ type: 'window' }).then(clientList => {
                for (const client of clientList) {
                    if (client.url.includes('trabalhe-conosco')) {
                        client.postMessage({ type: 'DECLINE_ORDER' });
                        return;
                    }
                }
            })
        );
    } else {
        // Clique normal - abrir app
        event.waitUntil(
            clients.matchAll({ type: 'window' }).then(clientList => {
                for (const client of clientList) {
                    if (client.url.includes('trabalhe-conosco') && 'focus' in client) {
                        return client.focus();
                    }
                }
                if (clients.openWindow) {
                    return clients.openWindow(url);
                }
            })
        );
    }
});

// Fechar notificação
self.addEventListener('notificationclose', event => {
    console.log('Notificação fechada:', event);
});

// Mensagens do app
self.addEventListener('message', event => {
    console.log('Mensagem recebida:', event.data);
    
    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
