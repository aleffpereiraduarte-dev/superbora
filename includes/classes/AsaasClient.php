<?php
/**
 * AsaasClient — Asaas API client for SuperBora
 *
 * Mirrors the EfiClient/MercadoPagoClient surface so callers can pick a
 * provider via env without rewriting the integration.
 *
 * Auth: simple Bearer-style header (`access_token: $aact_...`). No certificate.
 *
 * Asaas API quirks:
 *   - Requires `User-Agent` header on every request (returns 400 otherwise).
 *   - API keys can be locked to a list of allowed IPs (configured in dashboard).
 *   - Sandbox uses `sandbox.asaas.com`, production uses `api.asaas.com`.
 *
 * Env vars:
 *   ASAAS_API_KEY        — required
 *   ASAAS_WEBHOOK_TOKEN  — for webhook signature validation
 *   ASAAS_SANDBOX        — '1' for sandbox, '0' for production (default '0')
 */
class AsaasClient
{
    private string $apiKey;
    private string $webhookToken;
    private string $baseUrl;
    private int $timeout = 30;

    public function __construct()
    {
        $this->apiKey       = $_ENV['ASAAS_API_KEY']       ?? getenv('ASAAS_API_KEY')       ?: '';
        $this->webhookToken = $_ENV['ASAAS_WEBHOOK_TOKEN'] ?? getenv('ASAAS_WEBHOOK_TOKEN') ?: '';
        $sandbox = (string)($_ENV['ASAAS_SANDBOX'] ?? getenv('ASAAS_SANDBOX') ?: '0') === '1';
        $this->baseUrl = $sandbox
            ? 'https://sandbox.asaas.com/api/v3'
            : 'https://api.asaas.com/v3';
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    // ═══════════════════════════════════════
    // DICT LOOKUP (the main reason this class exists)
    // ═══════════════════════════════════════

    /**
     * Look up a PIX key holder via Asaas.
     *
     * Asaas exposes the DICT through their "validate destination" flow used
     * before initiating a transfer. The endpoint accepts a CPF, CNPJ, email,
     * phone or random key and returns the holder name + bank ISPB if found.
     *
     * Endpoint: GET /pix/transactions/destination?addressKey={key}
     * (alternate: POST /pix/transactions/preview { addressKey })
     *
     * @return array {success, holder_name?, holder_document?, bank_name?, bank_ispb?, error?}
     */
    public function lookupPixKey(string $pixKey): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Asaas nao configurado'];
        }
        if (empty($pixKey)) {
            return ['success' => false, 'error' => 'Chave obrigatoria'];
        }

        // Asaas wants the key URL-encoded as a query parameter
        $tries = [
            // Some account types use this preview endpoint
            ['POST', '/pix/transactions/preview', ['addressKey' => $pixKey]],
            // Others use this direct lookup
            ['GET',  '/pix/dict?addressKey=' . urlencode($pixKey), null],
        ];

