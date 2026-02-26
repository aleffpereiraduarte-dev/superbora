<?php
require_once __DIR__ . '/config/database.php';
/**
 * CRON MATCHING SIMPLIFICADO
 * OneMundo Mercado
 * Cria ofertas para shoppers de pedidos pagos
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(60);

$isWeb = php_sapi_name() !== 'cli';

if ($isWeb) {
    echo "<html><head><meta charset='UTF-8'><title>Matching</title>";
    echo "<style>body{font-family:monospace;background:#1a1a2e;color:#0f0;padding:20px;} .ok{color:#0f0;} .erro{color:#f66;} .aviso{color:#ff0;}</style>";
    echo "</head><body><pre>";
}

function output($msg) {
    echo $msg . "\n";
    if (ob_get_level()) ob_flush();
    flush();
}

output("====================================");
output("  MATCHING SIMPLIFICADO");
output("  " . date('d/m/Y H:i:s'));
output("====================================\n");

// Conexao
$conn = getMySQLi();
if ($conn->connect_error) {
    output("ERRO: " . $conn->connect_error);
    exit;
}
$conn->set_charset('utf8mb4');

output("[OK] Conexao estabelecida\n");

// Configuracoes
$TIMEOUT_OFERTA = 90; // segundos para aceitar
$MAX_OFERTAS_POR_PEDIDO = 3; // shoppers por pedido
$MAX_PEDIDOS = 20; // processar por execucao

// =============================================
// 1. EXPIRAR OFERTAS ANTIGAS
// =============================================
output("1. Expirando ofertas antigas...");
$conn->query("UPDATE om_shopper_offers SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()");
$expiradas = $conn->affected_rows;
output("   $expiradas ofertas expiradas\n");

// =============================================
// 2. BUSCAR PEDIDOS PENDENTES
// =============================================
output("2. Buscando pedidos pendentes...");

$sql = "SELECT o.order_id, o.partner_id, o.total, o.customer_lat, o.customer_lng,
               p.name as partner_name, p.latitude as partner_lat, p.longitude as partner_lng
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.payment_status = 'paid' 
        AND (o.shopper_id IS NULL OR o.shopper_id = 0)
        AND o.status NOT IN ('cancelled', 'delivered', 'completed')
        ORDER BY o.created_at ASC
        LIMIT $MAX_PEDIDOS";

$pedidos = $conn->query($sql);
$totalPedidos = $pedidos->num_rows;
output("   $totalPedidos pedidos encontrados\n");

if ($totalPedidos == 0) {
    output("\n[OK] Nenhum pedido pendente!");
    if ($isWeb) echo "</pre></body></html>";
    exit;
}

// =============================================
// 3. BUSCAR SHOPPERS ONLINE
// =============================================
output("3. Buscando shoppers online...");

$shoppers = $conn->query("
    SELECT shopper_id, name, current_lat, current_lng, rating
    FROM om_market_shoppers 
    WHERE status = 'online' 
    AND is_available = 1
    ORDER BY rating DESC, shopper_id ASC
");

$totalShoppers = $shoppers->num_rows;
output("   $totalShoppers shoppers disponiveis\n");

if ($totalShoppers == 0) {
    output("\n[AVISO] Nenhum shopper online!");
    if ($isWeb) echo "</pre></body></html>";
    exit;
}

// Carregar shoppers em array
$listaShoppers = array();
while ($s = $shoppers->fetch_assoc()) {
    $listaShoppers[] = $s;
}

// =============================================
// 4. CRIAR OFERTAS
// =============================================
output("4. Criando ofertas...\n");

$ofertasCriadas = 0;
$pedidosProcessados = 0;

while ($pedido = $pedidos->fetch_assoc()) {
    $orderId = intval($pedido['order_id']);
    $partnerId = intval($pedido['partner_id']);
    $total = floatval($pedido['total']);
    $earning = round($total * 0.10, 2); // 10% para shopper
    
    output("   Pedido #$orderId - R$ $total");
    
    // Verificar se ja tem ofertas pendentes
    $existentes = $conn->query("SELECT COUNT(*) as t FROM om_shopper_offers WHERE order_id = $orderId AND status = 'pending'");
    $row = $existentes->fetch_assoc();
    
    if ($row['t'] >= $MAX_OFERTAS_POR_PEDIDO) {
        output("      -> Ja tem ofertas pendentes, pulando...");
        continue;
    }
    
    // Criar ofertas para shoppers
    $ofertasPedido = 0;
    
    foreach ($listaShoppers as $shopper) {
        if ($ofertasPedido >= $MAX_OFERTAS_POR_PEDIDO) break;
        
        $shopperId = intval($shopper['shopper_id']);
        
        // Verificar se ja existe oferta deste shopper para este pedido
        $jaExiste = $conn->query("SELECT id FROM om_shopper_offers WHERE order_id = $orderId AND worker_id = $shopperId AND status IN ('pending', 'accepted')");
        if ($jaExiste->num_rows > 0) {
            continue;
        }
        
        // Verificar se shopper ja tem pedido ativo
        $pedidoAtivo = $conn->query("SELECT order_id FROM om_market_orders WHERE shopper_id = $shopperId AND status IN ('assigned', 'shopping', 'ready')");
        if ($pedidoAtivo->num_rows > 0) {
            continue;
        }
        
        // Criar oferta
        $expires = date('Y-m-d H:i:s', strtotime("+$TIMEOUT_OFERTA seconds"));
        
        $insert = "INSERT INTO om_shopper_offers 
                   (order_id, worker_id, partner_id, order_total, shopper_earning, status, current_wave, wave_started_at, created_at, expires_at) 
                   VALUES 
                   ($orderId, $shopperId, $partnerId, $total, $earning, 'pending', 1, NOW(), NOW(), '$expires')";
        
        if ($conn->query($insert)) {
            output("      -> Oferta criada para " . $shopper['name']);
            $ofertasCriadas++;
            $ofertasPedido++;
        } else {
            output("      -> ERRO: " . $conn->error);
        }
    }
    
    $pedidosProcessados++;
}

// =============================================
// 5. RESUMO
// =============================================
output("\n====================================");
output("  RESUMO");
output("====================================");
output("  Pedidos processados: $pedidosProcessados");
output("  Ofertas criadas: $ofertasCriadas");
output("  Shoppers disponiveis: $totalShoppers");
output("====================================\n");

// Mostrar ofertas pendentes
output("Ofertas pendentes agora:");
$pendentes = $conn->query("
    SELECT o.id, o.order_id, o.worker_id, o.order_total, o.expires_at, s.name
    FROM om_shopper_offers o
    LEFT JOIN om_market_shoppers s ON o.worker_id = s.shopper_id
    WHERE o.status = 'pending'
    ORDER BY o.created_at DESC
    LIMIT 10
");

if ($pendentes->num_rows > 0) {
    while ($row = $pendentes->fetch_assoc()) {
        output("  #" . $row['order_id'] . " -> " . $row['name'] . " (expira: " . $row['expires_at'] . ")");
    }
} else {
    output("  Nenhuma oferta pendente");
}

$conn->close();

if ($isWeb) {
    echo "</pre>";
    echo "<p style='margin-top:20px;'>";
    echo "<a href='?' style='padding:10px 20px; background:#00d4aa; color:#000; text-decoration:none; border-radius:5px; margin:5px;'>Rodar Novamente</a>";
    echo "<a href='/mercado/diagnostico_crons_pagarme.php' style='padding:10px 20px; background:#06c; color:#fff; text-decoration:none; border-radius:5px; margin:5px;'>Diagnostico</a>";
    echo "<a href='/mercado/shopper/' style='padding:10px 20px; background:#f60; color:#fff; text-decoration:none; border-radius:5px; margin:5px;'>App Shopper</a>";
    echo "</p>";
    echo "</body></html>";
}
