<?php
require_once __DIR__ . '/config/database.php';
/**
 * FIX: Atualiza função getOpenCartCustomer para puxar todos os dados
 */

$file = '/var/www/html/mercado/one_ultra_config.php';
$content = file_get_contents($file);

// Backup
file_put_contents($file . '.bak', $content);

$funcNova = '    function getOpenCartCustomer() {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (!isset($_SESSION["customer_id"]) || $_SESSION["customer_id"] <= 0) return null;
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ? AND status = '1'");
            $stmt->execute([$_SESSION["customer_id"]]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$c) return null;
            
            // Endereco
            $stmtEnd = $pdo->prepare("SELECT a.*, z.name as zona_nome FROM oc_address a LEFT JOIN oc_zone z ON a.zone_id = z.zone_id WHERE a.customer_id = ? ORDER BY a.address_id DESC LIMIT 1");
            $stmtEnd->execute([$c["customer_id"]]);
            $endereco = $stmtEnd->fetch(PDO::FETCH_ASSOC);
            
            // Apelido
            $apelido = null;
            $stmtAp = $pdo->prepare("SELECT valor FROM om_one_memoria_pessoal WHERE customer_id = ? AND chave = \'apelido\' LIMIT 1");
            $stmtAp->execute([$c["customer_id"]]);
            $rowAp = $stmtAp->fetch(PDO::FETCH_ASSOC);
            if ($rowAp) $apelido = $rowAp["valor"];
            
            // Pedidos
            $stmtPed = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total),0) as valor FROM oc_order WHERE customer_id = ? AND order_status_id > 0");
            $stmtPed->execute([$c["customer_id"]]);
            $pedidos = $stmtPed->fetch(PDO::FETCH_ASSOC);
            
            // Memorias
            $memorias = [];
            $stmtMem = $pdo->prepare("SELECT chave, valor FROM om_one_memoria_pessoal WHERE customer_id = ?");
            $stmtMem->execute([$c["customer_id"]]);
            while ($mem = $stmtMem->fetch(PDO::FETCH_ASSOC)) {
                $memorias[$mem["chave"]] = $mem["valor"];
            }
            
            return [
                "id" => (int)$c["customer_id"],
                "nome" => $c["firstname"],
                "sobrenome" => $c["lastname"] ?? "",
                "nome_completo" => trim(($c["firstname"] ?? "") . " " . ($c["lastname"] ?? "")),
                "apelido" => $apelido,
                "email" => $c["email"],
                "telefone" => $c["telephone"] ?? "",
                "logado" => true,
                "endereco" => $endereco ? [
                    "rua" => $endereco["address_1"] ?? "",
                    "complemento" => $endereco["address_2"] ?? "",
                    "cidade" => $endereco["city"] ?? "",
                    "estado" => $endereco["zona_nome"] ?? "",
                    "cep" => $endereco["postcode"] ?? ""
                ] : null,
                "total_pedidos" => (int)($pedidos["total"] ?? 0),
                "total_gasto" => (float)($pedidos["valor"] ?? 0),
                "memorias" => $memorias
            ];
        } catch (Exception $e) {}
        return null;
    }';

// Substitui a função antiga
$pattern = '/function getOpenCartCustomer\(\)\s*\{.*?return null;\s*\}/s';
$content = preg_replace($pattern, $funcNova, $content);

file_put_contents($file, $content);

echo "OK! Função atualizada.\n";

// Testa
$_SESSION['customer_id'] = 1000006;
require_once $file;
$c = getOpenCartCustomer();
print_r($c);
