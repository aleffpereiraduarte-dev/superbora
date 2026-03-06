<?php
/**
 * /api/mercado/admin/callcenter/ai-assist.php
 *
 * AI-powered assistant for call center agents.
 * Uses Claude to help with product suggestions, order parsing, confirmations, etc.
 *
 * POST mode='suggest_products': Suggest products based on menu + customer history.
 * POST mode='build_order_from_text': Parse natural language into structured order items.
 * POST mode='confirm_order': Generate PT-BR confirmation script from draft items.
 * POST mode='greeting': Generate personalized greeting from customer info.
 * POST mode='summarize_call': Summarize call transcription.
 * POST mode='find_store': Fuzzy match store name.
 * POST mode='upsell': Suggest complementary items.
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';
require_once __DIR__ . '/../../helpers/claude-client.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $mode = trim($input['mode'] ?? '');
    $context = $input['context'] ?? [];

    if (!$mode) {
        response(false, null, "Campo 'mode' obrigatorio. Valores: suggest_products, build_order_from_text, confirm_order, greeting, summarize_call, find_store, upsell", 400);
    }

    $claude = new ClaudeClient();

    // Helper: fetch store menu from database if not provided
    $fetchMenu = function($storeId) use ($db) {
        if (!$storeId) return '';
        $stmt = $db->prepare("
            SELECT c.name AS category, p.name, p.price, p.description
            FROM om_market_products p
            JOIN om_market_categories c ON c.category_id = p.category_id
            WHERE p.partner_id = ? AND p.status = 1
            ORDER BY c.sort_order, p.sort_order
            LIMIT 100
        ");
        $stmt->execute([$storeId]);
        $products = $stmt->fetchAll();
        $menuText = '';
        $lastCat = '';
        foreach ($products as $p) {
            if ($p['category'] !== $lastCat) {
                $menuText .= "\n[{$p['category']}]\n";
                $lastCat = $p['category'];
            }
            $menuText .= "- {$p['name']} R$" . number_format((float)$p['price'], 2, ',', '.') . ($p['description'] ? " ({$p['description']})" : '') . "\n";
        }
        return $menuText ?: 'Cardapio nao disponivel';
    };

    // ── Suggest products ──
    if ($mode === 'suggest_products') {
        $menu = $context['menu'] ?? '';
        $customerHistory = $context['customer_history'] ?? '';

        // Auto-fetch menu from store if not provided
        if (!$menu && !empty($context['store']['id'])) {
            $menu = $fetchMenu((int)$context['store']['id']);
        }
        if (!$menu) {
            response(false, null, "Selecione uma loja primeiro", 400);
        }

        $systemPrompt = <<<PROMPT
Voce e um assistente inteligente da central de atendimento SuperBora, um delivery de supermercado e restaurantes.

Seu papel: ajudar o atendente a sugerir produtos para o cliente baseado no cardapio da loja e no historico de pedidos do cliente.

Regras:
- Responda SEMPRE em portugues brasileiro (PT-BR)
- Sugira de 3 a 5 produtos relevantes
- Considere o historico do cliente para personalizar as sugestoes
- Se o cliente nunca pediu antes, sugira os mais populares/bem avaliados
- Inclua o preco de cada sugestao
- Seja breve e objetivo — o atendente precisa ser rapido
- Formate como lista numerada

Responda SOMENTE com as sugestoes, sem introducao desnecessaria.
PROMPT;

        $userMessage = "CARDAPIO DA LOJA:\n{$menu}\n\n";
        if ($customerHistory) {
            $userMessage .= "HISTORICO DO CLIENTE:\n{$customerHistory}\n\n";
        }
        $userMessage .= "Sugira produtos para este cliente.";

        $result = $claude->send($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], 1024);

        if (!$result['success']) {
            response(false, null, "Erro na IA: " . ($result['error'] ?? 'desconhecido'), 500);
        }

        response(true, [
            'response' => $result['text'],
            'suggestions' => $result['text'],
            'tokens_used' => ($result['input_tokens'] ?? 0) + ($result['output_tokens'] ?? 0),
        ]);
    }

    // ── Build order from natural language ──
    if ($mode === 'build_order_from_text') {
        $text = trim($input['text'] ?? $context['text'] ?? '');
        $menu = $context['menu'] ?? '';

        if (!$text) {
            response(false, null, "Campo 'text' ou context.text obrigatorio (ex: 'quero 2 coxinhas e um guarana')", 400);
        }
        if (!$menu && !empty($context['store']['id'])) {
            $menu = $fetchMenu((int)$context['store']['id']);
        }
        if (!$menu) {
            response(false, null, "Selecione uma loja primeiro", 400);
        }

        $systemPrompt = <<<PROMPT
Voce e um parser de pedidos para a central de atendimento SuperBora.

Seu trabalho: converter o texto falado pelo cliente em uma lista estruturada de itens do pedido, fazendo match com o cardapio da loja.

REGRAS CRITICAS:
1. Retorne SOMENTE JSON valido, sem texto adicional
2. Faca match dos itens mencionados com os produtos do cardapio
3. Se um item nao tem match exato, use o mais proximo e indique no campo "match_confidence"
4. Se nao encontrar match algum, coloque "matched": false
5. Quantidade padrao e 1 se nao especificada
6. Capture observacoes do cliente (ex: "sem cebola", "bem passado")

Formato de resposta (JSON array):
[
  {
    "product_id": 123,
    "name": "Nome do produto no cardapio",
    "price": 12.90,
    "quantity": 2,
    "options": [{"name": "Sem cebola", "price": 0}],
    "notes": "observacao do cliente",
    "matched": true,
    "match_confidence": "high",
    "original_text": "o que o cliente disse"
  }
]

match_confidence: "high" (match exato), "medium" (similar), "low" (pode estar errado)

Se nao conseguir fazer match de nenhum item, retorne:
[{"error": "Nao consegui identificar produtos", "original_text": "texto do cliente"}]
PROMPT;

        $userMessage = "CARDAPIO DA LOJA:\n{$menu}\n\nPEDIDO DO CLIENTE:\n\"{$text}\"";

        $result = $claude->send($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], 2048);

        if (!$result['success']) {
            response(false, null, "Erro na IA: " . ($result['error'] ?? 'desconhecido'), 500);
        }

        // Try to parse JSON from response
        $responseText = $result['text'] ?? '';
        $items = null;

        // Extract JSON from response (may be wrapped in markdown code block)
        if (preg_match('/\[[\s\S]*\]/', $responseText, $matches)) {
            $items = json_decode($matches[0], true);
        }

        if (!$items) {
            // Return raw text if JSON parsing fails
            response(true, [
                'items' => [],
                'raw_response' => $responseText,
                'parse_error' => true,
            ], "IA nao retornou JSON valido — veja raw_response");
        }

        response(true, [
            'items' => $items,
            'tokens_used' => ($result['input_tokens'] ?? 0) + ($result['output_tokens'] ?? 0),
        ]);
    }

    // ── Generate confirmation script ──
    if ($mode === 'confirm_order') {
        $draft = $context['draft'] ?? [];

        if (empty($draft) || empty($draft['items'])) {
            response(false, null, "context.draft obrigatorio com items", 400);
        }

        $systemPrompt = <<<PROMPT
Voce e um assistente da central de atendimento SuperBora.

Gere um ROTEIRO DE CONFIRMACAO para o atendente ler ao cliente pelo telefone.

Regras:
- Em portugues brasileiro (PT-BR) natural e cordial
- Liste cada item com quantidade e preco
- Confirme o endereco de entrega
- Confirme a forma de pagamento
- Informe o total
- Informe tempo estimado de entrega se disponivel
- Use linguagem educada mas direta
- Inclua pausas naturais marcadas com "..."
- Termine perguntando se esta tudo certo

Formato: texto corrido que o atendente pode ler naturalmente.
PROMPT;

        $itemsList = "";
        foreach ($draft['items'] as $item) {
            $qty = (int)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $itemsList .= "- {$qty}x {$item['name']} (R$ " . number_format($price, 2, ',', '.') . ")\n";
            foreach (($item['options'] ?? []) as $opt) {
                $itemsList .= "  + {$opt['name']}\n";
            }
        }

        $userMessage = "DADOS DO PEDIDO:\n";
        $userMessage .= "Loja: " . ($draft['partner_name'] ?? 'N/A') . "\n";
        $userMessage .= "Cliente: " . ($draft['customer_name'] ?? 'N/A') . "\n";
        $userMessage .= "Itens:\n{$itemsList}\n";
        $userMessage .= "Subtotal: R$ " . number_format((float)($draft['subtotal'] ?? 0), 2, ',', '.') . "\n";
        $userMessage .= "Taxa de entrega: R$ " . number_format((float)($draft['delivery_fee'] ?? 0), 2, ',', '.') . "\n";
        $userMessage .= "Taxa de servico: R$ " . number_format((float)($draft['service_fee'] ?? 0), 2, ',', '.') . "\n";
        if ((float)($draft['discount'] ?? 0) > 0) {
            $userMessage .= "Desconto: -R$ " . number_format((float)$draft['discount'], 2, ',', '.') . "\n";
        }
        $userMessage .= "TOTAL: R$ " . number_format((float)($draft['total'] ?? 0), 2, ',', '.') . "\n";
        $userMessage .= "Pagamento: " . ($draft['payment_method'] ?? 'N/A') . "\n";
        if (!empty($draft['address'])) {
            $addr = $draft['address'];
            $userMessage .= "Endereco: " . ($addr['full'] ?? ($addr['street'] ?? '') . ', ' . ($addr['number'] ?? '')) . "\n";
        }

        $result = $claude->send($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], 1024);

        if (!$result['success']) {
            response(false, null, "Erro na IA: " . ($result['error'] ?? 'desconhecido'), 500);
        }

        response(true, [
            'response' => $result['text'],
            'script' => $result['text'],
            'tokens_used' => ($result['input_tokens'] ?? 0) + ($result['output_tokens'] ?? 0),
        ]);
    }

    // ── Generate personalized greeting ──
    if ($mode === 'greeting') {
        $customerInfo = $context['customer_info'] ?? $context['customer'] ?? [];

        $systemPrompt = <<<PROMPT
Voce e um assistente da central de atendimento SuperBora.

Gere uma SAUDACAO PERSONALIZADA para o atendente usar ao atender a ligacao.

Regras:
- Portugues brasileiro natural e acolhedor
- Se tiver o nome do cliente, use-o
- Se for cliente recorrente (tem pedidos anteriores), mencione que e bom ter de volta
- Se for cliente novo, de boas-vindas
- Seja breve (2-3 frases maximo)
- Inclua o nome "SuperBora" na saudacao
- Use horario adequado (bom dia/boa tarde/boa noite)

Hora atual: {HORA}

Retorne SOMENTE a saudacao, nada mais.
PROMPT;

        $hora = (int)date('H');
        $periodo = $hora < 12 ? 'bom dia' : ($hora < 18 ? 'boa tarde' : 'boa noite');
        $systemPrompt = str_replace('{HORA}', date('H:i') . " ({$periodo})", $systemPrompt);

        $userMessage = "DADOS DO CLIENTE:\n";
        $userMessage .= "Nome: " . ($customerInfo['name'] ?? 'desconhecido') . "\n";
        $userMessage .= "Total de pedidos anteriores: " . ($customerInfo['total_orders'] ?? 0) . "\n";
        $userMessage .= "Total gasto: R$ " . number_format((float)($customerInfo['total_spent'] ?? 0), 2, ',', '.') . "\n";
        $userMessage .= "Ultimo pedido: " . ($customerInfo['last_order_date'] ?? 'nunca') . "\n";
        $userMessage .= "Cliente desde: " . ($customerInfo['created_at'] ?? 'N/A') . "\n";

        $result = $claude->send($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], 256);

        if (!$result['success']) {
            response(false, null, "Erro na IA: " . ($result['error'] ?? 'desconhecido'), 500);
        }

        response(true, [
            'response' => $result['text'],
            'greeting' => $result['text'],
            'tokens_used' => ($result['input_tokens'] ?? 0) + ($result['output_tokens'] ?? 0),
        ]);
    }

    // ── Summarize call transcription ──
    if ($mode === 'summarize_call') {
        $transcription = trim($input['transcription'] ?? $context['transcription'] ?? '');

        if (!$transcription) {
            response(false, null, "Campo 'transcription' ou context.transcription obrigatorio", 400);
        }

        $systemPrompt = <<<PROMPT
Voce e um analisador de chamadas da central de atendimento SuperBora.

Analise a transcricao da ligacao e extraia as informacoes de forma estruturada.

Retorne SOMENTE JSON valido no formato:
{
  "resumo": "Resumo breve da ligacao (1-2 frases)",
  "motivo_contato": "pedido|reclamacao|duvida|cancelamento|rastreamento|outro",
  "sentimento": "positivo|neutro|negativo|frustrado",
  "cliente_nome": "nome se identificado ou null",
  "loja_mencionada": "nome da loja se mencionada ou null",
  "pedido_id": "numero do pedido se mencionado ou null",
  "itens_mencionados": ["lista de produtos mencionados"],
  "problema_identificado": "descricao do problema se houver ou null",
  "resolucao": "como foi resolvido ou null",
  "acao_necessaria": "proxima acao necessaria ou null",
  "tags": ["tags relevantes para categorizacao"]
}
PROMPT;

        $result = $claude->send($systemPrompt, [
            ['role' => 'user', 'content' => "TRANSCRICAO:\n\n{$transcription}"],
        ], 1024);

        if (!$result['success']) {
            response(false, null, "Erro na IA: " . ($result['error'] ?? 'desconhecido'), 500);
        }

        $responseText = $result['text'] ?? '';
        $structured = null;

        // Extract JSON
        if (preg_match('/\{[\s\S]*\}/', $responseText, $matches)) {
            $structured = json_decode($matches[0], true);
        }

        response(true, [
            'summary' => $structured ?? ['raw' => $responseText],
            'tokens_used' => ($result['input_tokens'] ?? 0) + ($result['output_tokens'] ?? 0),
        ]);
    }

    // ── Find store (fuzzy match) ──
    if ($mode === 'find_store') {
        $query = trim($context['query'] ?? $input['query'] ?? '');
        $stores = $context['stores'] ?? [];

        if (!$query) {
            response(false, null, "context.query obrigatorio (nome falado pelo cliente)", 400);
        }
        if (empty($stores) || !is_array($stores)) {
            response(false, null, "context.stores obrigatorio (lista de lojas)", 400);
        }

        $systemPrompt = <<<PROMPT
Voce e um assistente de matching de lojas da SuperBora.

O cliente falou o nome de uma loja por telefone e pode ter pronunciado de forma imprecisa, abreviada ou com sotaque.

Seu trabalho: encontrar a loja correta na lista fornecida.

Retorne SOMENTE JSON valido:
{
  "matches": [
    {
      "store_name": "Nome exato da loja na lista",
      "store_id": 123,
      "confidence": "high|medium|low",
      "reason": "por que esta loja foi escolhida"
    }
  ],
  "no_match": false,
  "suggestion": "sugestao para o atendente se nenhum match"
}

Regras:
- Retorne ate 3 matches ordenados por confianca
- "high": match quase certo
- "medium": provavel mas ambiguo
- "low": pode ser, mas improvavel
- Se nenhuma loja corresponder, retorne no_match: true
PROMPT;

        $storesList = "";
        foreach ($stores as $s) {
            $storesList .= "- ID: " . ($s['id'] ?? '?') . " | Nome: " . ($s['name'] ?? '?') . "\n";
        }

        $userMessage = "LOJAS DISPONIVEIS:\n{$storesList}\n\nCLIENTE DISSE: \"{$query}\"";

        $result = $claude->send($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], 512);

        if (!$result['success']) {
            response(false, null, "Erro na IA: " . ($result['error'] ?? 'desconhecido'), 500);
        }

        $responseText = $result['text'] ?? '';
        $matchResult = null;
        if (preg_match('/\{[\s\S]*\}/', $responseText, $matches)) {
            $matchResult = json_decode($matches[0], true);
        }

        response(true, [
            'result' => $matchResult ?? ['raw' => $responseText],
            'tokens_used' => ($result['input_tokens'] ?? 0) + ($result['output_tokens'] ?? 0),
        ]);
    }

    // ── Upsell suggestions ──
    if ($mode === 'upsell') {
        $cart = $context['cart'] ?? [];
        $menu = $context['menu'] ?? '';

        if (empty($cart)) {
            response(false, null, "Adicione itens ao carrinho primeiro", 400);
        }
        if (!$menu && !empty($context['store']['id'])) {
            $menu = $fetchMenu((int)$context['store']['id']);
        }
        if (!$menu) {
            response(false, null, "Selecione uma loja primeiro", 400);
        }

        $systemPrompt = <<<PROMPT
Voce e um assistente de vendas da central de atendimento SuperBora.

Seu papel: sugerir itens complementares ao pedido atual do cliente para aumentar o ticket medio.

Regras:
- Sugira de 2 a 4 itens complementares
- Os itens devem fazer sentido com o que o cliente ja esta pedindo
- Exemplos: bebida para acompanhar comida, sobremesa, acompanhamento, etc.
- Inclua o preco de cada sugestao
- Escreva como se fosse o atendente falando ao cliente
- Seja sutil e nao agressivo — e uma sugestao, nao venda forcada
- Em portugues brasileiro natural

Formato: texto curto que o atendente pode ler ao telefone (3-5 linhas no maximo).
PROMPT;

        $cartItems = "";
        foreach ($cart as $item) {
            $cartItems .= "- {$item['quantity']}x {$item['name']}\n";
        }

        $userMessage = "ITENS NO CARRINHO:\n{$cartItems}\n\nCARDAPIO COMPLETO:\n{$menu}\n\nGere sugestao de upsell para o atendente.";

        $result = $claude->send($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], 512);

        if (!$result['success']) {
            response(false, null, "Erro na IA: " . ($result['error'] ?? 'desconhecido'), 500);
        }

        response(true, [
            'suggestion' => $result['text'],
            'tokens_used' => ($result['input_tokens'] ?? 0) + ($result['output_tokens'] ?? 0),
        ]);
    }

    // ── Handle customer question ──
    if ($mode === 'handle_question') {
        $text = trim($input['text'] ?? '');
        $customer = $context['customer'] ?? [];
        $store = $context['store'] ?? [];
        $draft = $context['draft'] ?? [];

        if (!$text) {
            response(false, null, "Campo 'text' obrigatorio", 400);
        }

        $systemPrompt = <<<PROMPT
Voce e um assistente inteligente da central de atendimento SuperBora, um delivery de supermercado e restaurantes.

Seu papel: ajudar o atendente a responder perguntas de clientes e gerenciar pedidos.

Voce pode:
- Responder duvidas sobre produtos, alergenos, ingredientes
- Informar sobre tempo de entrega, taxas, formas de pagamento
- Ajudar a resolver problemas de pedidos
- Sugerir produtos e combos
- Ajudar com cupons e promocoes
- Responder perguntas gerais sobre o servico

Regras:
- Responda SEMPRE em portugues brasileiro (PT-BR)
- Seja breve e objetivo — o atendente precisa de respostas rapidas
- Se nao souber a resposta, diga honestamente e sugira onde encontrar
- Adapte o tom para ser profissional mas acolhedor
- Considere o contexto do cliente e pedido atual se fornecidos
PROMPT;

        $userMessage = $text;
        if (!empty($customer)) {
            $userMessage .= "\n\nCONTEXTO - CLIENTE: " . ($customer['name'] ?? 'N/A') . ", tel: " . ($customer['phone'] ?? 'N/A') . ", pedidos anteriores: " . ($customer['total_orders'] ?? 0);
        }
        if (!empty($store)) {
            $userMessage .= "\nCONTEXTO - LOJA: " . ($store['name'] ?? 'N/A');
        }
        if (!empty($draft) && !empty($draft['items'])) {
            $userMessage .= "\nCONTEXTO - PEDIDO ATUAL: " . count($draft['items']) . " itens, total R$" . number_format((float)($draft['total'] ?? 0), 2, ',', '.');
        }

        $result = $claude->send($systemPrompt, [
            ['role' => 'user', 'content' => $userMessage],
        ], 1024);

        if (!$result['success']) {
            response(false, null, "Erro na IA: " . ($result['error'] ?? 'desconhecido'), 500);
        }

        response(true, [
            'response' => $result['text'],
            'tokens_used' => ($result['input_tokens'] ?? 0) + ($result['output_tokens'] ?? 0),
        ]);
    }

    response(false, null, "Mode invalido. Valores: suggest_products, build_order_from_text, confirm_order, greeting, summarize_call, find_store, upsell, handle_question", 400);

} catch (Exception $e) {
    error_log("[admin/callcenter/ai-assist] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
