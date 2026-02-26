<?php
/**
 * REGISTRAR FRETE NO PEDIDO
 * Chamado quando pedido é confirmado
 */

header("Content-Type: application/json; charset=utf-8");
require_once dirname(dirname(__DIR__)) . "/config.php";

$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$prefix = DB_PREFIX;

$input = json_decode(file_get_contents("php://input"), true);

$order_id = (int)($input["order_id"] ?? 0);
$product_id = (int)($input["product_id"] ?? 0);
$seller_id = (int)($input["seller_id"] ?? 0);
$customer_id = (int)($input["customer_id"] ?? 0);
$servico = $input["servico"] ?? "SEDEX";
$frete = $input["frete"] ?? [];
$membership = $input["membership"] ?? [];
$source = $input["source"] ?? "loja"; // loja ou market

if (!$order_id || !$product_id) {
    echo json_encode(["success" => false, "erro" => "Dados incompletos"]);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Determinar valores
    $frete_total = (float)($frete["preco_original"] ?? 0);
    $frete_cliente = (float)($frete["preco_final"] ?? $frete_total);
    $frete_vendedor = $frete_total - $frete_cliente; // O que OneMundo antecipou e desconta do vendedor
    $frete_gratis_usado = ($frete["frete_gratis"] ?? false) ? 1 : 0;
    
    // Inserir registro
    $stmt = $pdo->prepare("INSERT INTO {$prefix}om_frete_pedido 
        (order_id, product_id, seller_id, customer_id,
        frete_total, frete_cliente, frete_vendedor,
        membership, desconto_percent, frete_gratis_usado, entrega_numero,
        servico, prazo_correios, prazo_vendedor, prazo_total, data_estimada,
        cep_origem, cep_destino)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (order_id, product_id) DO UPDATE SET
        frete_total = EXCLUDED.frete_total,
        frete_cliente = EXCLUDED.frete_cliente,
        frete_vendedor = EXCLUDED.frete_vendedor");
    
    $entrega_numero = null;
    
    // Se usou frete grátis, registrar uso
    if ($frete_gratis_usado && $customer_id) {
        // Buscar próximo número de entrega
        $ciclo_dias = 30;
        $ciclo_inicio = date("Y-m-d"); // Simplificado
        $ciclo_fim = date("Y-m-d", strtotime("+29 days"));
        
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}om_frete_uso WHERE customer_id = ? AND ciclo_inicio >= NOW() - INTERVAL '30 days'");
        $stmt_count->execute([$customer_id]);
        $entrega_numero = (int)$stmt_count->fetchColumn() + 1;
        
        // Registrar uso (compartilhado loja + market)
        $stmt_uso = $pdo->prepare("INSERT INTO {$prefix}om_frete_uso 
            (customer_id, order_id, source, ciclo_inicio, ciclo_fim, entrega_numero, valor_frete)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_uso->execute([
            $customer_id, $order_id, $source, $ciclo_inicio, $ciclo_fim, 
            $entrega_numero, $frete_total
        ]);
    }
    
    $stmt->execute([
        $order_id, $product_id, $seller_id, $customer_id,
        $frete_total, $frete_cliente, $frete_vendedor,
        $membership["tipo"] ?? "none", $frete["desconto_percent"] ?? 0,
        $frete_gratis_usado, $entrega_numero,
        $servico, $frete["prazo_correios"] ?? 0, $frete["prazo_vendedor"] ?? 0,
        $frete["prazo_total"] ?? 0, $frete["data_estimada"] ?? null,
        $input["cep_origem"] ?? "", $input["cep_destino"] ?? ""
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        "success" => true,
        "mensagem" => "Frete registrado",
        "resumo" => [
            "frete_total" => $frete_total,
            "cliente_paga" => $frete_cliente,
            "vendedor_paga" => $frete_vendedor,
            "frete_gratis_usado" => $frete_gratis_usado
        ]
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("[frete/registrar] Erro: " . $e->getMessage());
    echo json_encode(["success" => false, "erro" => "Erro interno do servidor"]);
}
