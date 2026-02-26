<?php
/**
 * BoraUm & SuperBora Microservices Client
 * Bridge between PHP apps and Go microservices
 * Used by: BoraUm Passageiro, BoraUm Motorista, SuperBora
 */
class MicroServiceClient {
    private static $instance = null;
    private $services = [
        'auth'          => 'http://127.0.0.1:8501',
        'payments'      => 'http://127.0.0.1:8502',
        'notifications' => 'http://127.0.0.1:8503',
        'catalog'       => 'http://127.0.0.1:8504',
        'orders'        => 'http://127.0.0.1:8505',
        'chat'          => 'http://127.0.0.1:8506',
        'ratings'       => 'http://127.0.0.1:8507',
        'delivery'      => 'http://127.0.0.1:8508',
        'admin'         => 'http://127.0.0.1:8509',
        'gps'           => 'http://127.0.0.1:8500',
    ];

    private $timeout = 5;
    private $connectTimeout = 2;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ==================== AUTH ====================

    public function loginPhone($phone, $code, $appType = 'passenger') {
        return $this->post('auth', '/auth/login/phone', [
            'phone' => $phone, 'code' => $code, 'app_type' => $appType
        ]);
    }

    public function loginEmail($email, $password, $appType = 'passenger') {
        return $this->post('auth', '/auth/login/email', [
            'email' => $email, 'password' => $password, 'app_type' => $appType
        ]);
    }

    public function sendOTP($phone, $appType = 'passenger') {
        return $this->post('auth', '/auth/otp/send', [
            'phone' => $phone, 'app_type' => $appType
        ]);
    }

    public function validateToken($token) {
        return $this->post('auth', '/auth/validate', ['token' => $token]);
    }

    public function refreshTokens($refreshToken) {
        return $this->post('auth', '/auth/refresh', ['refresh_token' => $refreshToken]);
    }

    public function logout($accessToken, $refreshToken = '') {
        return $this->post('auth', '/auth/logout', [
            'access_token' => $accessToken, 'refresh_token' => $refreshToken
        ]);
    }

    public function getProfile($userID, $appType = 'passenger') {
        return $this->get('auth', "/auth/profile/{$appType}/{$userID}");
    }

    // ==================== PAYMENTS ====================

    public function getBalance($userID, $appType = 'passenger') {
        return $this->get('payments', "/payments/balance/{$appType}/{$userID}");
    }

    public function processPayment($userID, $amount, $method, $description, $refID = '', $refType = 'ride', $appType = 'passenger') {
        return $this->post('payments', '/payments/pay', [
            'user_id' => $userID, 'amount' => $amount, 'method' => $method,
            'description' => $description, 'reference_id' => $refID,
            'reference_type' => $refType, 'app_type' => $appType
        ]);
    }

    public function creditWallet($userID, $amount, $description, $appType = 'passenger') {
        return $this->post('payments', '/payments/credit', [
            'user_id' => $userID, 'amount' => $amount, 'description' => $description, 'app_type' => $appType
        ]);
    }

    public function generatePixQR($userID, $amount, $appType = 'passenger') {
        return $this->post('payments', '/payments/pix/generate', [
            'user_id' => $userID, 'amount' => $amount, 'app_type' => $appType
        ]);
    }

    public function getTransactions($userID, $appType = 'passenger', $limit = 20, $offset = 0) {
        return $this->get('payments', "/payments/transactions/{$appType}/{$userID}?limit={$limit}&offset={$offset}");
    }

    public function getPaymentMethods($userID, $appType = 'passenger') {
        return $this->get('payments', "/payments/methods/{$appType}/{$userID}");
    }

    public function requestWithdrawal($userID, $amount, $pixKey, $appType = 'driver') {
        return $this->post('payments', '/payments/withdraw', [
            'user_id' => $userID, 'amount' => $amount, 'pix_key' => $pixKey, 'app_type' => $appType
        ]);
    }

    // ==================== NOTIFICATIONS ====================

    public function sendNotification($userID, $title, $body, $type = 'push', $channel = 'system', $appType = 'passenger', $data = []) {
        return $this->post('notifications', '/notifications/send', [
            'user_id' => $userID, 'app_type' => $appType, 'title' => $title,
            'body' => $body, 'type' => $type, 'channel' => $channel, 'data' => $data
        ]);
    }

