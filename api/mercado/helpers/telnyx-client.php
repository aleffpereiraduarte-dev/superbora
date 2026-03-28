<?php
/**
 * Telnyx Client - SuperBora
 * Telephony helper for voice calls, SMS, and WebRTC via Telnyx API.
 *
 * Replaces Twilio for:
 *   - Outbound calls (Call Control API)
 *   - SMS messaging
 *   - WebRTC token generation for browser softphone
 *   - Call control (hangup, transfer, hold/unhold)
 *
 * Environment variables required:
 *   TELNYX_API_KEY, TELNYX_CONNECTION_ID, TELNYX_PHONE_US, TELNYX_PHONE_BR
 */

class TelnyxClient
{
    private string $apiKey;
    private string $connectionId;
    private string $phoneUS;
    private string $phoneBR;
    private string $baseUrl = 'https://api.telnyx.com/v2';

    public function __construct(
        ?string $apiKey = null,
        ?string $connectionId = null,
        ?string $phoneUS = null,
        ?string $phoneBR = null
    ) {
        $this->apiKey       = $apiKey       ?? $_ENV['TELNYX_API_KEY']       ?? getenv('TELNYX_API_KEY')       ?: '';
        $this->connectionId = $connectionId ?? $_ENV['TELNYX_CONNECTION_ID'] ?? getenv('TELNYX_CONNECTION_ID') ?: '';
        $this->phoneUS      = $phoneUS      ?? $_ENV['TELNYX_PHONE_US']     ?? getenv('TELNYX_PHONE_US')     ?: '';
        $this->phoneBR      = $phoneBR      ?? $_ENV['TELNYX_PHONE_BR']     ?? getenv('TELNYX_PHONE_BR')     ?: '';
    }

    /**
     * Check if Telnyx is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->connectionId);
    }

    /**
     * Pick the best caller ID for the destination number.
     * Brazilian numbers (+55) use the BR number; everything else uses US.
     */
    public function getCallerFor(string $to): string
    {
        $clean = preg_replace('/\D/', '', $to);
        if ($this->phoneBR && preg_match('/^55/', $clean)) {
            return $this->phoneBR;
        }
        return $this->phoneUS ?: $this->phoneBR;
    }

    // ── Calls ───────────────────────────────────────────────────

    /**
     * Make an outbound call via Telnyx Call Control.
     *
     * @param string      $to         Destination phone (E.164)
     * @param string|null $from       Caller ID override
     * @param string|null $webhookUrl Webhook URL for call events
     * @return array|null  Telnyx call data or null on failure
     */
    public function call(string $to, ?string $from = null, ?string $webhookUrl = null): ?array
    {
        $payload = [
            'connection_id' => $this->connectionId,
            'to'            => $to,
            'from'          => $from ?? $this->getCallerFor($to),
        ];

        if ($webhookUrl) {
            $payload['webhook_url'] = $webhookUrl;
        }

        $resp = $this->request('POST', '/calls', $payload);
        return $resp['data'] ?? null;
    }

    /**
     * List active calls on this connection.
     */
    public function listCalls(): ?array
    {
        $resp = $this->request('GET', '/calls?filter[connection_id]=' . urlencode($this->connectionId));
        return $resp['data'] ?? null;
    }

    /**
     * Hang up a call.
     */
    public function hangup(string $callControlId): bool
    {
        $resp = $this->request('POST', "/calls/{$callControlId}/actions/hangup", []);
        return isset($resp['data']);
    }

    /**
     * Transfer a call to another number.
     */
    public function transfer(string $callControlId, string $to): bool
    {
        $resp = $this->request('POST', "/calls/{$callControlId}/actions/transfer", [
            'to' => $to,
        ]);
        return isset($resp['data']);
    }

    /**
     * Place a call on hold.
     */
    public function hold(string $callControlId): bool
    {
        $resp = $this->request('POST', "/calls/{$callControlId}/actions/hold", []);
        return isset($resp['data']);
    }

    /**
     * Remove a call from hold.
     */
    public function unhold(string $callControlId): bool
    {
        $resp = $this->request('POST', "/calls/{$callControlId}/actions/unhold", []);
        return isset($resp['data']);
    }

    /**
     * Answer an incoming call.
     */
    public function answer(string $callControlId): bool
    {
        $resp = $this->request('POST', "/calls/{$callControlId}/actions/answer", []);
        return isset($resp['data']);
    }

    /**
     * Speak text into a call (TTS).
     */
    public function speak(string $callControlId, string $text, string $language = 'pt-BR'): bool
    {
        $resp = $this->request('POST', "/calls/{$callControlId}/actions/speak", [
            'payload'  => $text,
            'language' => $language,
            'voice'    => 'female',
        ]);
        return isset($resp['data']);
    }

    // ── SMS ─────────────────────────────────────────────────────

