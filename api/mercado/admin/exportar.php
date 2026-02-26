<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

/**
 * Sanitize a value for CSV output to prevent formula injection.
 * Cells starting with =, +, -, @ can trigger spreadsheet execution.
 */
function csvSanitize($value) {
    if (is_string($value) && isset($value[0]) && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }
    return $value;
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    $type = $_GET['type'] ?? 'orders';
    $format = $_GET['format'] ?? 'json';
    $date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $date_to = $_GET['date_to'] ?? date('Y-m-d');

    $data = [];

    switch ($type) {
        case 'orders':
            $stmt = $db->prepare("
                SELECT o.order_id, o.status, o.total, o.delivery_fee, o.subtotal, o.created_at,
                       c.firstname as customer, p.name as partner, s.name as shopper
                FROM om_market_orders o
                LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
                LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
                WHERE DATE(o.created_at) BETWEEN ? AND ?
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $data = $stmt->fetchAll();
            break;

        case 'customers':
            $stmt = $db->prepare("
                SELECT c.customer_id, c.firstname, c.lastname, c.email, c.telephone,
                       COUNT(o.order_id) as total_orders,
                       COALESCE(SUM(o.total), 0) as total_spent
                FROM oc_customer c
                LEFT JOIN om_market_orders o ON c.customer_id = o.customer_id
                    AND DATE(o.created_at) BETWEEN ? AND ?
                GROUP BY c.customer_id
                ORDER BY total_spent DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $data = $stmt->fetchAll();
            break;

        case 'shoppers':
            $stmt = $db->query("
                SELECT shopper_id, name, email, phone, status, rating, is_online, saldo, created_at
                FROM om_market_shoppers
                ORDER BY name ASC
            ");
            $data = $stmt->fetchAll();
            break;

        case 'financial':
            $stmt = $db->prepare("
                SELECT s.id, s.order_id, s.partner_id, p.name as partner_name,
                       s.amount, s.commission, s.net_amount, s.status, s.created_at
                FROM om_market_sales s
                INNER JOIN om_market_partners p ON s.partner_id = p.partner_id
                WHERE DATE(s.created_at) BETWEEN ? AND ?
                ORDER BY s.created_at DESC
            ");
            $stmt->execute([$date_from, $date_to]);
            $data = $stmt->fetchAll();
            break;

        default:
            response(false, null, "Tipo invalido. Use: orders, customers, shoppers, financial", 400);
    }

    om_audit()->log('export', $type, null, null, [
        'format' => $format,
        'records' => count($data)
    ]);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        // SECURITY: Sanitize date values to prevent CRLF header injection
        $safeDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ? $date_from : 'unknown';
        $safeDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) ? $date_to : 'unknown';
        header("Content-Disposition: attachment; filename={$type}_{$safeDateFrom}_{$safeDateTo}.csv");

        if (!empty($data)) {
            $output = fopen('php://output', 'w');
            // BOM for Excel UTF-8
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) {
                fputcsv($output, array_map('csvSanitize', $row));
            }
            fclose($output);
        }
        exit;
    }

    response(true, [
        'type' => $type,
        'records' => count($data),
        'data' => $data
    ], "Dados exportados");
} catch (Exception $e) {
    error_log("[admin/exportar] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
