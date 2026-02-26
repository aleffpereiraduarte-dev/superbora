<?php
/**
 * WooviClient - API client para Woovi (OpenPix)
 *
 * Endpoints utilizados:
 * - POST /api/v1/subaccount      → Criar sub-conta (PIX key validation)
 * - POST /api/v1/transfer/pix    → Enviar PIX (payout)
 * - GET  /api/v1/transfer/{id}   → Consultar status do payout
 */
class WooviClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.openpix.com.br/api/v1';
    private int $timeout = 30;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? ($_ENV['WOOVI_API_KEY'] ?? getenv('WOOVI_API_KEY') ?: '');
        if (empty($this->apiKey)) {
            throw new \RuntimeException('WOOVI_API_KEY nao configurada');
        }
    }

    /**
     * Criar um payout PIX para o parceiro
     *
     * @param int    $valueCents     Valor em centavos
     * @param string $correlationId  ID unico para idempotencia
     * @param string $pixKey         Chave PIX destino
     * @param string $pixKeyType     Tipo: CPF, CNPJ, EMAIL, PHONE, RANDOM
     * @param string $comment        Descricao do payout
     * @return array Response da API
     */
    public function createPayout(
        int $valueCents,
        string $correlationId,
        string $pixKey,
        string $pixKeyType,
        string $comment = ''
    ): array {
        $payload = [
            'value' => $valueCents,
            'correlationID' => $correlationId,
            'destinationAlias' => $pixKey,
            'destinationAliasType' => strtoupper($pixKeyType),
            'comment' => $comment ?: 'Repasse SuperBora',
        ];

        return $this->request('POST', '/transfer/pix', $payload);
    }

    /**
     * Criar cobranca PIX (para receber pagamento de cliente)
     *
     * @param int    $valueCents     Valor em centavos
     * @param string $correlationId  ID unico para idempotencia
     * @param string $comment        Descricao da cobranca
     * @param int    $expiresIn      Tempo em segundos para expirar (default 600 = 10 min)
     * @param array  $customer       Dados do cliente [name, email, phone, taxID]
     * @return array Response da API com pixCopiaECola e brCode
     */
    public function createCharge(
        int $valueCents,
        string $correlationId,
        string $comment = '',
        int $expiresIn = 600,
        array $customer = []
    ): array {
        $payload = [
            'correlationID' => $correlationId,
            'value' => $valueCents,
            'comment' => $comment ?: 'Pedido SuperBora',
            'expiresIn' => $expiresIn,
        ];

        if (!empty($customer)) {
            $payload['customer'] = $customer;
        }

        return $this->request('POST', '/charge', $payload);
    }

    /**
     * Consultar status de uma cobranca PIX
     */
    public function getChargeStatus(string $correlationId): array
    {
        return $this->request('GET', '/charge/' . urlencode($correlationId));
    }

    /**
     * Estornar cobranca PIX (refund)
     *
     * @param string $correlationId  O correlationID da cobranca original
     * @param string $comment        Motivo do estorno
     * @return array Response da API
     */
    public function refundCharge(string $correlationId, string $comment = ''): array
    {
        $payload = [
            'correlationID' => $correlationId,
        ];
        if ($comment) {
            $payload['comment'] = $comment;
        }
        return $this->request('POST', '/charge/' . urlencode($correlationId) . '/refund', $payload);
    }

    /**
     * Consultar status de um payout
     *
     * @param string $correlationId  O correlationID usado na criacao
     * @return array Response da API
     */
    public function getPayoutStatus(string $correlationId): array
    {
        return $this->request('GET', '/transfer/' . urlencode($correlationId));
    }

    /**
     * Verificar assinatura do webhook Woovi (OpenPix)
     * Woovi uses RSA SHA-256 signature with a public key
     *
     * @param string $rawBody   Body cru do request
     * @param string $signature Header x-webhook-secret (base64-encoded RSA signature)
     * @param string $publicKey PEM-formatted RSA public key
     * @return bool
     */
    public static function verifyWebhookSignature(string $rawBody, string $signature, string $publicKey): bool
    {
        if (empty($signature) || empty($publicKey)) {
            return false;
        }

        // Ensure PEM format (handle \n escapes from .env)
        $pem = str_replace('\\n', "\n", $publicKey);

        // Try RSA verification first (OpenPix standard)
        $key = openssl_pkey_get_public($pem);
        if ($key) {
            $sigDecoded = base64_decode($signature, true);
            if ($sigDecoded !== false) {
                $result = openssl_verify($rawBody, $sigDecoded, $key, OPENSSL_ALGO_SHA256);
                if ($result === 1) return true;
            }
        }

        // Fallback: simple HMAC comparison (for backwards compat)
        $computed = hash_hmac('sha256', $rawBody, $publicKey);
        return hash_equals($computed, $signature);
    }

    /**
     * Fazer request HTTP para a API Woovi
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Authorization: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("[WooviClient] cURL error: $curlError");
            throw new \RuntimeException("Erro de conexao com Woovi: $curlError");
        }

        $decoded = json_decode($responseBody, true);

        if ($httpCode >= 400) {
            $errorMsg = $decoded['error'] ?? $decoded['message'] ?? "HTTP $httpCode";
            error_log("[WooviClient] API error ($httpCode): $responseBody");
            throw new \RuntimeException("Woovi API error: $errorMsg", $httpCode);
        }

        return [
            'http_code' => $httpCode,
            'data' => $decoded,
            'raw' => $responseBody,
        ];
    }
}