    /**
     * Send an SMS message via Telnyx.
     *
     * @param string      $to      Destination phone (E.164)
     * @param string      $message Message body
     * @param string|null $from    Sender override
     * @return array  ['success' => bool, 'id' => string|null, 'message' => string]
     */
    public function sendSMS(string $to, string $message, ?string $from = null): array
    {
        if (!$this->isConfigured()) {
            error_log('[telnyx] SMS failed — not configured');
            return ['success' => false, 'id' => null, 'message' => 'Telnyx not configured'];
        }

        $to = $this->formatPhone($to);
        if (!$to) {
            return ['success' => false, 'id' => null, 'message' => 'Telefone invalido'];
        }

        $payload = [
            'from' => $from ?? $this->getCallerFor($to),
            'to'   => $to,
            'text' => $message,
        ];

        $resp = $this->request('POST', '/messages', $payload);

        if (isset($resp['data']['id'])) {
            $msgId = $resp['data']['id'];
            error_log('[telnyx] SMS sent to ' . substr($to, 0, 4) . '****' . substr($to, -2) . " | ID: {$msgId}");
            return ['success' => true, 'id' => $msgId, 'message' => 'SMS enviado'];
        }

        $errMsg = $resp['errors'][0]['detail'] ?? $resp['error'] ?? 'Unknown error';
        error_log('[telnyx] SMS failed to ' . substr($to, 0, 4) . '****' . substr($to, -2) . ": {$errMsg}");
        return ['success' => false, 'id' => null, 'message' => $errMsg];
    }

    // ── WebRTC ──────────────────────────────────────────────────

    /**
     * Generate a WebRTC telephony credential for the browser softphone.
     *
     * @param string $identity Agent identity (e.g. "agent_5")
     * @return array|null  Credential data including sip_username, sip_password, id
     */
    public function generateWebRTCToken(string $identity): ?array
    {
        if (!$this->isConfigured()) {
            error_log('[telnyx] WebRTC token failed — not configured');
            return null;
        }

        $payload = [
            'connection_id' => $this->connectionId,
            'name'          => $identity . '_' . time(),
            'tag'           => 'callcenter',
        ];

        $resp = $this->request('POST', '/telephony_credentials', $payload);

        if (isset($resp['data'])) {
            error_log("[telnyx] WebRTC credential created for {$identity}: id={$resp['data']['id']}");
            return $resp['data'];
        }

        error_log('[telnyx] WebRTC credential failed: ' . json_encode($resp));
        return null;
    }

    /**
     * Create a short-lived SIP token for an existing telephony credential.
     * This is the actual JWT that the @telnyx/webrtc SDK needs.
     *
     * @param string $credentialId  The ID from generateWebRTCToken()
     * @return string|null  The JWT token string
     */
    public function createSipToken(string $credentialId): ?string
    {
        $resp = $this->request('POST', "/telephony_credentials/{$credentialId}/token", []);

        // The token endpoint returns the JWT directly as a string, not wrapped in {data: ...}
        if (is_string($resp)) {
            return $resp;
        }

        // Some versions return it in a data wrapper
        if (isset($resp['data'])) {
            return is_string($resp['data']) ? $resp['data'] : ($resp['data']['token'] ?? null);
        }

        error_log('[telnyx] SIP token generation failed: ' . json_encode($resp));
        return null;
    }

    // ── Internal ────────────────────────────────────────────────

    /**
     * Format phone number to E.164.
     */
    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        // Brazilian numbers without country code
        if (strlen($phone) >= 10 && strlen($phone) <= 11
            && !preg_match('/^(55|1|44|34|49|33|61|81|86|91|52|54|56|57|58|595|598)/', $phone)
        ) {
            $phone = '55' . $phone;
        }

        if (strlen($phone) < 11 || strlen($phone) > 15) {
            return '';
        }

        return '+' . $phone;
    }

    /**
     * Make an HTTP request to the Telnyx API.
     *
     * @param string     $method  HTTP method
     * @param string     $path    API path (e.g. /calls)
     * @param array|null $body    JSON body for POST/PUT
     * @return array|string  Decoded response body
     */
    private function request(string $method, string $path, ?array $body = null)
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
        }

        curl_setopt_array($ch, $opts);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[telnyx] cURL error ({$method} {$path}): {$error}");
            return ['error' => $error];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("[telnyx] API error ({$method} {$path}): HTTP {$httpCode} — " . substr($result, 0, 500));
        }

        $decoded = json_decode($result, true);
        // If JSON decode fails, return raw string (e.g., for JWT token endpoints)
        return $decoded !== null ? $decoded : $result;
    }
}

// ── Singleton helper ────────────────────────────────────────────

function getTelnyxClient(): TelnyxClient
{
    static $instance = null;
    if ($instance === null) {
        $instance = new TelnyxClient();
    }
    return $instance;
}
