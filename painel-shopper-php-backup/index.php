<?php
/**
 * Shopper Dashboard - Interface Moderna tipo DoorDash/Instacart
 * Real-time updates, animações suaves, mobile-first
 */

session_start();
require_once dirname(__DIR__, 2) . '/database.php';

// Verificar autenticação
if (!isset($_SESSION['shopper_id'])) {
    header('Location: /painel/shopper/login.php');
    exit;
}

$db = getDB();
$shopper_id = $_SESSION['shopper_id'];

// Buscar dados do shopper
$stmt = $db->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ?");
$stmt->execute([$shopper_id]);
$shopper = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shopper) {
    session_destroy();
    header('Location: /painel/shopper/login.php');
    exit;
}

// API Endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'toggle_online':
                $online = intval($_POST['online']);
                $db->prepare("UPDATE om_market_shoppers SET online = ?, is_online = ?, disponivel = ?, is_available = ?, availability = ? WHERE shopper_id = ?")->execute([
                    $online, $online, $online, $online,
                    $online ? 'available' : 'offline',
                    $shopper_id
                ]);
                echo json_encode(['success' => true, 'online' => $online]);
                exit;

            case 'update_location':
                $lat = floatval($_POST['lat']);
                $lng = floatval($_POST['lng']);
                $db->prepare("UPDATE om_market_shoppers SET lat = ?, lng = ?, current_lat = ?, current_lng = ?, last_location_at = NOW(), ultima_localizacao = NOW() WHERE shopper_id = ?")->execute([$lat, $lng, $lat, $lng, $shopper_id]);
                echo json_encode(['success' => true]);
                exit;

            case 'get_dashboard':
                // Estatísticas do dia
                $stats = [
                    'ganhos_hoje' => floatval($shopper['total_earnings_today'] ?? 0),
                    'ganhos_semana' => floatval($shopper['total_earnings_week'] ?? 0),
                    'pedidos_hoje' => intval($shopper['orders_today'] ?? 0),
                    'total_pedidos' => intval($shopper['total_entregas'] ?? 0),
                    'rating' => floatval($shopper['rating'] ?? 5),
                    'nivel' => $shopper['nivel_nome'] ?? 'iniciante',
                    'saldo_disponivel' => floatval($shopper['saldo'] ?? 0),
                    'saldo_pendente' => floatval($shopper['saldo_pendente'] ?? 0),
                    'acceptance_rate' => floatval($shopper['acceptance_rate'] ?? 100),
                    'pontos' => intval($shopper['pontos'] ?? 0)
                ];

                // Pedidos disponíveis
                $stmt = $db->prepare("
                    SELECT o.*, p.name as partner_name, p.logo as partner_logo,
                           (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_items
                    FROM om_market_orders o
                    JOIN om_market_partners p ON o.partner_id = p.partner_id
                    WHERE o.status IN ('confirmado', 'pronto_coleta', 'aguardando_shopper')
                    AND o.shopper_id IS NULL
                    ORDER BY o.created_at DESC
                    LIMIT 10
                ");
                $stmt->execute();
                $pedidos_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Pedido ativo
                $stmt = $db->prepare("
                    SELECT o.*, p.name as partner_name, p.logo as partner_logo, p.address as partner_address,
                           p.latitude as partner_lat, p.longitude as partner_lng
                    FROM om_market_orders o
                    JOIN om_market_partners p ON o.partner_id = p.partner_id
                    WHERE o.shopper_id = ? AND o.status IN ('aceito', 'coletando', 'em_entrega', 'entregando')
                    LIMIT 1
                ");
                $stmt->execute([$shopper_id]);
                $pedido_ativo = $stmt->fetch(PDO::FETCH_ASSOC);

                // Boosts ativos
                $stmt = $db->prepare("
                    SELECT * FROM om_shopper_boosts
                    WHERE ativo = 1
                    AND (data_inicio IS NULL OR data_inicio <= CURDATE())
                    AND (data_fim IS NULL OR data_fim >= CURDATE())
                    AND (horario_inicio IS NULL OR TIME(NOW()) >= horario_inicio)
                    AND (horario_fim IS NULL OR TIME(NOW()) <= horario_fim)
                    LIMIT 3
                ");
                $stmt->execute();
                $boosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'stats' => $stats,
                    'pedidos' => $pedidos_disponiveis,
                    'pedido_ativo' => $pedido_ativo,
                    'boosts' => $boosts,
                    'online' => $shopper['online'] || $shopper['is_online']
                ]);
                exit;

            case 'aceitar_pedido':
                $order_id = intval($_POST['order_id']);

                // Verificar se ainda está disponível
                $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND shopper_id IS NULL");
                $stmt->execute([$order_id]);
                $order = $stmt->fetch();

                if (!$order) {
                    echo json_encode(['success' => false, 'error' => 'Pedido já foi aceito por outro shopper']);
                    exit;
                }

                // Aceitar pedido
                $db->prepare("UPDATE om_market_orders SET shopper_id = ?, status = 'aceito', accepted_at = NOW() WHERE order_id = ?")->execute([$shopper_id, $order_id]);

                // Atualizar shopper
                $db->prepare("UPDATE om_market_shoppers SET pedido_atual_id = ?, active_order_id = ?, is_busy = 1, disponivel = 0 WHERE shopper_id = ?")->execute([$order_id, $order_id, $shopper_id]);

                // Incrementar ofertas aceitas
                $db->prepare("UPDATE om_market_shoppers SET total_accepts = total_accepts + 1 WHERE shopper_id = ?")->execute([$shopper_id]);

                echo json_encode(['success' => true]);
                exit;

            case 'iniciar_coleta':
                $order_id = intval($_POST['order_id']);
                $db->prepare("UPDATE om_market_orders SET status = 'coletando', collection_started_at = NOW() WHERE order_id = ? AND shopper_id = ?")->execute([$order_id, $shopper_id]);
                echo json_encode(['success' => true]);
                exit;

            case 'finalizar_coleta':
                $order_id = intval($_POST['order_id']);
                $db->prepare("UPDATE om_market_orders SET status = 'em_entrega', collected_at = NOW() WHERE order_id = ? AND shopper_id = ?")->execute([$order_id, $shopper_id]);
                echo json_encode(['success' => true]);
                exit;

            case 'finalizar_entrega':
                $order_id = intval($_POST['order_id']);

                // Buscar valor do pedido
                $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
                $stmt->execute([$order_id, $shopper_id]);
                $order = $stmt->fetch();

                if (!$order) {
                    echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
                    exit;
                }

                // Calcular ganhos (base + distância)
                $ganho_base = 8.00; // R$ base por entrega
                $gorjeta = floatval($order['tip'] ?? 0);
                $total_ganho = $ganho_base + $gorjeta;

                // Finalizar pedido
                $db->prepare("UPDATE om_market_orders SET status = 'entregue', delivered_at = NOW() WHERE order_id = ?")->execute([$order_id]);

                // Atualizar shopper
                $db->prepare("
                    UPDATE om_market_shoppers SET
                        pedido_atual_id = NULL, active_order_id = NULL,
                        is_busy = 0, disponivel = 1,
                        total_entregas = total_entregas + 1,
                        orders_today = orders_today + 1,
                        total_earnings_today = total_earnings_today + ?,
                        saldo = saldo + ?,
                        pontos = pontos + 10
                    WHERE shopper_id = ?
                ")->execute([$total_ganho, $total_ganho, $shopper_id]);

                // Registrar transação
                $stmt = $db->prepare("SELECT saldo FROM om_market_shoppers WHERE shopper_id = ?");
                $stmt->execute([$shopper_id]);
                $novo_saldo = $stmt->fetchColumn();

                $db->prepare("
                    INSERT INTO om_shopper_wallet_transactions (shopper_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status)
                    VALUES (?, 'ganho', ?, ?, ?, 'pedido', ?, 'Entrega concluída', 'disponivel')
                ")->execute([$shopper_id, $total_ganho, $novo_saldo - $total_ganho, $novo_saldo, $order_id]);

                if ($gorjeta > 0) {
                    $db->prepare("
                        INSERT INTO om_shopper_wallet_transactions (shopper_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status)
                        VALUES (?, 'gorjeta', ?, ?, ?, 'pedido', ?, 'Gorjeta do cliente', 'disponivel')
                    ")->execute([$shopper_id, $gorjeta, $novo_saldo - $gorjeta, $novo_saldo, $order_id]);
                }

                // Verificar badges
                verificarBadges($db, $shopper_id);

                echo json_encode(['success' => true, 'ganho' => $total_ganho]);
                exit;

            case 'get_order_details':
                $order_id = intval($_POST['order_id']);
                $stmt = $db->prepare("
                    SELECT o.*, p.name as partner_name, p.address as partner_address, p.phone as partner_phone,
                           p.latitude as partner_lat, p.longitude as partner_lng, p.logo as partner_logo
                    FROM om_market_orders o
                    JOIN om_market_partners p ON o.partner_id = p.partner_id
                    WHERE o.order_id = ?
                ");
                $stmt->execute([$order_id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$order) {
                    echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
                    exit;
                }

                // Buscar itens
                $stmt = $db->prepare("
                    SELECT i.*, pr.name as product_name, pr.image as product_image
                    FROM om_market_order_items i
                    LEFT JOIN om_market_products pr ON i.product_id = pr.product_id
                    WHERE i.order_id = ?
                ");
                $stmt->execute([$order_id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'order' => $order, 'items' => $items]);
                exit;

            case 'get_earnings':
                $periodo = $_POST['periodo'] ?? 'hoje';

                $where = "";
                if ($periodo === 'hoje') $where = "AND DATE(created_at) = CURDATE()";
                elseif ($periodo === 'semana') $where = "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                elseif ($periodo === 'mes') $where = "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

                $stmt = $db->prepare("
                    SELECT tipo, SUM(valor) as total
                    FROM om_shopper_wallet_transactions
                    WHERE shopper_id = ? AND status = 'disponivel' $where
                    GROUP BY tipo
                ");
                $stmt->execute([$shopper_id]);
                $por_tipo = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $stmt = $db->prepare("
                    SELECT * FROM om_shopper_wallet_transactions
                    WHERE shopper_id = ? $where
                    ORDER BY created_at DESC LIMIT 50
                ");
                $stmt->execute([$shopper_id]);
                $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'por_tipo' => $por_tipo,
                    'transacoes' => $transacoes,
                    'saldo' => floatval($shopper['saldo'] ?? 0),
                    'saldo_pendente' => floatval($shopper['saldo_pendente'] ?? 0)
                ]);
                exit;

            case 'solicitar_saque':
                $valor = floatval($_POST['valor']);
                $saldo = floatval($shopper['saldo'] ?? 0);

                if ($valor <= 0 || $valor > $saldo) {
                    echo json_encode(['success' => false, 'error' => 'Valor inválido']);
                    exit;
                }

                $tipo = $_POST['tipo'] ?? 'semanal';
                $taxa = $tipo === 'instantaneo' ? 1.99 : 0;
                $valor_liquido = $valor - $taxa;

                // Verificar se pode saque instantâneo
                if ($tipo === 'instantaneo') {
                    $entregas = intval($shopper['total_entregas'] ?? 0);
                    if ($entregas < 5) {
                        echo json_encode(['success' => false, 'error' => 'Complete 5 entregas para liberar saque instantâneo']);
                        exit;
                    }
                }

                // Criar saque
                $db->prepare("
                    INSERT INTO om_shopper_saques (shopper_id, valor, taxa, valor_liquido, tipo, chave_pix, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pendente')
                ")->execute([$shopper_id, $valor, $taxa, $valor_liquido, $tipo, $shopper['pix_chave'] ?? $shopper['pix_key']]);

                // Descontar do saldo
                $db->prepare("UPDATE om_market_shoppers SET saldo = saldo - ? WHERE shopper_id = ?")->execute([$valor, $shopper_id]);

                echo json_encode(['success' => true, 'valor_liquido' => $valor_liquido]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

function verificarBadges($db, $shopper_id) {
    // Buscar dados atuais
    $stmt = $db->prepare("SELECT total_entregas, rating FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch();

    // Verificar badges de entregas
    $entregas_badges = [1 => 'primeira_entrega', 10 => '10_entregas', 50 => '50_entregas', 100 => '100_entregas', 500 => '500_entregas', 1000 => '1000_entregas'];

    foreach ($entregas_badges as $num => $codigo) {
        if ($shopper['total_entregas'] >= $num) {
            $stmt = $db->prepare("SELECT id FROM om_shopper_badges WHERE codigo = ?");
            $stmt->execute([$codigo]);
            $badge = $stmt->fetch();

            if ($badge) {
                $db->prepare("INSERT IGNORE INTO om_shopper_achievements (shopper_id, badge_id) VALUES (?, ?)")->execute([$shopper_id, $badge['id']]);
            }
        }
    }

    // Atualizar nível
    $entregas = $shopper['total_entregas'];
    $nivel = 'iniciante';
    if ($entregas >= 500) $nivel = 'diamante';
    elseif ($entregas >= 200) $nivel = 'ouro';
    elseif ($entregas >= 50) $nivel = 'prata';
    elseif ($entregas >= 10) $nivel = 'bronze';

    $db->prepare("UPDATE om_market_shoppers SET nivel_nome = ? WHERE shopper_id = ?")->execute([$nivel, $shopper_id]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Shopper - OneMundo</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#4a6cf7">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #4a6cf7;
            --primary-dark: #3d5bd9;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text: #1f2937;
            --text-secondary: #6b7280;
            --bg: #f3f4f6;
            --card: #ffffff;
            --border: #e5e7eb;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --radius: 16px;
            --radius-sm: 8px;
            --safe-bottom: env(safe-area-inset-bottom, 20px);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: calc(80px + var(--safe-bottom));
            overflow-x: hidden;
        }

        /* Header fixo */
        .app-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 16px 20px;
            z-index: 100;
            padding-top: max(16px, env(safe-area-inset-top));
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .user-name {
            font-size: 18px;
            font-weight: 600;
        }

        .user-level {
            font-size: 12px;
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .level-badge {
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 600;
        }

        /* Toggle Online */
        .online-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.15);
            padding: 8px 16px;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .online-toggle.active {
            background: var(--success);
        }

        .online-toggle .toggle-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255,255,255,0.5);
            transition: all 0.3s ease;
        }

        .online-toggle.active .toggle-dot {
            background: white;
            box-shadow: 0 0 10px rgba(255,255,255,0.5);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .online-toggle span {
            font-size: 14px;
            font-weight: 500;
        }

        /* Stats Cards */
        .stats-row {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            padding: 4px 0;
        }

        .stats-row::-webkit-scrollbar {
            display: none;
        }

        .stat-mini {
            background: rgba(255,255,255,0.15);
            padding: 10px 16px;
            border-radius: 12px;
            text-align: center;
            min-width: fit-content;
            backdrop-filter: blur(10px);
        }

        .stat-mini .value {
            font-size: 18px;
            font-weight: 700;
        }

        .stat-mini .label {
            font-size: 10px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Main Content */
        .main-content {
            padding: 20px;
            padding-top: calc(160px + env(safe-area-inset-top));
        }

        /* Boost Banner */
        .boost-banner {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 16px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: shimmer 3s infinite;
            position: relative;
            overflow: hidden;
        }

        .boost-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            100% { left: 100%; }
        }

        .boost-icon {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .boost-info h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .boost-info p {
            font-size: 12px;
            opacity: 0.9;
        }

        /* Cards */
        .card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 16px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:active {
            transform: scale(0.98);
        }

        .card-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-body {
            padding: 16px;
        }

        /* Order Card */
        .order-card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .order-card:active {
            transform: scale(0.98);
        }

        .order-card.active-order {
            border: 2px solid var(--primary);
            box-shadow: var(--shadow-lg), 0 0 0 4px rgba(74, 108, 247, 0.1);
        }

        .order-header {
            padding: 16px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .store-logo {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .store-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
        }

        .order-info {
            flex: 1;
        }

        .order-info h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .order-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .order-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .order-earning {
            text-align: right;
        }

        .order-earning .amount {
            font-size: 20px;
            font-weight: 700;
            color: var(--success);
        }

        .order-earning .tip {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .order-actions {
            padding: 12px 16px;
            background: var(--bg);
            display: flex;
            gap: 8px;
        }

        .btn {
            flex: 1;
            padding: 14px 20px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:active {
            background: var(--primary-dark);
            transform: scale(0.98);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--border);
            color: var(--text);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 64px;
            opacity: 0.3;
            margin-bottom: 16px;
        }

        .empty-state h4 {
            font-size: 18px;
            color: var(--text);
            margin-bottom: 8px;
        }

        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--card);
            border-top: 1px solid var(--border);
            display: flex;
            padding-bottom: var(--safe-bottom);
            z-index: 100;
        }

        .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 12px 8px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 10px;
            transition: color 0.2s;
            cursor: pointer;
        }

        .nav-item.active {
            color: var(--primary);
        }

        .nav-item i {
            font-size: 22px;
            margin-bottom: 4px;
        }

        /* Earnings Summary */
        .earnings-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }

        .earning-card {
            background: var(--card);
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
        }

        .earning-card.highlight {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .earning-card .amount {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .earning-card .label {
            font-size: 12px;
            opacity: 0.8;
        }

        /* Progress Bar */
        .progress-section {
            background: var(--card);
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .progress-bar {
            height: 8px;
            background: var(--bg);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--success));
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        /* Transaction Item */
        .transaction-item {
            display: flex;
            align-items: center;
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .transaction-item:last-child {
            border-bottom: none;
        }

        .transaction-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 18px;
        }

        .transaction-icon.ganho { background: #dcfce7; color: var(--success); }
        .transaction-icon.gorjeta { background: #fef3c7; color: var(--warning); }
        .transaction-icon.saque { background: #fee2e2; color: var(--danger); }
        .transaction-icon.bonus { background: #dbeafe; color: var(--primary); }

        .transaction-info {
            flex: 1;
        }

        .transaction-info h5 {
            font-size: 14px;
            margin-bottom: 2px;
        }

        .transaction-info span {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .transaction-amount {
            font-size: 16px;
            font-weight: 600;
        }

        .transaction-amount.positive { color: var(--success); }
        .transaction-amount.negative { color: var(--danger); }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 200;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-sheet {
            background: var(--card);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            border-radius: var(--radius) var(--radius) 0 0;
            overflow: hidden;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal-sheet {
            transform: translateY(0);
        }

        .modal-handle {
            width: 40px;
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            margin: 12px auto;
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 18px;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--bg);
            border: none;
            font-size: 18px;
            cursor: pointer;
        }

        .modal-body {
            padding: 20px;
            overflow-y: auto;
            max-height: calc(90vh - 120px);
        }

        /* Skeleton Loading */
        .skeleton {
            background: linear-gradient(90deg, var(--bg) 25%, #e5e7eb 50%, var(--bg) 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
            border-radius: var(--radius-sm);
        }

        @keyframes skeleton-loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Page Sections (hidden by default) */
        .page-section {
            display: none;
        }

        .page-section.active {
            display: block;
        }

        /* Floating Action */
        .fab-container {
            position: fixed;
            bottom: calc(100px + var(--safe-bottom));
            right: 20px;
            z-index: 50;
        }

        .fab {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            border: none;
            box-shadow: var(--shadow-lg);
            font-size: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s;
        }

        .fab:active {
            transform: scale(0.95);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="app-header">
        <div class="header-top">
            <div class="user-info">
                <div class="user-avatar"><?php echo $shopper['avatar'] ?? '🛒'; ?></div>
                <div>
                    <div class="user-name"><?php echo htmlspecialchars($shopper['name'] ?? $shopper['nome']); ?></div>
                    <div class="user-level">
                        <i class="fas fa-star"></i>
                        <span id="header-rating"><?php echo number_format($shopper['rating'] ?? 5, 1); ?></span>
                        <span class="level-badge" id="header-level"><?php echo $shopper['nivel_nome'] ?? 'iniciante'; ?></span>
                    </div>
                </div>
            </div>
            <div class="online-toggle" id="online-toggle" onclick="toggleOnline()">
                <div class="toggle-dot"></div>
                <span id="online-text">Offline</span>
            </div>
        </div>
        <div class="stats-row">
            <div class="stat-mini">
                <div class="value" id="stat-hoje">R$ 0</div>
                <div class="label">Hoje</div>
            </div>
            <div class="stat-mini">
                <div class="value" id="stat-pedidos">0</div>
                <div class="label">Entregas</div>
            </div>
            <div class="stat-mini">
                <div class="value" id="stat-saldo">R$ 0</div>
                <div class="label">Saldo</div>
            </div>
            <div class="stat-mini">
                <div class="value" id="stat-aceita"><?php echo number_format($shopper['acceptance_rate'] ?? 100, 0); ?>%</div>
                <div class="label">Aceita</div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Home Section -->
        <section class="page-section active" id="section-home">
            <!-- Boost Banner -->
            <div class="boost-banner" id="boost-banner" style="display: none;">
                <div class="boost-icon"><i class="fas fa-fire"></i></div>
                <div class="boost-info">
                    <h4 id="boost-title">Peak Pay Ativo!</h4>
                    <p id="boost-desc">+R$ 3,00 por entrega agora</p>
                </div>
            </div>

            <!-- Active Order -->
            <div id="active-order-container" style="display: none;"></div>

            <!-- Available Orders -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-shopping-bag"></i> Pedidos Disponíveis</h3>
                    <span id="orders-count">0</span>
                </div>
                <div class="card-body" id="orders-container">
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>Nenhum pedido disponível</h4>
                        <p>Novos pedidos aparecerão aqui automaticamente</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Earnings Section -->
        <section class="page-section" id="section-earnings">
            <div class="earnings-summary">
                <div class="earning-card highlight">
                    <div class="amount" id="earn-disponivel">R$ 0</div>
                    <div class="label">Disponível para Saque</div>
                </div>
                <div class="earning-card">
                    <div class="amount" id="earn-pendente">R$ 0</div>
                    <div class="label">Pendente</div>
                </div>
            </div>

            <button class="btn btn-primary" style="width: 100%; margin-bottom: 20px;" onclick="abrirModalSaque()">
                <i class="fas fa-wallet"></i> Solicitar Saque
            </button>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Histórico</h3>
                    <select id="filter-periodo" onchange="carregarGanhos()" style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);">
                        <option value="hoje">Hoje</option>
                        <option value="semana">Esta Semana</option>
                        <option value="mes">Este Mês</option>
                    </select>
                </div>
                <div id="transactions-container"></div>
            </div>
        </section>

        <!-- Profile Section -->
        <section class="page-section" id="section-profile">
            <div class="progress-section">
                <div class="progress-header">
                    <span>Próximo nível</span>
                    <span id="progress-level">Bronze (10 entregas)</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill" style="width: 0%;"></div>
                </div>
            </div>

            <div class="earnings-summary">
                <div class="earning-card">
                    <div class="amount" id="profile-entregas">0</div>
                    <div class="label">Total Entregas</div>
                </div>
                <div class="earning-card">
                    <div class="amount" id="profile-pontos">0</div>
                    <div class="label">Pontos</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-trophy"></i> Conquistas</h3>
                </div>
                <div class="card-body" id="badges-container">
                    <p style="color: var(--text-secondary); text-align: center;">Carregando conquistas...</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-cog"></i> Configurações</h3>
                </div>
                <div class="card-body">
                    <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border);">
                        <span>Aceita Shop & Deliver</span>
                        <input type="checkbox" id="config-shop" checked>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border);">
                        <span>Aceita Apenas Entrega</span>
                        <input type="checkbox" id="config-deliver" checked>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 12px 0;">
                        <span>Raio máximo (km)</span>
                        <input type="number" id="config-raio" value="10" style="width: 60px; text-align: center; border: 1px solid var(--border); border-radius: 8px; padding: 4px;">
                    </div>
                </div>
            </div>

            <button class="btn btn-outline" style="width: 100%; margin-top: 16px;" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i> Sair
            </button>
        </section>
    </main>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <div class="nav-item active" onclick="showSection('home')">
            <i class="fas fa-home"></i>
            <span>Início</span>
        </div>
        <div class="nav-item" onclick="showSection('earnings')">
            <i class="fas fa-wallet"></i>
            <span>Ganhos</span>
        </div>
        <div class="nav-item" onclick="showSection('profile')">
            <i class="fas fa-user"></i>
            <span>Perfil</span>
        </div>
        <div class="nav-item" onclick="abrirSuporte()">
            <i class="fas fa-headset"></i>
            <span>Suporte</span>
        </div>
    </nav>

    <!-- Modal Order Details -->
    <div class="modal-overlay" id="modal-order">
        <div class="modal-sheet">
            <div class="modal-handle"></div>
            <div class="modal-header">
                <h3>Detalhes do Pedido</h3>
                <button class="modal-close" onclick="closeModal('modal-order')">&times;</button>
            </div>
            <div class="modal-body" id="order-details-content">
                <!-- Conteúdo carregado dinamicamente -->
            </div>
        </div>
    </div>

    <!-- Modal Saque -->
    <div class="modal-overlay" id="modal-saque">
        <div class="modal-sheet">
            <div class="modal-handle"></div>
            <div class="modal-header">
                <h3>Solicitar Saque</h3>
                <button class="modal-close" onclick="closeModal('modal-saque')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="earning-card highlight" style="margin-bottom: 20px;">
                    <div class="amount" id="saque-disponivel">R$ 0</div>
                    <div class="label">Disponível</div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Valor do saque</label>
                    <input type="number" id="saque-valor" placeholder="R$ 0,00" style="width: 100%; padding: 14px; border: 1px solid var(--border); border-radius: 12px; font-size: 16px;">
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Tipo de saque</label>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-outline" style="flex: 1;" onclick="selecionarTipoSaque('semanal')" id="btn-saque-semanal">
                            Semanal (Grátis)
                        </button>
                        <button class="btn btn-outline" style="flex: 1;" onclick="selecionarTipoSaque('instantaneo')" id="btn-saque-instantaneo">
                            Instantâneo (R$ 1,99)
                        </button>
                    </div>
                </div>

                <button class="btn btn-success" style="width: 100%;" onclick="confirmarSaque()">
                    <i class="fas fa-check"></i> Confirmar Saque
                </button>
            </div>
        </div>
    </div>

    <script>
        let isOnline = false;
        let pedidoAtivo = null;
        let refreshInterval;
        let tipoSaqueSelecionado = 'semanal';
        let saldoDisponivel = 0;

        // Inicialização
        document.addEventListener('DOMContentLoaded', () => {
            carregarDashboard();
            iniciarGeolocalizacao();

            // Refresh a cada 10 segundos
            refreshInterval = setInterval(carregarDashboard, 10000);
        });

        async function carregarDashboard() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=get_dashboard'
                });
                const data = await response.json();

                if (data.success) {
                    atualizarUI(data);
                }
            } catch (error) {
                console.error('Erro ao carregar dashboard:', error);
            }
        }

        function atualizarUI(data) {
            const stats = data.stats;

            // Stats do header
            document.getElementById('stat-hoje').textContent = 'R$ ' + stats.ganhos_hoje.toFixed(2);
            document.getElementById('stat-pedidos').textContent = stats.pedidos_hoje;
            document.getElementById('stat-saldo').textContent = 'R$ ' + stats.saldo_disponivel.toFixed(2);
            document.getElementById('header-rating').textContent = stats.rating.toFixed(1);
            document.getElementById('header-level').textContent = stats.nivel;

            saldoDisponivel = stats.saldo_disponivel;

            // Online status
            isOnline = data.online;
            const toggle = document.getElementById('online-toggle');
            const text = document.getElementById('online-text');
            if (isOnline) {
                toggle.classList.add('active');
                text.textContent = 'Online';
            } else {
                toggle.classList.remove('active');
                text.textContent = 'Offline';
            }

            // Boosts
            if (data.boosts && data.boosts.length > 0) {
                const boost = data.boosts[0];
                document.getElementById('boost-banner').style.display = 'flex';
                document.getElementById('boost-title').textContent = boost.titulo;
                document.getElementById('boost-desc').textContent = '+R$ ' + parseFloat(boost.valor_extra).toFixed(2) + ' por entrega';
            } else {
                document.getElementById('boost-banner').style.display = 'none';
            }

            // Pedido ativo
            if (data.pedido_ativo) {
                pedidoAtivo = data.pedido_ativo;
                renderizarPedidoAtivo(data.pedido_ativo);
            } else {
                pedidoAtivo = null;
                document.getElementById('active-order-container').style.display = 'none';
            }

            // Pedidos disponíveis
            renderizarPedidos(data.pedidos);
        }

        function renderizarPedidoAtivo(order) {
            const container = document.getElementById('active-order-container');
            container.style.display = 'block';

            const statusText = {
                'aceito': 'Ir até o mercado',
                'coletando': 'Coletando itens',
                'em_entrega': 'Entregando'
            };

            const actionBtn = order.status === 'aceito' ?
                '<button class="btn btn-primary" onclick="iniciarColeta(' + order.order_id + ')"><i class="fas fa-store"></i> Cheguei no Mercado</button>' :
                order.status === 'coletando' ?
                '<button class="btn btn-primary" onclick="finalizarColeta(' + order.order_id + ')"><i class="fas fa-check"></i> Coleta Finalizada</button>' :
                '<button class="btn btn-success" onclick="finalizarEntrega(' + order.order_id + ')"><i class="fas fa-flag-checkered"></i> Entregar</button>';

            container.innerHTML = `
                <div class="order-card active-order">
                    <div class="order-header">
                        <div class="store-logo">
                            ${order.partner_logo ? '<img src="' + order.partner_logo + '">' : '<i class="fas fa-store"></i>'}
                        </div>
                        <div class="order-info">
                            <h4>${order.partner_name}</h4>
                            <div class="order-meta">
                                <span><i class="fas fa-hashtag"></i> ${order.order_id}</span>
                                <span><i class="fas fa-spinner fa-spin"></i> ${statusText[order.status] || order.status}</span>
                            </div>
                        </div>
                    </div>
                    <div class="order-actions">
                        <button class="btn btn-outline" onclick="verDetalhes(${order.order_id})"><i class="fas fa-list"></i></button>
                        <button class="btn btn-outline" onclick="abrirNavegacao('${order.partner_address}')"><i class="fas fa-map"></i></button>
                        ${actionBtn}
                    </div>
                </div>
            `;
        }

        function renderizarPedidos(pedidos) {
            const container = document.getElementById('orders-container');
            document.getElementById('orders-count').textContent = pedidos.length;

            if (pedidos.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>Nenhum pedido disponível</h4>
                        <p>Novos pedidos aparecerão aqui automaticamente</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = pedidos.map(order => `
                <div class="order-card" onclick="verDetalhes(${order.order_id})">
                    <div class="order-header">
                        <div class="store-logo">
                            ${order.partner_logo ? '<img src="' + order.partner_logo + '">' : '<i class="fas fa-store"></i>'}
                        </div>
                        <div class="order-info">
                            <h4>${escapeHtml(order.partner_name)}</h4>
                            <div class="order-meta">
                                <span><i class="fas fa-box"></i> ${order.total_items || '?'} itens</span>
                                <span><i class="fas fa-clock"></i> ${formatarTempo(order.created_at)}</span>
                            </div>
                        </div>
                        <div class="order-earning">
                            <div class="amount">R$ ${(8 + parseFloat(order.tip || 0)).toFixed(2)}</div>
                            ${order.tip > 0 ? '<div class="tip">inclui gorjeta</div>' : ''}
                        </div>
                    </div>
                    <div class="order-actions">
                        <button class="btn btn-primary" onclick="event.stopPropagation(); aceitarPedido(${order.order_id})">
                            <i class="fas fa-check"></i> Aceitar
                        </button>
                    </div>
                </div>
            `).join('');
        }

        async function toggleOnline() {
            const novoStatus = isOnline ? 0 : 1;

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=toggle_online&online=' + novoStatus
            });

            const data = await response.json();
            if (data.success) {
                isOnline = novoStatus === 1;
                const toggle = document.getElementById('online-toggle');
                const text = document.getElementById('online-text');

                if (isOnline) {
                    toggle.classList.add('active');
                    text.textContent = 'Online';
                    carregarDashboard();
                } else {
                    toggle.classList.remove('active');
                    text.textContent = 'Offline';
                }
            }
        }

        async function aceitarPedido(orderId) {
            if (pedidoAtivo) {
                alert('Você já tem um pedido em andamento!');
                return;
            }

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=aceitar_pedido&order_id=' + orderId
            });

            const data = await response.json();
            if (data.success) {
                carregarDashboard();
            } else {
                alert(data.error || 'Erro ao aceitar pedido');
            }
        }

        async function iniciarColeta(orderId) {
            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=iniciar_coleta&order_id=' + orderId
            });

            if ((await response.json()).success) {
                carregarDashboard();
            }
        }

        async function finalizarColeta(orderId) {
            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=finalizar_coleta&order_id=' + orderId
            });

            if ((await response.json()).success) {
                carregarDashboard();
            }
        }

        async function finalizarEntrega(orderId) {
            if (!confirm('Confirmar entrega realizada?')) return;

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=finalizar_entrega&order_id=' + orderId
            });

            const data = await response.json();
            if (data.success) {
                alert('Entrega concluída! Você ganhou R$ ' + data.ganho.toFixed(2));
                carregarDashboard();
            }
        }

        async function verDetalhes(orderId) {
            document.getElementById('modal-order').classList.add('active');
            document.getElementById('order-details-content').innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 32px;"></i></div>';

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_order_details&order_id=' + orderId
            });

            const data = await response.json();
            if (data.success) {
                const order = data.order;
                const items = data.items;

                document.getElementById('order-details-content').innerHTML = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin-bottom: 8px;">${escapeHtml(order.partner_name)}</h4>
                        <p style="color: var(--text-secondary);"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(order.partner_address || 'Endereço não informado')}</p>
                        <p style="color: var(--text-secondary);"><i class="fas fa-phone"></i> ${escapeHtml(order.partner_phone || 'Sem telefone')}</p>
                    </div>

                    <h5 style="margin-bottom: 12px;">Itens do Pedido (${items.length})</h5>
                    <div style="border: 1px solid var(--border); border-radius: 12px; overflow: hidden;">
                        ${items.map(item => `
                            <div style="display: flex; align-items: center; padding: 12px; border-bottom: 1px solid var(--border);">
                                <div style="width: 50px; height: 50px; background: var(--bg); border-radius: 8px; margin-right: 12px; display: flex; align-items: center; justify-content: center;">
                                    ${item.product_image ? '<img src="' + item.product_image + '" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;">' : '<i class="fas fa-box" style="color: var(--text-secondary);"></i>'}
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-weight: 500;">${escapeHtml(item.product_name || item.name || 'Produto')}</div>
                                    <div style="font-size: 12px; color: var(--text-secondary);">Qtd: ${item.quantity}</div>
                                </div>
                            </div>
                        `).join('')}
                    </div>

                    <div style="margin-top: 20px; padding: 16px; background: var(--bg); border-radius: 12px;">
                        <h5 style="margin-bottom: 12px;">Entregar em:</h5>
                        <p><i class="fas fa-user"></i> ${escapeHtml(order.customer_name || 'Cliente')}</p>
                        <p><i class="fas fa-map-marker-alt"></i> ${escapeHtml(order.delivery_address || order.address || 'Endereço não informado')}</p>
                        ${order.delivery_notes ? '<p><i class="fas fa-sticky-note"></i> ' + escapeHtml(order.delivery_notes) + '</p>' : ''}
                    </div>
                `;
            }
        }

        function iniciarGeolocalizacao() {
            if ('geolocation' in navigator) {
                navigator.geolocation.watchPosition(
                    (position) => {
                        if (isOnline) {
                            fetch('', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: `action=update_location&lat=${position.coords.latitude}&lng=${position.coords.longitude}`
                            });
                        }
                    },
                    (error) => console.log('Erro GPS:', error),
                    { enableHighAccuracy: true, maximumAge: 30000, timeout: 27000 }
                );
            }
        }

        function showSection(section) {
            document.querySelectorAll('.page-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

            document.getElementById('section-' + section).classList.add('active');
            event.currentTarget.classList.add('active');

            if (section === 'earnings') {
                carregarGanhos();
            }
        }

        async function carregarGanhos() {
            const periodo = document.getElementById('filter-periodo').value;

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_earnings&periodo=' + periodo
            });

            const data = await response.json();
            if (data.success) {
                document.getElementById('earn-disponivel').textContent = 'R$ ' + data.saldo.toFixed(2);
                document.getElementById('earn-pendente').textContent = 'R$ ' + data.saldo_pendente.toFixed(2);
                saldoDisponivel = data.saldo;

                const container = document.getElementById('transactions-container');
                if (data.transacoes.length === 0) {
                    container.innerHTML = '<div style="padding: 40px; text-align: center; color: var(--text-secondary);">Nenhuma transação neste período</div>';
                } else {
                    container.innerHTML = data.transacoes.map(t => `
                        <div class="transaction-item">
                            <div class="transaction-icon ${t.tipo}">
                                <i class="fas ${t.tipo === 'ganho' ? 'fa-arrow-down' : t.tipo === 'gorjeta' ? 'fa-heart' : t.tipo === 'saque' ? 'fa-arrow-up' : 'fa-gift'}"></i>
                            </div>
                            <div class="transaction-info">
                                <h5>${escapeHtml(t.descricao || t.tipo)}</h5>
                                <span>${formatarDataHora(t.created_at)}</span>
                            </div>
                            <div class="transaction-amount ${t.tipo === 'saque' ? 'negative' : 'positive'}">
                                ${t.tipo === 'saque' ? '-' : '+'}R$ ${parseFloat(t.valor).toFixed(2)}
                            </div>
                        </div>
                    `).join('');
                }
            }
        }

        function abrirModalSaque() {
            document.getElementById('saque-disponivel').textContent = 'R$ ' + saldoDisponivel.toFixed(2);
            document.getElementById('saque-valor').value = '';
            document.getElementById('modal-saque').classList.add('active');
        }

        function selecionarTipoSaque(tipo) {
            tipoSaqueSelecionado = tipo;
            document.getElementById('btn-saque-semanal').classList.toggle('btn-primary', tipo === 'semanal');
            document.getElementById('btn-saque-semanal').classList.toggle('btn-outline', tipo !== 'semanal');
            document.getElementById('btn-saque-instantaneo').classList.toggle('btn-primary', tipo === 'instantaneo');
            document.getElementById('btn-saque-instantaneo').classList.toggle('btn-outline', tipo !== 'instantaneo');
        }

        async function confirmarSaque() {
            const valor = parseFloat(document.getElementById('saque-valor').value);

            if (!valor || valor <= 0) {
                alert('Digite um valor válido');
                return;
            }

            if (valor > saldoDisponivel) {
                alert('Saldo insuficiente');
                return;
            }

            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=solicitar_saque&valor=${valor}&tipo=${tipoSaqueSelecionado}`
            });

            const data = await response.json();
            if (data.success) {
                alert('Saque solicitado! Valor líquido: R$ ' + data.valor_liquido.toFixed(2));
                closeModal('modal-saque');
                carregarGanhos();
                carregarDashboard();
            } else {
                alert(data.error || 'Erro ao solicitar saque');
            }
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function abrirNavegacao(endereco) {
            window.open('https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(endereco), '_blank');
        }

        function abrirSuporte() {
            alert('Em breve: Chat de suporte');
        }

        function logout() {
            if (confirm('Deseja sair?')) {
                window.location.href = '/painel/shopper/logout.php';
            }
        }

        function formatarTempo(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const now = new Date();
            const diff = (now - date) / 1000;
            if (diff < 60) return 'agora';
            if (diff < 3600) return Math.floor(diff / 60) + ' min';
            return Math.floor(diff / 3600) + 'h';
        }

        function formatarDataHora(dateStr) {
            if (!dateStr) return '';
            return new Date(dateStr).toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'});
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Service Worker para PWA
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }
    </script>
</body>
</html>
