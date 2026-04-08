<?php
/**
 * MercadoPagoClient — Mercado Pago API client for SuperBora
 *
 * Mirrors the EfiClient public interface so callers can pick a provider via
 * env without rewriting the integration:
 *   - createPixCharge(amount, description, expiresSeconds, customer) — receive PIX
 *   - checkChargeStatus(txid)                                         — poll status
 *   - refundPix(paymentId, amount)                                    — devolucao
 *   - validateWebhookSignature(headers, rawBody)                      — secure webhook
 *
 * Mercado Pago API docs:
 *   https://www.mercadopago.com.br/developers/pt/reference/payments/_payments/post
 *   https://www.mercadopago.com.br/developers/pt/docs/checkout-api/integration-configuration/integrate-with-pix
 *
 * Auth: simple Bearer access token (no certificate needed, unlike EFI).
 *
 * Env vars (read from /var/www/html/.env via $_ENV):
 *   MP_ACCESS_TOKEN     — required, prod or sandbox token
 *   MP_PUBLIC_KEY       — used by mobile SDK for tokenization
 *   MP_WEBHOOK_SECRET   — for HMAC validation of incoming webhooks
 */
class MercadoPagoClient
{
    private string $accessToken;
    private string $publicKey;
    private string $webhookSecret;
    private string $baseUrl = 'https://api.mercadopago.com';
    private int $timeout = 30;

    public function __construct()
    {
        $this->accessToken   = $_ENV['MP_ACCESS_TOKEN']   ?? getenv('MP_ACCESS_TOKEN')   ?: '';
        $this->publicKey     = $_ENV['MP_PUBLIC_KEY']     ?? getenv('MP_PUBLIC_KEY')     ?: '';
        $this->webhookSecret = $_ENV['MP_WEBHOOK_SECRET'] ?? getenv('MP_WEBHOOK_SECRET') ?: '';
    }

