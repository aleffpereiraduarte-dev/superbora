<?php
/**
 * POST /api/mercado/pedido/avaliar.php
 *
 * CORRIGIDO:
 * - SQL Injection fixed (5 queries vulneráveis)
 * - XSS prevention
 * - Rate limiting
 * - CORS restrito
 * - Validação de duplicação
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

// CORS restrito - exact match only (no prefix/substring matching)
setCorsHeaders();

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

// Rate limiting: 10 avaliações por minuto
if (!RateLimiter::check(10, 60)) {
    exit;
}

try {
    $input = getInput();
    $db = getDB();

    // Sanitizar entrada
    $order_id = (int)($input["order_id"] ?? 0);
    $nota = (int)($input["nota"] ?? $input["rating"] ?? 5);
    $comentario = strip_tags(trim(substr($input["comentario"] ?? $input["comment"] ?? "", 0, 500)));

    if (!$order_id) {
        response(false, null, "order_id é obrigatório", 400);
    }

    if ($nota < 1 || $nota > 5) {
        response(false, null, "Nota deve ser entre 1 e 5", 400);
    }

    // Sanitizar comentário (prevenir XSS)
    $comentario = htmlspecialchars($comentario, ENT_QUOTES, 'UTF-8');

    // Autenticação do cliente
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customer_id = (int)$payload['uid'];

    // Verify customer owns this order
    $stmtOwner = $db->prepare("SELECT customer_id, status, updated_at, shopper_id, avaliacao_cliente FROM om_market_orders WHERE order_id = ?");
    $stmtOwner->execute([$order_id]);
    $pedido = $stmtOwner->fetch();

    if (!$pedido || (int)$pedido['customer_id'] !== (int)$customer_id) {
        response(false, null, "Voce nao pode avaliar este pedido", 403);
    }

    if (!in_array($pedido["status"], ["entregue", "delivered", "finalizado"])) {
        response(false, null, "Pedido ainda nao foi entregue", 400);
    }

    // Check 30-day window
    if ($pedido['updated_at'] && strtotime($pedido['updated_at']) < strtotime('-30 days')) {
        response(false, null, "Prazo para avaliar este pedido expirou (30 dias)", 400);
    }

    // Verificar se já foi avaliado
    if ($pedido["avaliacao_cliente"]) {
        response(false, null, "Este pedido já foi avaliado", 409);
    }

    // Atualizar avaliação atomicamente (WHERE avaliacao_cliente IS NULL previne race condition)
    $stmt = $db->prepare("UPDATE om_market_orders SET avaliacao_cliente = ?, comentario_cliente = ?, avaliado_em = NOW() WHERE order_id = ? AND avaliacao_cliente IS NULL");
    $stmt->execute([$nota, $comentario, $order_id]);
    if ($stmt->rowCount() === 0) {
        response(false, null, "Este pedido já foi avaliado", 409);
    }

    // Atualizar média do shopper se tiver (prepared statements)
    if ($pedido["shopper_id"]) {
        $shopper_id = (int)$pedido["shopper_id"];

        $stmt = $db->prepare("SELECT AVG(avaliacao_cliente) as media FROM om_market_orders WHERE shopper_id = ? AND avaliacao_cliente IS NOT NULL");
        $stmt->execute([$shopper_id]);
        $result = $stmt->fetch();
        $media = round($result["media"], 2);

        $stmt = $db->prepare("UPDATE om_market_shoppers SET rating = ? WHERE shopper_id = ?");
        $stmt->execute([$media, $shopper_id]);
    }

    // Log da avaliação
    error_log("Pedido avaliado: #$order_id | Nota: $nota");

    response(true, [
        "order_id" => $order_id,
        "nota" => $nota,
        "comentario" => $comentario
    ], "Avaliação enviada! Obrigado.");

} catch (Exception $e) {
    error_log("Pedido avaliar error: " . $e->getMessage());
    response(false, null, "Erro ao enviar avaliação. Tente novamente.", 500);
}
