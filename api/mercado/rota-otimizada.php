<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/mercado/rota-otimizada.php?order_id=123
 * Gera lista de compras otimizada organizada por secao do supermercado
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao de Shopper APROVADO pelo RH
 * Header: Authorization: Bearer <token>
 *
 * Query: ?order_id=123
 *
 * SEGURANCA:
 * - Autenticacao obrigatoria (shopper aprovado)
 * - Verificacao de ownership (shopper do pedido)
 * - Prepared statements (sem SQL injection)
 * - Validacao de input
 * - Erros internos nao expostos ao cliente
 */

require_once __DIR__ . "/config/auth.php";

setCorsHeaders();

try {
    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICACAO - Shopper aprovado pelo RH
    // ═══════════════════════════════════════════════════════════════════
    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    $db = getDB();

    // ═══════════════════════════════════════════════════════════════════
    // VALIDACAO DE INPUT
    // ═══════════════════════════════════════════════════════════════════
    $order_id = (int)($_GET['order_id'] ?? 0);
    if (!$order_id) {
        response(false, null, 'order_id e obrigatorio', 400);
    }

    // ═══════════════════════════════════════════════════════════════════
    // VERIFICAR PEDIDO E OWNERSHIP
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.shopper_id, o.partner_id,
               p.name AS partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        response(false, null, 'Pedido nao encontrado', 404);
    }

    if ((int)$pedido['shopper_id'] !== $shopper_id) {
        response(false, null, 'Voce nao tem permissao para acessar este pedido', 403);
    }

    // Verificar se pedido esta em fase de coleta
    $statusPermitidos = ['aceito', 'coletando'];
    if (!in_array($pedido['status'], $statusPermitidos)) {
        response(false, null, 'Pedido nao esta em fase de coleta. Status: ' . $pedido['status'], 400);
    }

    // ═══════════════════════════════════════════════════════════════════
    // BUSCAR ITENS DO PEDIDO COM DETALHES DO PRODUTO
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $db->prepare("
        SELECT i.id AS item_id, i.product_id, i.quantity, i.price, i.collected,
               i.collected_quantity, i.collected_at,
               p.name, p.category_id, p.barcode, p.image, p.weight,
               c.name AS category_name
        FROM om_market_order_items i
        INNER JOIN om_market_products p ON i.product_id = p.product_id
        LEFT JOIN om_market_categories c ON p.category_id = c.category_id
        WHERE i.order_id = ? AND i.collected = 0
        ORDER BY p.category_id, p.name
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        response(true, [
            'order_id'           => $order_id,
            'total_items'        => 0,
            'estimated_time_min' => 0,
            'sections'           => [],
            'bag_plan'           => ['normal' => 0, 'fragil' => 0, 'congelado' => 0, 'limpeza' => 0],
            'ai_tips'            => null
        ], 'Todos os itens ja foram coletados');
    }

    // ═══════════════════════════════════════════════════════════════════
    // MAPEAMENTO DE SECOES DO SUPERMERCADO
    // ═══════════════════════════════════════════════════════════════════
    $sectionOrder = [
        'Hortifruti'  => 1,
        'Padaria'     => 2,
        'Acougue'     => 3,
        'Frios'       => 4,
        'Laticinios'  => 5,
        'Mercearia'   => 6,
        'Cereais'     => 7,
        'Massas'      => 8,
        'Molhos'      => 9,
        'Enlatados'   => 10,
        'Biscoitos'   => 11,
        'Bebidas'     => 12,
        'Limpeza'     => 13,
        'Higiene'     => 14,
        'Congelados'  => 15,
    ];

    // Icones por secao
    $sectionIcons = [
        'Hortifruti'  => 'leaf',
        'Padaria'     => 'bread-slice',
        'Acougue'     => 'drumstick-bite',
        'Frios'       => 'cheese',
        'Laticinios'  => 'glass-water',
        'Mercearia'   => 'basket-shopping',
        'Cereais'     => 'wheat-awn',
        'Massas'      => 'utensils',
        'Molhos'      => 'bottle-droplet',
        'Enlatados'   => 'jar',
        'Biscoitos'   => 'cookie',
        'Bebidas'     => 'bottle-water',
        'Limpeza'     => 'spray-can',
        'Higiene'     => 'pump-soap',
        'Congelados'  => 'snowflake',
    ];

    // Dicas por secao
    $sectionTips = [
        'Hortifruti'  => 'Escolha frutas firmes e sem manchas. Verificar qualidade visual.',
        'Padaria'     => 'Verificar data de validade e frescor.',
        'Acougue'     => 'Confirmar validade no rotulo. Pedir corte se necessario.',
        'Frios'       => 'Verificar temperatura e validade. Embalar bem.',
        'Laticinios'  => 'Conferir validade. Manter refrigerado.',
        'Mercearia'   => 'Verificar embalagens intactas.',
        'Cereais'     => 'Verificar embalagens lacradas.',
        'Massas'      => 'Verificar embalagens intactas.',
        'Molhos'      => 'Cuidado com vidros. Separar em sacola propria.',
        'Enlatados'   => 'Verificar se latas nao estao amassadas.',
        'Biscoitos'   => 'Cuidado para nao amassar. Itens frageis.',
        'Bebidas'     => 'Itens pesados - colocar no fundo da sacola.',
        'Limpeza'     => 'SEPARAR de alimentos! Sacola propria obrigatoria.',
        'Higiene'     => 'Verificar lacre de seguranca.',
        'Congelados'  => 'PEGAR POR ULTIMO! Manter temperatura. Usar sacola termica.',
    ];

    // Tipo de sacola por secao
    $sectionBagType = [
        'Hortifruti'  => 'normal',
        'Padaria'     => 'fragil',
        'Acougue'     => 'normal',
        'Frios'       => 'normal',
        'Laticinios'  => 'normal',
        'Mercearia'   => 'normal',
        'Cereais'     => 'normal',
        'Massas'      => 'normal',
        'Molhos'      => 'fragil',
        'Enlatados'   => 'normal',
        'Biscoitos'   => 'fragil',
        'Bebidas'     => 'normal',
        'Limpeza'     => 'limpeza',
        'Higiene'     => 'normal',
        'Congelados'  => 'congelado',
    ];

    // Mapeamento de categoria para secao (normalizado para lowercase)
    $categoryToSection = [
        'hortifruti' => 'Hortifruti', 'frutas' => 'Hortifruti', 'verduras' => 'Hortifruti',
        'legumes' => 'Hortifruti', 'organicos' => 'Hortifruti', 'saladas' => 'Hortifruti',
        'padaria' => 'Padaria', 'paes' => 'Padaria', 'bolos' => 'Padaria',
        'acougue' => 'Acougue', 'carnes' => 'Acougue', 'aves' => 'Acougue',
        'peixes' => 'Acougue', 'frutos do mar' => 'Acougue',
        'frios' => 'Frios', 'embutidos' => 'Frios', 'presunto' => 'Frios',
        'queijos' => 'Frios', 'fatiados' => 'Frios',
        'laticinios' => 'Laticinios', 'leite' => 'Laticinios', 'iogurte' => 'Laticinios',
        'manteiga' => 'Laticinios', 'creme de leite' => 'Laticinios',
        'mercearia' => 'Mercearia', 'temperos' => 'Mercearia', 'condimentos' => 'Mercearia',
        'oleos' => 'Mercearia', 'azeites' => 'Mercearia', 'farinhas' => 'Mercearia',
        'acucar' => 'Mercearia', 'sal' => 'Mercearia',
        'cereais' => 'Cereais', 'granola' => 'Cereais', 'aveia' => 'Cereais',
        'arroz' => 'Cereais', 'feijao' => 'Cereais', 'graos' => 'Cereais',
        'massas' => 'Massas', 'macarrao' => 'Massas',
        'molhos' => 'Molhos', 'catchup' => 'Molhos', 'mostarda' => 'Molhos',
        'maionese' => 'Molhos',
        'enlatados' => 'Enlatados', 'conservas' => 'Enlatados',
        'biscoitos' => 'Biscoitos', 'bolachas' => 'Biscoitos', 'snacks' => 'Biscoitos',
        'salgadinhos' => 'Biscoitos', 'chocolates' => 'Biscoitos', 'doces' => 'Biscoitos',
        'bebidas' => 'Bebidas', 'sucos' => 'Bebidas', 'refrigerantes' => 'Bebidas',
        'agua' => 'Bebidas', 'cerveja' => 'Bebidas', 'vinho' => 'Bebidas',
        'limpeza' => 'Limpeza', 'detergente' => 'Limpeza', 'desinfetante' => 'Limpeza',
        'sabao' => 'Limpeza', 'amaciante' => 'Limpeza',
        'higiene' => 'Higiene', 'shampoo' => 'Higiene', 'sabonete' => 'Higiene',
        'papel higienico' => 'Higiene', 'creme dental' => 'Higiene',
        'fraldas' => 'Higiene', 'absorvente' => 'Higiene',
        'congelados' => 'Congelados', 'sorvete' => 'Congelados', 'pizza congelada' => 'Congelados',
        'lasanha' => 'Congelados', 'polpa' => 'Congelados',
    ];

    // ═══════════════════════════════════════════════════════════════════
    // AGRUPAR ITENS POR SECAO
    // ═══════════════════════════════════════════════════════════════════
    $groupedItems = [];
    $totalItemCount = 0;

    foreach ($items as $item) {
        $categoryName = $item['category_name'] ?? '';
        $categoryLower = mb_strtolower(trim($categoryName));

        // Determinar secao baseado no nome da categoria
        $section = 'Mercearia'; // default
        if (isset($categoryToSection[$categoryLower])) {
            $section = $categoryToSection[$categoryLower];
        } else {
            // Busca parcial: verificar se o nome da categoria contem alguma chave
            foreach ($categoryToSection as $key => $sectionName) {
                if (mb_strpos($categoryLower, $key) !== false || mb_strpos($key, $categoryLower) !== false) {
                    $section = $sectionName;
                    break;
                }
            }
        }

        if (!isset($groupedItems[$section])) {
            $groupedItems[$section] = [];
        }

        $groupedItems[$section][] = [
            'id'       => (int)$item['item_id'],
            'product_id' => (int)$item['product_id'],
            'name'     => $item['name'],
            'qty'      => (int)$item['quantity'],
            'price'    => round((float)$item['price'], 2),
            'barcode'  => $item['barcode'] ?? null,
            'image'    => $item['image'] ?? null,
            'weight'   => $item['weight'] ? round((float)$item['weight'], 3) : null,
            'category' => $categoryName
        ];

        $totalItemCount += (int)$item['quantity'];
    }

    // ═══════════════════════════════════════════════════════════════════
    // ORDENAR SECOES POR LAYOUT DO SUPERMERCADO
    // ═══════════════════════════════════════════════════════════════════
    $sections = [];
    $bagPlan = ['normal' => 0, 'fragil' => 0, 'congelado' => 0, 'limpeza' => 0];

    // Ordenar secoes pelo mapeamento de layout
    uksort($groupedItems, function($a, $b) use ($sectionOrder) {
        $orderA = $sectionOrder[$a] ?? 99;
        $orderB = $sectionOrder[$b] ?? 99;
        return $orderA - $orderB;
    });

    foreach ($groupedItems as $sectionName => $sectionItems) {
        $sectionNumber = $sectionOrder[$sectionName] ?? 99;
        $icon = $sectionIcons[$sectionName] ?? 'tag';
        $tip = $sectionTips[$sectionName] ?? 'Verificar qualidade e validade.';
        $bagType = $sectionBagType[$sectionName] ?? 'normal';

        // Ordenar itens dentro da secao por nome
        usort($sectionItems, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $sections[] = [
            'name'           => $sectionName,
            'section_number' => $sectionNumber,
            'icon'           => $icon,
            'items'          => $sectionItems,
            'tip'            => $tip,
            'bag_type'       => $bagType
        ];

        // Calcular sacolas necessarias (estimativa: ~8 itens por sacola)
        $sectionItemCount = array_sum(array_column($sectionItems, 'qty'));
        $bagsNeeded = max(1, ceil($sectionItemCount / 8));
        $bagPlan[$bagType] = ($bagPlan[$bagType] ?? 0) + $bagsNeeded;
    }

    // ═══════════════════════════════════════════════════════════════════
    // ESTIMAR TEMPO DE COLETA
    // ═══════════════════════════════════════════════════════════════════
    $itemCount = count($items);
    $estimatedTimeMin = round($itemCount * 1.5); // 1.5 min por item unico
    $estimatedTimeMin = max(5, $estimatedTimeMin); // Minimo 5 minutos

    // Ajustar se tiver secoes especiais (acougue, padaria = mais tempo)
    if (isset($groupedItems['Acougue'])) {
        $estimatedTimeMin += 5; // Fila do acougue
    }
    if (isset($groupedItems['Padaria'])) {
        $estimatedTimeMin += 3; // Fila da padaria
    }

    // ═══════════════════════════════════════════════════════════════════
    // DICAS COM CLAUDE (OPCIONAL)
    // ═══════════════════════════════════════════════════════════════════
    $aiTips = null;
    if (defined('CLAUDE_API_KEY') && !empty(CLAUDE_API_KEY) && $itemCount > 0) {
        $aiTips = getClaudeShoppingTips($sections, $itemCount, $estimatedTimeMin);
    }

    // Fallback de dicas
    if (!$aiTips) {
        $aiTips = buildFallbackTips($sections, $itemCount);
    }

    // ═══════════════════════════════════════════════════════════════════
    // RESPOSTA
    // ═══════════════════════════════════════════════════════════════════
    response(true, [
        'order_id'           => $order_id,
        'total_items'        => $itemCount,
        'estimated_time_min' => $estimatedTimeMin,
        'sections'           => $sections,
        'bag_plan'           => $bagPlan,
        'ai_tips'            => $aiTips
    ], 'Rota otimizada gerada com sucesso');

} catch (Exception $e) {
    error_log("[rota-otimizada] Erro: " . $e->getMessage());
    response(false, null, 'Erro ao gerar rota otimizada. Tente novamente.', 500);
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNCOES AUXILIARES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Chama Claude para gerar dicas de compras otimizadas
 */
function getClaudeShoppingTips(array $sections, int $itemCount, int $estimatedTime): ?string {
    try {
        // Montar resumo das secoes para o prompt
        $sectionSummary = [];
        foreach ($sections as $section) {
            $itemNames = array_map(function($item) {
                return $item['name'] . ' (x' . $item['qty'] . ')';
            }, $section['items']);
            $sectionSummary[] = $section['name'] . ': ' . implode(', ', array_slice($itemNames, 0, 5));
            if (count($itemNames) > 5) {
                $sectionSummary[count($sectionSummary) - 1] .= ' e mais ' . (count($itemNames) - 5) . ' itens';
            }
        }

        $listText = implode('. ', $sectionSummary);

        $prompt = "Voce e um assistente de shoppers de supermercado. "
                . "Gere 2-3 dicas praticas e curtas (max 200 caracteres total) em portugues brasileiro para otimizar esta coleta de {$itemCount} itens (~{$estimatedTime} min). "
                . "Secoes: {$listText}. "
                . "Foque em: ordem de coleta, conservacao de temperatura, organizacao nas sacolas. "
                . "Responda APENAS as dicas em texto corrido, sem numeracao, sem quebras de linha.";

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . CLAUDE_API_KEY,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => 'claude-3-haiku-20240307',
                'max_tokens' => 200,
                'messages'   => [['role' => 'user', 'content' => $prompt]]
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$result) {
            return null;
        }

        $data = json_decode($result, true);
        $text = $data['content'][0]['text'] ?? null;

        // Sanitizar resposta
        if ($text) {
            $text = trim($text, " \t\n\r\0\x0B\"'");
            $text = mb_substr($text, 0, 500);
        }

        return $text;

    } catch (Exception $e) {
        error_log("[rota-otimizada] Claude error: " . $e->getMessage());
        return null;
    }
}

/**
 * Gera dicas de fallback quando Claude nao esta disponivel
 */
function buildFallbackTips(array $sections, int $itemCount): string {
    $tips = [];

    $sectionNames = array_column($sections, 'name');

    // Dica geral de ordem
    $tips[] = 'Comece pelo hortifruti e termine nos congelados para manter a temperatura.';

    // Dica de sacolas se tiver limpeza
    if (in_array('Limpeza', $sectionNames)) {
        $tips[] = 'Separe produtos de limpeza dos alimentos em sacola propria.';
    }

    // Dica de congelados
    if (in_array('Congelados', $sectionNames)) {
        $tips[] = 'Pegue os congelados por ultimo e use sacola termica.';
    }

    // Dica de volume
    if ($itemCount > 15) {
        $tips[] = 'Pedido grande: use o carrinho para facilitar a coleta.';
    }

    // Dica de frageis
    if (in_array('Molhos', $sectionNames) || in_array('Biscoitos', $sectionNames)) {
        $tips[] = 'Itens frageis: separe vidros e biscoitos em sacola propria.';
    }

    return implode(' ', array_slice($tips, 0, 3));
}
