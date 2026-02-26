<?php
/**
 * GET /api/mercado/boraum/config.php
 *
 * Retorna configuracoes dinamicas do marketplace para o app BoraUm.
 * Nao requer autenticacao (dados publicos).
 *
 * Resposta:
 *   { service_fee, min_order_default, delivery_fee_base, app_version_min, ... }
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        response(false, null, "Metodo nao permitido", 405);
    }

    // Tentar carregar do banco (tabela de config, se existir)
    $config = [
        'service_fee'       => 2.49,
        'min_order_default' => 15.00,
        'delivery_fee_base' => 5.99,
        'free_delivery_min' => 80.00,
        'max_delivery_km'   => 15,
        'pix_enabled'       => true,
        'card_enabled'      => true,
        'saldo_enabled'     => true,
        'cash_enabled'      => true,
    ];

    $db = getDB();

    // Tentar ler de om_market_settings (se a tabela existir)
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM om_market_settings WHERE setting_key IN (
            'service_fee','min_order_default','delivery_fee_base','free_delivery_min',
            'max_delivery_km','pix_enabled','card_enabled','saldo_enabled','cash_enabled'
        )");
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $key = $row['setting_key'];
            $val = $row['setting_value'];

            if (in_array($key, ['pix_enabled', 'card_enabled', 'saldo_enabled', 'cash_enabled'])) {
                $config[$key] = (bool)(int)$val;
            } elseif (in_array($key, ['max_delivery_km'])) {
                $config[$key] = (int)$val;
            } else {
                $config[$key] = (float)$val;
            }
        }
    } catch (PDOException $e) {
        // Tabela nao existe - usar defaults (nao e erro)
    }

    response(true, $config);

} catch (Exception $e) {
    error_log("[boraum/config] Erro: " . $e->getMessage());
    // Mesmo com erro, retornar defaults para nao quebrar o app
    response(true, [
        'service_fee' => 2.49,
    ]);
}
