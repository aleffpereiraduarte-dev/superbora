<?php
/**
 * GET /api/mercado/partner/review-response.php?review_id=X - Ver resposta
 * POST /api/mercado/partner/review-response.php - Responder avaliacao (com IA opcional)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $reviewId = intval($_GET['review_id'] ?? 0);

        if ($reviewId > 0) {
            // Get specific review and response
            $stmt = $db->prepare("
                SELECT r.*, rr.response, rr.ai_generated, rr.created_at as response_date
                FROM om_market_reviews r
                LEFT JOIN om_review_responses rr ON rr.review_id = r.id
                WHERE r.id = ? AND r.partner_id = ?
            ");
            $stmt->execute([$reviewId, $partnerId]);
            $review = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$review) response(false, null, "Avaliacao nao encontrada", 404);
            response(true, $review);
        }

        // List reviews without response
        $stmt = $db->prepare("
            SELECT r.*, COALESCE(u.name, r.customer_name) as customer_display_name,
                   (SELECT COUNT(*) FROM om_review_responses rr WHERE rr.review_id = r.id) as has_response
            FROM om_market_reviews r
            LEFT JOIN om_customers u ON u.customer_id = r.customer_id
            WHERE r.partner_id = ?
            ORDER BY r.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$partnerId]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Stats
        $unanswered = array_filter($reviews, fn($r) => $r['has_response'] == 0);

        response(true, [
            'reviews' => $reviews,
            'unanswered_count' => count($unanswered),
            'negative_unanswered' => count(array_filter($unanswered, fn($r) => ($r['rating'] ?? 5) <= 3)),
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $reviewId = intval($input['review_id'] ?? 0);
        $action = $input['action'] ?? 'respond';

        if (!$reviewId) response(false, null, "ID da avaliacao obrigatorio", 400);

        // Verify review belongs to partner
        $stmt = $db->prepare("SELECT * FROM om_market_reviews WHERE id = ? AND partner_id = ?");
        $stmt->execute([$reviewId, $partnerId]);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$review) response(false, null, "Avaliacao nao encontrada", 404);

        if ($action === 'respond') {
            $response = strip_tags(trim($input['response'] ?? ''));
            $aiGenerated = (int)($input['ai_generated'] ?? 0);

            if (empty($response)) response(false, null, "Resposta obrigatoria", 400);

            // Check if already responded
            $stmt = $db->prepare("SELECT id FROM om_review_responses WHERE review_id = ?");
            $stmt->execute([$reviewId]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update
                $stmt = $db->prepare("UPDATE om_review_responses SET response = ?, ai_generated = ? WHERE review_id = ?");
                $stmt->execute([$response, $aiGenerated, $reviewId]);
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO om_review_responses (review_id, partner_id, response, ai_generated) VALUES (?, ?, ?, ?)");
                $stmt->execute([$reviewId, $partnerId, $response, $aiGenerated]);
            }

            response(true, null, "Resposta salva!");
        }

        if ($action === 'generate_ai') {
            // Generate AI response suggestion
            $rating = (int)($review['rating'] ?? 5);
            $comment = $review['comment'] ?? $review['comentario'] ?? '';

            // Get partner name
            $stmt = $db->prepare("SELECT name, trade_name FROM om_market_partners WHERE partner_id = ?");
            $stmt->execute([$partnerId]);
            $partner = $stmt->fetch();
            $partnerName = $partner['trade_name'] ?? $partner['name'] ?? 'Nosso Restaurante';

            // Generate contextual response based on rating and comment
            if ($rating >= 4) {
                $suggestions = [
                    "Obrigado pela avaliacao! Ficamos muito felizes que voce teve uma boa experiencia conosco. Esperamos ve-lo(a) novamente em breve! - Equipe {$partnerName}",
                    "Muito obrigado pelo feedback positivo! E um prazer atende-lo(a). Volte sempre! - {$partnerName}",
                    "Agradecemos sua avaliacao! Trabalhamos duro para oferecer o melhor e seu reconhecimento nos motiva. Ate a proxima! - Equipe {$partnerName}",
                ];
            } elseif ($rating == 3) {
                $suggestions = [
                    "Obrigado pelo seu feedback! Lamentamos que sua experiencia nao tenha sido perfeita. Poderia nos contar mais sobre o que podemos melhorar? Queremos garantir sua satisfacao na proxima visita. - {$partnerName}",
                    "Agradecemos sua avaliacao. Estamos sempre buscando melhorar e seu feedback e muito importante. Entre em contato conosco para que possamos resolver qualquer questao. - Equipe {$partnerName}",
                ];
            } else {
                $suggestions = [
                    "Lamentamos muito que sua experiencia nao tenha sido satisfatoria. Isso nao reflete nosso padrao de qualidade. Gostaríamos de entender melhor o ocorrido e fazer as devidas correcoes. Por favor, entre em contato conosco. - {$partnerName}",
                    "Pedimos sinceras desculpas pelo ocorrido. Seu feedback e muito importante para melhorarmos. Gostaríamos de oferecer uma nova experiencia. Entre em contato conosco para resolvermos essa situacao. - Equipe {$partnerName}",
                ];
            }

            // Add personalization if there's a comment
            $suggestion = $suggestions[array_rand($suggestions)];

            response(true, [
                'suggested_response' => $suggestion,
                'rating' => $rating,
                'has_comment' => !empty($comment),
            ]);
        }

        response(false, null, "Acao invalida", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/review-response] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
