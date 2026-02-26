<?php
/**
 * GET/POST /api/mercado/partner/profile.php
 * GET  - Retorna perfil completo do parceiro
 * POST - Atualiza campos parciais (whitelist)
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $stmt = $db->prepare("
            SELECT partner_id, name, trade_name, description, descricao,
                   phone, telefone, whatsapp, email, logo, banner,
                   opens_at, closes_at, open_sunday, sunday_opens_at, sunday_closes_at,
                   delivery_fee, taxa_entrega, free_delivery_above, free_delivery_min,
                   min_order_value, min_order, delivery_radius_km, delivery_radius, raio_entrega_km,
                   tempo_preparo, delivery_time_min, delivery_time_max,
                   accepts_pix, accepts_card, is_open, categoria,
                   address, city, state, cep, auto_accept, weekly_hours,
                   aceita_retirada, entrega_propria, aceita_boraum
            FROM om_market_partners
            WHERE partner_id = ?
        ");
        $stmt->execute([$partnerId]);
        $p = $stmt->fetch();

        if (!$p) response(false, null, "Parceiro nao encontrado", 404);

        response(true, [
            "id" => (int)$p['partner_id'],
            "name" => $p['name'],
            "trade_name" => $p['trade_name'],
            "description" => $p['description'] ?: $p['descricao'],
            "phone" => $p['phone'] ?: $p['telefone'],
            "whatsapp" => $p['whatsapp'],
            "email" => $p['email'],
            "logo" => $p['logo'],
            "banner" => $p['banner'],
            "categoria" => $p['categoria'],
            "address" => $p['address'],
            "city" => $p['city'],
            "state" => $p['state'],
            "cep" => $p['cep'],
            "is_open" => (bool)$p['is_open'],
            "horarios" => [
                "opens_at" => $p['opens_at'],
                "closes_at" => $p['closes_at'],
                "open_sunday" => (bool)$p['open_sunday'],
                "sunday_opens_at" => $p['sunday_opens_at'],
                "sunday_closes_at" => $p['sunday_closes_at'],
                "weekly_hours" => $p['weekly_hours'] ? json_decode($p['weekly_hours'], true) : null,
            ],
            "entrega" => [
                "delivery_fee" => (float)($p['delivery_fee'] ?: $p['taxa_entrega']),
                "free_delivery_above" => (float)($p['free_delivery_above'] ?: $p['free_delivery_min']),
                "min_order_value" => (float)($p['min_order_value'] ?: $p['min_order']),
                "delivery_radius_km" => (float)($p['delivery_radius_km'] ?: $p['delivery_radius'] ?: $p['raio_entrega_km']),
                "tempo_preparo" => (int)($p['tempo_preparo'] ?: $p['delivery_time_min']),
                "delivery_time_max" => (int)$p['delivery_time_max'],
                "aceita_retirada" => (bool)($p['aceita_retirada'] ?? true),
                "entrega_propria" => (bool)($p['entrega_propria'] ?? false),
                "aceita_boraum" => (bool)($p['aceita_boraum'] ?? true),
            ],
            "pagamentos" => [
                "accepts_pix" => (bool)$p['accepts_pix'],
                "accepts_card" => (bool)$p['accepts_card'],
            ],
            "auto_accept" => (bool)($p['auto_accept'] ?? false),
        ]);
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();

        // Whitelist of allowed fields for update - prevents SQL injection via field names
        // Only fields in this list can be updated; column names are hardcoded, not from user input
        $allowed = [
            'trade_name' => 'trade_name',
            'description' => 'description',
            'phone' => 'phone',
            'whatsapp' => 'whatsapp',
            'opens_at' => 'opens_at',
            'closes_at' => 'closes_at',
            'open_sunday' => 'open_sunday',
            'sunday_opens_at' => 'sunday_opens_at',
            'sunday_closes_at' => 'sunday_closes_at',
            'delivery_fee' => 'delivery_fee',
            'free_delivery_above' => 'free_delivery_above',
            'min_order_value' => 'min_order_value',
            'delivery_radius_km' => 'delivery_radius_km',
            'tempo_preparo' => 'tempo_preparo',
            'accepts_pix' => 'accepts_pix',
            'accepts_card' => 'accepts_card',
            'auto_accept' => 'auto_accept',
            'weekly_hours' => 'weekly_hours',
            'aceita_retirada' => 'aceita_retirada',
            'entrega_propria' => 'entrega_propria',
            'aceita_boraum' => 'aceita_boraum',
        ];

        $setClauses = [];
        $params = [];

        foreach ($allowed as $inputKey => $dbCol) {
            if (array_key_exists($inputKey, $input)) {
                // Safe: $dbCol comes from hardcoded whitelist above, not user input
                $setClauses[] = "$dbCol = ?";
                $value = $input[$inputKey];
                // Sanitize text fields to prevent stored XSS
                if (in_array($inputKey, ['trade_name', 'description']) && is_string($value)) {
                    $value = strip_tags(trim($value));
                }
                // Validate delivery radius bounds (0.5 - 50 km)
                if ($inputKey === 'delivery_radius_km') {
                    $value = max(0.5, min(50, (float)$value));
                }
                // Validate tempo_preparo bounds (5 - 180 min)
                if ($inputKey === 'tempo_preparo') {
                    $value = max(5, min(180, (int)$value));
                }
                // weekly_hours: encode array to JSON string
                if ($inputKey === 'weekly_hours' && is_array($value)) {
                    $value = json_encode($value);
                }
                $params[] = $value;
            }
        }

        if (empty($setClauses)) {
            response(false, null, "Nenhum campo valido para atualizar", 400);
        }

        $setClauses[] = "updated_at = NOW()";
        $params[] = $partnerId;

        // Using prepared statement: column names from whitelist, values bound as parameters
        $sql = "UPDATE om_market_partners SET " . implode(', ', $setClauses) . " WHERE partner_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        response(true, ["updated" => true, "fields" => array_keys(array_intersect_key($input, $allowed))]);
    }

} catch (Exception $e) {
    error_log("[partner/profile] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar perfil", 500);
}
