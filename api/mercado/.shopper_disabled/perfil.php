<?php
/**
 * GET/PUT /api/mercado/shopper/perfil.php
 * GET: Retorna perfil completo do shopper
 * PUT: Atualiza campos permitidos do perfil
 */
require_once __DIR__ . "/../config/auth.php";
try {
    $db = getDB(); $auth = requireShopperAuth(); $shopper_id = $auth["uid"];
    $method = $_SERVER["REQUEST_METHOD"];
    if ($method === "GET") {
        $stmt = $db->prepare("SELECT shopper_id, name, email, phone, cpf, photo, status, rating, is_online, disponivel, saldo, last_login, data_aprovacao, pix_key, banco_nome, banco_agencia, banco_numero_conta, created_at FROM om_market_shoppers WHERE shopper_id = ?");
        $stmt->execute([$shopper_id]); $shopper = $stmt->fetch();
        if (!$shopper) { response(false, null, "Shopper nao encontrado", 404); }
        $stmt = $db->prepare("SELECT saldo_disponivel, saldo_pendente FROM om_shopper_saldo WHERE shopper_id = ?"); $stmt->execute([$shopper_id]); $saldo = $stmt->fetch();
        $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE shopper_id = ? AND status = 'entregue'"); $stmt->execute([$shopper_id]); $total_entregas = (int)$stmt->fetchColumn();
        $cpf = $shopper["cpf"] ?? ""; $cpf_masked = strlen($cpf) >= 11 ? substr($cpf, 0, 3) . ".***.***-" . substr($cpf, -2) : $cpf;
        response(true, ["shopper_id" => (int)$shopper["shopper_id"], "name" => $shopper["name"], "email" => $shopper["email"], "phone" => $shopper["phone"], "cpf" => $cpf_masked, "photo" => $shopper["photo"], "status" => (int)$shopper["status"], "rating" => floatval($shopper["rating"] ?? 5.0), "is_online" => (bool)($shopper["is_online"] ?? $shopper["disponivel"] ?? false), "total_entregas" => $total_entregas, "saldo_disponivel" => floatval($saldo["saldo_disponivel"] ?? 0), "saldo_pendente" => floatval($saldo["saldo_pendente"] ?? 0), "last_login" => $shopper["last_login"], "data_aprovacao" => $shopper["data_aprovacao"], "dados_bancarios" => ["pix_key" => $shopper["pix_key"], "bank_name" => $shopper["banco_nome"], "bank_agency" => $shopper["banco_agencia"], "bank_account" => $shopper["banco_numero_conta"]], "created_at" => $shopper["created_at"]], "Perfil carregado");
    } elseif ($method === "PUT") {
        $input = getInput();
        // Whitelist of allowed fields: input_field => db_column
        // Only these fields can be updated - prevents SQL injection via field names
        $allowed_fields = [
            "name" => "name",
            "phone" => "phone",
            "photo" => "photo",
            "pix_key" => "pix_key",
            "bank_name" => "banco_nome",
            "bank_agency" => "banco_agencia",
            "bank_account" => "banco_numero_conta"
        ];
        $updates = []; $params = [];
        foreach ($allowed_fields as $input_field => $db_field) {
            if (isset($input[$input_field])) {
                $value = trim($input[$input_field]);
                if ($input_field === "name" && strlen($value) < 2) { response(false, null, "Nome deve ter pelo menos 2 caracteres", 400); }
                if ($input_field === "phone" && strlen($value) < 10) { response(false, null, "Telefone invalido", 400); }
                // Safe: $db_field comes from hardcoded whitelist, not user input
                $updates[] = "$db_field = ?"; $params[] = $value;
            }
        }
        if (empty($updates)) { response(false, null, "Nenhum campo para atualizar", 400); }
        // Using prepared statement with parameter binding for all values
        $params[] = $shopper_id; $sql = "UPDATE om_market_shoppers SET " . implode(", ", $updates) . " WHERE shopper_id = ?"; $stmt = $db->prepare($sql); $stmt->execute($params);
        logAudit("update", "shopper", $shopper_id, null, $input, "Perfil atualizado pelo shopper #$shopper_id");
        $stmt = $db->prepare("SELECT shopper_id, name, email, phone, photo, pix_key, banco_nome, banco_agencia, banco_numero_conta FROM om_market_shoppers WHERE shopper_id = ?"); $stmt->execute([$shopper_id]); $updated = $stmt->fetch();
        response(true, ["shopper_id" => (int)$updated["shopper_id"], "name" => $updated["name"], "email" => $updated["email"], "phone" => $updated["phone"], "photo" => $updated["photo"], "dados_bancarios" => ["pix_key" => $updated["pix_key"], "bank_name" => $updated["banco_nome"], "bank_agency" => $updated["banco_agencia"], "bank_account" => $updated["banco_numero_conta"]]], "Perfil atualizado com sucesso");
    } else { response(false, null, "Metodo nao permitido", 405); }
} catch (Exception $e) { error_log("[shopper/perfil] Erro: " . $e->getMessage()); response(false, null, "Erro ao processar perfil", 500); }
