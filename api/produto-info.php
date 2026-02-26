<?php
/**
 * API pública para info do produto (Full, tempo entrega, etc)
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: public, max-age=300"); // Cache 5 min

// Carregar config do OpenCart
$oc_root = dirname(__DIR__);
if (file_exists($oc_root . '/config.php')) {
    require_once $oc_root . '/config.php';
}

$product_id = (int)($_GET["product_id"] ?? $_GET["id"] ?? 0);

if (!$product_id) {
    http_response_code(400);
    echo json_encode(["erro" => "product_id obrigatório"]);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buscar info do produto
    $sql = "SELECT pf.*, w.name as cd_nome, w.city as cd_cidade, w.state as cd_estado
            FROM " . DB_PREFIX . "om_product_fulfillment pf
            LEFT JOIN " . DB_PREFIX . "wms_warehouse w ON w.warehouse_id = pf.warehouse_id
            WHERE pf.product_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$product_id]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$info || $info["tipo"] !== "full" || !in_array($info["status_full"], ["disponivel", "conferido"])) {
        echo json_encode([
            "is_full" => false,
            "product_id" => $product_id
        ]);
        exit;
    }
    
    // Calcular tempo de entrega baseado no CEP do cliente (se disponível)
    $cep_cliente = $_GET["cep"] ?? null;
    $tempo_entrega = "Entrega Expressa";
    $entrega_expressa = true;
    
    if ($cep_cliente) {
        // Lógica de cálculo baseada na zona
        $prefixo_cep = substr(preg_replace("/\D/", "", $cep_cliente), 0, 5);
        
        // Verificar se é capital (6h), metropolitana (24h) ou interior (2-5 dias)
        // Simplificado - na prática usar tabela de zonas
        $tempo_entrega = "Entrega em até 6h";
    }
    
    echo json_encode([
        "is_full" => true,
        "product_id" => $product_id,
        "cd_nome" => $info["cd_nome"] ?? "CD OneMundo",
        "cd_cidade" => $info["cd_cidade"] ?? "",
        "cd_estado" => $info["cd_estado"] ?? "",
        "tempo_entrega" => $tempo_entrega,
        "entrega_expressa" => $entrega_expressa,
        "status" => $info["status_full"]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    error_log("[produto-info] Erro: " . $e->getMessage());
    echo json_encode(["erro" => "Erro interno do servidor"]);
}
