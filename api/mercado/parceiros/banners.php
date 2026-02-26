<?php
/**
 * GET /api/mercado/parceiros/banners.php
 * Returns active promotional banners and available coupons for the storefront
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

// Reduced cache TTL from 300 to 60 for fresher coupon data
header('Cache-Control: public, max-age=60');

try {
    $db = getDB();

    $cacheKey = "vitrine_banners_coupons";

    $data = CacheHelper::remember($cacheKey, 60, function() use ($db) {
        // Active banners
        $stmt = $db->prepare("
            SELECT banner_id, title, subtitle, icon, image, bg_color, text_color,
                   link, partner_id, start_date, end_date
            FROM om_market_banners
            WHERE status = '1'
              AND (start_date IS NULL OR start_date <= NOW())
              AND (end_date IS NULL OR end_date >= NOW())
            ORDER BY sort_order ASC, banner_id DESC
            LIMIT 10
        ");
        $stmt->execute();
        $banners = [];
        foreach ($stmt->fetchAll() as $b) {
            $banners[] = [
                "id" => (int)$b["banner_id"],
                "titulo" => $b["title"],
                "subtitulo" => $b["subtitle"],
                "icone" => $b["icon"],
                "imagem" => $b["image"],
                "bg_color" => $b["bg_color"] ?: "#059669",
                "text_color" => $b["text_color"] ?: "#ffffff",
                "link" => $b["link"],
                "partner_id" => $b["partner_id"] ? (int)$b["partner_id"] : null
            ];
        }

        // Active coupons (public, not first-order-only)
        $stmt = $db->prepare("
            SELECT id, code, name, description, discount_type, discount_value,
                   max_discount, min_order_value, valid_until
            FROM om_market_coupons
            WHERE status = 'active'
              AND (valid_from IS NULL OR valid_from <= NOW())
              AND (valid_until IS NULL OR valid_until >= NOW())
              AND first_order_only = 0
              AND (max_uses IS NULL OR current_uses < max_uses)
            ORDER BY discount_value DESC
            LIMIT 6
        ");
        $stmt->execute();
        $cupons = [];
        foreach ($stmt->fetchAll() as $c) {
            $cupons[] = [
                "id" => (int)$c["id"],
                "codigo" => $c["code"],
                "nome" => $c["name"],
                "descricao" => $c["description"],
                "tipo" => $c["discount_type"],
                "valor" => (float)$c["discount_value"],
                "desconto_maximo" => $c["max_discount"] ? (float)$c["max_discount"] : null,
                "pedido_minimo" => (float)($c["min_order_value"] ?? 0),
                "validade" => $c["valid_until"]
            ];
        }

        return [
            "banners" => $banners,
            "cupons" => $cupons
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Banners] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar banners", 500);
}