    public function isConfigured(): bool
    {
        return !empty($this->accessToken);
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    // ═══════════════════════════════════════
    // PIX CHARGE — Receive customer payment
    // ═══════════════════════════════════════

    /**
     * Create a PIX payment (cobranca) via Mercado Pago.
     *
     * Mercado Pago does not have a separate "cob" + "loc" flow like EFI's BCB
     * implementation. Everything is a single POST /v1/payments call with
     * payment_method_id=pix, and the QR code comes back inside the response
     * body under point_of_interaction.transaction_data.
     *
     * @param float  $amount         Amount in BRL (e.g. 49.90)
     * @param string $description    Payer-facing description (max 256 chars)
     * @param int    $expiresSeconds Expiration in seconds (default 600 = 10 min)
     * @param array  $customer       Optional ['name'/'nome', 'email', 'cpf' OR 'cnpj']
     * @return array {success, txid, qrcode_image (base64), qrcode_text (copia-e-cola),
     *               expires_at, amount, mp_payment_id, mp_status}
     */
    public function createPixCharge(float $amount, string $description, int $expiresSeconds = 600, array $customer = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mercado Pago nao configurado'];
        }
        if ($amount < 0.01 || !is_finite($amount)) {
            return ['success' => false, 'error' => 'Valor invalido'];
        }

        $expiresSeconds = max(60, min(86400, $expiresSeconds));
        $expiresAt = date('Y-m-d\TH:i:s.000P', time() + $expiresSeconds);

        // Build payer object — MP requires at least an email, ideally CPF too
        $payer = [
            'email' => $customer['email'] ?? 'cliente@superbora.com.br',
        ];
        if (!empty($customer['name']) || !empty($customer['nome'])) {
            $fullName = $customer['name'] ?? $customer['nome'];
            $parts = explode(' ', trim($fullName), 2);
            $payer['first_name'] = $parts[0] ?? 'Cliente';
            if (isset($parts[1])) $payer['last_name'] = $parts[1];
        }
        if (!empty($customer['cpf'])) {
            $cpf = preg_replace('/\D/', '', $customer['cpf']);
            if (strlen($cpf) === 11) {
                $payer['identification'] = ['type' => 'CPF', 'number' => $cpf];
            }
        } elseif (!empty($customer['cnpj'])) {
            $cnpj = preg_replace('/\D/', '', $customer['cnpj']);
            if (strlen($cnpj) === 14) {
                $payer['identification'] = ['type' => 'CNPJ', 'number' => $cnpj];
            }
        }

        // External reference: SuperBora-side identifier we can correlate via webhook.
        // We mirror EfiClient's "SB" prefix + timestamp + random suffix shape.
        $externalRef = 'SB' . date('YmdHis') . bin2hex(random_bytes(6));

        $payload = [
            'transaction_amount' => round($amount, 2),
            'description'        => substr($description, 0, 256),
            'payment_method_id'  => 'pix',
            'payer'              => $payer,
            'date_of_expiration' => $expiresAt,
            'external_reference' => $externalRef,
            // Idempotency: MP recommends a unique X-Idempotency-Key header
            // (we set it below in the request method).
        ];

        try {
            $response = $this->request('POST', '/v1/payments', $payload, [
                'X-Idempotency-Key: ' . $externalRef,
            ]);

            if (!isset($response['id'])) {
                $err = $response['message'] ?? $response['error'] ?? 'Erro ao criar cobranca';
                error_log('[MP] createPixCharge failed: ' . json_encode($response));
                return ['success' => false, 'error' => $err];
            }

            $txData = $response['point_of_interaction']['transaction_data'] ?? [];
            $qrText = $txData['qr_code'] ?? '';
            $qrBase64 = $txData['qr_code_base64'] ?? '';
            $qrImage = $qrBase64
                ? 'data:image/png;base64,' . $qrBase64
                : ($qrText ? 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qrText) : '');

            return [
                'success'        => true,
                'txid'           => $externalRef,           // Our reference (used in webhooks)
                'mp_payment_id'  => (string)$response['id'], // MP's internal payment id
                'mp_status'      => $response['status'] ?? 'pending',
                'status'         => 'ATIVA',
                'qrcode_image'   => $qrImage,
                'qrcode_text'    => $qrText,
                'expires_at'     => date('Y-m-d H:i:s', time() + $expiresSeconds),
                'amount'         => $amount,
                'ticket_url'     => $txData['ticket_url'] ?? null,
            ];
        } catch (\Exception $e) {
            error_log('[MP] createPixCharge exception: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Erro interno ao criar cobranca PIX'];
        }
    }

    /**
     * Check the status of a PIX payment.
     *
     * @param string $paymentId  Mercado Pago payment id (returned from createPixCharge as mp_payment_id)
     * @return array {success, status, paid, paid_at, mp_status}
     */
    public function checkChargeStatus(string $paymentId): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mercado Pago nao configurado'];
        }
        if (empty($paymentId)) {
            return ['success' => false, 'error' => 'payment_id obrigatorio'];
        }

        try {
            $response = $this->request('GET', '/v1/payments/' . urlencode($paymentId));
            if (!isset($response['id'])) {
                return ['success' => false, 'error' => $response['message'] ?? 'Pagamento nao encontrado'];
            }
            $mpStatus = $response['status'] ?? 'unknown';
            $isPaid = $mpStatus === 'approved';
            return [
                'success'    => true,
                'paid'       => $isPaid,
                'mp_status'  => $mpStatus,
                'status'     => $isPaid ? 'CONCLUIDA' : ($mpStatus === 'cancelled' ? 'EXPIRADA' : 'ATIVA'),
                'paid_at'    => $response['date_approved'] ?? null,
                'amount'     => floatval($response['transaction_amount'] ?? 0),
                'payer'      => $response['payer'] ?? null,
            ];
        } catch (\Exception $e) {
            error_log('[MP] checkChargeStatus exception: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Erro ao consultar pagamento'];
        }
    }

    /**
     * Refund a PIX payment fully or partially.
     *
     * @param string $paymentId  MP payment id
     * @param float|null $amount Optional partial amount; null = full refund
     * @return array {success, refund_id, status}
     */
    public function refundPix(string $paymentId, ?float $amount = null): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mercado Pago nao configurado'];
        }
        if (empty($paymentId)) {
            return ['success' => false, 'error' => 'payment_id obrigatorio'];
        }

        $payload = [];
        if ($amount !== null && $amount > 0) {
            $payload['amount'] = round($amount, 2);
        }
        try {
            $response = $this->request('POST', '/v1/payments/' . urlencode($paymentId) . '/refunds', $payload, [
                'X-Idempotency-Key: refund-' . $paymentId . '-' . bin2hex(random_bytes(4)),
            ]);
            if (!isset($response['id'])) {
                return ['success' => false, 'error' => $response['message'] ?? 'Erro no estorno'];
            }
            return [
                'success'   => true,
                'refund_id' => (string)$response['id'],
                'status'    => $response['status'] ?? 'approved',
            ];
        } catch (\Exception $e) {
            error_log('[MP] refundPix exception: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Erro interno ao estornar'];
        }
    }

    /**
     * Validate the webhook signature MP sends in the x-signature header.
     * Reference: https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
     *
     * MP signs: id:{data.id};request-id:{x-request-id};ts:{timestamp};
     * Then HMAC-SHA256 with the secret. We compare against the v1=... value
     * in the x-signature header.
     */
    public function validateWebhookSignature(array $headers, string $rawBody): bool
    {
        if (empty($this->webhookSecret)) {
            // No secret configured = treat as open and let the route layer decide.
            return true;
        }

        // Normalize header keys to lowercase since header casing varies by stack
        $lowered = [];
        foreach ($headers as $k => $v) {
            $lowered[strtolower($k)] = is_array($v) ? ($v[0] ?? '') : $v;
        }
        $signatureHeader = $lowered['x-signature'] ?? '';
        $requestId = $lowered['x-request-id'] ?? '';
        if (!$signatureHeader || !$requestId) return false;

        // x-signature format: "ts=1735000000,v1=abc123..."
        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            $kv = explode('=', trim($segment), 2);
            if (count($kv) === 2) $parts[trim($kv[0])] = trim($kv[1]);
        }
        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if (!$ts || !$v1) return false;

        // Pull data.id from query OR JSON body — webhook style varies
        $dataId = $_GET['data.id'] ?? $_GET['id'] ?? '';
        if (!$dataId && $rawBody) {
            $body = json_decode($rawBody, true);
            $dataId = $body['data']['id'] ?? $body['id'] ?? '';
        }

        $manifest = sprintf('id:%s;request-id:%s;ts:%s;', $dataId, $requestId, $ts);
        $expected = hash_hmac('sha256', $manifest, $this->webhookSecret);
        return hash_equals($expected, $v1);
    }

    // ═══════════════════════════════════════
    // Internal HTTP
    // ═══════════════════════════════════════

    private function request(string $method, string $path, array $body = [], array $extraHeaders = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = array_merge([
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ? json_encode($body) : '{}');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['error' => 'cURL: ' . $err];
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['error' => "HTTP {$httpCode}: invalid JSON"];
        }
        if ($httpCode >= 400 && !isset($decoded['error'])) {
            $decoded['error'] = $decoded['message'] ?? "HTTP {$httpCode}";
        }
        return $decoded;
    }
}
