<?php
/**
 * NFeService - NF-e / NFC-e invoice generation service
 *
 * Integrates with NFE.io API for electronic invoice emission.
 * Falls back to simulated responses when credentials are not configured.
 *
 * Usage:
 *   $nfe = new NFeService($db);
 *   $result = $nfe->emitNFCe($partnerId, $orderData);
 *   $result = $nfe->cancelInvoice($externalId, 'reason');
 *   $result = $nfe->getStatus($externalId);
 */

class NFeService {
    private $apiKey;
    private $baseUrl;
    private $companyId;
    private $ambiente;
    private $db;
    private $isConfigured;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->apiKey = $_ENV['NFEIO_API_KEY'] ?? '';
        $this->companyId = $_ENV['NFEIO_COMPANY_ID'] ?? '';
        $this->ambiente = $_ENV['NFEIO_AMBIENTE'] ?? 'production';
        $this->baseUrl = 'https://api.nfe.io/v1';
        $this->isConfigured = !empty($this->apiKey) && $this->apiKey !== 'CHANGE_ME';
    }

    /**
     * Generate NFC-e from order data
     *
     * @param int $partnerId
     * @param array $orderData Must contain: order_id, items[], total, customer_name, customer_cpf (optional)
     * @return array ['success' => bool, 'invoice_id' => int, 'message' => string, ...]
     */
    public function emitNFCe(int $partnerId, array $orderData): array {
        $orderId = (int)($orderData['order_id'] ?? 0);
        if (!$orderId) {
            return ['success' => false, 'message' => 'order_id obrigatorio'];
        }

        // Check for duplicate invoice for this order
        $stmt = $this->db->prepare("
            SELECT invoice_id, status FROM om_partner_invoices
            WHERE partner_id = ? AND order_id = ? AND status IN ('pending', 'processing', 'authorized')
            LIMIT 1
        ");
        $stmt->execute([$partnerId, $orderId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return [
                'success' => false,
                'message' => 'Ja existe uma nota fiscal para este pedido (status: ' . $existing['status'] . ')',
                'invoice_id' => (int)$existing['invoice_id'],
            ];
        }

        // Get partner fiscal config
        $config = $this->getPartnerConfig($partnerId);

        // Build invoice items
        $items = $this->buildItems($orderData['items'] ?? []);
        $totalAmount = round((float)($orderData['total'] ?? 0), 2);
        $customerCpf = trim($orderData['customer_cpf'] ?? '');
        $customerName = trim($orderData['customer_name'] ?? '');

        // Estimate tax amount (simplified - ICMS for Simples Nacional)
        $taxAmount = $this->estimateTax($totalAmount, $config);

        // Insert invoice record as pending
        $stmt = $this->db->prepare("
            INSERT INTO om_partner_invoices
            (partner_id, order_id, invoice_type, status, total_amount, tax_amount,
             customer_cpf, customer_name, items_json, series, created_at, updated_at)
            VALUES (?, ?, 'nfce', 'processing', ?, ?, ?, ?, ?, ?, NOW(), NOW())
            RETURNING invoice_id
        ");
        $stmt->execute([
            $partnerId,
            $orderId,
            $totalAmount,
            $taxAmount,
            $customerCpf ?: null,
            $customerName ?: null,
            json_encode($items, JSON_UNESCAPED_UNICODE),
            (int)($config['series'] ?? 1),
        ]);
        $invoiceId = (int)$stmt->fetchColumn();

        // Build NFE.io payload
        $nfePayload = $this->buildNFePayload($partnerId, $orderData, $items, $config, $customerCpf, $customerName);

        // Call NFE.io API or simulate
        if ($this->isConfigured) {
            $result = $this->callNFeApi($nfePayload, $config);
        } else {
            $result = $this->simulateEmission($invoiceId, $totalAmount);
        }

        // Update invoice record with result
        if ($result['success']) {
            $stmt = $this->db->prepare("
                UPDATE om_partner_invoices
                SET status = 'authorized',
                    external_id = ?,
                    access_key = ?,
                    number = ?,
                    xml_url = ?,
                    pdf_url = ?,
                    issued_at = NOW(),
                    updated_at = NOW()
                WHERE invoice_id = ?
            ");
            $stmt->execute([
                $result['external_id'] ?? null,
                $result['access_key'] ?? null,
                $result['number'] ?? null,
                $result['xml_url'] ?? null,
                $result['pdf_url'] ?? null,
                $invoiceId,
            ]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE om_partner_invoices
                SET status = 'error',
                    error_message = ?,
                    updated_at = NOW()
                WHERE invoice_id = ?
            ");
            $stmt->execute([
                $result['message'] ?? 'Erro desconhecido',
                $invoiceId,
            ]);
        }

        $result['invoice_id'] = $invoiceId;
        return $result;
    }

    /**
     * Cancel an authorized invoice
     *
     * @param string $externalId NFE.io invoice ID
     * @param string $reason Cancellation reason (required by SEFAZ)
     * @return array
     */
    public function cancelInvoice(string $externalId, string $reason): array {
        if (empty($reason) || mb_strlen($reason) < 15) {
            return ['success' => false, 'message' => 'Motivo do cancelamento deve ter no minimo 15 caracteres'];
        }

        // Look up invoice
        $stmt = $this->db->prepare("
            SELECT invoice_id, partner_id, status FROM om_partner_invoices
            WHERE external_id = ? AND status = 'authorized'
            LIMIT 1
        ");
        $stmt->execute([$externalId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            return ['success' => false, 'message' => 'Nota fiscal nao encontrada ou nao pode ser cancelada'];
        }

        if ($this->isConfigured) {
            $companyId = $this->getPartnerCompanyId($invoice['partner_id']);
            $response = $this->httpRequest(
                'DELETE',
                "/companies/{$companyId}/serviceinvoices/{$externalId}",
                ['reason' => $reason]
            );

            if (!$response['success']) {
                // Update with error
                $stmt = $this->db->prepare("
                    UPDATE om_partner_invoices
                    SET error_message = ?, updated_at = NOW()
                    WHERE invoice_id = ?
                ");
                $stmt->execute([$response['message'] ?? 'Erro ao cancelar', $invoice['invoice_id']]);
                return $response;
            }
        }

        // Mark as cancelled
        $stmt = $this->db->prepare("
            UPDATE om_partner_invoices
            SET status = 'cancelled',
                cancelled_at = NOW(),
                error_message = ?,
                updated_at = NOW()
            WHERE invoice_id = ?
        ");
        $stmt->execute(['Cancelamento: ' . $reason, $invoice['invoice_id']]);

        return [
            'success' => true,
            'message' => 'Nota fiscal cancelada com sucesso',
            'invoice_id' => (int)$invoice['invoice_id'],
        ];
    }

    /**
     * Get invoice status from NFE.io
     *
     * @param string $externalId
     * @return array
     */
    public function getStatus(string $externalId): array {
        if (!$this->isConfigured) {
            // Return local status
            $stmt = $this->db->prepare("
                SELECT status, error_message FROM om_partner_invoices
                WHERE external_id = ? LIMIT 1
            ");
            $stmt->execute([$externalId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['success' => false, 'message' => 'Nota fiscal nao encontrada'];
            }
            return ['success' => true, 'status' => $row['status'], 'error_message' => $row['error_message']];
        }

        // Look up partner for this invoice to get company ID
        $stmt = $this->db->prepare("
            SELECT partner_id FROM om_partner_invoices WHERE external_id = ? LIMIT 1
        ");
        $stmt->execute([$externalId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['success' => false, 'message' => 'Nota fiscal nao encontrada'];
        }

        $companyId = $this->getPartnerCompanyId((int)$row['partner_id']);
        $response = $this->httpRequest('GET', "/companies/{$companyId}/serviceinvoices/{$externalId}");

        if ($response['success'] && isset($response['data'])) {
            $nfeStatus = $response['data']['status'] ?? 'unknown';
            $mappedStatus = $this->mapNFeStatus($nfeStatus);

            // Sync local status
            $stmt = $this->db->prepare("
                UPDATE om_partner_invoices
                SET status = ?,
                    pdf_url = COALESCE(?, pdf_url),
                    xml_url = COALESCE(?, xml_url),
                    updated_at = NOW()
                WHERE external_id = ?
            ");
            $stmt->execute([
                $mappedStatus,
                $response['data']['pdfUrl'] ?? null,
                $response['data']['xmlUrl'] ?? null,
                $externalId,
            ]);

            return [
                'success' => true,
                'status' => $mappedStatus,
                'nfe_status' => $nfeStatus,
                'pdf_url' => $response['data']['pdfUrl'] ?? null,
                'xml_url' => $response['data']['xmlUrl'] ?? null,
            ];
        }

        return $response;
    }

    /**
     * Get PDF download URL for an invoice
     *
     * @param string $externalId
     * @return string|null
     */
    public function downloadPdf(string $externalId): ?string {
        $stmt = $this->db->prepare("
            SELECT pdf_url FROM om_partner_invoices WHERE external_id = ? LIMIT 1
        ");
        $stmt->execute([$externalId]);
        $row = $stmt->fetch();

        if ($row && !empty($row['pdf_url'])) {
            return $row['pdf_url'];
        }

        // Try to fetch from NFE.io
        if ($this->isConfigured) {
            $statusResult = $this->getStatus($externalId);
            return $statusResult['pdf_url'] ?? null;
        }

        return null;
    }

    /**
     * Get XML download URL for an invoice
     *
     * @param string $externalId
     * @return string|null
     */
    public function downloadXml(string $externalId): ?string {
        $stmt = $this->db->prepare("
            SELECT xml_url FROM om_partner_invoices WHERE external_id = ? LIMIT 1
        ");
        $stmt->execute([$externalId]);
        $row = $stmt->fetch();

        if ($row && !empty($row['xml_url'])) {
            return $row['xml_url'];
        }

        if ($this->isConfigured) {
            $statusResult = $this->getStatus($externalId);
            return $statusResult['xml_url'] ?? null;
        }

        return null;
    }

    /**
     * Get partner fiscal config
     */
    public function getPartnerConfig(int $partnerId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM om_partner_fiscal_config WHERE partner_id = ? LIMIT 1
        ");
        $stmt->execute([$partnerId]);
        $config = $stmt->fetch();

        if (!$config) {
            return [
                'partner_id' => $partnerId,
                'enabled' => false,
                'auto_emit' => false,
                'regime' => 'simples',
                'cnpj' => null,
                'inscricao_estadual' => null,
                'inscricao_municipal' => null,
                'crt' => 1,
                'cfop' => '5102',
                'ncm_padrao' => '21069090',
                'nfeio_company_id' => null,
                'series' => 1,
            ];
        }

        return $config;
    }

    /**
     * Save/update partner fiscal config
     */
    public function savePartnerConfig(int $partnerId, array $data): array {
        $allowed = ['enabled', 'auto_emit', 'regime', 'cnpj', 'inscricao_estadual',
                     'inscricao_municipal', 'crt', 'cfop', 'ncm_padrao', 'nfeio_company_id'];

        $fields = [];
        $values = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = $key;
                if ($key === 'enabled' || $key === 'auto_emit') {
                    $values[] = $data[$key] ? true : false;
                } elseif ($key === 'crt') {
                    $values[] = (int)$data[$key];
                } else {
                    $values[] = trim((string)$data[$key]) ?: null;
                }
            }
        }

        if (empty($fields)) {
            return ['success' => false, 'message' => 'Nenhum campo para atualizar'];
        }

        // Validate CNPJ format if provided
        if (isset($data['cnpj']) && !empty($data['cnpj'])) {
            $cnpj = preg_replace('/\D/', '', $data['cnpj']);
            if (strlen($cnpj) !== 14) {
                return ['success' => false, 'message' => 'CNPJ deve ter 14 digitos'];
            }
        }

        // Validate regime
        if (isset($data['regime']) && !in_array($data['regime'], ['mei', 'simples', 'presumido', 'real'], true)) {
            return ['success' => false, 'message' => 'Regime tributario invalido'];
        }

        // UPSERT
        $setClauses = array_map(fn($f) => "{$f} = ?", $fields);
        $setClauses[] = "updated_at = NOW()";

        $insertFields = array_merge(['partner_id'], $fields, ['updated_at']);
        $insertPlaceholders = array_merge(['?'], array_fill(0, count($fields), '?'), ['NOW()']);

        $sql = "INSERT INTO om_partner_fiscal_config (partner_id, " . implode(', ', $fields) . ", updated_at)
                VALUES (?, " . implode(', ', array_fill(0, count($fields), '?')) . ", NOW())
                ON CONFLICT (partner_id) DO UPDATE SET " . implode(', ', $setClauses);

        $params = array_merge([$partnerId], $values, $values);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return ['success' => true, 'message' => 'Configuracao fiscal salva com sucesso'];
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Build invoice items array from order items
     */
    private function buildItems(array $orderItems): array {
        $items = [];
        foreach ($orderItems as $item) {
            $qty = (int)($item['quantity'] ?? $item['quantidade'] ?? 1);
            $price = round((float)($item['price'] ?? $item['preco_unitario'] ?? 0), 2);
            $items[] = [
                'name' => trim($item['name'] ?? $item['nome'] ?? 'Produto'),
                'quantity' => $qty,
                'unit_price' => $price,
                'total' => round($price * $qty, 2),
                'ncm' => $item['ncm'] ?? '21069090',
                'cfop' => $item['cfop'] ?? '5102',
            ];
        }
        return $items;
    }

    /**
     * Build the NFE.io API payload
     */
    private function buildNFePayload(int $partnerId, array $orderData, array $items, array $config, string $cpf, string $name): array {
        $nfeItems = [];
        foreach ($items as $item) {
            $nfeItems[] = [
                'description' => $item['name'],
                'quantity' => $item['quantity'],
                'unitAmount' => $item['unit_price'],
                'amount' => $item['total'],
                'taxDetails' => [
                    'ncm' => $item['ncm'],
                    'cfop' => $item['cfop'],
                ],
            ];
        }

        $payload = [
            'cityServiceCode' => '0107', // Servicos de alimentacao
            'description' => 'Venda de produtos alimenticios - Pedido #' . ($orderData['order_id'] ?? ''),
            'servicesAmount' => round((float)($orderData['total'] ?? 0), 2),
            'items' => $nfeItems,
        ];

        // Add borrower (customer) info if CPF provided
        if (!empty($cpf)) {
            $payload['borrower'] = [
                'name' => $name ?: 'Consumidor',
                'federalTaxNumber' => preg_replace('/\D/', '', $cpf),
            ];
        }

        return $payload;
    }

    /**
     * Call NFE.io API to emit invoice
     */
    private function callNFeApi(array $payload, array $config): array {
        $companyId = $config['nfeio_company_id'] ?? $this->companyId;
        if (empty($companyId) || $companyId === 'CHANGE_ME') {
            return ['success' => false, 'message' => 'Company ID nao configurado no NFE.io'];
        }

        $response = $this->httpRequest('POST', "/companies/{$companyId}/serviceinvoices", $payload);

        if ($response['success'] && isset($response['data'])) {
            $data = $response['data'];
            return [
                'success' => true,
                'message' => 'NFC-e emitida com sucesso',
                'external_id' => $data['id'] ?? null,
                'access_key' => $data['accessKey'] ?? null,
                'number' => $data['number'] ?? null,
                'xml_url' => $data['xmlUrl'] ?? null,
                'pdf_url' => $data['pdfUrl'] ?? null,
            ];
        }

        return $response;
    }

    /**
     * Make HTTP request to NFE.io API
     */
    private function httpRequest(string $method, string $endpoint, array $data = []): array {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Authorization: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[NFeService] cURL error: {$error}");
            return ['success' => false, 'message' => 'Erro de conexao com NFE.io: ' . $error];
        }

        $responseData = json_decode($responseBody, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => $responseData,
                'http_code' => $httpCode,
            ];
        }

        $errorMsg = $responseData['message'] ?? $responseData['error'] ?? "HTTP {$httpCode}";
        error_log("[NFeService] API error: {$httpCode} - {$errorMsg}");
        return [
            'success' => false,
            'message' => 'Erro NFE.io: ' . $errorMsg,
            'http_code' => $httpCode,
        ];
    }

    /**
     * Simulate invoice emission for testing (when NFE.io not configured)
     */
    private function simulateEmission(int $invoiceId, float $totalAmount): array {
        // Generate realistic-looking simulated data
        $fakeAccessKey = str_pad((string)$invoiceId, 4, '0', STR_PAD_LEFT)
            . date('Ymd')
            . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT)
            . str_pad((string)random_int(1, 999999999), 9, '0', STR_PAD_LEFT)
            . str_pad((string)random_int(1, 999999999), 9, '0', STR_PAD_LEFT)
            . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $fakeAccessKey = substr($fakeAccessKey, 0, 44);

        return [
            'success' => true,
            'message' => 'NFC-e emitida com sucesso (modo simulacao - configure NFEIO_API_KEY para emissao real)',
            'external_id' => 'sim_' . bin2hex(random_bytes(12)),
            'access_key' => $fakeAccessKey,
            'number' => $invoiceId,
            'xml_url' => null,
            'pdf_url' => null,
            'simulated' => true,
        ];
    }

    /**
     * Map NFE.io status to our internal status
     */
    private function mapNFeStatus(string $nfeStatus): string {
        return match(strtolower($nfeStatus)) {
            'issued', 'authorized' => 'authorized',
            'cancelled', 'canceled' => 'cancelled',
            'pending', 'created', 'waiting' => 'processing',
            'error', 'denied', 'rejected' => 'error',
            default => 'processing',
        };
    }

    /**
     * Get the NFE.io company ID for a partner
     */
    private function getPartnerCompanyId(int $partnerId): string {
        $config = $this->getPartnerConfig($partnerId);
        $companyId = $config['nfeio_company_id'] ?? '';
        if (empty($companyId)) {
            $companyId = $this->companyId;
        }
        return $companyId;
    }

    /**
     * Estimate tax amount based on regime and total
     */
    private function estimateTax(float $totalAmount, array $config): float {
        $regime = $config['regime'] ?? 'simples';
        $rate = match($regime) {
            'mei' => 0.0, // MEI pays fixed monthly DAS
            'simples' => 0.06, // ~6% first bracket Simples Nacional
            'presumido' => 0.0557, // ~5.57% Lucro Presumido
            'real' => 0.0925, // Approximate effective rate
            default => 0.06,
        };
        return round($totalAmount * $rate, 2);
    }
}