    public function getNotifications($userID, $appType = 'passenger', $limit = 20, $offset = 0) {
        return $this->get('notifications', "/notifications/{$appType}/{$userID}?limit={$limit}&offset={$offset}");
    }

    public function getUnreadCount($userID, $appType = 'passenger') {
        return $this->get('notifications', "/notifications/unread/{$appType}/{$userID}");
    }

    public function markNotificationRead($notifID, $userID, $appType = 'passenger') {
        return $this->post('notifications', "/notifications/read/{$notifID}", [
            'user_id' => $userID, 'app_type' => $appType
        ]);
    }

    public function registerDevice($userID, $fcmToken, $platform = 'android', $appType = 'passenger') {
        return $this->post('notifications', '/notifications/device', [
            'user_id' => $userID, 'app_type' => $appType, 'fcm_token' => $fcmToken, 'platform' => $platform
        ]);
    }

    // ==================== CATALOG/VITRINE (SuperBora) ====================

    public function getVitrine($lat = 0, $lng = 0) {
        return $this->get('catalog', "/catalog/vitrine?lat={$lat}&lng={$lng}");
    }

    public function searchProducts($query, $categoryID = 0, $storeID = 0, $page = 1, $perPage = 20) {
        return $this->get('catalog', "/catalog/search?q=" . urlencode($query) . "&category_id={$categoryID}&store_id={$storeID}&page={$page}&per_page={$perPage}");
    }

    public function getProduct($productID) {
        return $this->get('catalog', "/catalog/product/{$productID}");
    }

    public function getStore($storeID) {
        return $this->get('catalog', "/catalog/store/{$storeID}");
    }

    public function getNearbyStores($lat, $lng, $radius = 10, $limit = 20) {
        return $this->get('catalog', "/catalog/stores/nearby?lat={$lat}&lng={$lng}&radius={$radius}&limit={$limit}");
    }

    public function getCategories() {
        return $this->get('catalog', '/catalog/categories');
    }

    public function getBanners() {
        return $this->get('catalog', '/catalog/banners');
    }

    // ==================== ORDERS (SuperBora) ====================

    public function createOrder($userID, $storeID, $items, $paymentMethod, $address, $lat, $lng, $notes = '', $coupon = '') {
        return $this->post('orders', '/orders/create', [
            'user_id' => $userID, 'store_id' => $storeID, 'items' => $items,
            'payment_method' => $paymentMethod, 'address' => $address,
            'lat' => $lat, 'lng' => $lng, 'notes' => $notes, 'coupon_code' => $coupon
        ]);
    }

    public function getOrder($orderID) {
        return $this->get('orders', "/orders/{$orderID}");
    }

    public function updateOrderStatus($orderID, $status) {
        return $this->post('orders', "/orders/{$orderID}/status", ['status' => $status], 'PUT');
    }

    public function getUserOrders($userID, $status = '', $limit = 20, $offset = 0) {
        return $this->get('orders', "/orders/user/{$userID}?status={$status}&limit={$limit}&offset={$offset}");
    }

    public function cancelOrder($orderID, $userID, $reason = '') {
        return $this->post('orders', "/orders/{$orderID}/cancel", ['user_id' => $userID, 'reason' => $reason]);
    }

    // ==================== CHAT ====================

    public function sendChatMessage($roomID, $senderID, $content, $type = 'text', $appType = 'passenger') {
        return $this->post('chat', '/chat/send', [
            'room_id' => $roomID, 'sender_id' => $senderID, 'content' => $content,
            'type' => $type, 'app_type' => $appType
        ]);
    }

    public function getChatMessages($roomID, $limit = 50, $offset = 0) {
        return $this->get('chat', "/chat/messages/{$roomID}?limit={$limit}&offset={$offset}");
    }

    public function getOrCreateRoom($type, $refID, $userIDs) {
        return $this->post('chat', '/chat/room', [
            'type' => $type, 'ref_id' => $refID, 'user_ids' => $userIDs
        ]);
    }

    public function getUserRooms($userID) {
        return $this->get('chat', "/chat/rooms/{$userID}");
    }

    // ==================== RATINGS ====================

    public function rate($fromUserID, $toUserID, $refType, $refID, $stars, $comment = '', $tags = [], $appType = 'passenger') {
        return $this->post('ratings', '/ratings/rate', [
            'from_user_id' => $fromUserID, 'to_user_id' => $toUserID,
            'ref_type' => $refType, 'ref_id' => $refID, 'stars' => $stars,
            'comment' => $comment, 'tags' => $tags, 'app_type' => $appType
        ]);
    }

