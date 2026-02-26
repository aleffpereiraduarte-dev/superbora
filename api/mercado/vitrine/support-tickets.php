<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * Customer Support Tickets API
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * GET /api/mercado/vitrine/support-tickets.php
 *   Lista tickets do cliente autenticado
 *   Query params: ?status=open|waiting|resolved|closed
 *
 * GET /api/mercado/vitrine/support-tickets.php?id=X
 *   Retorna um ticket especifico com mensagens
 *
 * POST /api/mercado/vitrine/support-tickets.php
 *   Cria novo ticket de suporte
 *   Body: { subject, message, order_id?, category? }
 *
 * PUT /api/mercado/vitrine/support-tickets.php
 *   Atualiza status do ticket (fecha ticket)
 *   Body: { id, status }
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
    // GET - Listar tickets ou detalhe de um ticket
    // ═══════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $ticketId = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if ($ticketId) {
            // Buscar ticket especifico
            $stmt = $db->prepare("
                SELECT t.id, t.assunto, t.status, t.categoria,
                       t.referencia_id, t.referencia_tipo,
                       t.created_at, t.updated_at,
                       o.order_number
                FROM om_support_tickets t
                LEFT JOIN om_market_orders o ON o.order_id = t.referencia_id AND t.referencia_tipo = 'order'
                WHERE t.id = ? AND t.entidade_tipo = 'customer' AND t.entidade_id = ?
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

            // Marcar mensagens como lidas
            $db->prepare("
                UPDATE om_support_messages
                SET lida = 1, lida_em = NOW()
                WHERE ticket_id = ? AND remetente_tipo != 'customer' AND lida = 0
            ")->execute([$ticketId]);

            $orderId = ($ticket['referencia_tipo'] === 'order' && $ticket['referencia_id']) ? (int)$ticket['referencia_id'] : null;

            response(true, [
                'ticket' => [
                    'id' => (int)$ticket['id'],
                    'subject' => $ticket['assunto'],
                    'status' => $ticket['status'],
                    'status_label' => getStatusLabel($ticket['status']),
                    'category' => $ticket['categoria'],
                    'order_id' => $orderId,
                    'order_number' => $ticket['order_number'],
                    'created_at' => $ticket['created_at'],
                    'updated_at' => $ticket['updated_at']
                ],
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

        // Listar todos os tickets do cliente
        $status = $_GET['status'] ?? null;

        $sql = "
            SELECT t.id, t.assunto, t.status, t.categoria,
                   t.referencia_id, t.referencia_tipo,
                   t.created_at, t.updated_at,
                   o.order_number,
                   (SELECT COUNT(*) FROM om_support_messages m
                    WHERE m.ticket_id = t.id AND m.remetente_tipo != 'customer' AND m.lida = 0) as unread_count
            FROM om_support_tickets t
            LEFT JOIN om_market_orders o ON o.order_id = t.referencia_id AND t.referencia_tipo = 'order'
            WHERE t.entidade_tipo = 'customer' AND t.entidade_id = ?
        ";
        $params = [$customerId];

        if ($status && in_array($status, ['open', 'waiting', 'resolved', 'closed'])) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY t.updated_at DESC LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();

        response(true, [
            'tickets' => array_map(function($t) {
                $orderId = ($t['referencia_tipo'] === 'order' && $t['referencia_id']) ? (int)$t['referencia_id'] : null;
                return [
                    'id' => (int)$t['id'],
                    'subject' => $t['assunto'],
                    'status' => $t['status'],
                    'status_label' => getStatusLabel($t['status']),
                    'category' => $t['categoria'],
                    'order_id' => $orderId,
                    'order_number' => $t['order_number'],
                    'unread_count' => (int)$t['unread_count'],
                    'created_at' => $t['created_at'],
                    'updated_at' => $t['updated_at']
                ];
            }, $tickets)
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // POST - Criar novo ticket
    // ═══════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        $subject = trim($input['subject'] ?? '');
        $message = trim($input['message'] ?? '');
        $orderId = isset($input['order_id']) ? (int)$input['order_id'] : null;
        $category = trim($input['category'] ?? 'outro');

        if (empty($subject) || strlen($subject) < 5) {
            response(false, null, "Assunto deve ter pelo menos 5 caracteres", 400);
        }

        if (empty($message) || strlen($message) < 10) {
            response(false, null, "Mensagem deve ter pelo menos 10 caracteres", 400);
        }

        // Verificar se order_id pertence ao cliente
        if ($orderId) {
            $stmt = $db->prepare("
                SELECT order_id FROM om_market_orders
                WHERE order_id = ? AND customer_id = ?
            ");
            $stmt->execute([$orderId, $customerId]);
            if (!$stmt->fetch()) {
                $orderId = null; // Pedido nao pertence ao cliente
            }
        }

        // Verificar se ja existe ticket aberto para este pedido
        if ($orderId) {
            $stmt = $db->prepare("
                SELECT id FROM om_support_tickets
                WHERE entidade_tipo = 'customer' AND entidade_id = ?
                  AND referencia_tipo = 'order' AND referencia_id = ?
                  AND status IN ('open', 'waiting')
            ");
            $stmt->execute([$customerId, $orderId]);
            $existingTicket = $stmt->fetch();

            if ($existingTicket) {
                response(false, ['ticket_id' => (int)$existingTicket['id']],
                    "Ja existe um ticket aberto para este pedido", 409);
            }
        }

        // Criar ticket
        $stmt = $db->prepare("
            INSERT INTO om_support_tickets
                (entidade_tipo, entidade_id, entidade_nome, assunto, categoria, status, referencia_tipo, referencia_id, created_at, updated_at)
            VALUES ('customer', ?, ?, ?, ?, 'open', ?, ?, NOW(), NOW())
            RETURNING id
        ");
        $refTipo = $orderId ? 'order' : null;
        $stmt->execute([$customerId, $customerName, $subject, $category, $refTipo, $orderId]);
        $ticketId = (int)$stmt->fetchColumn();

        // Adicionar mensagem inicial
        $stmt = $db->prepare("
            INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_id, remetente_nome, mensagem, lida, created_at)
            VALUES (?, 'customer', ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$ticketId, $customerId, $customerName, $message]);

        // Tentar resposta automatica do bot
        $botResponse = getBotResponse($db, $message, $category);

        if ($botResponse) {
            $stmt = $db->prepare("
                INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_nome, mensagem, lida, created_at)
                VALUES (?, 'bot', 'Assistente Virtual', ?, 0, NOW())
            ");
            $stmt->execute([$ticketId, $botResponse['answer']]);

            // Se o bot respondeu, atualizar status para waiting (aguardando confirmacao)
            $db->prepare("UPDATE om_support_tickets SET status = 'waiting', updated_at = NOW() WHERE id = ?")->execute([$ticketId]);
        }

        response(true, [
            'ticket_id' => $ticketId,
            'has_bot_response' => !empty($botResponse),
            'message' => 'Ticket criado com sucesso'
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PUT - Atualizar ticket (fechar)
    // ═══════════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);

        $ticketId = (int)($input['id'] ?? 0);
        $newStatus = $input['status'] ?? '';

        if (!$ticketId) {
            response(false, null, "ID do ticket obrigatorio", 400);
        }

        // Verificar ownership
        $stmt = $db->prepare("SELECT id, status FROM om_support_tickets WHERE id = ? AND entidade_tipo = 'customer' AND entidade_id = ?");
        $stmt->execute([$ticketId, $customerId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            response(false, null, "Ticket nao encontrado", 404);
        }

        // Cliente so pode fechar ticket
        if ($newStatus !== 'closed') {
            response(false, null, "Operacao nao permitida", 403);
        }

        $db->prepare("
            UPDATE om_support_tickets SET status = 'closed', resolvido_em = NOW(), updated_at = NOW() WHERE id = ?
        ")->execute([$ticketId]);

        response(true, ['message' => 'Ticket fechado com sucesso']);
    }

    response(false, null, "Metodo nao suportado", 405);

} catch (Exception $e) {
    error_log("[vitrine/support-tickets] Erro: " . $e->getMessage());
    response(false, null, "Erro interno do servidor", 500);
}

// ═══════════════════════════════════════════════════════════════════
// FUNCOES AUXILIARES
// ═══════════════════════════════════════════════════════════════════

function getStatusLabel(string $status): string {
    return [
        'open' => 'Aberto',
        'waiting' => 'Aguardando',
        'resolved' => 'Resolvido',
        'closed' => 'Fechado'
    ][$status] ?? ucfirst($status);
}

/**
 * Tenta encontrar resposta automatica do bot baseada em keywords
 */
function getBotResponse(PDO $db, string $message, string $category): ?array {
    $message = mb_strtolower($message);

    // Palavras-chave comuns mapeadas para categorias
    $keywordMap = [
        'pedido' => ['pedido', 'compra', 'encomendar', 'fazer', 'como'],
        'entrega' => ['entrega', 'demora', 'atrasado', 'atraso', 'tempo', 'chegar', 'prazo', 'agendada'],
        'reembolso' => ['reembolso', 'devolver', 'dinheiro', 'estorno', 'errado', 'faltando', 'danificado', 'problema'],
        'cancelar' => ['cancelar', 'cancelamento', 'desistir', 'nao quero'],
        'pagamento' => ['pagamento', 'pagar', 'cartao', 'pix', 'dinheiro', 'forma'],
        'conta' => ['conta', 'perfil', 'senha', 'email', 'telefone', 'endereco'],
        'pontos' => ['pontos', 'fidelidade', 'bonus', 'recompensa'],
        'cupom' => ['cupom', 'desconto', 'promocao', 'codigo']
    ];

    // Detectar categoria pela mensagem
    $detectedCategory = null;
    $maxMatches = 0;

    foreach ($keywordMap as $cat => $keywords) {
        $matches = 0;
        foreach ($keywords as $kw) {
            if (strpos($message, $kw) !== false) {
                $matches++;
            }
        }
        if ($matches > $maxMatches) {
            $maxMatches = $matches;
            $detectedCategory = $cat;
        }
    }

    if (!$detectedCategory && $category) {
        $detectedCategory = $category;
    }

    if (!$detectedCategory || $maxMatches < 1) {
        return null; // Nao conseguiu identificar, encaminhar para humano
    }

    // Buscar FAQ mais relevante
    $stmt = $db->prepare("
        SELECT id, pergunta, resposta, categoria
        FROM om_support_faq
        WHERE ativo::text = '1'
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
                $score += mb_strlen($kw); // Palavras maiores = match melhor
            }
        }

        // Bonus for matching category
        if ($detectedCategory && $faq['categoria'] && strpos(mb_strtolower($faq['categoria']), $detectedCategory) !== false) {
            $score += 3;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $faq;
        }
    }

    if ($bestMatch && $bestScore >= 5) {
        return [
            'faq_id' => (int)$bestMatch['id'],
            'question' => $bestMatch['pergunta'],
            'answer' => $bestMatch['resposta']
        ];
    }

    return null;
}
