<?php
/**
 * 🎯 SMART DATA - Incluir no index.php após conexão
 * <?php include __DIR__ . '/includes/smart-data.php'; ?>
 */

if (!isset($pdo)) {
    $oc_root = dirname(__DIR__);
    if (file_exists($oc_root . '/config.php')) {
        require_once($oc_root . '/config.php');
        $pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD);
    } else {
        // Fallback para variáveis de ambiente ou arquivo de config separado
        $db_host = '147.93.12.236';
        $db_name = DB_DATABASE ?? '';
        $db_user = DB_USERNAME ?? '';
        $db_pass = DB_PASSWORD ?? '';
        
        if (empty($db_name) || empty($db_user)) {
            throw new Exception('Configuração de banco não encontrada');
        }
        
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

$_cid = $customer_id ?? $_SESSION['customer_id'] ?? $_pro_customer_id ?? 0;
$_today = date('Y-m-d');

// PRIMEIRA COMPRA
$feat_is_first_order = true;
if ($_cid) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelled','failed')");
        $stmt->execute([$_cid]);
        $feat_is_first_order = ($stmt->fetchColumn() == 0);
    } catch (Exception $e) {
        error_log('Smart Data Error: ' . $e->getMessage());
        // Opcional: definir valores padrão seguros
    }
}

// STREAK
$feat_streak = 0;
$feat_streak_bonus = 0;
if ($_cid) {
    try {
        $stmt = $pdo->prepare("SELECT current_streak, last_purchase_date FROM om_customer_streak WHERE customer_id = ?");
        $stmt->execute([$_cid]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($s) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            if ($s['last_purchase_date'] === $_today || $s['last_purchase_date'] === $yesterday) {
                $feat_streak = (int)$s['current_streak'];
                $feat_streak_bonus = min($feat_streak * 2, 20);
            }
        }
    } catch (Exception $e) {
        error_log('Smart Data Error: ' . $e->getMessage());
        // Opcional: definir valores padrão seguros
    }
}

// PONTOS
$feat_points = 0;
$feat_level = 'iniciante';
if ($_cid) {
    try {
        $stmt = $pdo->prepare("SELECT points, level FROM om_customer_points WHERE customer_id = ?");
        $stmt->execute([$_cid]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p) { $feat_points = (int)$p['points']; $feat_level = $p['level']; }
    } catch (Exception $e) {
        error_log('Smart Data Error: ' . $e->getMessage());
        // Opcional: definir valores padrão seguros
    }
}

// MISSÕES
$feat_missoes = [
    ['id' => 'first_purchase', 'titulo' => 'Primeira compra do dia', 'desc' => 'Faça uma compra hoje', 'pontos' => 50, 'completed' => false],
    ['id' => 'add_3_items', 'titulo' => 'Adicione 3 itens', 'desc' => 'Coloque 3 produtos no carrinho', 'pontos' => 30, 'completed' => false],
    ['id' => 'try_hortifruti', 'titulo' => 'Experimente Hortifruti', 'desc' => 'Compre um item de hortifruti', 'pontos' => 40, 'completed' => false],
];
$feat_missoes_completed = 0;

if ($_cid) {
    try {
        $stmt = $pdo->prepare("SELECT mission_type FROM om_customer_missions WHERE customer_id = ? AND mission_date = ? AND completed = 1");
        $stmt->execute([$_cid, $_today]);
        $done = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($feat_missoes as &$m) {
            if (in_array($m['id'], $done)) { $m['completed'] = true; $feat_missoes_completed++; }
        }
        
        // Auto-check 3 itens
        // Validar e sanitizar dados do carrinho
$cart = [];
if (isset($_SESSION['market_cart']) && is_array($_SESSION['market_cart'])) {
    foreach ($_SESSION['market_cart'] as $item) {
        if (isset($item['price'], $item['qty']) && is_numeric($item['price']) && is_numeric($item['qty'])) {
            $cart[] = $item;
        }
    }
}
        if (count($cart) >= 3 && !in_array('add_3_items', $done)) {
            foreach ($feat_missoes as &$m) {
                if ($m['id'] === 'add_3_items') { $m['completed'] = true; $feat_missoes_completed++; }
            }
        }
    } catch (Exception $e) {
        error_log('Smart Data Error: ' . $e->getMessage());
        // Opcional: definir valores padrão seguros
    }
}

