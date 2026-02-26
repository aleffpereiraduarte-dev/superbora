<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║                    📡 API - VERIFICAR PEDIDOS DISPONÍVEIS                            ║
 * ║                          Polling para notificações                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['error' => 'Não autenticado', 'has_order' => false]);
    exit;
}

$conn = getMySQLi();
$conn->set_charset('utf8mb4');

$worker_id = $_SESSION['worker_id'];

// Verificar se worker está online
$worker = $conn->query("SELECT * FROM om_workers WHERE worker_id = $worker_id")->fetch_assoc();

if (!$worker || !$worker['is_online']) {
    echo json_encode(['has_order' => false, 'reason' => 'offline']);
    exit;
}

// Verificar se tem pedido pendente para este worker
// Na prática, você teria uma tabela om_order_offers ou similar
$result = $conn->query("
    SELECT o.*, p.name as partner_name, p.logo_url as partner_logo
    FROM om_orders o
    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
    WHERE o.status = 'pendente'
    AND o.assigned_worker_id IS NULL
    AND o.created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ORDER BY o.created_at DESC
    LIMIT 1
");

if ($result && $result->num_rows > 0) {
    $order = $result->fetch_assoc();
    
    // Simular dados extras para a notificação
    echo json_encode([
        'has_order' => true,
        'order' => [
            'id' => $order['order_id'],
            'store' => $order['partner_name'] ?? 'Loja',
            'store_color' => '#43B02A',
            'address' => $order['store_address'] ?? 'Endereço da loja',
            'customer' => $order['customer_name'] ?? 'Cliente',
            'customer_address' => $order['delivery_address'] ?? 'Endereço de entrega',
            'customer_rating' => 4.8,
            'items' => $order['total_items'] ?? rand(5, 25),
            'distance' => round(rand(10, 80) / 10, 1),
            'time' => rand(15, 45),
            'type' => 'Full',
            'base' => round($order['delivery_fee'] ?? rand(20, 50)),
            'tip' => round($order['tip_amount'] ?? rand(0, 20)),
            'bonus' => rand(0, 1) ? rand(5, 15) : 0
        ]
    ]);
} else {
    // Simulação: 10% de chance de ter um pedido novo a cada poll
    if (rand(1, 100) <= 10) {
        // Gerar pedido simulado
        $stores = [
            ['name' => 'Pão de Açúcar', 'color' => '#43B02A'],
            ['name' => 'Carrefour', 'color' => '#004E9A'],
            ['name' => 'Extra', 'color' => '#E31837'],
            ['name' => 'Dia', 'color' => '#E30613'],
            ['name' => 'Assaí', 'color' => '#FF6600'],
        ];
        
        $customers = [
            ['name' => 'Marina Costa', 'address' => 'Jardins'],
            ['name' => 'Ricardo Mendes', 'address' => 'Consolação'],
            ['name' => 'Ana Paula', 'address' => 'Pinheiros'],
            ['name' => 'Carlos Silva', 'address' => 'Moema'],
            ['name' => 'Julia Santos', 'address' => 'Vila Mariana'],
        ];
        
        $store = $stores[array_rand($stores)];
        $customer = $customers[array_rand($customers)];
        $base = rand(20, 50);
        $tip = rand(0, 20);
        $bonus = rand(0, 1) ? rand(5, 15) : 0;
        
        echo json_encode([
            'has_order' => true,
            'order' => [
                'id' => rand(10000, 99999),
                'store' => $store['name'],
                'store_color' => $store['color'],
                'address' => 'Av. Paulista, ' . rand(100, 3000),
                'customer' => $customer['name'],
                'customer_address' => $customer['address'] . ', São Paulo',
                'customer_rating' => round(rand(40, 50) / 10, 1),
                'items' => rand(5, 25),
                'distance' => round(rand(10, 80) / 10, 1),
                'time' => rand(15, 45),
                'type' => ['Shop', 'Delivery', 'Full'][rand(0, 2)],
                'base' => $base,
                'tip' => $tip,
                'bonus' => $bonus
            ]
        ]);
    } else {
        echo json_encode(['has_order' => false]);
    }
}

$conn->close();
