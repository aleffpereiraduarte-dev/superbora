<?php
/**
 * WhatsApp Rating System — SuperBora
 *
 * Handles post-delivery rating requests via WhatsApp.
 * After delivery, proactively asks customer to rate 1-5,
 * then saves review to om_market_reviews.
 *
 * Integration points:
 *   - confirmar-entrega.php sets rating_pending flag on order
 *   - whatsapp-ai.php calls these functions before Claude AI
 *   - zapi-whatsapp.php has the whatsappAskRating() template
 */

require_once __DIR__ . '/zapi-whatsapp.php';

/**
 * Check if customer has a recently delivered order that hasn't been rated yet.
 * If found, send the rating request via WhatsApp and return the order info.
 *
 * Called from whatsapp-ai.php when a customer messages and we detect they
 * might have an unrated delivered order (within last 2 hours).
 *
 * @return array|null Order data if rating request was sent, null otherwise
 */
function checkAndSendRatingRequest(PDO $db, string $phone, ?int $customerId): ?array
{
    if (!$customerId) {
        return null;
    }

    try {
        // Find delivered orders in the last 2 hours that have no review yet
        $stmt = $db->prepare("
            SELECT o.order_id, o.order_number, o.partner_id, p.name as partner_name,
                   o.delivery_confirmed_at
            FROM om_market_orders o
            INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.customer_id = ?
              AND o.status IN ('entregue', 'retirado')
              AND o.delivery_confirmed_at >= NOW() - INTERVAL '2 hours'
              AND NOT EXISTS (
                  SELECT 1 FROM om_market_reviews r
                  WHERE r.order_id = o.order_id AND r.customer_id = o.customer_id
              )
            ORDER BY o.delivery_confirmed_at DESC
            LIMIT 1
        ");
        $stmt->execute([$customerId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        // Send rating request
        $result = whatsappAskRating($phone, $order['order_number'], $order['partner_name']);

        if ($result['success']) {
            error_log("[whatsapp-rating] Rating request sent for order #{$order['order_number']} to phone ending " . substr($phone, -4));
        } else {
            error_log("[whatsapp-rating] Failed to send rating request for order #{$order['order_number']}: " . ($result['message'] ?? 'unknown'));
        }

        return $order;

    } catch (\Exception $e) {
        error_log("[whatsapp-rating] Error checking for unrated orders: " . $e->getMessage());
        return null;
    }
}

/**
 * Try to interpret a customer message as a rating response.
 *
 * Detects:
 *   - Numeric rating: "5", "4", "3", "2", "1"
 *   - Star emojis: counting them
 *   - Text with embedded number: "nota 4", "dou 5"
 *
 * @return int|null Rating 1-5 if detected, null otherwise
 */
function extractRatingFromMessage(string $message): ?int
{
    $msg = trim($message);

    // Exact single digit 1-5
    if (preg_match('/^[1-5]$/', $msg)) {
        return (int)$msg;
    }

    // Count star emojis
    $starCount = substr_count($msg, "\u{2B50}") + substr_count($msg, "\u{2605}");
    if ($starCount >= 1 && $starCount <= 5) {
        return $starCount;
    }

    // "nota X" or "dou X" or "X estrelas" patterns
    if (preg_match('/(?:nota|dou|daria|minha nota|avaliacao|avalio)\s*:?\s*([1-5])/iu', $msg, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/([1-5])\s*(?:estrelas?|stars?)/iu', $msg, $m)) {
        return (int)$m[1];
    }

    return null;
}

/**
 * Process a rating response from a customer.
 *
 * Saves the review to om_market_reviews. If the message is just a number (1-5),
 * it's saved as rating only. If it's text, it could be a comment — if there's
 * already a pending rating context, attach the comment.
 *
 * @param PDO    $db
 * @param string $phone       Customer phone
 * @param int    $customerId  Customer ID
 * @param string $message     The raw message
 * @param array  $ratingContext  Context with 'rating_requested_for_order' info
 * @return array|null ['success' => bool, 'response' => string] or null if not a rating
 */
function processRatingResponse(PDO $db, string $phone, int $customerId, string $message, array $ratingContext): ?array
{
    $orderId = $ratingContext['rating_requested_for_order'] ?? null;
    $pendingRating = $ratingContext['rating_pending_value'] ?? null;

    // If no order is awaiting rating, nothing to process
    if (!$orderId) {
        return null;
    }

    $rating = extractRatingFromMessage($message);
    $comment = null;

    if ($rating !== null) {
        // Pure rating detected — message might also have a comment after the number
        $msgClean = trim(preg_replace('/^[1-5]\s*/', '', trim($message)));
        if (mb_strlen($msgClean) > 2) {
            $comment = $msgClean;
        }
    } elseif ($pendingRating) {
        // We already have a rating, this message is a follow-up comment
        $rating = (int)$pendingRating;
        $comment = trim($message);
    } else {
        // No rating detected and no pending rating — not a rating response
        return null;
    }

    try {
        // Verify order belongs to customer and hasn't been reviewed yet
        $stmt = $db->prepare("
            SELECT o.order_id, o.order_number, o.partner_id, p.name as partner_name,
                   c.name as customer_name
            FROM om_market_orders o
            INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
            INNER JOIN om_market_customers c ON o.customer_id = c.customer_id
            WHERE o.order_id = ? AND o.customer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            error_log("[whatsapp-rating] Order #{$orderId} not found for customer #{$customerId}");
            return null;
        }

        // Check for duplicate review
        $stmtCheck = $db->prepare("
            SELECT id FROM om_market_reviews
            WHERE order_id = ? AND customer_id = ?
            LIMIT 1
        ");
        $stmtCheck->execute([$orderId, $customerId]);
        if ($stmtCheck->fetch()) {
            return [
                'success' => true,
                'response' => "Voce ja avaliou esse pedido! Obrigado pelo feedback. 😊",
                'clear_rating_context' => true,
            ];
        }

        // Save the review
        $stmtInsert = $db->prepare("
            INSERT INTO om_market_reviews
                (order_id, customer_id, partner_id, customer_name, rating, comment, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtInsert->execute([
            $orderId,
            $customerId,
            (int)$order['partner_id'],
            $order['customer_name'] ?? 'Cliente',
            $rating,
            $comment,
        ]);

        error_log("[whatsapp-rating] Review saved: order #{$order['order_number']}, rating={$rating}, customer #{$customerId}");

        // Build thank you response based on rating
        $stars = str_repeat("\u{2B50}", $rating);
        if ($rating >= 4) {
            $response = "Obrigado pela avaliacao! {$stars}\n\n"
                      . "Ficamos felizes que voce gostou do pedido da *{$order['partner_name']}*! ð";
        } elseif ($rating === 3) {
            $response = "Obrigado pela avaliacao! {$stars}\n\n"
                      . "Vamos trabalhar para melhorar sua experiencia com a *{$order['partner_name']}*.\n"
                      . "Seu feedback e muito importante!";
        } else {
            $response = "Obrigado pela avaliacao. {$stars}\n\n"
                      . "Sentimos muito que sua experiencia nao foi boa.\n"
                      . "Seu feedback foi registrado e vamos trabalhar para melhorar.\n"
                      . "Se precisar de ajuda, estamos aqui!";
        }

        // Check if order has items we can ask about
        $hasItems = false;
        try {
            $itemStmt = $db->prepare("
                SELECT COUNT(*) as cnt FROM om_market_order_items WHERE order_id = ?
            ");
            $itemStmt->execute([$orderId]);
            $hasItems = ((int)$itemStmt->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
        } catch (\Exception $e) { /* ignore */ }

        if ($hasItems) {
            $response .= "\n\nE os itens do pedido? Algum que voce adorou â¤ï¸ ou que poderia melhorar? (pode pular digitando *nao*)";
        }

        return [
            'success' => true,
            'response' => $response,
            'clear_rating_context' => true,
            'item_rating_order_id' => $hasItems ? $orderId : null,
        ];

    } catch (\Exception $e) {
        error_log("[whatsapp-rating] Error saving review: " . $e->getMessage());
        return [
            'success' => false,
            'response' => "Desculpe, houve um erro ao salvar sua avaliacao. Tente novamente.",
            'clear_rating_context' => false,
        ];
    }
}


/**
 * Process item-level rating/feedback response from a customer.
 *
 * After the store-level rating, we ask about specific items. The customer can:
 *   - Name items they loved or disliked with optional ratings
 *   - Skip with "nao", "pular", "nada"
 *   - Give general item feedback as free text
 *
 * Stores feedback in sb_reviews with product_id when possible.
 *
 * @return array|null Response array or null if not an item rating
 */
function processItemRatingResponse(PDO $db, string $phone, int $customerId, string $message, array $context): ?array
{
    $orderId = $context['item_rating_order_id'] ?? null;
    if (!$orderId) {
        return null;
    }

    $msg = mb_strtolower(trim($message));

    // Skip responses
    if (in_array($msg, ['nao', 'nÃ£o', 'pular', 'nada', 'n', 'skip', 'sem comentarios', 'ta bom', 'tudo certo', 'tudo bem', 'nenhum'])) {
        return [
            'success' => true,
            'response' => "Tudo bem! Obrigado pelo seu tempo. ð\n\nPrecisa de mais alguma coisa?",
            'clear_item_rating' => true,
        ];
    }

    try {
        // Get order info
        $orderStmt = $db->prepare("
            SELECT o.order_id, o.partner_id, o.order_number
            FROM om_market_orders o
            WHERE o.order_id = ? AND o.customer_id = ?
        ");
        $orderStmt->execute([$orderId, $customerId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return [
                'success' => false,
                'response' => "Nao encontrei o pedido. Precisa de mais alguma coisa?",
                'clear_item_rating' => true,
            ];
        }

        // Get order items for matching
        $itemsStmt = $db->prepare("
            SELECT oi.product_id, oi.name
            FROM om_market_order_items oi
            WHERE oi.order_id = ?
            ORDER BY oi.id
        ");
        $itemsStmt->execute([$orderId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Try to extract item rating from message
        $rating = extractRatingFromMessage($message);
        $matchedProductId = null;
        $matchedProductName = null;

        // Try to match mentioned item names against order items
        foreach ($orderItems as $item) {
            $itemNameLower = mb_strtolower($item['name']);
            // Check if the item name (or significant part) appears in the message
            $words = explode(' ', $itemNameLower);
            $significantWords = array_filter($words, function($w) {
                return mb_strlen($w) >= 4;
            });

            foreach ($significantWords as $word) {
                if (mb_strpos($msg, $word) !== false) {
                    $matchedProductId = (int)$item['product_id'];
                    $matchedProductName = $item['name'];
                    break 2;
                }
            }
        }

        // Determine sentiment from message
        $sentiment = 'neutral';
        if (preg_match('/adorei|amei|otimo|excelente|perfeito|maravilh|delici|incr[iÃ­]vel|top|sensacional|melhor/ui', $msg)) {
            $sentiment = 'positive';
            if (!$rating) $rating = 5;
        } elseif (preg_match('/ruim|horrivel|pessimo|frio|demorou|errado|faltou|quebrado|estragado|podre|vencido|nojento/ui', $msg)) {
            $sentiment = 'negative';
            if (!$rating) $rating = 2;
        } elseif (preg_match('/bom|gostei|legal|ok|razoavel|normal|medio/ui', $msg)) {
            $sentiment = 'positive';
            if (!$rating) $rating = 4;
        }

        // Default rating if none extracted
        if (!$rating) {
            $rating = 3;
        }

        // Save to sb_reviews
        $stmtInsert = $db->prepare("
            INSERT INTO sb_reviews
                (user_id, store_id, product_id, order_id, rating, title, body, verified_purchase, status, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, true, 'published', NOW())
        ");

        $title = $matchedProductName
            ? ($sentiment === 'positive' ? "Adorei: {$matchedProductName}" : ($sentiment === 'negative' ? "Pode melhorar: {$matchedProductName}" : $matchedProductName))
            : ($sentiment === 'positive' ? 'Itens otimos!' : ($sentiment === 'negative' ? 'Itens podem melhorar' : 'Feedback dos itens'));

        $stmtInsert->execute([
            $customerId,
            (int)$order['partner_id'],
            $matchedProductId,
            $orderId,
            $rating,
            $title,
            trim($message),
        ]);

        error_log("[whatsapp-rating] Item review saved: order #{$order['order_number']}, product_id=" . ($matchedProductId ?? 'null') . ", rating={$rating}, sentiment={$sentiment}");

        // Build response
        if ($matchedProductName) {
            if ($sentiment === 'positive') {
                $response = "Anotado! Que bom que voce curtiu o *{$matchedProductName}*! ð\n\nVou passar esse feedback pra loja. Obrigado!";
            } else {
                $response = "Entendi, vou registrar seu feedback sobre o *{$matchedProductName}*. Vamos passar pra loja pra melhorar! ð\n\nObrigado por nos contar!";
            }
        } else {
            $response = "Obrigado pelo feedback sobre os itens! Vou registrar e passar pra loja. ð\n\nPrecisa de mais alguma coisa?";
        }

        return [
            'success' => true,
            'response' => $response,
            'clear_item_rating' => true,
        ];

    } catch (\Exception $e) {
        error_log("[whatsapp-rating] Error saving item review: " . $e->getMessage());
        return [
            'success' => true,
            'response' => "Obrigado pelo feedback! Precisa de mais alguma coisa?",
            'clear_item_rating' => true,
        ];
    }
}
