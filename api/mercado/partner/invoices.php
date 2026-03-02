<?php
/**
 * Partner Invoices (NF-e / NFC-e) Endpoint
 *
 * GET  /api/mercado/partner/invoices.php                  — List invoices (paginated)
 * GET  /api/mercado/partner/invoices.php?action=config    — Get fiscal config
 * GET  /api/mercado/partner/invoices.php?action=stats     — Invoice stats for dashboard
 *
 * POST /api/mercado/partner/invoices.php
 *   action: 'emit'         — Emit invoice for a single order
 *   action: 'emit_batch'   — Emit invoices for multiple orders
 *   action: 'cancel'       — Cancel an authorized invoice
 *   action: 'download_pdf' — Get PDF URL for an invoice
 *   action: 'download_xml' — Get XML URL for an invoice
 *   action: 'save_config'  — Save/update fiscal configuration
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once __DIR__ . "/../helpers/nfe-service.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    $nfeService = new NFeService($db);

    $method = $_SERVER['REQUEST_METHOD'];

    // ======================== GET REQUESTS ========================
    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? '');

        // ---------- GET CONFIG ----------
        if ($action === 'config') {
            $config = $nfeService->getPartnerConfig($partnerId);
            response(true, ['config' => $config], 'Configuracao fiscal carregada');
        }

        // ---------- GET STATS ----------
        if ($action === 'stats') {
            $monthStart = date('Y-m-01');
            $monthEnd = date('Y-m-t');

            // Invoices this month
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) FILTER (WHERE status = 'authorized') as emitidas,
                    COUNT(*) FILTER (WHERE status = 'pending' OR status = 'processing') as pendentes,
                    COUNT(*) FILTER (WHERE status = 'error') as erros,
                    COALESCE(SUM(total_amount) FILTER (WHERE status = 'authorized'), 0) as valor_total
                FROM om_partner_invoices
                WHERE partner_id = ?
                  AND created_at >= ?::date
                  AND created_at <= (?::date + INTERVAL '1 day')
            ");
            $stmt->execute([$partnerId, $monthStart, $monthEnd]);
            $stats = $stmt->fetch();

            response(true, [
                'emitidas_mes' => (int)($stats['emitidas'] ?? 0),
                'valor_total_mes' => round((float)($stats['valor_total'] ?? 0), 2),
                'pendentes' => (int)($stats['pendentes'] ?? 0),
                'erros' => (int)($stats['erros'] ?? 0),
            ], 'Estatisticas de notas fiscais');
        }

        // ---------- LIST INVOICES (default GET) ----------
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $status = trim($_GET['status'] ?? '');
        $orderId = (int)($_GET['order_id'] ?? 0);
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        $where = ["partner_id = ?"];
        $params = [$partnerId];

        if ($status && in_array($status, ['pending', 'processing', 'authorized', 'cancelled', 'error'], true)) {
            $where[] = "status = ?";
            $params[] = $status;
        }

        if ($orderId > 0) {
            $where[] = "order_id = ?";
            $params[] = $orderId;
        }

        if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where[] = "created_at >= ?::date";
            $params[] = $dateFrom;
        }

        if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where[] = "created_at <= (?::date + INTERVAL '1 day')";
            $params[] = $dateTo;
        }

        $whereClause = implode(' AND ', $where);

        // Count
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_partner_invoices WHERE {$whereClause}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // Fetch
        $stmtList = $db->prepare("
            SELECT
                invoice_id, partner_id, order_id, invoice_type, status,
                external_id, access_key, number, series,
                xml_url, pdf_url,
                total_amount, tax_amount,
                customer_cpf, customer_name,
                error_message,
                issued_at, cancelled_at, created_at, updated_at
            FROM om_partner_invoices
            WHERE {$whereClause}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmtList->execute(array_merge($params, [$limit, $offset]));
        $invoices = $stmtList->fetchAll();

        // Format
        $formatted = [];
        foreach ($invoices as $inv) {
            $formatted[] = [
                'invoice_id' => (int)$inv['invoice_id'],
                'order_id' => $inv['order_id'] ? (int)$inv['order_id'] : null,
                'invoice_type' => $inv['invoice_type'],
                'status' => $inv['status'],
                'external_id' => $inv['external_id'],
                'access_key' => $inv['access_key'],
                'number' => $inv['number'] ? (int)$inv['number'] : null,
                'series' => (int)$inv['series'],
                'xml_url' => $inv['xml_url'],
                'pdf_url' => $inv['pdf_url'],
                'total_amount' => round((float)($inv['total_amount'] ?? 0), 2),
                'tax_amount' => round((float)($inv['tax_amount'] ?? 0), 2),
                'customer_cpf' => $inv['customer_cpf'],
                'customer_name' => $inv['customer_name'],
                'error_message' => $inv['error_message'],
                'issued_at' => $inv['issued_at'],
                'cancelled_at' => $inv['cancelled_at'],
                'created_at' => $inv['created_at'],
            ];
        }

        // Get config status for header
        $config = $nfeService->getPartnerConfig($partnerId);

        response(true, [
            'invoices' => $formatted,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
            'fiscal_enabled' => (bool)($config['enabled'] ?? false),
            'auto_emit' => (bool)($config['auto_emit'] ?? false),
        ], 'Notas fiscais listadas');
    }

    // ======================== POST REQUESTS ========================
    if ($method === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        // ---------- EMIT SINGLE INVOICE ----------
        if ($action === 'emit') {
            $orderId = (int)($input['order_id'] ?? 0);
            if (!$orderId) {
                response(false, null, 'order_id obrigatorio', 400);
            }

            // Fetch order data
            $stmt = $db->prepare("
                SELECT
                    o.order_id, o.order_number, o.total, o.subtotal, o.delivery_fee,
                    o.customer_name, o.customer_phone, o.status
                FROM om_market_orders o
                WHERE o.order_id = ? AND o.partner_id = ?
                LIMIT 1
            ");
            $stmt->execute([$orderId, $partnerId]);
            $order = $stmt->fetch();

            if (!$order) {
                response(false, null, 'Pedido nao encontrado', 404);
            }

            // Fetch order items
            $stmtItems = $db->prepare("
                SELECT name, quantity, price FROM om_market_order_items WHERE order_id = ?
            ");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll();

            $orderData = [
                'order_id' => $orderId,
                'total' => (float)$order['total'],
                'items' => $items,
                'customer_name' => $order['customer_name'] ?? '',
                'customer_cpf' => trim($input['customer_cpf'] ?? ''),
            ];

            $result = $nfeService->emitNFCe($partnerId, $orderData);

            if ($result['success']) {
                response(true, $result, $result['message'] ?? 'NFC-e emitida');
            } else {
                response(false, $result, $result['message'] ?? 'Erro ao emitir NFC-e', 400);
            }
        }

        // ---------- EMIT BATCH ----------
        if ($action === 'emit_batch') {
            $orderIds = $input['order_ids'] ?? [];
            if (!is_array($orderIds) || empty($orderIds)) {
                response(false, null, 'order_ids obrigatorio (array)', 400);
            }

            // Limit batch size
            if (count($orderIds) > 50) {
                response(false, null, 'Maximo de 50 notas por lote', 400);
            }

            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($orderIds as $orderId) {
                $orderId = (int)$orderId;
                if (!$orderId) continue;

                // Fetch order
                $stmt = $db->prepare("
                    SELECT o.order_id, o.total, o.customer_name
                    FROM om_market_orders o
                    WHERE o.order_id = ? AND o.partner_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$orderId, $partnerId]);
                $order = $stmt->fetch();

                if (!$order) {
                    $results[] = ['order_id' => $orderId, 'success' => false, 'message' => 'Pedido nao encontrado'];
                    $errorCount++;
                    continue;
                }

                // Fetch items
                $stmtItems = $db->prepare("
                    SELECT name, quantity, price FROM om_market_order_items WHERE order_id = ?
                ");
                $stmtItems->execute([$orderId]);
                $items = $stmtItems->fetchAll();

                $orderData = [
                    'order_id' => $orderId,
                    'total' => (float)$order['total'],
                    'items' => $items,
                    'customer_name' => $order['customer_name'] ?? '',
                    'customer_cpf' => '',
                ];

                $result = $nfeService->emitNFCe($partnerId, $orderData);
                $result['order_id'] = $orderId;
                $results[] = $result;

                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }

            response(true, [
                'results' => $results,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'total' => count($results),
            ], "{$successCount} nota(s) emitida(s), {$errorCount} erro(s)");
        }

        // ---------- CANCEL INVOICE ----------
        if ($action === 'cancel') {
            $invoiceId = (int)($input['invoice_id'] ?? 0);
            $reason = trim($input['reason'] ?? '');

            if (!$invoiceId) {
                response(false, null, 'invoice_id obrigatorio', 400);
            }

            // Verify this invoice belongs to the partner
            $stmt = $db->prepare("
                SELECT external_id FROM om_partner_invoices
                WHERE invoice_id = ? AND partner_id = ? AND status = 'authorized'
                LIMIT 1
            ");
            $stmt->execute([$invoiceId, $partnerId]);
            $invoice = $stmt->fetch();

            if (!$invoice) {
                response(false, null, 'Nota fiscal nao encontrada ou nao pode ser cancelada', 404);
            }

            $externalId = $invoice['external_id'];
            if (empty($externalId)) {
                // No external ID — just mark as cancelled locally
                $stmt = $db->prepare("
                    UPDATE om_partner_invoices
                    SET status = 'cancelled', cancelled_at = NOW(), error_message = ?, updated_at = NOW()
                    WHERE invoice_id = ?
                ");
                $stmt->execute(['Cancelamento local: ' . ($reason ?: 'Sem motivo'), $invoiceId]);
                response(true, ['invoice_id' => $invoiceId], 'Nota fiscal cancelada');
            }

            $result = $nfeService->cancelInvoice($externalId, $reason);

            if ($result['success']) {
                response(true, $result, $result['message']);
            } else {
                response(false, $result, $result['message'], 400);
            }
        }

        // ---------- DOWNLOAD PDF ----------
        if ($action === 'download_pdf') {
            $invoiceId = (int)($input['invoice_id'] ?? 0);
            if (!$invoiceId) {
                response(false, null, 'invoice_id obrigatorio', 400);
            }

            $stmt = $db->prepare("
                SELECT external_id, pdf_url FROM om_partner_invoices
                WHERE invoice_id = ? AND partner_id = ?
                LIMIT 1
            ");
            $stmt->execute([$invoiceId, $partnerId]);
            $invoice = $stmt->fetch();

            if (!$invoice) {
                response(false, null, 'Nota fiscal nao encontrada', 404);
            }

            $pdfUrl = $invoice['pdf_url'];
            if (empty($pdfUrl) && !empty($invoice['external_id'])) {
                $pdfUrl = $nfeService->downloadPdf($invoice['external_id']);
            }

            if ($pdfUrl) {
                response(true, ['pdf_url' => $pdfUrl], 'URL do PDF');
            } else {
                response(false, null, 'PDF nao disponivel (nota em modo simulacao ou ainda nao processada)', 404);
            }
        }

        // ---------- DOWNLOAD XML ----------
        if ($action === 'download_xml') {
            $invoiceId = (int)($input['invoice_id'] ?? 0);
            if (!$invoiceId) {
                response(false, null, 'invoice_id obrigatorio', 400);
            }

            $stmt = $db->prepare("
                SELECT external_id, xml_url FROM om_partner_invoices
                WHERE invoice_id = ? AND partner_id = ?
                LIMIT 1
            ");
            $stmt->execute([$invoiceId, $partnerId]);
            $invoice = $stmt->fetch();

            if (!$invoice) {
                response(false, null, 'Nota fiscal nao encontrada', 404);
            }

            $xmlUrl = $invoice['xml_url'];
            if (empty($xmlUrl) && !empty($invoice['external_id'])) {
                $xmlUrl = $nfeService->downloadXml($invoice['external_id']);
            }

            if ($xmlUrl) {
                response(true, ['xml_url' => $xmlUrl], 'URL do XML');
            } else {
                response(false, null, 'XML nao disponivel', 404);
            }
        }

        // ---------- SAVE CONFIG ----------
        if ($action === 'save_config') {
            $configData = $input['config'] ?? $input;
            // Remove non-config fields
            unset($configData['action']);

            $result = $nfeService->savePartnerConfig($partnerId, $configData);

            if ($result['success']) {
                $config = $nfeService->getPartnerConfig($partnerId);
                response(true, ['config' => $config], $result['message']);
            } else {
                response(false, null, $result['message'], 400);
            }
        }

        // Unknown action
        response(false, null, 'Acao nao reconhecida. Acoes disponiveis: emit, emit_batch, cancel, download_pdf, download_xml, save_config', 400);
    }

    // Method not allowed
    response(false, null, 'Metodo nao permitido', 405);

} catch (Exception $e) {
    error_log("[partner/invoices] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
