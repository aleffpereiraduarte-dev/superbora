<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * Support Messages API
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * GET /api/mercado/vitrine/support-messages.php?ticket_id=X
 *   Lista mensagens de um ticket
 *
 * POST /api/mercado/vitrine/support-messages.php
 *   Envia nova mensagem em um ticket existente
 *   Body: { ticket_id, message }
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICACAO - Requer cliente logado
    // ═══════════════════════════════════════════════════════════════════
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Autenticacao necessaria", 401);
    }

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Token invalido", 401);
    }

    $customerId = (int)$payload['uid'];

    // Get customer name
    $stmt = $db->prepare("SELECT name FROM om_market_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    $customerName = $customer['name'] ?? 'Cliente';

    $method = $_SERVER['REQUEST_METHOD'];

    // ═══════════════════════════════════════════════════════════════════
    // GET - Listar mensagens de um ticket
    // ═══════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $ticketId = (int)($_GET['ticket_id'] ?? 0);

        if (!$ticketId) {
            response(false, null, "ticket_id obrigatorio", 400);
        }

        // Verificar ownership
        $stmt = $db->prepare("
            SELECT id, status FROM om_support_tickets WHERE id = ? AND entidade_tipo = 'customer' AND entidade_id = ?
        ");
        $stmt->execute([$ticketId, $customerId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            response(false, null, "Ticket nao encontrado", 404);
        }

        // Buscar mensagens
        $stmt = $db->prepare("
            SELECT id, remetente_tipo, remetente_nome, mensagem, anexos, lida, created_at
            FROM om_support_messages
            WHERE ticket_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$ticketId]);
        $messages = $stmt->fetchAll();

        // Marcar mensagens do suporte como lidas
        $db->prepare("
            UPDATE om_support_messages
            SET lida = 1, lida_em = NOW()
            WHERE ticket_id = ? AND remetente_tipo != 'customer' AND lida = 0
        ")->execute([$ticketId]);

        response(true, [
            'ticket_id' => $ticketId,
            'status' => $ticket['status'],
            'messages' => array_map(function($msg) {
                $anexos = $msg['anexos'];
                if (is_string($anexos) && $anexos !== '') {
                    $anexos = json_decode($anexos, true);
                }
                return [
                    'id' => (int)$msg['id'],
                    'sender_type' => $msg['remetente_tipo'],
                    'sender_name' => $msg['remetente_nome'],
                    'message' => $msg['mensagem'],
                    'attachments' => $anexos ?: null,
                    'is_read' => (bool)$msg['lida'],
                    'created_at' => $msg['created_at']
                ];
            }, $messages)
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // POST - Enviar nova mensagem (JSON or multipart/form-data with photo)
    // ═══════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        // Support both JSON and multipart/form-data (when photo is attached)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false) {
            // FormData upload
            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $needHuman = (bool)($_POST['need_human'] ?? false);
        } else {
            // JSON body
            $input = json_decode(file_get_contents('php://input'), true);
            $ticketId = (int)($input['ticket_id'] ?? 0);
            $message = trim($input['message'] ?? '');
            $needHuman = (bool)($input['need_human'] ?? false);
        }

        if (!$ticketId) {
            response(false, null, "ticket_id obrigatorio", 400);
        }

        // Allow empty message if photo is provided
        $hasPhoto = !empty($_FILES['photo']['tmp_name']);
        if (empty($message) && !$hasPhoto) {
            response(false, null, "Mensagem ou foto obrigatoria", 400);
        }
        if (!empty($message) && strlen($message) < 2 && !$hasPhoto) {
            response(false, null, "Mensagem muito curta", 400);
        }

        // Handle photo upload
        $anexos = null;
        if ($hasPhoto) {
            $photo = $_FILES['photo'];

            // Validate file size (max 5MB)
            if ($photo['size'] > 5 * 1024 * 1024) {
                response(false, null, "Foto muito grande. Maximo 5MB.", 400);
            }

            // Validate MIME type
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($photo['tmp_name']);
            if (!in_array($detectedMime, $allowedMimes)) {
                response(false, null, "Tipo de arquivo nao permitido. Use JPEG, PNG, WebP ou GIF.", 400);
            }

            // Create upload directory if it doesn't exist
            $uploadDir = dirname(__DIR__, 3) . '/uploads/support';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $ext = match ($detectedMime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'jpg',
            };
            $filename = 'ticket_' . $ticketId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $filepath = $uploadDir . '/' . $filename;

            if (!move_uploaded_file($photo['tmp_name'], $filepath)) {
                response(false, null, "Erro ao salvar a foto. Tente novamente.", 500);
            }

            // Build attachment URL (relative to web root)
            $attachmentUrl = '/uploads/support/' . $filename;
            $anexos = json_encode([
                ['url' => $attachmentUrl, 'type' => $detectedMime, 'filename' => $filename]
            ]);
        }

        // Verificar ownership e status
        $stmt = $db->prepare("
            SELECT id, status, assunto FROM om_support_tickets WHERE id = ? AND entidade_tipo = 'customer' AND entidade_id = ?
        ");
        $stmt->execute([$ticketId, $customerId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            response(false, null, "Ticket nao encontrado", 404);
        }

        if ($ticket['status'] === 'closed') {
            response(false, null, "Este ticket esta fechado. Abra um novo ticket.", 400);
        }

        // Use message text, or a placeholder if only photo
        $messageText = !empty($message) ? $message : '[Foto]';

        // Inserir mensagem do cliente (with optional attachment)
        $stmt = $db->prepare("
            INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_id, remetente_nome, mensagem, anexos, lida, created_at)
            VALUES (?, 'customer', ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$ticketId, $customerId, $customerName, $messageText, $anexos]);
        $messageId = (int)$db->lastInsertId();

        // Se cliente pediu atendimento humano ou disse que bot nao ajudou
        if ($needHuman || isNeedingHuman($message)) {
            // Atualizar status para open (aguardando suporte humano)
            $db->prepare("UPDATE om_support_tickets SET status = 'open' WHERE id = ?")->execute([$ticketId]);

            // Adicionar mensagem de transicao
            $botMessage = "Entendi! Estou encaminhando sua solicitacao para nossa equipe de suporte. Um atendente ira responder em breve. Nosso horario de atendimento e de segunda a sexta das 8h as 22h, e aos finais de semana das 9h as 20h.";

            $stmt = $db->prepare("
                INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_nome, mensagem, lida, created_at)
                VALUES (?, 'bot', 'Assistente Virtual', ?, 0, NOW())
            ");
            $stmt->execute([$ticketId, $botMessage]);

            response(true, [
                'message_id' => $messageId,
                'escalated' => true,
                'bot_response' => $botMessage,
                'attachment_url' => $anexos ? json_decode($anexos, true)[0]['url'] ?? null : null,
            ]);
        }

        // Tentar resposta do bot (skip bot if only photo was sent)
        $botResponse = !empty($message) ? getBotResponse($db, $message) : null;

        if ($botResponse) {
            $stmt = $db->prepare("
                INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_nome, mensagem, lida, created_at)
                VALUES (?, 'bot', 'Assistente Virtual', ?, 0, NOW())
            ");
            $stmt->execute([$ticketId, $botResponse['answer']]);

            // Atualizar status para waiting
            $db->prepare("UPDATE om_support_tickets SET status = 'waiting' WHERE id = ?")->execute([$ticketId]);

            response(true, [
                'message_id' => $messageId,
                'bot_response' => $botResponse['answer'],
                'faq_matched' => $botResponse['question'],
                'attachment_url' => $anexos ? json_decode($anexos, true)[0]['url'] ?? null : null,
            ]);
        }

        // Nenhuma resposta do bot, escalar para humano
        $db->prepare("UPDATE om_support_tickets SET status = 'open' WHERE id = ?")->execute([$ticketId]);

        $escalationMessage = "Nao encontrei uma resposta automatica para sua duvida. Ja encaminhei para nossa equipe de suporte que respondera em breve!";

        $stmt = $db->prepare("
            INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_nome, mensagem, lida, created_at)
            VALUES (?, 'bot', 'Assistente Virtual', ?, 0, NOW())
        ");
        $stmt->execute([$ticketId, $escalationMessage]);

        response(true, [
            'message_id' => $messageId,
            'escalated' => true,
            'bot_response' => $escalationMessage,
            'attachment_url' => $anexos ? json_decode($anexos, true)[0]['url'] ?? null : null,
        ]);
    }

    response(false, null, "Metodo nao suportado", 405);

} catch (Exception $e) {
    error_log("[vitrine/support-messages] Erro: " . $e->getMessage());
    response(false, null, "Erro interno do servidor", 500);
}

// ═══════════════════════════════════════════════════════════════════
// FUNCOES AUXILIARES
// ═══════════════════════════════════════════════════════════════════

/**
 * Detecta se cliente quer falar com humano
 */
function isNeedingHuman(string $message): bool {
    $message = mb_strtolower($message);

    $humanTriggers = [
        'atendente',
        'humano',
        'pessoa',
        'falar com alguem',
        'suporte humano',
        'nao ajudou',
        'nao resolveu',
        'quero falar',
        'ligar',
        'telefone',
        'nao entendeu',
        'bot nao',
        'robo nao'
    ];

    foreach ($humanTriggers as $trigger) {
        if (strpos($message, $trigger) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Tenta encontrar resposta do FAQ
 */
function getBotResponse(PDO $db, string $message): ?array {
    $message = mb_strtolower($message);

    // Ignorar mensagens muito curtas ou que sao apenas confirmacoes
    if (strlen($message) < 5 || preg_match('/^(ok|sim|nao|obrigado|entendi|beleza|valeu|certo|legal)$/i', trim($message))) {
        return null;
    }

    // Buscar FAQs — table uses Portuguese column names: pergunta, resposta, ativo, ordem
    $stmt = $db->prepare("
        SELECT id, pergunta, resposta, categoria
        FROM om_support_faq
        WHERE ativo = 1
        ORDER BY ordem ASC
    ");
    $stmt->execute();
    $faqs = $stmt->fetchAll();

    $bestMatch = null;
    $bestScore = 0;

    foreach ($faqs as $faq) {
        // Match against pergunta (question) words since there's no keywords column
        $faqWords = preg_split('/[\s,?!.]+/', mb_strtolower($faq['pergunta']));
        $score = 0;

        foreach ($faqWords as $kw) {
            $kw = trim($kw);
            if (mb_strlen($kw) >= 4 && strpos($message, $kw) !== false) {
                $score += mb_strlen($kw);
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $faq;
        }
    }

    // Exigir score minimo para evitar matches fracos
    if ($bestMatch && $bestScore >= 5) {
        return [
            'faq_id' => (int)$bestMatch['id'],
            'question' => $bestMatch['pergunta'],
            'answer' => $bestMatch['resposta']
        ];
    }

    return null;
}