        $lastError = 'Endpoint nao encontrado';
        foreach ($tries as [$method, $path, $body]) {
            [$code, $resp] = $this->request($method, $path, $body);
            if ($code === 200 && is_array($resp)) {
                // Asaas returns the recipient inside `pixAddressKey` or `pixKey` or top-level
                $name = $resp['ownerName']
                    ?? $resp['pixAddressKey']['ownerName']
                    ?? $resp['holder']['name']
                    ?? $resp['name']
                    ?? null;
                if ($name) {
                    return [
                        'success'         => true,
                        'holder_name'     => $name,
                        'holder_document' => $resp['ownerCpfCnpj']
                            ?? $resp['pixAddressKey']['ownerCpfCnpj']
                            ?? $resp['holder']['document']
                            ?? null,
                        'bank_name'       => $resp['bankName']
                            ?? $resp['pixAddressKey']['bankName']
                            ?? $resp['bank']['name']
                            ?? null,
                        'bank_ispb'       => $resp['ispb']
                            ?? $resp['pixAddressKey']['ispb']
                            ?? $resp['bank']['ispb']
                            ?? null,
                        'lookup_source'   => 'asaas',
                    ];
                }
            }
            if ($code === 404) {
                $lastError = 'Chave nao encontrada na DICT';
                continue;
            }
            if ($code >= 400) {
                $lastError = is_array($resp)
                    ? ($resp['errors'][0]['description'] ?? "HTTP {$code}")
                    : "HTTP {$code}";
            }
        }
        return ['success' => false, 'error' => $lastError];
    }

    // ═══════════════════════════════════════
    // PIX CHARGE (receive)
    // ═══════════════════════════════════════

    /**
     * Create a PIX charge using Asaas QR code dinamico.
     * Asaas uses a 2-step flow: create payment + generate QR code.
     */
    public function createPixCharge(float $amount, string $description, int $expiresSeconds = 600, array $customer = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Asaas nao configurado'];
        }
        if ($amount < 0.01) {
            return ['success' => false, 'error' => 'Valor invalido'];
        }

        // 1. Ensure customer exists in Asaas (find by CPF or create)
        $cpf = preg_replace('/\D/', '', $customer['cpf'] ?? '');
        $customerId = null;
        if ($cpf && strlen($cpf) === 11) {
            [$code, $resp] = $this->request('GET', '/customers?cpfCnpj=' . $cpf);
            if ($code === 200 && !empty($resp['data'][0]['id'])) {
                $customerId = $resp['data'][0]['id'];
            } else {
                [$code, $resp] = $this->request('POST', '/customers', [
                    'name'      => $customer['name'] ?? 'Cliente SuperBora',
                    'cpfCnpj'   => $cpf,
                    'email'     => $customer['email'] ?? null,
                    'mobilePhone' => preg_replace('/\D/', '', $customer['phone'] ?? '') ?: null,
                ]);
                if ($code >= 200 && $code < 300 && !empty($resp['id'])) {
                    $customerId = $resp['id'];
                }
            }
        }

        if (!$customerId) {
            return ['success' => false, 'error' => 'Falha ao criar cliente Asaas'];
        }

        // 2. Create the payment
        $externalRef = 'SB' . date('YmdHis') . bin2hex(random_bytes(4));
        $dueDate = date('Y-m-d', time() + $expiresSeconds + 86400); // give 1 day buffer

        [$code, $resp] = $this->request('POST', '/payments', [
            'customer'         => $customerId,
            'billingType'      => 'PIX',
            'value'            => round($amount, 2),
            'dueDate'          => $dueDate,
            'description'      => substr($description, 0, 256),
            'externalReference' => $externalRef,
        ]);

        if ($code < 200 || $code >= 300 || empty($resp['id'])) {
            return ['success' => false, 'error' => $resp['errors'][0]['description'] ?? 'Erro ao criar pagamento'];
        }

        $paymentId = $resp['id'];

        // 3. Generate QR code
        [$code, $qrResp] = $this->request('GET', "/payments/{$paymentId}/pixQrCode");
        if ($code !== 200 || empty($qrResp['payload'])) {
            return ['success' => false, 'error' => 'Erro ao gerar QR code'];
        }

        return [
            'success'      => true,
            'txid'         => $externalRef,
            'mp_payment_id' => $paymentId, // re-use the field name for symmetry with MP
            'asaas_payment_id' => $paymentId,
            'status'       => 'ATIVA',
            'qrcode_image' => $qrResp['encodedImage'] ? 'data:image/png;base64,' . $qrResp['encodedImage'] : '',
            'qrcode_text'  => $qrResp['payload'],
            'expires_at'   => $qrResp['expirationDate'] ?? date('Y-m-d H:i:s', time() + $expiresSeconds),
            'amount'       => $amount,
        ];
    }

    public function checkChargeStatus(string $paymentId): array
    {
        if (!$this->isConfigured()) return ['success' => false, 'error' => 'Asaas nao configurado'];
        [$code, $resp] = $this->request('GET', '/payments/' . urlencode($paymentId));
        if ($code !== 200 || empty($resp['id'])) {
            return ['success' => false, 'error' => $resp['errors'][0]['description'] ?? 'Pagamento nao encontrado'];
        }
        $status = $resp['status'] ?? 'PENDING';
        $isPaid = in_array($status, ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'], true);
        return [
            'success'   => true,
            'paid'      => $isPaid,
            'mp_status' => $status,
            'status'    => $isPaid ? 'CONCLUIDA' : ($status === 'OVERDUE' ? 'EXPIRADA' : 'ATIVA'),
            'amount'    => floatval($resp['value'] ?? 0),
            'paid_at'   => $resp['confirmedDate'] ?? null,
        ];
    }

    /**
     * Validate Asaas webhook by checking the asaas-access-token header
     * matches our configured webhook secret.
     */
    public function validateWebhookSignature(array $headers): bool
    {
        if (empty($this->webhookToken)) return true;
        $lowered = [];
        foreach ($headers as $k => $v) {
            $lowered[strtolower($k)] = is_array($v) ? ($v[0] ?? '') : $v;
        }
        $token = $lowered['asaas-access-token'] ?? $lowered['asaas-signature'] ?? '';
        return hash_equals($this->webhookToken, $token);
    }

    // ═══════════════════════════════════════
    // Internal HTTP
    // ═══════════════════════════════════════

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'access_token: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: SuperBora/1.0',
            ],
            CURLOPT_USERAGENT      => 'SuperBora/1.0',
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = $resp ? json_decode($resp, true) : null;
        return [$code, $decoded];
    }
}
