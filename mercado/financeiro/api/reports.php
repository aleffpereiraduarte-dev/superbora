<?php
/**
 * API de Relatórios - Sistema Financeiro
 */
header("Content-Type: application/json; charset=utf-8");
session_start();

if (!isset($_SESSION["fin_user_id"])) {
    http_response_code(401);
    die(json_encode(["success" => false, "error" => "Não autenticado"]));
}

require_once dirname(__DIR__) . "/../admin/config.php";
$pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$action = $_GET["action"] ?? "";

switch ($action) {

case "dre":
    // Demonstrativo de Resultado
    $dateFrom = $_GET["date_from"] ?? date("Y-m-01");
    $dateTo = $_GET["date_to"] ?? date("Y-m-t");
    
    $data = [];
    
    // Receitas
    $data["receitas"] = $pdo->query("SELECT COALESCE(SUM(received_value), 0) FROM om_fin_receivables WHERE receipt_date BETWEEN \"$dateFrom\" AND \"$dateTo\"")->fetchColumn();
    
    // Despesas por categoria
    $stmt = $pdo->query("SELECT c.name, COALESCE(SUM(p.paid_value), 0) as total FROM om_fin_payables p LEFT JOIN om_fin_expense_categories c ON c.category_id = p.category_id WHERE p.payment_date BETWEEN \"$dateFrom\" AND \"$dateTo\" AND p.status = \"pago\" GROUP BY p.category_id, c.name ORDER BY total DESC");
    $data["despesas_por_categoria"] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Total despesas
    $data["total_despesas"] = array_sum(array_column($data["despesas_por_categoria"], "total"));
    
    // Resultado
    $data["resultado"] = $data["receitas"] - $data["total_despesas"];
    $data["margem"] = $data["receitas"] > 0 ? round(($data["resultado"] / $data["receitas"]) * 100, 2) : 0;
    
    echo json_encode(["success" => true, "dre" => $data]);
    break;

case "cashFlowReport":
    $dateFrom = $_GET["date_from"] ?? date("Y-m-01");
    $dateTo = $_GET["date_to"] ?? date("Y-m-t");
    
    $stmt = $pdo->query("
        SELECT transaction_date as date, 
               SUM(CASE WHEN transaction_type = \"entrada\" THEN amount ELSE 0 END) as entradas,
               SUM(CASE WHEN transaction_type = \"saida\" THEN amount ELSE 0 END) as saidas
        FROM om_fin_bank_transactions
        WHERE transaction_date BETWEEN \"$dateFrom\" AND \"$dateTo\"
        GROUP BY transaction_date
        ORDER BY transaction_date
    ");
    
    echo json_encode(["success" => true, "cash_flow" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case "supplierRanking":
    $dateFrom = $_GET["date_from"] ?? date("Y-01-01");
    $dateTo = $_GET["date_to"] ?? date("Y-12-31");
    $limit = (int)($_GET["limit"] ?? 20);
    
    $stmt = $pdo->query("
        SELECT s.company_name, s.cpf_cnpj, COUNT(p.payable_id) as qtd_pagamentos, SUM(p.paid_value) as total_pago
        FROM om_fin_payables p
        JOIN om_fin_suppliers s ON s.supplier_id = p.supplier_id
        WHERE p.payment_date BETWEEN \"$dateFrom\" AND \"$dateTo\" AND p.status = \"pago\"
        GROUP BY p.supplier_id, s.company_name, s.cpf_cnpj
        ORDER BY total_pago DESC
        LIMIT $limit
    ");
    
    echo json_encode(["success" => true, "ranking" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case "costCenterReport":
    $dateFrom = $_GET["date_from"] ?? date("Y-m-01");
    $dateTo = $_GET["date_to"] ?? date("Y-m-t");
    
    $stmt = $pdo->query("
        SELECT cc.code, cc.name, cc.type,
               COALESCE(SUM(p.paid_value), 0) as despesas,
               COALESCE((SELECT SUM(r.received_value) FROM om_fin_receivables r WHERE r.cost_center_id = cc.cost_center_id AND r.receipt_date BETWEEN \"$dateFrom\" AND \"$dateTo\"), 0) as receitas
        FROM om_fin_cost_centers cc
        LEFT JOIN om_fin_payables p ON p.cost_center_id = cc.cost_center_id AND p.payment_date BETWEEN \"$dateFrom\" AND \"$dateTo\" AND p.status = \"pago\"
        WHERE cc.is_active = 1
        GROUP BY cc.cost_center_id, cc.code, cc.name, cc.type
        ORDER BY despesas DESC
    ");
    
    echo json_encode(["success" => true, "report" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

default:
    echo json_encode(["success" => false, "error" => "Ação inválida"]);
}
?>