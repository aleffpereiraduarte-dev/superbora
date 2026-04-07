<?php
/**
 * GET/POST /api/mercado/customer/consent.php
 * LGPD - Granular consent management for the authenticated customer.
 *
 * GET  -> Returns current consent for all categories.
 * POST -> Update consent for one or more categories.
 *         Body: { "consents": { "analytics": true, "marketing_email": false, ... } }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

const CONSENT_CATEGORIES = [
    'analytics',
    'marketing_email',
    'marketing_push',
    'marketing_whatsapp',
    'data_sharing_partners',
    'personalization',
];

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $customerId = 0;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && ($payload['type'] ?? '') === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }
    if (!$customerId) {
        response(false, null, 'Nao autorizado', 401);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $stmt = $db->prepare(
            "SELECT consent_type, granted, granted_at, revoked_at
             FROM om_customer_consent
             WHERE customer_id = :cid
             ORDER BY granted_at DESC"
        );
        $stmt->execute([':cid' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Latest decision per category
        $current = [];
        foreach ($rows as $r) {
            $type = $r['consent_type'];
            if (!isset($current[$type])) {
                $current[$type] = [
                    'granted' => $r['revoked_at'] === null && (bool)$r['granted'],
                    'updated_at' => $r['revoked_at'] ?? $r['granted_at'],
                ];
            }
        }

        // Fill defaults (all FALSE for marketing, TRUE for analytics/personalization)
        $defaults = [
            'analytics' => true,
            'marketing_email' => false,
            'marketing_push' => false,
            'marketing_whatsapp' => false,
            'data_sharing_partners' => false,
            'personalization' => true,
        ];
        $result = [];
        foreach (CONSENT_CATEGORIES as $cat) {
            $result[$cat] = $current[$cat]['granted'] ?? $defaults[$cat];
        }

        response(true, [
            'consents' => $result,
            'history_count' => count($rows),
        ]);
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $consents = $input['consents'] ?? null;
        if (!is_array($consents)) {
            response(false, null, 'Parametro consents obrigatorio', 400);
        }

        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        $now = date('Y-m-d H:i:s');

        $db->beginTransaction();
        try {
            $insStmt = $db->prepare(
                "INSERT INTO om_customer_consent
                 (customer_id, consent_type, granted, granted_at, revoked_at, source, ip_address, created_at)
                 VALUES (:cid, :type, :granted, :gat, :rat, 'app', :ip, NOW())"
            );

            foreach ($consents as $type => $value) {
                if (!in_array($type, CONSENT_CATEGORIES, true)) {
                    continue;
                }
                $granted = (bool)$value;
                $insStmt->execute([
                    ':cid' => $customerId,
                    ':type' => $type,
                    ':granted' => $granted,
                    ':gat' => $granted ? $now : null,
                    ':rat' => $granted ? null : $now,
                    ':ip' => $ip,
                ]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        response(true, ['updated' => true]);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[consent] ' . $e->getMessage());
    response(false, null, 'Erro ao processar consentimento', 500);
}
