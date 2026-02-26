<?php
/**
 * CRON MASTER - OneMundo Mercado
 * Sistema centralizado de automacao do marketplace
 *
 * Instalar no crontab:
 * * * * * * /usr/bin/php /var/www/html/mercado/cron/cron_master.php >> /var/log/mercado-cron.log 2>&1
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/cron_errors.log');

// Garantir que logs existe
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Config centralizado
require_once dirname(__DIR__) . '/config/database.php';

$pdo = getPDO();
$now = new DateTime();
$minute = (int)$now->format('i');
$hour = (int)$now->format('H');

function logCron($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
}

logCron("=== CRON MASTER INICIADO ===");

// A CADA MINUTO - Verificar pedidos aguardando shopper
try {
    $stmt = $pdo->query("
        SELECT order_id, partner_id, customer_id, total,
               EXTRACT(EPOCH FROM (NOW() - created_at))/60 as minutos_aguardando
        FROM om_market_orders
        WHERE status IN ('paid', 'pago')
        AND shopper_id IS NULL
        AND created_at > NOW() - INTERVAL '2 hours'
        ORDER BY created_at ASC
    ");
    $pedidos_aguardando = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pedidos_aguardando as $pedido) {
        if ($pedido['minutos_aguardando'] >= 5) {
            $stmt = $pdo->prepare("
                SELECT shopper_id, name FROM om_market_shoppers
                WHERE is_online = 1 AND status = 'active'
                AND shopper_id NOT IN (
                    SELECT shopper_id FROM om_market_orders
                    WHERE status IN ('shopping', 'em_compra') AND shopper_id IS NOT NULL
                )
                ORDER BY rating DESC, last_order_at ASC LIMIT 1
            ");
            $stmt->execute();
            $shopper = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($shopper) {
                $stmt = $pdo->prepare("UPDATE om_market_orders SET shopper_id = ?, status = 'accepted', shopper_name = ? WHERE order_id = ?");
                $stmt->execute([$shopper['shopper_id'], $shopper['name'], $pedido['order_id']]);
                logCron("Auto-dispatch: Pedido #{$pedido['order_id']} -> Shopper {$shopper['name']}");
            }
        }
    }
    logCron("Verificados " . count($pedidos_aguardando) . " pedidos aguardando");
} catch (Exception $e) {
    logCron("ERRO dispatch: " . $e->getMessage());
}

// Verificar pedidos prontos aguardando motorista (BoraUm)
try {
    $stmt = $pdo->query("
        SELECT order_id, boraum_pedido_id,
               EXTRACT(EPOCH FROM (NOW() - delivery_dispatched_at))/60 as minutos_dispatch
        FROM om_market_orders
        WHERE status = 'awaiting_delivery'
        AND boraum_pedido_id IS NOT NULL
        AND delivery_id IS NULL
    ");
    $pedidos_boraum = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pedidos_boraum as $pedido) {
        $ch = curl_init("http://localhost/mercado/api/dispatch_boraum.php?action=status&order_id=" . $pedido['order_id']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($data && $data['success'] && $data['status'] === 'aceito') {
            logCron("Motorista aceito para pedido #{$pedido['order_id']}");
        }

        if ($pedido['minutos_dispatch'] >= 10) {
            $ch = curl_init("http://localhost/mercado/api/dispatch_boraum.php?action=retry");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['order_id' => $pedido['order_id']]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10
            ]);
            curl_exec($ch);
            curl_close($ch);
            logCron("Retry dispatch para pedido #{$pedido['order_id']}");
        }
    }
} catch (Exception $e) {
    logCron("ERRO boraum: " . $e->getMessage());
}

// A CADA 5 MINUTOS
if ($minute % 5 === 0) {
    // Monitor de pedidos atrasados
    try {
        $stmt = $pdo->query("
            SELECT order_id, status, customer_id, partner_id,
                   EXTRACT(EPOCH FROM (NOW() - COALESCE(updated_at, created_at)))/60 as minutos
            FROM om_market_orders
            WHERE status IN ('paid', 'accepted', 'shopping', 'em_compra')
            AND COALESCE(updated_at, created_at) < NOW() - INTERVAL '30 minutes'
        ");
        $atrasados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($atrasados as $pedido) {
            $severity = $pedido['minutos'] > 60 ? 'critical' : 'warning';
            $stmt = $pdo->prepare("SELECT id FROM om_market_ai_alerts WHERE order_id = ? AND alert_type = 'delivery_delay' AND status = 'new' AND created_at > NOW() - INTERVAL '1 hour'");
            $stmt->execute([$pedido['order_id']]);

            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO om_market_ai_alerts (alert_type, severity, order_id, partner_id, title, description, ai_suggestion, created_at) VALUES ('delivery_delay', ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $severity, $pedido['order_id'], $pedido['partner_id'],
                    "Pedido #{$pedido['order_id']} atrasado ({$pedido['minutos']} min)",
                    "Pedido esta no status '{$pedido['status']}' ha {$pedido['minutos']} minutos",
                    $pedido['minutos'] > 60 ? "URGENTE: Contatar mercado e cliente" : "Verificar status"
                ]);
                logCron("Alerta criado: Pedido #{$pedido['order_id']} ({$pedido['minutos']} min)");
            }
        }
    } catch (Exception $e) {
        logCron("ERRO monitor: " . $e->getMessage());
    }

    // Atualizar workers offline
    try {
        $pdo->exec("UPDATE om_market_shoppers SET is_online = 0 WHERE is_online = 1 AND last_activity < NOW() - INTERVAL '15 minutes'");
        $pdo->exec("UPDATE om_market_deliveries SET is_online = 0 WHERE is_online = 1 AND last_activity < NOW() - INTERVAL '15 minutes'");
        logCron("Workers offline atualizados");
    } catch (Exception $e) {}
}

// A CADA HORA
if ($minute === 0) {
    try {
        $hoje = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as fat FROM om_market_orders WHERE DATE(created_at) = ? AND status NOT IN ('cancelado', 'cancelled')");
        $stmt->execute([$hoje]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        logCron("Stats hora: {$stats['total']} pedidos, R$ " . number_format($stats['fat'], 2));
    } catch (Exception $e) {}

    // Sincronizar precos AI com tabela de precos (usa precos calculados pela IA)
    try {
        $updated = $pdo->exec("
            UPDATE om_market_products_price
            SET ai_price = ps.sale_price,
                price_calculated_by = 'AI',
                price_updated_at = NOW()
            FROM om_market_products_sale ps
            WHERE om_market_products_price.product_id = ps.product_id
              AND om_market_products_price.partner_id = ps.partner_id
              AND ps.status = '1' AND ps.sale_price > 0
        ");
        logCron("Sync precos AI: $updated produtos atualizados");
    } catch (Exception $e) {
        logCron("ERRO sync precos: " . $e->getMessage());
    }
}

// DIARIAMENTE AS 3h - Executar precificacao AI
if ($hour === 3 && $minute === 0) {
    logCron("Iniciando precificacao AI...");
    try {
        require_once dirname(__DIR__) . '/ia/PrecificacaoInteligente.php';
        $ia = new PrecificacaoInteligente();

        $stmt = $pdo->query("SELECT partner_id, name FROM om_market_partners WHERE status = '1'");
        $mercados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totais = ['produtos' => 0, 'atualizados' => 0, 'lucro' => 0];

        foreach ($mercados as $m) {
            $resultado = $ia->processarMercado($m['partner_id']);
            $totais['produtos'] += $resultado['total_produtos'];
            $totais['atualizados'] += $resultado['atualizados'];
            $totais['lucro'] += $resultado['lucro_total_estimado'];
            logCron("  Mercado {$m['name']}: {$resultado['atualizados']} produtos, margem {$resultado['margem_media']}%");
        }

        logCron("Precificacao AI finalizada: {$totais['atualizados']} produtos, lucro estimado R$ " . number_format($totais['lucro'], 2));
    } catch (Exception $e) {
        logCron("ERRO precificacao: " . $e->getMessage());
    }
}

logCron("=== CRON MASTER FINALIZADO ===\n");
