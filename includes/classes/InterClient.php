<?php
/**
 * InterClient — Banco Inter PJ API client for SuperBora
 *
 * Mirrors the EfiClient/MercadoPagoClient/AsaasClient surface so callers can
 * pick a provider via env without rewriting the integration.
 *
 * Auth: OAuth2 client_credentials + mTLS certificate (PKCS#8 RSA key + PEM cert).
 *
 * Inter-specific quirks:
 *   - Endpoint: cdpj.partners.bancointer.com.br (production), no sandbox.
 *   - OAuth scopes are tied to the application config in the Inter dashboard.
 *     If you request a scope that isn't enabled, you get HTTP 401 with
 *     "No registered scope value for this client has been requested".
 *   - DICT lookup is exposed via the Pix Pagamento flow: their endpoint
 *     `GET /pix-pagamento/v2/destinatarios?chave={key}` returns the recipient
 *     before initiating a payment. Some account tiers expose it directly,
 *     others require initiating a (cancellable) payment to read the data.
 *   - Inter PJ correntistas get DICT lookup FREE (no per-query fee).
 *
 * Env vars:
 *   INTER_CLIENT_ID
 *   INTER_CLIENT_SECRET
 *   INTER_CERT_PATH        — path to PEM cert
 *   INTER_KEY_PATH         — path to PEM private key (separate file)
 *   INTER_SANDBOX          — '1' for sandbox (UAT), '0' for production
 */
class InterClient
{
    private string $clientId;
    private string $clientSecret;
    private string $certPath;
    private string $keyPath;
    private string $baseUrl;
    private int $timeout = 30;

    private array $tokens = []; // [scope_key => ['access_token', 'expires_at']]

    public function __construct()
    {
        $this->clientId     = $_ENV['INTER_CLIENT_ID']     ?? getenv('INTER_CLIENT_ID')     ?: '';
        $this->clientSecret = $_ENV['INTER_CLIENT_SECRET'] ?? getenv('INTER_CLIENT_SECRET') ?: '';
        $this->certPath     = $_ENV['INTER_CERT_PATH']     ?? getenv('INTER_CERT_PATH')     ?: '/var/www/html/api/certs/inter.crt';
        $this->keyPath      = $_ENV['INTER_KEY_PATH']      ?? getenv('INTER_KEY_PATH')      ?: '/var/www/html/api/certs/inter.key';
        $sandbox = (string)($_ENV['INTER_SANDBOX'] ?? getenv('INTER_SANDBOX') ?: '0') === '1';
        $this->baseUrl = $sandbox
            ? 'https://cdpj-sandbox.partners.uatinter.co'
            : 'https://cdpj.partners.bancointer.com.br';
    }

    public function isConfigured(): bool
    {
        if (empty($this->clientId) || empty($this->clientSecret)) return false;
        if (!file_exists($this->certPath) || !file_exists($this->keyPath)) return false;
        return true;
    }

    /**
     * Authenticate with Inter and cache the access token by scope set.
     * Returns the bearer token string or empty on failure.
     */
    private function authenticate(string $scopes): string
    {
        $cacheKey = md5($scopes);
        if (isset($this->tokens[$cacheKey]) && $this->tokens[$cacheKey]['expires_at'] > time() + 30) {
            return $this->tokens[$cacheKey]['access_token'];
        }

        $ch = curl_init($this->baseUrl . '/oauth/v2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'client_credentials',
                'scope'         => $scopes,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSLCERT  => $this->certPath,
            CURLOPT_SSLKEY   => $this->keyPath,
            CURLOPT_TIMEOUT  => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log('[InterClient] cURL error: ' . $err);
            return '';
        }
        if ($code !== 200) {
            error_log("[InterClient] OAuth HTTP {$code}: " . substr($body, 0, 300));
            return '';
        }

        $data = json_decode($body, true);
        if (empty($data['access_token'])) return '';

        $this->tokens[$cacheKey] = [
            'access_token' => $data['access_token'],
            'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
            'scope_granted' => $data['scope'] ?? '',
        ];
        return $data['access_token'];
    }

    // ═══════════════════════════════════════
    // DICT LOOKUP — the main feature
    // ═══════════════════════════════════════

    /**
     * Look up a PIX key holder via Banco Inter DICT.
     *
     * Inter exposes the DICT through `GET /pix-pagamento/v2/destinatarios?chave={key}`.
     * The response includes the recipient name, masked CPF/CNPJ, ISPB and bank name.
     * Free for Inter PJ correntistas.
     *
     * Requires the `pix.write` scope on the Inter application.
     */
    public function lookupPixKey(string $pixKey): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Inter nao configurado'];
        }
        if (empty($pixKey)) {
            return ['success' => false, 'error' => 'Chave obrigatoria'];
        }

        $token = $this->authenticate('pix.write pix.read');
        if (!$token) {
            return ['success' => false, 'error' => 'Falha na autenticacao Inter (escopo pix.write nao disponivel?)'];
        }

        // Try the "destinatarios" lookup endpoint first
        $tries = [
            ['GET', '/pix-pagamento/v2/destinatarios?chave=' . urlencode($pixKey)],
            ['GET', '/pix-pagamento/v2/dict/' . urlencode($pixKey)],
            ['GET', '/banking/v2/pix/' . urlencode($pixKey)],
        ];

        $lastError = 'Endpoint nao encontrado';
        foreach ($tries as [$method, $path]) {
            $ch = curl_init($this->baseUrl . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_SSLCERT  => $this->certPath,
                CURLOPT_SSLKEY   => $this->keyPath,
                CURLOPT_TIMEOUT  => $this->timeout,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 404) { $lastError = 'Endpoint inexistente'; continue; }
            if ($code !== 200) {
                $err = json_decode($body, true);
                $lastError = $err['detail'] ?? $err['message'] ?? "HTTP {$code}";
                continue;
            }

            $data = json_decode($body, true);
            if (!is_array($data)) continue;

            // Inter response shape (from BCB DICT):
            // {
            //   "chave": "...",
            //   "tipoChave": "CPF",
            //   "nomeCorrentista": "JOAO DA SILVA",
            //   "cpfCnpjCorrentista": "***456789**",
            //   "instituicao": { "nome": "Banco Inter", "ispb": "00416968" },
            //   "tipoConta": "CACC",
            //   "agencia": "0001",
            //   "conta": "..."
            // }
            $name = $data['nomeCorrentista']
                ?? $data['nome']
                ?? $data['holder']['name']
                ?? null;
            if (!$name) continue;

            return [
                'success'         => true,
                'holder_name'     => $name,
                'holder_document' => $data['cpfCnpjCorrentista'] ?? $data['cpfCnpj'] ?? null,
                'bank_name'       => $data['instituicao']['nome'] ?? $data['nomeInstituicao'] ?? null,
                'bank_ispb'       => $data['instituicao']['ispb'] ?? $data['ispb']            ?? null,
                'agencia'         => $data['agencia'] ?? null,
                'conta'           => $data['conta']   ?? null,
                'lookup_source'   => 'inter',
            ];
        }

        return ['success' => false, 'error' => $lastError];
    }

    // ═══════════════════════════════════════
    // (Future) PIX charge / cob — stubs for symmetry
    // Inter has these but we're not using them yet.
    // ═══════════════════════════════════════
    public function createPixCharge(float $amount, string $description, int $expiresSeconds = 600, array $customer = []): array
    {
        return ['success' => false, 'error' => 'InterClient::createPixCharge nao implementado (use EFI ou MP)'];
    }
    public function checkChargeStatus(string $txid): array
    {
        return ['success' => false, 'error' => 'nao implementado'];
    }
}
