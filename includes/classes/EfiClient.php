<?php
/**
 * EfiClient - API client para Efí (Gerencianet) — SuperBora
 *
 * Handles:
 * - PIX charge creation (receiving payments via QR code)
 * - PIX charge status checking
 * - PIX refund (devolucao)
 * - PIX payout (sending money to partners)
 * - Card payment (Brazilian credit/debit via tokenized cards)
 *
 * APIs:
 * - PIX API: pix.api.efipay.com.br (cob, webhook, devolucao)
 * - Charges API: api.efipay.com.br (boleto, card payments)
 *
 * Auth: OAuth2 client_credentials + mTLS certificate
 *
 * Config (.env.efi):
 *   EFI_CLIENT_ID, EFI_CLIENT_SECRET, EFI_CERT_PATH, EFI_PIX_KEY, EFI_SANDBOX
 *   EFI_ACCOUNT_ID (for card payments)
 */
class EfiClient
{
    private string $clientId;
    private string $clientSecret;
    private string $certPath;
    private string $pixKey;
    private string $accountId;
    private string $pixBaseUrl;
    private string $chargesBaseUrl;
    private string $tokenizerBaseUrl;
    private bool $sandbox;

    // Separate tokens per scope
    private array $tokens = [];

    public function __construct()
    {
        $envFile = dirname(dirname(__DIR__)) . '/.env.efi';
        $env = [];
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $env[trim($key)] = trim($value);
                }
            }
        }

        $this->clientId = $env['EFI_CLIENT_ID'] ?? getenv('EFI_CLIENT_ID') ?: '';
        $this->clientSecret = $env['EFI_CLIENT_SECRET'] ?? getenv('EFI_CLIENT_SECRET') ?: '';
        $this->certPath = $env['EFI_CERT_PATH'] ?? getenv('EFI_CERT_PATH') ?: '/var/www/html/api/certs/efi.pem';
        $this->pixKey = $env['EFI_PIX_KEY'] ?? getenv('EFI_PIX_KEY') ?: '';
        $this->accountId = $env['EFI_ACCOUNT_ID'] ?? getenv('EFI_ACCOUNT_ID') ?: '';
        $this->sandbox = filter_var($env['EFI_SANDBOX'] ?? getenv('EFI_SANDBOX') ?: '0', FILTER_VALIDATE_BOOLEAN);

        if ($this->sandbox) {
            $this->pixBaseUrl = 'https://pix-h.api.efipay.com.br';
            $this->chargesBaseUrl = 'https://cobrancas-h.api.efipay.com.br';
            $this->tokenizerBaseUrl = 'https://tokenizer-h.sejaefi.com.br';
        } else {
            $this->pixBaseUrl = 'https://pix.api.efipay.com.br';
            $this->chargesBaseUrl = 'https://cobrancas.api.efipay.com.br';
            $this->tokenizerBaseUrl = 'https://tokenizer.sejaefi.com.br';
        }
    }

    /**
     * Check if EFI is properly configured
     */
    public function isConfigured(): bool
    {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->certPath)) {
            return false;
        }
        if (!file_exists($this->certPath) || !is_readable($this->certPath)) {
            error_log("[EFI] Certificate not found or not readable: " . basename($this->certPath));
            return false;
        }
        return true;
    }

    // ═══════════════════════════════════════
    // PIX CHARGE (Cobranca Imediata)
    // ═══════════════════════════════════════

    /**
     * Create PIX charge (cobranca imediata) for receiving customer payment
     *
     * @param float  $amount         Amount in BRL (e.g. 49.90)
     * @param string $description    Charge description
     * @param int    $expiresSeconds Expiration in seconds (default 600 = 10 min)
     * @param array  $customer       Optional: [nome, cpf, cnpj]
     * @return array [success, txid, qrcode_image, qrcode_text (copia-e-cola), expires_at]
     */
    public function createPixCharge(float $amount, string $description, int $expiresSeconds = 600, array $customer = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }
        if ($amount < 0.01 || !is_finite($amount)) {
            return ['success' => false, 'error' => 'Valor invalido'];
        }
        if (empty($this->pixKey)) {
            return ['success' => false, 'error' => 'EFI_PIX_KEY nao configurada'];
        }

        $expiresSeconds = max(60, min(86400, $expiresSeconds));

        try {
            if (!$this->authenticate('pix')) {
                return ['success' => false, 'error' => 'Falha na autenticacao EFI'];
            }

            // Generate unique txid (26-35 chars, alphanumeric, no special chars)
            $txid = 'SB' . date('YmdHis') . bin2hex(random_bytes(6));

            $payload = [
                'calendario' => [
                    'expiracao' => $expiresSeconds,
                ],
                'devedor' => [],
                'valor' => [
                    'original' => number_format(round($amount, 2), 2, '.', ''),
                ],
                'chave' => $this->pixKey,
                'solicitacaoPagador' => substr($description, 0, 140),
            ];

            // Add customer info if provided
            if (!empty($customer['cpf'])) {
                $cpf = preg_replace('/\D/', '', $customer['cpf']);
                if (strlen($cpf) === 11) {
                    $payload['devedor'] = [
                        'cpf' => $cpf,
                        'nome' => substr($customer['nome'] ?? $customer['name'] ?? 'Cliente', 0, 200),
                    ];
                }
            } elseif (!empty($customer['cnpj'])) {
                $cnpj = preg_replace('/\D/', '', $customer['cnpj']);
                if (strlen($cnpj) === 14) {
                    $payload['devedor'] = [
                        'cnpj' => $cnpj,
                        'nome' => substr($customer['nome'] ?? $customer['name'] ?? 'Cliente', 0, 200),
                    ];
                }
            }

            if (empty($payload['devedor'])) {
                unset($payload['devedor']);
            }

            // Create the charge
            $response = $this->pixRequest('PUT', "/v2/cob/{$txid}", $payload);

            if (!isset($response['txid'])) {
                $error = $response['mensagem'] ?? $response['detail'] ?? $response['message'] ?? 'Erro ao criar cobranca';
                error_log("[EFI] createPixCharge failed: " . json_encode($response));
                return ['success' => false, 'error' => $error];
            }

            // Get QR code from loc
            $locId = $response['loc']['id'] ?? null;
            $qrcodeImage = '';
            $qrcodeText = '';

            if ($locId) {
                $qrResponse = $this->pixRequest('GET', "/v2/loc/{$locId}/qrcode");
                $qrcodeImage = $qrResponse['imagemQrcode'] ?? '';
                $qrcodeText = $qrResponse['qrcode'] ?? '';
            }

            // If no QR code from loc, try pixCopiaECola from the charge itself
            if (empty($qrcodeText)) {
                $qrcodeText = $response['pixCopiaECola'] ?? '';
            }

            // Generate QR code URL from text if no image
            if (empty($qrcodeImage) && !empty($qrcodeText)) {
                $qrcodeImage = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qrcodeText);
            }

            return [
                'success' => true,
                'txid' => $response['txid'],
                'status' => $response['status'] ?? 'ATIVA',
                'qrcode_image' => $qrcodeImage,
                'qrcode_text' => $qrcodeText,
                'loc_id' => $locId,
                'expires_at' => date('Y-m-d H:i:s', time() + $expiresSeconds),
                'amount' => $amount,
            ];

        } catch (\Exception $e) {
            error_log("[EFI] createPixCharge exception: " . $e->getMessage());
            return ['success' => false, 'error' => 'Erro interno ao criar cobranca PIX'];
        }
    }

    /**
     * Check PIX charge status
     *
     * @param string $txid The txid from createPixCharge
     * @return array [success, status, paid, txid, pix (payment details if paid)]
     */
    public function checkChargeStatus(string $txid): array
    {
        if (!$this->isConfigured() || !$this->authenticate('pix')) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }

        $response = $this->pixRequest('GET', "/v2/cob/{$txid}");

        if (!isset($response['txid'])) {
            return ['success' => false, 'error' => $response['mensagem'] ?? 'Cobranca nao encontrada'];
        }

        $status = $response['status'] ?? 'unknown';
        $paid = $status === 'CONCLUIDA';

        $result = [
            'success' => true,
            'status' => $status,
            'paid' => $paid,
            'txid' => $response['txid'],
        ];

        // If paid, include PIX payment details (e2eId needed for refunds)
        if ($paid && !empty($response['pix'])) {
            $pix = $response['pix'][0] ?? [];
            $result['e2e_id'] = $pix['endToEndId'] ?? '';
            $result['paid_at'] = $pix['horario'] ?? '';
            $result['paid_amount'] = $pix['valor'] ?? '';
        }

        return $result;
    }

    /**
     * Look up a PIX key in the BCB DICT directory.
     * Returns the holder name + bank info via EFI's `/v2/gn/dict/keys/{key}` endpoint.
     *
     * Requires EFI Pay merchant account with PIX certified.
     *
     * @param  string $pixKey CPF, CNPJ, email, phone, or EVP (UUID)
     * @return array [success, holder_name, holder_document, bank_name, bank_ispb]
     */
    public function lookupPixKey(string $pixKey): array
    {
        if (!$this->isConfigured() || !$this->authenticate('pix')) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }

        $encoded = urlencode($pixKey);
        $response = $this->pixRequest('GET', "/v2/gn/dict/keys/{$encoded}");

        if (empty($response) || !is_array($response)) {
            return ['success' => false, 'error' => 'Chave PIX nao encontrada'];
        }

        // EFI Pay quirk: when the key doesn't exist in DICT, the API returns
        //   HTTP 200 with { "nome": "nao_encontrado", "mensagem": "Recurso nao encontrado" }
        // (instead of a proper 404). We must filter that out before treating
        // the response as a real DICT hit, otherwise the recipient card would
        // proudly show "nao_encontrado" as the holder name.
        if (isset($response['nome']) && $response['nome'] === 'nao_encontrado') {
            return ['success' => false, 'error' => 'Chave PIX nao encontrada na DICT'];
        }
        if (!empty($response['mensagem']) && empty($response['nomeRecebedor']) && empty($response['nomeFantasia'])) {
            // Generic EFI error envelope ({"mensagem":"..."}) — also not a hit.
            return ['success' => false, 'error' => $response['mensagem']];
        }

        // EFI response shape (from BCB DICT):
        // {
        //   "chave": "...",
        //   "tipoChave": "CPF",
        //   "nomeRecebedor": "JOAO DA SILVA",
        //   "nomeFantasia": "...",
        //   "documentoRecebedor": "***456789**",
        //   "ispb": "18236120",
        //   "agencia": "0001",
        //   "conta": { "tipo": "CACC", "numero": "..." }
        // }
        $name = $response['nomeRecebedor'] ?? $response['nome'] ?? null;
        if (!$name || $name === 'nao_encontrado') {
            return ['success' => false, 'error' => 'Resposta DICT invalida'];
        }

        return [
            'success' => true,
            'holder_name' => $name,
            'holder_document' => $response['documentoRecebedor'] ?? null,
            'bank_name' => $response['nomeFantasia'] ?? bankNameFromIspb($response['ispb'] ?? ''),
            'bank_ispb' => $response['ispb'] ?? null,
            'agencia' => $response['agencia'] ?? null,
            'conta' => $response['conta'] ?? null,
        ];
    }

    /**
     * Refund a PIX payment (devolucao)
     *
     * @param string $e2eId  The endToEndId from the paid PIX
     * @param float  $amount Amount to refund (partial or full)
     * @return array [success, devolucao_id, status]
     */
    public function refundPix(string $e2eId, float $amount): array
    {
        if (!$this->isConfigured() || !$this->authenticate('pix')) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }

        if (empty($e2eId)) {
            return ['success' => false, 'error' => 'e2eId obrigatorio para devolucao'];
        }

        // Generate unique devolucao ID
        $devolucaoId = 'DEV' . date('YmdHis') . bin2hex(random_bytes(4));

        $payload = [
            'valor' => number_format(round($amount, 2), 2, '.', ''),
        ];

        $response = $this->pixRequest('PUT', "/v2/pix/{$e2eId}/devolucao/{$devolucaoId}", $payload);

        if (isset($response['id']) || isset($response['rtrId'])) {
            $status = $response['status'] ?? 'EM_PROCESSAMENTO';
            error_log("[EFI] PIX refund OK: e2e={$e2eId} dev={$devolucaoId} status={$status}");
            return [
                'success' => true,
                'devolucao_id' => $response['id'] ?? $response['rtrId'] ?? $devolucaoId,
                'status' => $status,
            ];
        }

        $error = $response['mensagem'] ?? $response['detail'] ?? 'Erro na devolucao';
        error_log("[EFI] PIX refund failed: e2e={$e2eId} error={$error}");
        return ['success' => false, 'error' => $error];
    }

    // ═══════════════════════════════════════
    // PIX PAYOUT (Send money to partners)
    // ═══════════════════════════════════════

    /**
     * Send PIX payout to a destination key
     *
     * @param float  $amount      Amount in BRL
     * @param string $pixKey      Destination PIX key
     * @param string $pixType     Type: cpf, cnpj, phone, email, random
     * @param string $description Description
     * @param string|null $idempotencyKey External idempotency key
     * @return array [success, e2e_id, status]
     */
    public function sendPix(float $amount, string $pixKey, string $pixType, string $description = 'Repasse SuperBora', ?string $idempotencyKey = null): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }
        if ($amount < 0.01 || !is_finite($amount) || $amount > 100000) {
            return ['success' => false, 'error' => 'Valor invalido para PIX'];
        }

        $pixKey = trim($pixKey);
        if (empty($pixKey)) {
            return ['success' => false, 'error' => 'Chave PIX vazia'];
        }

        try {
            if (!$this->authenticate('pix')) {
                return ['success' => false, 'error' => 'Falha na autenticacao EFI'];
            }

            // Generate idEnvio (unique, 26-35 chars, alphanumeric)
            $idEnvio = $idempotencyKey
                ? preg_replace('/[^a-zA-Z0-9]/', '', substr($idempotencyKey, 0, 35))
                : 'SBP' . date('YmdHis') . bin2hex(random_bytes(5));

            // Format PIX key
            $formattedKey = $this->formatPixKey($pixKey, $pixType);

            $payload = [
                'valor' => number_format(round($amount, 2), 2, '.', ''),
                'pagador' => [
                    'chave' => $this->pixKey,
                    'infoPagador' => substr($description, 0, 140),
                ],
                'favorecido' => [
                    'chave' => $formattedKey,
                ],
            ];

            $response = $this->pixRequest('PUT', "/v2/pix/{$idEnvio}", $payload);

            if (isset($response['endToEndId'])) {
                $maskedKey = substr($formattedKey, 0, 4) . '***' . substr($formattedKey, -4);
                error_log("[EFI] PIX sent: R$ {$amount} to {$pixType}:{$maskedKey} e2e={$response['endToEndId']}");
                return [
                    'success' => true,
                    'e2e_id' => $response['endToEndId'],
                    'transfer_id' => $response['endToEndId'],
                    'status' => $response['status'] ?? 'completed',
                ];
            }

            if (isset($response['idEnvio'])) {
                return [
                    'success' => true,
                    'transfer_id' => $response['idEnvio'],
                    'e2e_id' => $response['endToEndId'] ?? $response['idEnvio'],
                    'status' => 'processing',
                ];
            }

            $error = $response['mensagem'] ?? $response['detail'] ?? 'Erro ao enviar PIX';
            error_log("[EFI] PIX send failed: " . substr($error, 0, 100));
            return ['success' => false, 'error' => 'Erro ao enviar PIX'];

        } catch (\Exception $e) {
            error_log("[EFI] sendPix exception: " . $e->getMessage());
            return ['success' => false, 'error' => 'Erro interno no PIX'];
        }
    }

    // ═══════════════════════════════════════
    // CARD PAYMENTS (Brazilian credit/debit)
    // ═══════════════════════════════════════

    /**
     * Tokenize card data via EFI tokenizer service.
     *
     * Flow: salt → pubkey → RSA encrypt → tokenizer → payment_token
     * Uses tokenizer.sejaefi.com.br (no mTLS needed for tokenizer endpoints)
     *
     * @param string $number      Card number (digits only)
     * @param string $cvv         CVV (3-4 digits)
     * @param string $expMonth    Expiration month (01-12)
     * @param string $expYear     Expiration year (4 digits, e.g. 2030)
     * @param string $brand       Card brand (visa, mastercard, elo, amex, hipercard)
     * @param bool   $reuse       If true, token can be reused for future charges
     * @return array [success, payment_token, card_mask] or [success => false, error]
     */
    public function tokenizeCard(string $number, string $cvv, string $expMonth, string $expYear, string $brand = 'visa', bool $reuse = false): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }
        if (empty($this->accountId)) {
            error_log("[EFI] tokenizeCard: EFI_ACCOUNT_ID not configured");
            return ['success' => false, 'error' => 'Configuracao de pagamento incompleta'];
        }

        $number = preg_replace('/\D/', '', $number);
        $cvv = preg_replace('/\D/', '', $cvv);
        $expMonth = str_pad(preg_replace('/\D/', '', $expMonth), 2, '0', STR_PAD_LEFT);
        $expYear = preg_replace('/\D/', '', $expYear);
        if (strlen($expYear) === 2) $expYear = '20' . $expYear;

        if (strlen($number) < 13 || strlen($number) > 19) {
            return ['success' => false, 'error' => 'Numero do cartao invalido'];
        }
        if (strlen($cvv) < 3 || strlen($cvv) > 4) {
            return ['success' => false, 'error' => 'CVV invalido'];
        }

        try {
            // Step 1: Get salt from EFI tokenizer (returns {"code":200,"data":"JWT_TOKEN"})
            $saltResponse = $this->simpleGet("{$this->tokenizerBaseUrl}/salt");
            $salt = $saltResponse['data'] ?? $saltResponse['salt'] ?? '';
            if (empty($salt)) {
                error_log("[EFI] tokenizeCard: salt failed: " . json_encode($saltResponse));
                return ['success' => false, 'error' => 'Erro ao iniciar tokenizacao'];
            }

            // Step 2: Get RSA public key using account identifier
            $pubkeyResponse = $this->simpleGet("{$this->chargesBaseUrl}/v1/pubkey?code={$this->accountId}");
            if (empty($pubkeyResponse['data'])) {
                error_log("[EFI] tokenizeCard: pubkey failed: " . json_encode($pubkeyResponse));
                return ['success' => false, 'error' => 'Erro ao obter chave de criptografia'];
            }
            $publicKeyPem = $pubkeyResponse['data'];

            // Step 3: RSA encrypt card data
            $payload = "{$brand};{$number};{$cvv};{$expMonth};{$expYear};{$salt}";
            $publicKey = openssl_pkey_get_public($publicKeyPem);
            if (!$publicKey) {
                error_log("[EFI] tokenizeCard: invalid PEM: " . openssl_error_string());
                return ['success' => false, 'error' => 'Erro na chave de criptografia'];
            }

            $encrypted = '';
            if (!openssl_public_encrypt($payload, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING)) {
                error_log("[EFI] tokenizeCard: RSA encrypt failed: " . openssl_error_string());
                return ['success' => false, 'error' => 'Erro na criptografia do cartao'];
            }

            // Step 4: Send encrypted data to tokenizer
            $tokenResponse = $this->simplePost("{$this->tokenizerBaseUrl}/card", [
                'data' => base64_encode($encrypted),
            ]);

            if (!empty($tokenResponse['payment_token'])) {
                $cardMask = $tokenResponse['card_mask'] ?? (str_repeat('*', strlen($number) - 4) . substr($number, -4));
                error_log("[EFI] Card tokenized: mask={$cardMask} reuse=" . ($reuse ? 'yes' : 'no'));
                return [
                    'success' => true,
                    'payment_token' => $tokenResponse['payment_token'],
                    'card_mask' => $cardMask,
                ];
            }

            // Check nested data structure
            if (!empty($tokenResponse['data']['payment_token'])) {
                $cardMask = $tokenResponse['data']['card_mask'] ?? substr($number, -4);
                error_log("[EFI] Card tokenized: mask={$cardMask} reuse=" . ($reuse ? 'yes' : 'no'));
                return [
                    'success' => true,
                    'payment_token' => $tokenResponse['data']['payment_token'],
                    'card_mask' => $cardMask,
                ];
            }

            $error = $tokenResponse['error_description'] ?? $tokenResponse['message'] ?? $tokenResponse['error'] ?? 'Erro ao tokenizar cartao';
            error_log("[EFI] tokenizeCard: tokenizer failed: " . json_encode($tokenResponse));
            return ['success' => false, 'error' => $error];

        } catch (\Exception $e) {
            error_log("[EFI] tokenizeCard exception: " . $e->getMessage());
            return ['success' => false, 'error' => 'Erro interno na tokenizacao'];
        }
    }

    /**
     * Create a card charge (one-step)
     *
     * Flow: create charge → associate payment (card token) → capture
     *
     * @param float  $amount      Amount in BRL
     * @param string $description Description
     * @param array  $customer    [name, cpf, email, phone]
     * @param string $paymentToken Card payment_token from EFI tokenizeCard() or JS SDK
     * @param int    $installments Number of installments (1-12)
     * @return array [success, charge_id, status, installments, total]
     */
    public function chargeCard(float $amount, string $description, array $customer, string $paymentToken, int $installments = 1): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }
        if ($amount < 1.00 || !is_finite($amount)) {
            return ['success' => false, 'error' => 'Valor minimo R$ 1,00 para cartao'];
        }
        if (empty($paymentToken)) {
            return ['success' => false, 'error' => 'Token do cartao obrigatorio'];
        }

        $installments = max(1, min(12, $installments));
        $amountCents = (int)round($amount * 100);

        try {
            if (!$this->authenticate('charges')) {
                return ['success' => false, 'error' => 'Falha na autenticacao EFI'];
            }

            // Step 1: Create charge
            $chargePayload = [
                'items' => [
                    [
                        'name' => substr($description, 0, 255),
                        'value' => $amountCents,
                        'amount' => 1,
                    ],
                ],
            ];

            $chargeResponse = $this->chargesRequest('POST', '/v1/charge', $chargePayload);

            if (!isset($chargeResponse['data']['charge_id'])) {
                $error = $chargeResponse['error_description'] ?? $chargeResponse['message'] ?? 'Erro ao criar cobranca';
                error_log("[EFI] createCharge failed: " . json_encode($chargeResponse));
                return ['success' => false, 'error' => $error];
            }

            $chargeId = $chargeResponse['data']['charge_id'];

            // Step 2: Pay the charge with card token
            $cpf = preg_replace('/\D/', '', $customer['cpf'] ?? '');
            $phone = preg_replace('/\D/', '', $customer['phone'] ?? '');

            $payPayload = [
                'payment' => [
                    'credit_card' => [
                        'installments' => $installments,
                        'payment_token' => $paymentToken,
                        'customer' => [
                            'name' => substr($customer['name'] ?? 'Cliente', 0, 255),
                            'email' => $customer['email'] ?? 'cliente@superbora.com.br',
                            'cpf' => $cpf,
                            'birth' => $customer['birth'] ?? '1990-01-01',
                            'phone_number' => $phone ? (strlen($phone) >= 10 ? $phone : '') : '',
                        ],
                    ],
                ],
            ];

            $payResponse = $this->chargesRequest('POST', "/v1/charge/{$chargeId}/pay", $payPayload);

            if (isset($payResponse['data']['charge_id'])) {
                $status = $payResponse['data']['status'] ?? 'approved';
                error_log("[EFI] Card charge OK: #{$chargeId} status={$status} amount=R$ {$amount}");
                return [
                    'success' => true,
                    'charge_id' => $chargeId,
                    'status' => $status,
                    'installments' => $installments,
                    'total' => $payResponse['data']['total'] ?? $amountCents,
                    'payment_method' => 'efi_card',
                ];
            }

            $error = $payResponse['error_description'] ?? $payResponse['message'] ?? 'Erro ao processar cartao';
            error_log("[EFI] payCharge failed: chargeId={$chargeId} " . json_encode($payResponse));

            // Cancel the unpaid charge
            $this->chargesRequest('PUT', "/v1/charge/{$chargeId}/cancel");

            return ['success' => false, 'error' => $error];

        } catch (\Exception $e) {
            error_log("[EFI] chargeCard exception: " . $e->getMessage());
            return ['success' => false, 'error' => 'Erro interno no pagamento'];
        }
    }

    /**
     * Refund a card charge
     *
     * @param int $chargeId The charge_id from chargeCard
     * @return array [success, status]
     */
    public function refundCard(int $chargeId): array
    {
        if (!$this->isConfigured() || !$this->authenticate('charges')) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }

        $response = $this->chargesRequest('POST', "/v1/charge/{$chargeId}/refund");

        if (isset($response['data']['charge_id'])) {
            error_log("[EFI] Card refund OK: #{$chargeId}");
            return [
                'success' => true,
                'status' => $response['data']['status'] ?? 'refunded',
            ];
        }

        $error = $response['error_description'] ?? $response['message'] ?? 'Erro no estorno';
        error_log("[EFI] Card refund failed: #{$chargeId} error={$error}");
        return ['success' => false, 'error' => $error];
    }

    // ═══════════════════════════════════════
    // WEBHOOK VERIFICATION
    // ═══════════════════════════════════════

    /**
     * Verify EFI webhook notification.
     * EFI PIX webhooks use mTLS — the server must present the EFI CA certificate.
     * Additionally, we can verify the request comes from EFI IP ranges.
     *
     * For simpler setups, EFI sends the txid/e2eId and we verify by checking the charge status.
     *
     * @param string $rawBody Raw request body
     * @return array|false Parsed payload or false if invalid
     */
    public static function parseWebhookPayload(string $rawBody)
    {
        $data = json_decode($rawBody, true);
        if (!$data || !isset($data['pix'])) {
            return false;
        }
        return $data;
    }

    /**
     * Register webhook URL with EFI
     *
     * @param string $webhookUrl Your webhook URL (must be HTTPS)
     * @return array [success, message]
     */
    public function registerWebhook(string $webhookUrl): array
    {
        if (!$this->isConfigured() || !$this->authenticate('pix')) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }
        if (empty($this->pixKey)) {
            return ['success' => false, 'error' => 'EFI_PIX_KEY obrigatoria'];
        }

        $payload = ['webhookUrl' => $webhookUrl];
        $response = $this->pixRequest('PUT', "/v2/webhook/{$this->pixKey}", $payload);

        if (isset($response['webhookUrl']) || (isset($response['status']) && $response['status'] === 200)) {
            return ['success' => true, 'message' => 'Webhook registrado'];
        }

        return ['success' => false, 'error' => $response['mensagem'] ?? 'Erro ao registrar webhook'];
    }

    // ═══════════════════════════════════════
    // PAGAMENTO DE CONTAS — Pay boletos via barcode
    // ═══════════════════════════════════════

    /**
     * Consult a boleto/conta barcode and return its details (amount, due date,
     * beneficiary). Used to PREVIEW before the user confirms payment.
     *
     * Accepts both formats:
     *   - Linha digitavel (47 digits, with or without separators)
     *   - Codigo de barras (44 digits raw)
     *
     * Endpoint: GET /v3/gn/barcode/{codBarras}
     * Required scope: gn.barcode.read
     *
     * @return array {success, amount, due_date, beneficiary, type, error?}
     */
    public function consultBarcode(string $barcode): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }

        $clean = preg_replace('/\D/', '', $barcode);
        if (strlen($clean) !== 44 && strlen($clean) !== 47 && strlen($clean) !== 48) {
            return ['success' => false, 'error' => 'Codigo de barras invalido (precisa ter 44 ou 47 digitos)'];
        }

        try {
            $resp = $this->pixRequest('GET', '/v3/gn/barcode/' . urlencode($clean));

            // EFI's "nao_encontrado" sentinel + standard 404 error envelope
            if (isset($resp['nome']) && $resp['nome'] === 'nao_encontrado') {
                return ['success' => false, 'error' => 'Boleto nao encontrado ou EFI Pagamento de Contas nao habilitado'];
            }
            if (isset($resp['error']) || isset($resp['mensagem'])) {
                return ['success' => false, 'error' => $resp['mensagem'] ?? $resp['error_description'] ?? $resp['error']];
            }

            // Successful response shape (from EFI docs):
            // {
            //   "tipo": "Boleto" | "Convenio" | "Tributo",
            //   "valor": "100.50",
            //   "vencimento": "2026-05-10",
            //   "dataLimite": "2026-06-09",
            //   "beneficiario": { "nome": "EMPRESA XYZ", "cnpj": "..." },
            //   "pagador":      { "nome": "...", "cpf": "..." },
            //   "permiteAlteracao": false  // true for tributo/convenio
            // }
            return [
                'success'           => true,
                'type'              => $resp['tipo']        ?? 'Boleto',
                'amount'            => floatval($resp['valor'] ?? 0),
                'due_date'          => $resp['vencimento']  ?? null,
                'limit_date'        => $resp['dataLimite']  ?? null,
                'beneficiary_name'  => $resp['beneficiario']['nome']  ?? '',
                'beneficiary_doc'   => $resp['beneficiario']['cnpj'] ?? $resp['beneficiario']['cpf'] ?? null,
                'allow_change'      => (bool)($resp['permiteAlteracao'] ?? false),
                'barcode'           => $clean,
            ];
        } catch (\Exception $e) {
            error_log('[EFI] consultBarcode exception: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Erro ao consultar boleto'];
        }
    }

    /**
     * Pay a boleto/conta. Caller must have already debited their wallet
     * BEFORE calling this — if EFI returns an error, refund.
     *
     * Endpoint: POST /v3/gn/barcode/{codBarras}
     * Required scope: gn.barcode.pay.write
     *
     * @param string $barcode  44 or 47-digit code (will be normalized)
     * @param float  $amount   Amount to pay (only for tributo where allowChange=true)
     * @param string $description Internal description
     * @return array {success, payment_id, status, error?}
     */
    public function payBarcode(string $barcode, float $amount, string $description = 'Pagamento SuperBora'): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }

        $clean = preg_replace('/\D/', '', $barcode);
        if (strlen($clean) !== 44 && strlen($clean) !== 47 && strlen($clean) !== 48) {
            return ['success' => false, 'error' => 'Codigo de barras invalido'];
        }

        try {
            $payload = [
                'valor'     => number_format(round($amount, 2), 2, '.', ''),
                'descricao' => substr($description, 0, 100),
            ];
            $resp = $this->pixRequest('POST', '/v3/gn/barcode/' . urlencode($clean), $payload);

            if (isset($resp['nome']) && $resp['nome'] === 'nao_encontrado') {
                return ['success' => false, 'error' => 'EFI Pagamento de Contas nao esta habilitado nesta conta'];
            }
            if (isset($resp['error']) || (isset($resp['mensagem']) && empty($resp['idPagamento']))) {
                return ['success' => false, 'error' => $resp['mensagem'] ?? $resp['error_description'] ?? $resp['error']];
            }

            // Response shape: { idPagamento, status: 'EM_PROCESSAMENTO'|'AGENDADO'|'CONFIRMADO', ... }
            return [
                'success'    => true,
                'payment_id' => $resp['idPagamento'] ?? $resp['id_pagamento'] ?? null,
                'status'     => $resp['status'] ?? 'EM_PROCESSAMENTO',
                'amount'     => floatval($resp['valor'] ?? $amount),
                'paid_at'    => $resp['horario']['solicitacao'] ?? null,
            ];
        } catch (\Exception $e) {
            error_log('[EFI] payBarcode exception: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Erro ao pagar boleto'];
        }
    }

    /**
     * Check status of a previously requested barcode payment.
     */
    public function checkBarcodePayment(string $paymentId): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'EFI nao configurado'];
        }
        $resp = $this->pixRequest('GET', '/v3/gn/requests/' . urlencode($paymentId));
        if (isset($resp['nome']) && $resp['nome'] === 'nao_encontrado') {
            return ['success' => false, 'error' => 'Pagamento nao encontrado'];
        }
        return [
            'success' => true,
            'status'  => $resp['status'] ?? 'unknown',
            'amount'  => floatval($resp['valor'] ?? 0),
            'paid_at' => $resp['horario']['liquidacao'] ?? null,
        ];
    }

    // ═══════════════════════════════════════
    // INTERNAL: Auth + HTTP
    // ═══════════════════════════════════════

    /**
     * Authenticate with EFI OAuth2 (separate tokens for PIX and Charges APIs)
     */
    private function authenticate(string $api = 'pix'): bool
    {
        // Check cached token
        if (isset($this->tokens[$api]) && time() < $this->tokens[$api]['expires_at']) {
            return true;
        }

        $baseUrl = $api === 'pix' ? $this->pixBaseUrl : $this->chargesBaseUrl;
        $url = $api === 'pix' ? $baseUrl . '/oauth/token' : $baseUrl . '/v1/authorize';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            CURLOPT_POSTFIELDS => json_encode(['grant_type' => 'client_credentials']),
            CURLOPT_SSLCERT => $this->certPath,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("[EFI] Auth curl error ({$api}): {$curlError}");
            return false;
        }

        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['access_token'])) {
            $this->tokens[$api] = [
                'access_token' => $data['access_token'],
                'expires_at' => time() + ($data['expires_in'] ?? 3600) - 60,
            ];
            return true;
        }

        error_log("[EFI] Auth failed ({$api}): HTTP {$httpCode} — " . substr($response, 0, 200));
        return false;
    }

    /**
     * PIX API request (pix.api.efipay.com.br)
     */
    private function pixRequest(string $method, string $endpoint, ?array $data = null): array
    {
        return $this->request($this->pixBaseUrl, $method, $endpoint, $data);
    }

    /**
     * Charges API request (api.efipay.com.br)
     */
    private function chargesRequest(string $method, string $endpoint, ?array $data = null): array
    {
        return $this->request($this->chargesBaseUrl, $method, $endpoint, $data);
    }

    /**
     * Generic HTTP request with mTLS
     */
    private function request(string $baseUrl, string $method, string $endpoint, ?array $data = null): array
    {
        $api = ($baseUrl === $this->pixBaseUrl) ? 'pix' : 'charges';
        if (!isset($this->tokens[$api]) || time() >= ($this->tokens[$api]['expires_at'] ?? 0)) {
            if (!$this->authenticate($api)) {
                return ['success' => false, 'error' => 'Falha na autenticacao EFI (' . $api . ')'];
            }
        }
        $token = $this->tokens[$api]['access_token'] ?? '';

        $ch = curl_init($baseUrl . $endpoint);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ];

        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH']);
        $timeout = $isWrite ? 60 : 30;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSLCERT => $this->certPath,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("[EFI] Request error {$method} {$endpoint}: {$curlError}");
            return ['success' => false, 'error' => 'Erro de comunicacao com EFI'];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && $httpCode !== 204) {
            error_log("[EFI] Invalid JSON response {$method} {$endpoint}: HTTP {$httpCode} — " . substr($response, 0, 300));
            return ['success' => false, 'error' => "Invalid response (HTTP {$httpCode})"];
        }
        return $decoded ?: ['success' => false, 'error' => "Empty response (HTTP {$httpCode})"];
    }

    /**
     * Simple GET request (no mTLS, no auth — for tokenizer/pubkey endpoints)
     */
    private function simpleGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("[EFI] simpleGet error {$url}: {$err}");
            return ['error' => $err];
        }
        return json_decode($response, true) ?: ['error' => "HTTP {$httpCode}", 'raw' => substr($response, 0, 300)];
    }

    /**
     * Simple POST request (no mTLS, no auth — for tokenizer endpoints)
     */
    private function simplePost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("[EFI] simplePost error {$url}: {$err}");
            return ['error' => $err];
        }
        return json_decode($response, true) ?: ['error' => "HTTP {$httpCode}", 'raw' => substr($response, 0, 300)];
    }

    /**
     * Format PIX key by type
     */
    private function formatPixKey(string $key, string $type): string
    {
        $key = trim($key);
        switch ($type) {
            case 'cpf':
            case 'cnpj':
                return preg_replace('/\D/', '', $key);
            case 'phone':
            case 'telefone':
                $numbers = preg_replace('/\D/', '', $key);
                if (strlen($numbers) === 11) return '+55' . $numbers;
                if (strlen($numbers) >= 13 && str_starts_with($numbers, '55')) return '+' . $numbers;
                return '+55' . $numbers;
            case 'email':
                return strtolower($key);
            case 'random':
            case 'aleatoria':
            case 'evp':
                return $key;
            default:
                return $key;
        }
    }
}

