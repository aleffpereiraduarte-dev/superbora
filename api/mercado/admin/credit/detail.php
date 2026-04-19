<?php
/**
 * GET /admin/credit/detail.php?customer_id=123
 *
 * Returns the full history of evaluations for a customer plus the raw
 * metrics snapshot of the latest one. Used by the admin modal that opens
 * when you click a row in the dashboard.
 */

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/credit-scoring.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    om_auth()->requireAdmin();

    $customerId = (int)($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) response(false, null, 'customer_id obrigatorio', 400);

    $engine = new CreditScoringEngine($db);

    // Customer
    $stmt = $db->prepare("SELECT customer_id, name, email, phone, cpf, birth_date, created_at, foto FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Latest evaluation + history
    $latest = $engine->latestEvaluation($customerId);
    $history = $engine->history($customerId, 24);

    $raw = [];
    if ($latest && !empty($latest['raw_metrics'])) {
        $raw = is_string($latest['raw_metrics'])
            ? (json_decode($latest['raw_metrics'], true) ?: [])
            : $latest['raw_metrics'];
    }

    // Current card, if any
    $card = null;
    try {
        $stmt = $db->prepare("
            SELECT id, status, credit_limit, used_limit, card_last4, card_brand, activated_at
            FROM om_credit_cards WHERE customer_id = ?
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$customerId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $card = [
                'id'           => (int)$c['id'],
                'status'       => $c['status'],
                'credit_limit' => (float)$c['credit_limit'],
                'used_limit'   => (float)$c['used_limit'],
                'last4'        => $c['card_last4'],
                'brand'        => $c['card_brand'],
                'activated_at' => $c['activated_at'],
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    response(true, [
        'customer' => $customer ? [
            'customer_id' => (int)$customer['customer_id'],
            'name'        => $customer['name'],
            'email'       => $customer['email'],
            'phone'       => $customer['phone'],
            'cpf'         => $customer['cpf'],
            'birth_date'  => $customer['birth_date'],
            'created_at'  => $customer['created_at'],
            'photo'       => $customer['foto'],
        ] : null,
        'card'   => $card,
        'latest' => $latest ? [
            'id'                 => (int)$latest['id'],
            'evaluated_at'       => $latest['evaluated_at'],
            'overall_score'      => (int)$latest['overall_score'],
            'factor_scores'      => [
                'app_usage'         => (int)$latest['app_usage_score'],
                'purchase_behavior' => (int)$latest['purchase_behavior_score'],
                'payment_behavior'  => (int)$latest['payment_behavior_score'],
                'external_data'     => (int)$latest['external_data_score'],
                'social_signals'    => (int)$latest['social_signals_score'],
            ],
            'recommended_limit'  => (float)$latest['recommended_limit'],
            'ai_decision'        => $latest['ai_decision'],
            'ai_reasoning'       => $latest['ai_reasoning'],
            'red_flags'          => $latest['ai_red_flags'] ? json_decode($latest['ai_red_flags'], true) : [],
            'positive_signals'   => $latest['ai_positive_signals'] ? json_decode($latest['ai_positive_signals'], true) : [],
            'final_decision'     => $latest['final_decision'],
            'final_limit'        => (float)$latest['final_limit'],
            'admin_override'     => (bool)$latest['admin_override'],
            'admin_override_notes' => $latest['admin_override_notes'],
            'admin_override_at'  => $latest['admin_override_at'],
            'next_eval_at'       => $latest['next_eval_at'],
        ] : null,
        'history'      => array_map(function ($h) {
            return [
                'id'             => (int)$h['id'],
                'evaluated_at'   => $h['evaluated_at'],
                'overall_score'  => (int)$h['overall_score'],
                'final_decision' => $h['final_decision'],
                'final_limit'    => (float)$h['final_limit'],
            ];
        }, $history),
        'raw_metrics' => $raw,
    ]);
} catch (Exception $e) {
    error_log('[admin-credit-detail] ' . $e->getMessage());
    response(false, null, 'Erro ao carregar detalhes', 500);
}
