// Firebase Configuration - SuperBora
// Used by all apps (vitrine, shopper, painel)
const firebaseConfig = {
  apiKey: "AIzaSyC5943EdhMUcoDX4cX15UBnsO1Xihuf_sE",
  authDomain: "onemundo-52ca6.firebaseapp.com",
  projectId: "onemundo-52ca6",
  storageBucket: "onemundo-52ca6.firebasestorage.app",
  messagingSenderId: "782929446226",
  appId: "1:782929446226:web:90a36e056b392a6294268b",
  measurementId: "G-TCHEBQZD73"
};

// Register push token with backend
async function registerPushToken(token, userType) {
  const authToken = localStorage.getItem('token');
  if (!authToken) return;

  try {
    await fetch('/api/mercado/notifications/register-token.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + authToken
      },
      body: JSON.stringify({
        token: token,
        device_type: 'web',
        user_type: userType
      })
    });
  } catch (e) {
    console.error('Erro ao registrar token push:', e);
  }
}

// Initialize Firebase Messaging
async function initFirebaseMessaging(userType) {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    console.log('Push notifications nao suportadas');
    return null;
  }

  try {
    // Dynamic import
    const { initializeApp } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-app.js');
    const { getMessaging, getToken, onMessage } = await import('https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging.js');

    const app = initializeApp(firebaseConfig);
    const messaging = getMessaging(app);

    // Register service worker
    const registration = await navigator.serviceWorker.register('/mercado/firebase-messaging-sw.js');

    // Request permission
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      console.log('Permissao de notificacao negada');
      return null;
    }

    // Get token
    const token = await getToken(messaging, {
      vapidKey: '', // Usar VAPID key quando configurada
      serviceWorkerRegistration: registration
    });

    if (token) {
      await registerPushToken(token, userType);
      console.log('Push token registrado');
    }

    // Handle foreground messages
    onMessage(messaging, (payload) => {
      console.log('Mensagem recebida:', payload);
      const { title, body, icon } = payload.notification || {};
      if (title) {
        new Notification(title, {
          body: body || '',
          icon: icon || '/mercado/assets/img/icon-192.png'
        });
      }
    });

    return messaging;
  } catch (e) {
    console.error('Erro Firebase Messaging:', e);
    return null;
  }
}
