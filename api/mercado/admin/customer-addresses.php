<?php
/**
 * GET /api/mercado/admin/customer-addresses.php
 *
 * Lista enderecos de um cliente para o painel administrativo.
 *
 * Query: ?customer_id=123
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') response(false, null, "Metodo nao permitido", 405);

    $customer_id = (int)($_GET['customer_id'] ?? 0);
    if (!$customer_id) response(false, null, "customer_id obrigatorio", 400);

    // Verify customer exists
    $stmt = $db->prepare("SELECT customer_id, name FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    if (!$customer) response(false, null, "Cliente nao encontrado", 404);

    // Fetch addresses (active and inactive for admin visibility)
    $addresses = [];
    $source = 'om_customer_addresses';

    try {
        $stmt = $db->prepare("
            SELECT address_id, label, street, number, complement, neighborhood,
                   city, state, zipcode, lat, lng, reference, is_default, is_active
            FROM om_customer_addresses
            WHERE customer_id = ?
            ORDER BY is_default DESC, is_active DESC, address_id DESC
        ");
        $stmt->execute([$customer_id]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $a) {
            $addresses[] = [
                'id' => (int)$a['address_id'],
                'label' => $a['label'],
                'street' => $a['street'],
                'number' => $a['number'],
                'complement' => $a['complement'],
                'neighborhood' => $a['neighborhood'],
                'city' => $a['city'],
                'state' => $a['state'],
                'zip' => $a['zipcode'],
                'lat' => $a['lat'] ? (float)$a['lat'] : null,
                'lng' => $a['lng'] ? (float)$a['lng'] : null,
                'reference' => $a['reference'],
                'is_default' => (bool)$a['is_default'],
                'is_active' => (bool)($a['is_active'] ?? true),
            ];
        }
    } catch (Exception $e) {
        // Table may not exist, try legacy oc_address table
        try {
            $source = 'oc_address';
            $stmt = $db->prepare("
                SELECT address_id, address_1, address_2, city, postcode,
                       zone_id, country_id
                FROM oc_address
                WHERE customer_id = ?
                ORDER BY address_id DESC
            ");
            $stmt->execute([$customer_id]);
            $rows = $stmt->fetchAll();

            foreach ($rows as $a) {
                $addresses[] = [
                    'id' => (int)$a['address_id'],
                    'label' => null,
                    'street' => $a['address_1'] ?? '',
                    'number' => '',
                    'complement' => $a['address_2'] ?? '',
                    'neighborhood' => '',
                    'city' => $a['city'] ?? '',
                    'state' => '',
                    'zip' => $a['postcode'] ?? '',
                    'lat' => null,
                    'lng' => null,
                    'reference' => null,
                    'is_default' => false,
                    'is_active' => true,
                ];
            }
        } catch (Exception $e2) {
            // Neither table exists
            $source = 'none';
        }
    }

    response(true, [
        'customer_id' => $customer_id,
        'customer_name' => $customer['name'],
        'addresses' => $addresses,
        'total' => count($addresses),
        'source' => $source,
    ], count($addresses) > 0 ? "Enderecos do cliente listados" : "Nenhum endereco encontrado");

} catch (Exception $e) {
    error_log("[admin/customer-addresses] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