/**
 * Map a BCB ISPB code to the bank's commercial name.
 * Top ~30 brazilian banks. Falls back to "ISPB <code>" for unknowns.
 */
function bankNameFromIspb(string $ispb): string
{
    if (!$ispb) return '';
    static $map = [
        '00000000' => 'Banco do Brasil',
        '60746948' => 'Bradesco',
        '60701190' => 'Itau',
        '90400888' => 'Santander',
        '00360305' => 'Caixa Economica Federal',
        '18236120' => 'Nubank',
        '13140088' => 'Inter',
        '90400500' => 'Sicoob',
        '00714671' => 'Sicredi',
        '04902979' => 'Banco Original',
        '17192451' => 'Will Bank',
        '32402502' => 'C6 Bank',
        '13884775' => 'PagBank',
        '21018182' => 'Mercado Pago',
        '12865507' => 'BS2',
        '07237373' => 'Banco do Nordeste',
        '04184779' => 'Banco da Amazonia',
        '08561701' => 'Stone Pagamentos',
        '23522214' => 'BTG Pactual',
        '40434681' => 'Acesso Solucoes',
        '36113876' => 'Iti (Itau)',
        '00558456' => 'BDMG',
        '00997185' => 'Banese',
        '60498557' => 'Banco Safra',
        '90400125' => 'Banco Votorantim',
        '17184037' => 'Banco Topazio',
        '13720915' => 'Genial',
        '02038232' => 'Banco Daycoval',
        '07207996' => 'Cresol',
        '21332862' => 'Picpay',
        '23114447' => 'Neon',
        '08609934' => 'Cora',
        '13486793' => 'Banco BRB',
    ];
    $clean = preg_replace('/\D/', '', $ispb);
    $clean = str_pad($clean, 8, '0', STR_PAD_LEFT);
    return $map[$clean] ?? ('Banco ISPB ' . $clean);
}