    public function getUserRating($userID, $appType = 'passenger') {
        return $this->get('ratings', "/ratings/user/{$appType}/{$userID}");
    }

    public function getReviews($refType, $refID, $limit = 20, $offset = 0) {
        return $this->get('ratings', "/ratings/reviews/{$refType}/{$refID}?limit={$limit}&offset={$offset}");
    }

    // ==================== DELIVERY (SuperBora) ====================

    public function updateShopperLocation($shopperID, $lat, $lng, $heading = 0, $speed = 0) {
        return $this->post('delivery', '/delivery/shopper/location', [
            'shopper_id' => $shopperID, 'lat' => $lat, 'lng' => $lng,
            'heading' => $heading, 'speed' => $speed
        ]);
    }

    public function findNearbyShoppers($lat, $lng, $radius = 5, $limit = 10) {
        return $this->get('delivery', "/delivery/shoppers/nearby?lat={$lat}&lng={$lng}&radius={$radius}&limit={$limit}");
    }

    public function createDelivery($orderID, $pickupAddr, $pickupLat, $pickupLng, $delivAddr, $delivLat, $delivLng) {
        return $this->post('delivery', '/delivery/create', [
            'order_id' => $orderID, 'pickup_addr' => $pickupAddr,
            'pickup_lat' => $pickupLat, 'pickup_lng' => $pickupLng,
            'delivery_addr' => $delivAddr, 'delivery_lat' => $delivLat, 'delivery_lng' => $delivLng
        ]);
    }

    public function trackDelivery($deliveryID) {
        return $this->get('delivery', "/delivery/{$deliveryID}/track");
    }

    public function updateDeliveryStatus($deliveryID, $status) {
        return $this->post('delivery', "/delivery/{$deliveryID}/status", ['status' => $status], 'PUT');
    }

    // ==================== ADMIN ====================

    public function getDashboard() {
        return $this->get('admin', '/admin/dashboard');
    }

    public function getAnalytics($period = 'daily', $days = 30) {
        return $this->get('admin', "/admin/analytics?period={$period}&days={$days}");
    }

    public function getUserStats() {
        return $this->get('admin', '/admin/users');
    }

    public function getFinancials($startDate = '', $endDate = '') {
        return $this->get('admin', "/admin/financials?start={$startDate}&end={$endDate}");
    }

    // ==================== GPS (existing service) ====================

    public function updateDriverGPS($driverID, $lat, $lng, $heading = 0, $speed = 0, $rideID = 0) {
        return $this->post('gps', '/gps/update', [
            'driver_id' => $driverID, 'latitude' => $lat, 'longitude' => $lng,
            'heading' => $heading, 'speed' => $speed, 'ride_id' => $rideID
        ]);
    }

    public function findNearbyDrivers($lat, $lng, $radius = 5, $limit = 10) {
        return $this->get('gps', "/drivers/nearby?lat={$lat}&lng={$lng}&radius={$radius}&limit={$limit}");
    }

    public function dispatch($passengerID, $lat, $lng, $destLat, $destLng, $vehicleType = 'standard') {
        return $this->post('gps', '/dispatch/find', [
            'passenger_id' => $passengerID, 'origin_lat' => $lat, 'origin_lng' => $lng,
            'dest_lat' => $destLat, 'dest_lng' => $destLng, 'vehicle_type' => $vehicleType
        ]);
    }

    // ==================== HEALTH ====================

    public function healthCheckAll() {
        $results = [];
        foreach ($this->services as $name => $url) {
            $results[$name] = $this->get($name, '/health');
        }
        return $results;
    }

    // ==================== HTTP Client ====================

    private function get($service, $path) {
        return $this->request('GET', $service, $path);
    }

    private function post($service, $path, $data = [], $method = 'POST') {
        return $this->request($method, $service, $path, $data);
    }

    private function request($method, $service, $path, $data = null) {
        $url = $this->services[$service] . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($data !== null && ($method === 'POST' || $method === 'PUT')) {
            $json = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($json)]);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => $error, 'http_code' => 0];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['error' => 'Invalid JSON response', 'raw' => $response, 'http_code' => $httpCode];
        }

        return $decoded;
    }
}