// FRETE
$feat_frete_minimo = $membership_frete_minimo ?? 150;
if ($feat_streak >= 5) $feat_frete_minimo = max(0, $feat_frete_minimo - 20);
if ($feat_streak >= 7) $feat_frete_minimo = max(0, $feat_frete_minimo - 30);

// Validar e sanitizar dados do carrinho
$cart = [];
if (isset($_SESSION['market_cart']) && is_array($_SESSION['market_cart'])) {
    foreach ($_SESSION['market_cart'] as $item) {
        if (isset($item['price'], $item['qty']) && is_numeric($item['price']) && is_numeric($item['qty'])) {
            $cart[] = $item;
        }
    }
}
$feat_cart_total = 0;
foreach ($cart as $item) {
    $price = ($item['price_promo'] ?? 0) > 0 ? $item['price_promo'] : $item['price'];
    $feat_cart_total += $price * ($item['qty'] ?? 1);
}
$feat_frete_gratis_falta = max(0, $feat_frete_minimo - $feat_cart_total);
$feat_tem_frete_gratis = ($feat_cart_total >= $feat_frete_minimo) || ($membership_frete_gratis ?? false);

// CUPOM
$feat_cupom_ativo = $_SESSION['applied_coupon'] ?? null;
$feat_show_cupom_primeira = $feat_is_first_order && !$feat_cupom_ativo;

// PROVA SOCIAL
$feat_prova_social = [
    ['nome' => 'M***', 'cidade' => 'São Paulo', 'tempo' => '2 min'],
    ['nome' => 'J***', 'cidade' => 'Rio de Janeiro', 'tempo' => '5 min'],
    ['nome' => 'A***', 'cidade' => 'Belo Horizonte', 'tempo' => '8 min'],
];
// Implementar cache para prova social
$cache_key = 'prova_social_' . date('YmdH'); // Cache por hora
$feat_prova_social_cached = apcu_fetch($cache_key);

if ($feat_prova_social_cached === false) {
    try {
        $stmt = $pdo->query("SELECT CONCAT(LEFT(c.firstname,1),'***') as nome, COALESCE(a.city,'Brasil') as cidade, TIMESTAMPDIFF(MINUTE,o.created_at,NOW()) as min FROM om_market_orders o LEFT JOIN oc_customer c ON o.customer_id=c.customer_id LEFT JOIN oc_address a ON c.address_id=a.address_id WHERE o.status NOT IN ('cancelled','failed') AND o.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY o.created_at DESC LIMIT 5");
        $real = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($real)) {
            $feat_prova_social = [];
            foreach ($real as $r) {
                $t = (int)$r['min'];
                $feat_prova_social[] = ['nome' => $r['nome'] ?: 'Cliente', 'cidade' => $r['cidade'], 'tempo' => $t < 60 ? "{$t} min" : floor($t/60)."h"];
            }
            apcu_store($cache_key, $feat_prova_social, 3600); // Cache por 1 hora
        }
    } catch (Exception $e) {
        error_log('Prova Social Error: ' . $e->getMessage());
    }
} else {
    $feat_prova_social = $feat_prova_social_cached;
}

// DEBUG - apenas em ambiente de desenvolvimento e com autenticação
if (isset($_GET['debug_smart']) && defined('DEBUG') && DEBUG === true) {
    // Verificar se é admin ou desenvolvedor
    if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
        http_response_code(403);
        exit('Acesso negado');
    }
    
    header('Content-Type: application/json');
    echo json_encode(compact('feat_is_first_order','feat_streak','feat_streak_bonus','feat_points','feat_missoes_completed','feat_cart_total','feat_frete_gratis_falta','feat_tem_frete_gratis'));
    exit;
}