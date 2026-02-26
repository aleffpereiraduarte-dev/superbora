#!/usr/bin/env php
<?php
/**
 * SuperBora WebSocket Server
 * Real-time notifications for partner panel
 *
 * Usage: php server.php
 * Listens on port 8080
 */

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

/**
 * WebSocket Handler for Partner Notifications
 */
class PartnerNotificationServer implements MessageComponentInterface
{
    protected $clients;
    protected $partnerConnections; // partner_id => [connections]
    protected $connectionPartner;  // connection resourceId => partner_id
    protected $channels;           // channel_name => [connections]

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->partnerConnections = [];
        $this->connectionPartner = [];
        $this->channels = [];

        echo "WebSocket Server initialized\n";
    }

    /**
     * New connection opened
     */
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection: {$conn->resourceId}\n";

        // Send welcome message requesting authentication
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'Connected to SuperBora WebSocket Server',
            'connectionId' => $conn->resourceId,
            'action_required' => 'authenticate'
        ]));
    }

    /**
     * Message received from client
     */
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);

        if (!$data || !isset($data['type'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Invalid message format'
            ]));
            return;
        }

        echo "Message from {$from->resourceId}: {$data['type']}\n";

        switch ($data['type']) {
            case 'auth':
                $this->handleAuth($from, $data);
                break;

            case 'subscribe':
                $this->handleSubscribe($from, $data);
                break;

            case 'unsubscribe':
                $this->handleUnsubscribe($from, $data);
                break;

            case 'ping':
                $from->send(json_encode(['type' => 'pong', 'timestamp' => time()]));
                break;

            case 'broadcast':
                // Internal broadcast (from API)
                $this->handleBroadcast($data);
                break;

            default:
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Unknown message type'
                ]));
        }
    }

    /**
     * Handle partner authentication
     */
    protected function handleAuth(ConnectionInterface $conn, array $data)
    {
        if (!isset($data['partner_id']) || !isset($data['token'])) {
            $conn->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Missing partner_id or token'
            ]));
            return;
        }

        $partnerId = (int) $data['partner_id'];
        $token = $data['token'];

        // Validate token (simple validation - in production, verify against database)
        if (!$this->validateToken($partnerId, $token)) {
            $conn->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Invalid authentication token'
            ]));
            return;
        }

        // Register connection for this partner
        if (!isset($this->partnerConnections[$partnerId])) {
            $this->partnerConnections[$partnerId] = [];
        }
        $this->partnerConnections[$partnerId][$conn->resourceId] = $conn;
        $this->connectionPartner[$conn->resourceId] = $partnerId;

        // Auto-subscribe to partner channel
        $partnerChannel = "partner_{$partnerId}";
        $this->subscribeToChannel($conn, $partnerChannel);

        $conn->send(json_encode([
            'type' => 'auth_success',
            'partner_id' => $partnerId,
            'subscribed_channels' => [$partnerChannel]
        ]));

        echo "Partner {$partnerId} authenticated (connection {$conn->resourceId})\n";
    }

    /**
     * Validate authentication token
     */
    protected function validateToken(int $partnerId, string $token): bool
    {
        // Simple token validation
        // In production: query database to verify token

        // For now, accept tokens that match pattern or are valid JWT
        if (empty($token)) {
            return false;
        }

        // Check if it's a valid format (simple check)
        // In production, verify against database session or JWT
        if (strlen($token) < 10) {
            return false;
        }

        // TODO: Add database validation
        // $db = new PDO(...);
        // $stmt = $db->prepare("SELECT id FROM partner_sessions WHERE partner_id = ? AND token = ? AND expires_at > NOW()");
        // $stmt->execute([$partnerId, $token]);
        // return $stmt->fetch() !== false;

        return true;
    }

    /**
     * Handle channel subscription
     */
    protected function handleSubscribe(ConnectionInterface $conn, array $data)
    {
        if (!isset($this->connectionPartner[$conn->resourceId])) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Must authenticate before subscribing'
            ]));
            return;
        }

        if (!isset($data['channel'])) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Missing channel name'
            ]));
            return;
        }

        $channel = $data['channel'];
        $partnerId = $this->connectionPartner[$conn->resourceId];

        // Security: only allow subscribing to own partner channel or public channels
        if (strpos($channel, 'partner_') === 0) {
            $channelPartnerId = (int) str_replace('partner_', '', $channel);
            if ($channelPartnerId !== $partnerId) {
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => 'Cannot subscribe to other partner channels'
                ]));
                return;
            }
        }

        $this->subscribeToChannel($conn, $channel);

        $conn->send(json_encode([
            'type' => 'subscribed',
            'channel' => $channel
        ]));
    }

    /**
     * Subscribe connection to channel
     */
    protected function subscribeToChannel(ConnectionInterface $conn, string $channel)
    {
        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }
        $this->channels[$channel][$conn->resourceId] = $conn;
    }

    /**
     * Handle channel unsubscription
     */
    protected function handleUnsubscribe(ConnectionInterface $conn, array $data)
    {
        if (!isset($data['channel'])) {
            return;
        }

        $channel = $data['channel'];

        if (isset($this->channels[$channel][$conn->resourceId])) {
            unset($this->channels[$channel][$conn->resourceId]);

            $conn->send(json_encode([
                'type' => 'unsubscribed',
                'channel' => $channel
            ]));
        }
    }

    /**
     * Handle broadcast message (from API)
     */
    protected function handleBroadcast(array $data)
    {
        if (!isset($data['target']) || !isset($data['payload'])) {
            return;
        }

        $target = $data['target'];
        $payload = $data['payload'];

        // Broadcast to specific partner
        if (isset($target['partner_id'])) {
            $this->sendToPartner($target['partner_id'], $payload);
        }

        // Broadcast to channel
        if (isset($target['channel'])) {
            $this->sendToChannel($target['channel'], $payload);
        }

        // Broadcast to all
        if (isset($target['all']) && $target['all'] === true) {
            $this->sendToAll($payload);
        }
    }

    /**
     * Send message to specific partner
     */
    public function sendToPartner(int $partnerId, array $payload)
    {
        if (!isset($this->partnerConnections[$partnerId])) {
            echo "No connections for partner {$partnerId}\n";
            return;
        }

        $message = json_encode($payload);

        foreach ($this->partnerConnections[$partnerId] as $conn) {
            $conn->send($message);
        }

        echo "Sent to partner {$partnerId}: {$payload['type']}\n";
    }

    /**
     * Send message to channel
     */
    public function sendToChannel(string $channel, array $payload)
    {
        if (!isset($this->channels[$channel])) {
            return;
        }

        $message = json_encode($payload);

        foreach ($this->channels[$channel] as $conn) {
            $conn->send($message);
        }

        echo "Sent to channel {$channel}: {$payload['type']}\n";
    }

    /**
     * Send message to all connected clients
     */
    public function sendToAll(array $payload)
    {
        $message = json_encode($payload);

        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }

    /**
     * Connection closed
     */
    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);

        // Remove from partner connections
        if (isset($this->connectionPartner[$conn->resourceId])) {
            $partnerId = $this->connectionPartner[$conn->resourceId];
            unset($this->partnerConnections[$partnerId][$conn->resourceId]);
            unset($this->connectionPartner[$conn->resourceId]);

            if (empty($this->partnerConnections[$partnerId])) {
                unset($this->partnerConnections[$partnerId]);
            }

            echo "Partner {$partnerId} disconnected (connection {$conn->resourceId})\n";
        }

        // Remove from all channels
        foreach ($this->channels as $channel => $connections) {
            unset($this->channels[$channel][$conn->resourceId]);
            if (empty($this->channels[$channel])) {
                unset($this->channels[$channel]);
            }
        }

        echo "Connection {$conn->resourceId} closed\n";
    }

    /**
     * Error occurred
     */
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error on connection {$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    /**
     * Get server stats
     */
    public function getStats(): array
    {
        return [
            'total_connections' => $this->clients->count(),
            'authenticated_partners' => count($this->partnerConnections),
            'active_channels' => count($this->channels)
        ];
    }
}

// Server configuration
$host = '0.0.0.0';
$port = 8080;

echo "========================================\n";
echo "  SuperBora WebSocket Server\n";
echo "========================================\n";
echo "Starting server on {$host}:{$port}\n";
echo "Press Ctrl+C to stop\n";
echo "----------------------------------------\n";

try {
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new PartnerNotificationServer()
            )
        ),
        $port,
        $host
    );

    echo "Server running!\n";
    echo "WebSocket URL: ws://superbora.com.br:{$port}\n";
    echo "----------------------------------------\n";

    $server->run();
} catch (Exception $e) {
    echo "Failed to start server: {$e->getMessage()}\n";
    exit(1);
}
