// Firebase Messaging Service Worker - SuperBora
importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: "AIzaSyC5943EdhMUcoDX4cX15UBnsO1Xihuf_sE",
  authDomain: "onemundo-52ca6.firebaseapp.com",
  projectId: "onemundo-52ca6",
  storageBucket: "onemundo-52ca6.firebasestorage.app",
  messagingSenderId: "782929446226",
  appId: "1:782929446226:web:90a36e056b392a6294268b"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
  console.log('[SW] Background message:', payload);

  const notificationTitle = payload.notification?.title || 'SuperBora';
  const notificationOptions = {
    body: payload.notification?.body || '',
    icon: '/mercado/assets/img/icon-192.png',
    badge: '/mercado/assets/img/badge-72.png',
    tag: 'superbora-' + Date.now(),
    data: payload.data || {}
  };

  return self.registration.showNotification(notificationTitle, notificationOptions);
});

// Handle notification click
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/mercado/vitrine/';
  event.waitUntil(clients.openWindow(url));
});
