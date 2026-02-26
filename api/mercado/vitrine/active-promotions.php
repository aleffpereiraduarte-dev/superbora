<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/vitrine/active-promotions.php
 *
 * Retorna promocoes ativas AGORA para um parceiro
 * Verifica: horario atual, dia da semana, validade, limites
 *
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Params:
 *   - partner_id (required): ID do parceiro
 *   - product_ids (optional): IDs dos produtos para filtrar promocoes aplicaveis
 *
 * Response: {
 *   "success": true,
 *   "data": {
 *     "promotions": [...],
 *     "product_discounts": { product_id: {...discount_info} },
 *     "happy_hour_active": bool,
 *     "happy_hour_ends_at": "HH:MM" or null
 *   }
 * }
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    if (!$partnerId) {
        response(false, null, "partner_id e obrigatorio", 400);
    }

    // IDs de produtos opcionais para filtrar
    $productIdsParam = $_GET['product_ids'] ?? '';
    $productIds = [];
    if ($productIdsParam) {
        $productIds = array_filter(array_map('intval', explode(',', $productIdsParam)));
    }

    // ═══════════════════════════════════════════════════════════════════
    // DETERMINAR CONTEXTO TEMPORAL
    // ═══════════════════════════════════════════════════════════════════
    $spTz = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTime('now', $spTz);
    $currentDate = $now->format('Y-m-d');
    $currentTime = $now->format('H:i:s');
    $dayOfWeek = ((int)$now->format('w') + 1); // 1=Dom, 2=Seg, ..., 7=Sab

    // ═══════════════════════════════════════════════════════════════════
    // BUSCAR TODAS PROMOCOES POTENCIALMENTE ATIVAS
    // ═══════════════════════════════════════════════════════════════════
    $allPromotions = [];
    try {
        $stmt = $db->prepare("
            SELECT id, type, name, description, badge_text, badge_color,
                   discount_percent, buy_quantity, get_quantity, get_discount_percent,
                   min_quantity, quantity_discount_percent, applies_to, product_ids,
                   category_ids, days_of_week, start_time, end_time, valid_from,
                   valid_until, max_uses, current_uses, priority, status
            FROM om_promotions_advanced
            WHERE partner_id = ?
              AND status = '1'
              AND (valid_from IS NULL OR valid_from <= ?)
              AND (valid_until IS NULL OR valid_until >= ?)
              AND (max_uses IS NULL OR current_uses < max_uses)
            ORDER BY priority DESC, type ASC
        ");
        $stmt->execute([$partnerId, $currentDate, $currentDate]);
        $allPromotions = $stmt->fetchAll();
    } catch (Exception $e) {
        // Table om_promotions_advanced may not exist yet — return empty promotions
        error_log("[vitrine/active-promotions] Tabela om_promotions_advanced nao encontrada: " . $e->getMessage());
    }

    // ═══════════════════════════════════════════════════════════════════
    // FILTRAR PROMOCOES REALMENTE ATIVAS AGORA
    // ═══════════════════════════════════════════════════════════════════
    $activePromotions = [];
    $happyHourActive = false;
    $happyHourEndsAt = null;
    $bogoActive = false;
    $quantityDiscountActive = false;

    foreach ($allPromotions as $promo) {
        $isActive = true;

        // Para Happy Hour, verificar horario e dia
        if ($promo['type'] === 'happy_hour') {
            // Verificar dia da semana
            $allowedDays = array_map('intval', explode(',', $promo['days_of_week'] ?? '1,2,3,4,5,6,7'));
            if (!in_array($dayOfWeek, $allowedDays)) {
                $isActive = false;
            }

            // Verificar horario
            if ($isActive && $promo['start_time'] && $promo['end_time']) {
                $startTime = $promo['start_time'];
                $endTime = $promo['end_time'];

                // Suporte para horarios que cruzam meia-noite
                if ($endTime < $startTime) {
                    // Ex: 22:00 - 02:00
                    if ($currentTime < $startTime && $currentTime >= $endTime) {
                        $isActive = false;
                    }
                } else {
                    // Horario normal
                    if ($currentTime < $startTime || $currentTime >= $endTime) {
                        $isActive = false;
                    }
                }

                if ($isActive) {
                    $happyHourActive = true;
                    // Calcular quando termina
                    $happyHourEndsAt = substr($endTime, 0, 5);
                }
            }
        }

        if ($isActive) {
            $formattedPromo = [
                "id" => (int)$promo['id'],
                "type" => $promo['type'],
                "name" => $promo['name'],
                "description" => $promo['description'],
                "badge_text" => $promo['badge_text'],
                "badge_color" => $promo['badge_color'],
                "discount_percent" => (float)$promo['discount_percent'],
                "buy_quantity" => (int)$promo['buy_quantity'],
                "get_quantity" => (int)$promo['get_quantity'],
                "get_discount_percent" => (float)$promo['get_discount_percent'],
                "min_quantity" => (int)$promo['min_quantity'],
                "quantity_discount_percent" => (float)$promo['quantity_discount_percent'],
                "applies_to" => $promo['applies_to'],
                "product_ids" => $promo['product_ids'] ? json_decode($promo['product_ids'], true) : [],
                "category_ids" => $promo['category_ids'] ? json_decode($promo['category_ids'], true) : [],
                "valid_until" => $promo['valid_until'],
            ];

            // Adicionar info de countdown para happy hour
            if ($promo['type'] === 'happy_hour' && $happyHourEndsAt) {
                $endDateTime = new DateTime($currentDate . ' ' . $promo['end_time'], $spTz);
                if ($endDateTime < $now) {
                    // Se cruzou meia-noite, adicionar 1 dia
                    $endDateTime->modify('+1 day');
                }
                $diff = $now->diff($endDateTime);
                $formattedPromo['ends_in_minutes'] = ($diff->h * 60) + $diff->i;
                $formattedPromo['ends_at'] = $happyHourEndsAt;
            }

            $activePromotions[] = $formattedPromo;

            // Flags
            if ($promo['type'] === 'bogo') $bogoActive = true;
            if ($promo['type'] === 'quantity_discount') $quantityDiscountActive = true;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // CALCULAR DESCONTOS POR PRODUTO (se product_ids fornecidos)
    // ═══════════════════════════════════════════════════════════════════
    $productDiscounts = [];

    if (!empty($productIds)) {
        // Buscar categorias dos produtos
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $db->prepare("SELECT product_id, category_id FROM om_market_products WHERE product_id IN ($ph)");
        $stmt->execute($productIds);
        $productCategories = [];
        foreach ($stmt->fetchAll() as $p) {
            $productCategories[(int)$p['product_id']] = (int)$p['category_id'];
        }

        foreach ($productIds as $productId) {
            $productCategory = $productCategories[$productId] ?? 0;
            $applicablePromotions = [];

            foreach ($activePromotions as $promo) {
                $applies = false;

                // Verificar se a promocao se aplica a este produto
                if ($promo['applies_to'] === 'all') {
                    $applies = true;
                } elseif ($promo['applies_to'] === 'products' && !empty($promo['product_ids'])) {
                    $applies = in_array($productId, $promo['product_ids']);
                } elseif ($promo['applies_to'] === 'category' && !empty($promo['category_ids'])) {
                    $applies = in_array($productCategory, $promo['category_ids']);
                }

                if ($applies) {
                    $applicablePromotions[] = [
                        'type' => $promo['type'],
                        'badge_text' => $promo['badge_text'],
                        'badge_color' => $promo['badge_color'],
                        'discount_percent' => $promo['discount_percent'],
                        'buy_quantity' => $promo['buy_quantity'],
                        'get_quantity' => $promo['get_quantity'],
                        'get_discount_percent' => $promo['get_discount_percent'],
                        'min_quantity' => $promo['min_quantity'],
                        'quantity_discount_percent' => $promo['quantity_discount_percent'],
                        'ends_in_minutes' => $promo['ends_in_minutes'] ?? null,
                        'ends_at' => $promo['ends_at'] ?? null,
                    ];
                }
            }

            if (!empty($applicablePromotions)) {
                $productDiscounts[$productId] = $applicablePromotions;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // RESPOSTA
    // ═══════════════════════════════════════════════════════════════════
    response(true, [
        "promotions" => $activePromotions,
        "product_discounts" => $productDiscounts,
        "summary" => [
            "happy_hour_active" => $happyHourActive,
            "happy_hour_ends_at" => $happyHourEndsAt,
            "bogo_active" => $bogoActive,
            "quantity_discount_active" => $quantityDiscountActive,
            "total_active" => count($activePromotions)
        ],
        "context" => [
            "current_time" => $currentTime,
            "day_of_week" => $dayOfWeek,
            "day_name" => getDayName($dayOfWeek)
        ]
    ]);

} catch (Exception $e) {
    error_log("[vitrine/active-promotions] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar promocoes", 500);
}

/**
 * Retorna nome do dia da semana
 */
function getDayName(int $dayOfWeek): string
{
    $days = [
        1 => 'Domingo',
        2 => 'Segunda',
        3 => 'Terca',
        4 => 'Quarta',
        5 => 'Quinta',
        6 => 'Sexta',
        7 => 'Sabado'
    ];
    return $days[$dayOfWeek] ?? '';
}
