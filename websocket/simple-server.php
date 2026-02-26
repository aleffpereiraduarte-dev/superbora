#!/usr/bin/env php
<?php
/**
 * SuperBora Simple WebSocket Server
 * Lightweight alternative without Ratchet dependencies
 *
 * Usage: php simple-server.php
 * Listens on port 8080
 */

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

class SimpleWebSocketServer
{
    private $socket;
    private $clients = [];
    private $partnerConnections = []; // partner_id => [socket_ids]
    private $socketPartner = [];      // socket_id => partner_id
    private $channels = [];           // channel => [socket_ids]
    private $host;
    private $port;

    public function __construct(string $host = '0.0.0.0', int $port = 8080)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function run()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($this->socket, $this->host, $this->port);
        socket_listen($this->socket);

        $this->clients[] = $this->socket;

        $this->log("Server started on {$this->host}:{$this->port}");
        $this->log("WebSocket URL: ws://superbora.com.br:{$this->port}");

        while (true) {
            $read = $this->clients;
            $write = null;
            $except = null;

            if (socket_select($read, $write, $except, 0, 10000) === false) {
                continue;
            }

            // Check for new connections
            if (in_array($this->socket, $read)) {
                $newSocket = socket_accept($this->socket);
                $this->clients[] = $newSocket;

                // Perform WebSocket handshake
                $header = socket_read($newSocket, 1024);
                $this->performHandshake($header, $newSocket);

                $socketId = $this->getSocketId($newSocket);
                $this->log("New connection: {$socketId}");

                // Send welcome message
                $this->sendToSocket($newSocket, [
                    'type' => 'welcome',
                    'message' => 'Connected to SuperBora WebSocket Server',
                    'connectionId' => $socketId,
                    'action_required' => 'authenticate'
                ]);

                // Remove master socket from read array
                $key = array_search($this->socket, $read);
                unset($read[$key]);
            }

            // Handle messages from clients
            foreach ($read as $socket) {
                $data = @socket_read($socket, 65535);

                if ($data === false || strlen($data) === 0) {
                    $this->disconnectClient($socket);
                    continue;
                }

                $message = $this->unmask($data);

                if (empty($message)) {
                    continue;
                }

                $this->handleMessage($socket, $message);
            }
        }
    }

    private function performHandshake(string $header, $socket)
    {
        $headers = [];
        $lines = preg_split("/\r\n/", $header);

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $matches)) {
                $headers[strtolower($matches[1])] = $matches[2];
            }
        }

        $secKey = $headers['sec-websocket-key'] ?? '';
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept: {$secAccept}\r\n\r\n";

        socket_write($socket, $response, strlen($response));
    }

    private function unmask(string $payload): string
    {
        if (strlen($payload) < 2) {
            return '';
        }

        $length = ord($payload[1]) & 127;

        if ($length === 126) {
            $masks = substr($payload, 4, 4);
            $data = substr($payload, 8);
        } elseif ($length === 127) {
            $masks = substr($payload, 10, 4);
            $data = substr($payload, 14);
        } else {
            $masks = substr($payload, 2, 4);
            $data = substr($payload, 6);
        }

        $text = '';
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }

        return $text;
    }

    private function mask(string $text): string
    {
        $b1 = 0x81;
        $length = strlen($text);

        if ($length <= 125) {
            $header = pack('CC', $b1, $length);
        } elseif ($length <= 65535) {
            $header = pack('CCn', $b1, 126, $length);
        } else {
            $header = pack('CCNN', $b1, 127, 0, $length);
        }

        return $header . $text;
    }

    private function getSocketId($socket): int
    {
        return (int) $socket;
    }

    private function handleMessage($socket, string $message)
    {
        $data = json_decode($message, true);
        $socketId = $this->getSocketId($socket);

        if (!$data || !isset($data['type'])) {
            $this->sendToSocket($socket, [
                'type' => 'error',
                'message' => 'Invalid message format'
            ]);
            return;
        }

        $this->log("Message from {$socketId}: {$data['type']}");

        switch ($data['type']) {
            case 'auth':
                $this->handleAuth($socket, $data);
                break;

            case 'subscribe':
                $this->handleSubscribe($socket, $data);
                break;

            case 'unsubscribe':
                $this->handleUnsubscribe($socket, $data);
                break;

            case 'ping':
                $this->sendToSocket($socket, ['type' => 'pong', 'timestamp' => time()]);
                break;

            case 'broadcast':
                $this->handleBroadcast($data);
                break;

            default:
                $this->sendToSocket($socket, [
                    'type' => 'error',
                    'message' => 'Unknown message type'
                ]);
        }
    }

    private function handleAuth($socket, array $data)
    {
        $socketId = $this->getSocketId($socket);

        if (!isset($data['partner_id']) || !isset($data['token'])) {
            $this->sendToSocket($socket, [
                'type' => 'auth_error',
                'message' => 'Missing partner_id or token'
            ]);
            return;
        }

        $partnerId = (int) $data['partner_id'];
        $token = $data['token'];

        // Simple token validation
        if (strlen($token) < 10) {
            $this->sendToSocket($socket, [
                'type' => 'auth_error',
                'message' => 'Invalid authentication token'
            ]);
            return;
        }

        // Register connection
        if (!isset($this->partnerConnections[$partnerId])) {
            $this->partnerConnections[$partnerId] = [];
        }
        $this->partnerConnections[$partnerId][$socketId] = $socket;
        $this->socketPartner[$socketId] = $partnerId;

        // Auto-subscribe to partner channel
        $channel = "partner_{$partnerId}";
        $this->subscribeToChannel($socket, $channel);

        $this->sendToSocket($socket, [
            'type' => 'auth_success',
            'partner_id' => $partnerId,
            'subscribed_channels' => [$channel]
        ]);

        $this->log("Partner {$partnerId} authenticated");
    }

    private function handleSubscribe($socket, array $data)
    {
        $socketId = $this->getSocketId($socket);

        if (!isset($this->socketPartner[$socketId])) {
            $this->sendToSocket($socket, [
                'type' => 'error',
                'message' => 'Must authenticate first'
            ]);
            return;
        }

        if (!isset($data['channel'])) {
            return;
        }

        $channel = $data['channel'];
        $partnerId = $this->socketPartner[$socketId];

        // Security check for partner channels
        if (strpos($channel, 'partner_') === 0) {
            $channelPartnerId = (int) str_replace('partner_', '', $channel);
            if ($channelPartnerId !== $partnerId) {
                $this->sendToSocket($socket, [
                    'type' => 'error',
                    'message' => 'Cannot subscribe to other partner channels'
                ]);
                return;
            }
        }

        $this->subscribeToChannel($socket, $channel);

        $this->sendToSocket($socket, [
            'type' => 'subscribed',
            'channel' => $channel
        ]);
    }

    private function subscribeToChannel($socket, string $channel)
    {
        $socketId = $this->getSocketId($socket);

        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }
        $this->channels[$channel][$socketId] = $socket;
    }

    private function handleUnsubscribe($socket, array $data)
    {
        if (!isset($data['channel'])) {
            return;
        }

        $channel = $data['channel'];
        $socketId = $this->getSocketId($socket);

        if (isset($this->channels[$channel][$socketId])) {
            unset($this->channels[$channel][$socketId]);

            $this->sendToSocket($socket, [
                'type' => 'unsubscribed',
                'channel' => $channel
            ]);
        }
    }

    private function handleBroadcast(array $data)
    {
        if (!isset($data['target']) || !isset($data['payload'])) {
            return;
        }

        $target = $data['target'];
        $payload = $data['payload'];

        if (isset($target['partner_id'])) {
            $this->sendToPartner($target['partner_id'], $payload);
        }

        if (isset($target['channel'])) {
            $this->sendToChannel($target['channel'], $payload);
        }

        if (isset($target['all']) && $target['all']) {
            $this->sendToAll($payload);
        }
    }

    public function sendToPartner(int $partnerId, array $payload)
    {
        if (!isset($this->partnerConnections[$partnerId])) {
            $this->log("No connections for partner {$partnerId}");
            return;
        }

        foreach ($this->partnerConnections[$partnerId] as $socket) {
            $this->sendToSocket($socket, $payload);
        }

        $this->log("Sent to partner {$partnerId}: {$payload['type']}");
    }

    public function sendToChannel(string $channel, array $payload)
    {
        if (!isset($this->channels[$channel])) {
            return;
        }

        foreach ($this->channels[$channel] as $socket) {
            $this->sendToSocket($socket, $payload);
        }

        $this->log("Sent to channel {$channel}: {$payload['type']}");
    }

    public function sendToAll(array $payload)
    {
        foreach ($this->clients as $client) {
            if ($client !== $this->socket) {
                $this->sendToSocket($client, $payload);
            }
        }
    }

    private function sendToSocket($socket, array $data)
    {
        $message = $this->mask(json_encode($data));
        @socket_write($socket, $message, strlen($message));
    }

    private function disconnectClient($socket)
    {
        $socketId = $this->getSocketId($socket);

        // Remove from partner connections
        if (isset($this->socketPartner[$socketId])) {
            $partnerId = $this->socketPartner[$socketId];
            unset($this->partnerConnections[$partnerId][$socketId]);
            unset($this->socketPartner[$socketId]);

            if (empty($this->partnerConnections[$partnerId])) {
                unset($this->partnerConnections[$partnerId]);
            }

            $this->log("Partner {$partnerId} disconnected");
        }

        // Remove from channels
        foreach ($this->channels as $channel => $sockets) {
            unset($this->channels[$channel][$socketId]);
            if (empty($this->channels[$channel])) {
                unset($this->channels[$channel]);
            }
        }

        // Remove from clients
        $key = array_search($socket, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
        }

        socket_close($socket);
        $this->log("Connection {$socketId} closed");
    }

    private function log(string $message)
    {
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
    }
}

// Start server
echo "========================================\n";
echo "  SuperBora Simple WebSocket Server\n";
echo "========================================\n";
echo "Press Ctrl+C to stop\n";
echo "----------------------------------------\n";

$server = new SimpleWebSocketServer('0.0.0.0', 8080);
$server->run();
