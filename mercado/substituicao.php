<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  API SUBSTITUIÇÃO DE PRODUTOS                                                ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  POST /sugerir    - Shopper sugere substituição                              ║
 * ║  POST /responder  - Cliente aprova/recusa                                    ║
 * ║  GET  /pendentes  - Lista substituições pendentes do pedido                  ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://seudominio.com');
// Ou para múltiplos domínios:
$allowed_origins = ['https://app.exemplo.com', 'https://admin.exemplo.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$oc_root = dirname(dirname(__DIR__));
require_once($oc_root . '/config.php');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
    exit;
}

// Mover para arquivo de migração/instalação separado
// Esta verificação só deve ocorrer durante a instalação/atualização do sistema

// Adicionar colunas de substituição nos itens
try {
    $pdo->exec("ALTER TABLE om_market_order_items ADD COLUMN substituted TINYINT(1) DEFAULT 0");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE om_market_order_items ADD COLUMN original_name VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE om_market_order_items ADD COLUMN original_price DECIMAL(10,2) DEFAULT NULL");
} catch (Exception $e) {}

// Verificar token de autenticação
$headers = getallheaders();
$token = $headers['Authorization'] ?? '';
if (!$token || !validateToken($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token inválido ou ausente']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = array_merge($_GET, $_POST);

$action = $input['action'] ?? $_GET['action'] ?? '';

// ═══════════════════════════════════════════════════════════════════════════════
// GET PENDENTES - Lista substituições pendentes
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'pendentes') {
    $order_id = (int)($input['order_id'] ?? $_GET['order_id'] ?? 0);
    
    if (!$order_id) {
        echo json_encode(['success' => false, 'error' => 'order_id obrigatório']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT * FROM om_market_substitutions 
        WHERE order_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$order_id]);
    $substitutions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pending = array_filter($substitutions, fn($s) => $s['status'] === 'pending');
    
    echo json_encode([
        'success' => true,
        'substitutions' => $substitutions,
        'pending_count' => count($pending),
        'has_pending' => count($pending) > 0
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// SUGERIR - Shopper sugere substituição
// ═══════════════════════════════════════════════════════════════════════════════

if ($action === 'sugerir') {
    $order_id = (int)($input['order_id'] ?? 0);
    $item_id = (int)($input['item_id'] ?? 0);
    $shopper_id = (int)($input['shopper_id'] ?? 0);
    
    // Produto original
    $original_name = trim($input['original_name'] ?? '');
    $original_price = (float)($input['original_price'] ?? 0);
    $original_product_id = (int)($input['original_product_id'] ?? 0);
    
    // Produto sugerido
    $suggested_name = trim($input['suggested_name'] ?? '');
    $suggested_price = (float)($input['suggested_price'] ?? 0);
    $suggested_product_id = (int)($input['suggested_product_id'] ?? 0);
    $suggested_image = trim($input['suggested_image'] ?? '');
    
    $reason = trim($input['reason'] ?? 'Produto indisponível');
    
    if (!$order_id || !$original_name || !$suggested_name) {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
        exit;
    }
    
    // Verificar se pedido existe e está em shopping
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
        exit;
    }
    
    if (!in_array($order['status'], ['confirmed', 'shopping'])) {
        echo json_encode(['success' => false, 'error' => 'Pedido não está em compras']);
        exit;
    }
    
    // Verificar se já tem substituição pendente para este item
    $stmt = $pdo->prepare("SELECT substitution_id FROM om_market_substitutions WHERE order_id = ? AND item_id = ? AND status = 'pending'");
    $stmt->execute([$order_id, $item_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Já existe substituição pendente para este item']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Inserir substituição
        $stmt = $pdo->prepare("
            INSERT INTO om_market_substitutions (
                order_id, item_id, original_product_id, original_name, original_price,
                suggested_product_id, suggested_name, suggested_price, suggested_image,
                reason, shopper_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $order_id, $item_id, $original_product_id, $original_name, $original_price,
            $suggested_product_id, $suggested_name, $suggested_price, $suggested_image,
            $reason, $shopper_id
        ]);
        
        $substitution_id = $pdo->lastInsertId();
        
        // Calcular diferença de preço
        $diff = $suggested_price - $original_price;
        $diff_text = $diff > 0 ? "+R$ " . number_format($diff, 2, ',', '.') : 
                    ($diff < 0 ? "-R$ " . number_format(abs($diff), 2, ',', '.') : "mesmo preço");
        
        // Enviar mensagem no chat
        $shopper_name = $order['shopper_name'] ?: 'Shopper';
        $msg = "🔄 *Substituição sugerida*\n\n";
        $msg .= "❌ *Indisponível:* {$original_name}\n";
        $msg .= "✅ *Sugestão:* {$suggested_name}\n";
        $msg .= "💰 Preço: R$ " . number_format($suggested_price, 2, ',', '.') . " ({$diff_text})\n\n";
        $msg .= "📝 Motivo: {$reason}\n\n";
        $msg .= "Aguardando sua aprovação...";
        
        $stmt = $pdo->prepare("
            INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added)
            VALUES (?, 'shopper', ?, ?, ?, 'substitution', NOW())
        ");
        $stmt->execute([$order_id, $shopper_id, $shopper_name, $msg]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'substitution_id' => $substitution_id,
            'message' => 'Substituição enviada para aprovação'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Substituição error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESPONDER - Cliente aprova ou recusa
// ═══════════════════════════════════════════════════════════════════════════════

if ($action === 'responder') {
    $substitution_id = (int)($input['substitution_id'] ?? 0);
    $response = $input['response'] ?? ''; // 'approve' ou 'reject'
    $note = trim($input['note'] ?? '');
    
    if (!$substitution_id || !in_array($response, ['approve', 'reject'])) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    // Buscar substituição
    $stmt = $pdo->prepare("SELECT * FROM om_market_substitutions WHERE substitution_id = ?");
    $stmt->execute([$substitution_id]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sub) {
        echo json_encode(['success' => false, 'error' => 'Substituição não encontrada']);
        exit;
    }
    
    if ($sub['status'] !== 'pending') {
        echo json_encode(['success' => false, 'error' => 'Substituição já foi respondida']);
        exit;
    }
    
    // Buscar pedido
    $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
    $stmt->execute([$sub['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    try {
        $pdo->beginTransaction();
        
        $new_status = ($response === 'approve') ? 'approved' : 'rejected';
        
        // Atualizar substituição
        $stmt = $pdo->prepare("
            UPDATE om_market_substitutions 
            SET status = ?, responded_at = NOW(), response_note = ?
            WHERE substitution_id = ?
        ");
        $stmt->execute([$new_status, $note, $substitution_id]);
        
        if ($response === 'approve') {
            // Atualizar item do pedido
            if ($sub['item_id']) {
                $stmt = $pdo->prepare("
                    UPDATE om_market_order_items SET
                        product_id = ?,
                        name = ?,
                        price = ?,
                        total = quantity * ?,
                        substituted = 1,
                        original_name = ?,
                        original_price = ?
                    WHERE item_id = ?
                ");
                $stmt->execute([
                    $sub['suggested_product_id'],
                    $sub['suggested_name'],
                    $sub['suggested_price'],
                    $sub['suggested_price'],
                    $sub['original_name'],
                    $sub['original_price'],
                    $sub['item_id']
                ]);
            }
            
            // Recalcular total do pedido
            $stmt = $pdo->prepare("SELECT SUM(total) as subtotal FROM om_market_order_items WHERE order_id = ?");
            $stmt->execute([$sub['order_id']]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $new_subtotal = (float)$totals['subtotal'];
            $new_total = $new_subtotal + (float)$order['delivery_fee'];
            
            $pdo->prepare("UPDATE om_market_orders SET subtotal = ?, total = ? WHERE order_id = ?")
                ->execute([$new_subtotal, $new_total, $sub['order_id']]);
            
            // Mensagem de aprovação
            $msg = "✅ Substituição aprovada!\n\n";
            $msg .= "{$sub['original_name']} → {$sub['suggested_name']}";
            if ($note) $msg .= "\n\n📝 Nota: {$note}";
            
        } else {
            // Mensagem de recusa
            $msg = "❌ Substituição recusada\n\n";
            $msg .= "O cliente prefere não substituir: {$sub['original_name']}";
            if ($note) $msg .= "\n\n📝 Nota: {$note}";
        }
        
        // Enviar mensagem no chat
        $stmt = $pdo->prepare("
            INSERT INTO om_market_chat (order_id, sender_type, sender_id, sender_name, message, message_type, date_added)
            VALUES (?, 'customer', ?, ?, ?, 'text', NOW())
        ");
        $stmt->execute([$sub['order_id'], $order['customer_id'], $order['customer_name'], $msg]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'status' => $new_status,
            'message' => $response === 'approve' ? 'Substituição aprovada!' : 'Substituição recusada'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Substituição error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
