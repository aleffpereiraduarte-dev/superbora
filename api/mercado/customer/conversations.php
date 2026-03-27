<?php
/**
 * /api/mercado/customer/conversations.php
 * AI Assistant (One) conversation persistence
 *
 * GET              - List customer's saved conversations
 * POST             - Save/update a conversation
 * DELETE ?id=xxx   - Delete a conversation
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();
    $customerId = requireCustomerAuth();
    $method = $_SERVER["REQUEST_METHOD"];

    // ── Ensure table exists ─────────────────────────────────────────
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_ai_conversations (
                id SERIAL PRIMARY KEY,
                conversation_id VARCHAR(100) UNIQUE NOT NULL,
                customer_id INTEGER NOT NULL,
                title VARCHAR(255),
                messages JSONB NOT NULL DEFAULT '[]',
                message_count INTEGER DEFAULT 0,
                preview TEXT,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_conv_customer ON om_ai_conversations(customer_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_conv_id ON om_ai_conversations(conversation_id)");
    } catch (Exception $e) {
        // Table likely already exists — ignore
        error_log("[conversations] Table creation note: " . $e->getMessage());
    }

    // ── GET: List conversations ─────────────────────────────────────
    if ($method === "GET") {
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        // If requesting a specific conversation with messages
        $convId = $_GET['id'] ?? '';
        if ($convId) {
            $stmt = $db->prepare("
                SELECT conversation_id, title, preview, messages, message_count, created_at, updated_at
                FROM om_ai_conversations
                WHERE conversation_id = ? AND customer_id = ?
                LIMIT 1
            ");
            $stmt->execute([$convId, $customerId]);
            $conv = $stmt->fetch();
            if (!$conv) {
                response(false, null, "Conversa nao encontrada", 404);
            }
            $conv['messages'] = json_decode($conv['messages'], true) ?: [];
            $conv['message_count'] = (int)$conv['message_count'];
            response(true, ['conversation' => $conv]);
        }

        // List all (without full messages for performance)
        $stmt = $db->prepare("
            SELECT conversation_id, title, preview, message_count, created_at, updated_at
            FROM om_ai_conversations
            WHERE customer_id = ?
            ORDER BY updated_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$customerId, $limit, $offset]);
        $conversations = $stmt->fetchAll();

        foreach ($conversations as &$c) {
            $c['message_count'] = (int)$c['message_count'];
        }

        response(true, ['conversations' => $conversations]);
    }

    // ── POST: Save/update conversation ──────────────────────────────
    if ($method === "POST") {
        $input = getInput();

        $convId = $input['conversation_id'] ?? '';
        $messages = $input['messages'] ?? [];
        $title = $input['title'] ?? null;

        if (empty($convId)) {
            response(false, null, "conversation_id obrigatorio", 400);
        }
        if (!is_array($messages)) {
            response(false, null, "messages deve ser um array", 400);
        }

        // Validate conversation_id format (alphanumeric, underscores, hyphens, max 100)
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,100}$/', $convId)) {
            response(false, null, "conversation_id invalido", 400);
        }

        $messageCount = count($messages);

        // Auto-generate title from first user message if not provided
        if (!$title) {
            foreach ($messages as $msg) {
                if (!empty($msg['isMine']) && !empty($msg['text'])) {
                    $title = mb_substr(trim($msg['text']), 0, 50, 'UTF-8');
                    break;
                }
            }
        }
        if (!$title) {
            $title = 'Conversa';
        }

        // Generate preview from last assistant message
        $preview = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (empty($messages[$i]['isMine']) && !empty($messages[$i]['text'])) {
                $preview = mb_substr(trim($messages[$i]['text']), 0, 100, 'UTF-8');
                break;
            }
        }
        if (!$preview && $messageCount > 0) {
            $lastMsg = end($messages);
            $preview = mb_substr(trim($lastMsg['text'] ?? 'Conversa'), 0, 100, 'UTF-8');
        }

        // Limit messages stored (keep last 50)
        if (count($messages) > 50) {
            $messages = array_slice($messages, -50);
            $messageCount = count($messages);
        }

        $messagesJson = json_encode($messages, JSON_UNESCAPED_UNICODE);

        // Upsert: insert or update
        $stmt = $db->prepare("
            INSERT INTO om_ai_conversations (conversation_id, customer_id, title, messages, message_count, preview, created_at, updated_at)
            VALUES (?, ?, ?, ?::jsonb, ?, ?, NOW(), NOW())
            ON CONFLICT (conversation_id)
            DO UPDATE SET
                messages = EXCLUDED.messages,
                message_count = EXCLUDED.message_count,
                preview = EXCLUDED.preview,
                title = COALESCE(EXCLUDED.title, om_ai_conversations.title),
                updated_at = NOW()
            WHERE om_ai_conversations.customer_id = ?
        ");
        $stmt->execute([
            $convId,
            $customerId,
            $title,
            $messagesJson,
            $messageCount,
            $preview,
            $customerId,
        ]);

        response(true, ['conversation_id' => $convId], "Conversa salva");
    }

    // ── DELETE: Remove conversation ─────────────────────────────────
    if ($method === "DELETE") {
        $convId = $_GET['id'] ?? '';
        if (empty($convId)) {
            response(false, null, "id obrigatorio", 400);
        }

        $stmt = $db->prepare("
            DELETE FROM om_ai_conversations
            WHERE conversation_id = ? AND customer_id = ?
        ");
        $stmt->execute([$convId, $customerId]);

        if ($stmt->rowCount() === 0) {
            response(false, null, "Conversa nao encontrada", 404);
        }

        response(true, null, "Conversa removida");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[customer/conversations] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar conversas", 500);
}
