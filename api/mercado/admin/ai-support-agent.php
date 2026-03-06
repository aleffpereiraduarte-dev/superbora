<?php
/**
 * POST /api/mercado/admin/ai-support-agent.php
 *
 * AI support agent for admin panel. Uses Claude to help resolve customer issues.
 *
 * Body: { messages: [{role, content}], context?: {order_id, customer_id, ticket_id} }
 * Returns: { response: string, suggested_actions: [{label, endpoint, method, payload}] }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once __DIR__ . "/../helpers/claude-client.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $messages = $input['messages'] ?? [];
    $context = $input['context'] ?? [];

    if (empty($messages) || !is_array($messages)) {
        response(false, null, "Campo 'messages' obrigatorio (array de {role, content})", 400);
    }

    // Validate message format
    foreach ($messages as $msg) {
        if (empty($msg['role']) || empty($msg['content'])) {
            response(false, null, "Cada mensagem deve ter 'role' e 'content'", 400);
        }
        if (!in_array($msg['role'], ['user', 'assistant'])) {
            response(false, null, "Role deve ser 'user' ou 'assistant'", 400);
        }
    }

    // Build context information from database
    $contextInfo = "";

    // Fetch order details if order_id provided
    if (!empty($context['order_id'])) {
        $order_id = (int)$context['order_id'];
        $stmt = $db->prepare("
            SELECT o.order_id, o.order_number, o.status, o.total, o.subtotal,
                   o.delivery_fee, o.service_fee, o.forma_pagamento, o.payment_status,
                   o.notes, o.created_at, o.delivered_at, o.refund_amount,
                   o.cancelamento_motivo, o.cancelado_por,
                   c.name as customer_name, c.email as customer_email, c.phone as customer_phone,
                   p.name as partner_name
            FROM om_market_orders o
            LEFT JOIN om_customers c ON o.customer_id = c.customer_id
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        if ($order) {
            $contextInfo .= "\n\n--- DADOS DO PEDIDO #{$order['order_number']} ---\n";
            $contextInfo .= "Status: {$order['status']}\n";
            $contextInfo .= "Total: R$ " . number_format((float)$order['total'], 2, ',', '.') . "\n";
            $contextInfo .= "Subtotal: R$ " . number_format((float)$order['subtotal'], 2, ',', '.') . "\n";
            $contextInfo .= "Taxa entrega: R$ " . number_format((float)$order['delivery_fee'], 2, ',', '.') . "\n";
            $contextInfo .= "Pagamento: {$order['forma_pagamento']} ({$order['payment_status']})\n";
            $contextInfo .= "Cliente: {$order['customer_name']} ({$order['customer_email']}, {$order['customer_phone']})\n";
            $contextInfo .= "Loja: {$order['partner_name']}\n";
            $contextInfo .= "Criado em: {$order['created_at']}\n";
            if ($order['delivered_at']) $contextInfo .= "Entregue em: {$order['delivered_at']}\n";
            if ((float)($order['refund_amount'] ?? 0) > 0) {
                $contextInfo .= "Reembolso ja aplicado: R$ " . number_format((float)$order['refund_amount'], 2, ',', '.') . "\n";
            }
            if ($order['cancelamento_motivo']) {
                $contextInfo .= "Motivo cancelamento: {$order['cancelamento_motivo']} (por: {$order['cancelado_por']})\n";
            }
            if ($order['notes']) $contextInfo .= "Observacoes: {$order['notes']}\n";

            // Fetch order items
            $stmt = $db->prepare("SELECT name, quantity, price, total FROM om_market_order_items WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchAll();
            if ($items) {
                $contextInfo .= "Itens:\n";
                foreach ($items as $item) {
                    $contextInfo .= "  - {$item['name']} x{$item['quantity']} = R$ " . number_format((float)$item['total'], 2, ',', '.') . "\n";
                }
            }
        }
    }

    // Fetch customer details if customer_id provided
    if (!empty($context['customer_id'])) {
        $customer_id = (int)$context['customer_id'];
        $stmt = $db->prepare("
            SELECT customer_id, name, email, phone, status, created_at, last_login
            FROM om_customers WHERE customer_id = ?
        ");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        if ($customer) {
            $contextInfo .= "\n\n--- DADOS DO CLIENTE ---\n";
            $contextInfo .= "ID: {$customer['customer_id']}\n";
            $contextInfo .= "Nome: {$customer['name']}\n";
            $contextInfo .= "Email: {$customer['email']}\n";
            $contextInfo .= "Telefone: {$customer['phone']}\n";
            $contextInfo .= "Status: {$customer['status']}\n";
            $contextInfo .= "Cadastro: {$customer['created_at']}\n";
            $contextInfo .= "Ultimo login: {$customer['last_login']}\n";

            // Order stats
            $stmt = $db->prepare("
                SELECT COUNT(*) as total_orders,
                       COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN total ELSE 0 END), 0) as total_spent,
                       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
                FROM om_market_orders WHERE customer_id = ?
            ");
            $stmt->execute([$customer_id]);
            $stats = $stmt->fetch();
            $contextInfo .= "Total pedidos: {$stats['total_orders']}\n";
            $contextInfo .= "Total gasto: R$ " . number_format((float)$stats['total_spent'], 2, ',', '.') . "\n";
            $contextInfo .= "Cancelamentos: {$stats['cancelled_count']}\n";
        }
    }

    // Fetch ticket details if ticket_id provided
    if (!empty($context['ticket_id'])) {
        $ticket_id = (int)$context['ticket_id'];
        try {
            $stmt = $db->prepare("
                SELECT id, customer_id, order_id, category, subject, description,
                       status, priority, created_at, resolved_at
                FROM om_support_tickets WHERE id = ?
            ");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch();
            if ($ticket) {
                $contextInfo .= "\n\n--- TICKET DE SUPORTE #{$ticket_id} ---\n";
                $contextInfo .= "Categoria: {$ticket['category']}\n";
                $contextInfo .= "Assunto: {$ticket['subject']}\n";
                $contextInfo .= "Descricao: {$ticket['description']}\n";
                $contextInfo .= "Status: {$ticket['status']}\n";
                $contextInfo .= "Prioridade: {$ticket['priority']}\n";
                $contextInfo .= "Criado em: {$ticket['created_at']}\n";
            }
        } catch (Exception $e) {
            // Table may not exist yet
        }
    }

    // System prompt
    $systemPrompt = "Voce e o assistente de IA do suporte SuperBora. Voce ajuda agentes de suporte a resolver problemas de clientes de forma rapida e eficiente.

POLITICAS IMPORTANTES:
- Reembolsos abaixo de R\$30,00: aprovacao automatica permitida
- Reembolsos acima de R\$30,00: requerem aprovacao de supervisor
- SLA de tickets: 24h para primeira resposta, 48h para resolucao
- Pedidos cancelados: reembolso automatico se pagamento ja processado
- Pedidos atrasados (>1h apos estimativa): oferecer cupom de desconto de 10%
- Itens errados/faltantes: reembolso proporcional + cupom de R\$5
- Problemas de qualidade: solicitar fotos, reembolso total se confirmado
- Cliente suspenso: apenas RH pode reativar
- Fraude suspeita: escalar para equipe de seguranca

FORMATO DE RESPOSTA:
Responda SEMPRE em portugues brasileiro.
Forneca passos claros de resolucao.
Ao final da sua resposta, se houver acoes sugeridas, inclua um bloco JSON assim:

```json
{\"suggested_actions\": [{\"label\": \"Descricao da acao\", \"endpoint\": \"/api/mercado/admin/...\", \"method\": \"POST\", \"payload\": {}}]}
```

Se nao houver acoes, nao inclua o bloco JSON.
" . $contextInfo;

    // Format messages for Claude API
    $claudeMessages = [];
    foreach ($messages as $msg) {
        $claudeMessages[] = [
            'role' => $msg['role'],
            'content' => $msg['content'],
        ];
    }

    // Send to Claude
    $claude = new ClaudeClient();
    $result = $claude->send($systemPrompt, $claudeMessages, 2048);

    if (!$result['success']) {
        error_log("[ai-support-agent] Claude error: " . ($result['error'] ?? 'unknown'));
        response(false, null, "Erro ao consultar IA: " . ($result['error'] ?? 'erro desconhecido'), 502);
    }

    $responseText = $result['text'];
    $suggestedActions = [];

    // Parse suggested_actions from response
    if (preg_match('/```json\s*(\{[\s\S]*?\})\s*```/', $responseText, $matches)) {
        $parsed = ClaudeClient::parseJson($matches[1]);
        if ($parsed && isset($parsed['suggested_actions'])) {
            $suggestedActions = $parsed['suggested_actions'];
        }
        // Remove the JSON block from the visible response
        $responseText = trim(str_replace($matches[0], '', $responseText));
    }

    response(true, [
        'response' => $responseText,
        'suggested_actions' => $suggestedActions,
        'tokens' => [
            'input' => $result['input_tokens'] ?? 0,
            'output' => $result['output_tokens'] ?? 0,
        ],
    ], "Resposta do agente IA");

} catch (Exception $e) {
    error_log("[admin/ai-support-agent] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
