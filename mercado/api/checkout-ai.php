<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * CHECKOUT AI API - Sugestões Inteligentes com Claude
 * Endpoint para sugestões de produtos, entrega e pagamento
 * ══════════════════════════════════════════════════════════════════════════════
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Carregar configurações
require_once dirname(__DIR__) . '/includes/env_loader.php';

// API Key do Anthropic (deixar vazio para respostas simuladas)
$ANTHROPIC_API_KEY = env('ANTHROPIC_API_KEY', '');

// Receber dados
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if (empty($action)) {
    echo json_encode(['success' => false, 'error' => 'Ação não informada']);
    exit;
}

// Conexão com banco para buscar produtos
$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    // Sem conexão, usar sugestões genéricas
}

switch ($action) {
    case 'cart_suggestion':
        $items = $input['items'] ?? [];
        $total = $input['total'] ?? 0;
        echo json_encode(getCartSuggestion($items, $total, $pdo, $ANTHROPIC_API_KEY));
        break;

    case 'delivery_suggestion':
        $items = $input['items'] ?? [];
        $total = $input['total'] ?? 0;
        echo json_encode(getDeliverySuggestion($items, $total));
        break;

    case 'payment_suggestion':
        $total = $input['total'] ?? 0;
        echo json_encode(getPaymentSuggestion($total));
        break;

    case 'substitution_suggestion':
        $productId = $input['product_id'] ?? 0;
        echo json_encode(getSubstitutionSuggestion($productId, $pdo));
        break;

    case 'tracking_suggestion':
        $orderId = $input['order_id'] ?? 0;
        $status = $input['status'] ?? '';
        $canAddItems = $input['can_add_items'] ?? false;
        $scanProgress = $input['scan_progress'] ?? 0;
        echo json_encode(getTrackingSuggestion($orderId, $status, $canAddItems, $scanProgress, $pdo));
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Ação desconhecida']);
}

/**
 * Sugestão de produtos complementares baseado no carrinho
 */
function getCartSuggestion($items, $total, $pdo, $apiKey) {
    // Mapear categorias de produtos comuns
    $complementaryPairs = [
        // Café da manhã
        'pão' => ['manteiga', 'queijo', 'presunto', 'leite', 'café'],
        'café' => ['açúcar', 'leite', 'biscoito', 'pão'],
        'leite' => ['achocolatado', 'cereal', 'café', 'pão'],

        // Almoço/Jantar
        'arroz' => ['feijão', 'óleo', 'sal', 'alho', 'cebola'],
        'feijão' => ['arroz', 'linguiça', 'bacon', 'alho'],
        'macarrão' => ['molho de tomate', 'queijo ralado', 'carne moída'],
        'carne' => ['alho', 'cebola', 'tempero', 'azeite'],
        'frango' => ['tempero', 'limão', 'alho', 'batata'],

        // Lanches
        'presunto' => ['pão', 'queijo', 'maionese'],
        'queijo' => ['presunto', 'pão', 'tomate'],
        'biscoito' => ['leite', 'café', 'suco'],

        // Bebidas
        'refrigerante' => ['gelo', 'salgadinho', 'pizza'],
        'cerveja' => ['gelo', 'amendoim', 'salgadinho'],
        'suco' => ['biscoito', 'pão', 'fruta'],

        // Limpeza
        'detergente' => ['esponja', 'sabão em pó', 'desinfetante'],
        'sabão em pó' => ['amaciante', 'água sanitária'],

        // Higiene
        'shampoo' => ['condicionador', 'sabonete'],
        'papel higiênico' => ['sabonete', 'creme dental']
    ];

    // Analisar itens do carrinho
    $cartItems = [];
    foreach ($items as $item) {
        $cartItems[] = strtolower($item['name'] ?? '');
    }

    // Encontrar sugestão complementar
    $suggestion = null;
    $suggestedProduct = null;

    foreach ($cartItems as $itemName) {
        foreach ($complementaryPairs as $product => $complements) {
            if (stripos($itemName, $product) !== false) {
                // Verificar se algum complemento já está no carrinho
                foreach ($complements as $complement) {
                    $alreadyInCart = false;
                    foreach ($cartItems as $checkItem) {
                        if (stripos($checkItem, $complement) !== false) {
                            $alreadyInCart = true;
                            break;
                        }
                    }

                    if (!$alreadyInCart) {
                        // Buscar produto no banco
                        if ($pdo) {
                            $suggestedProduct = findProductByName($pdo, $complement);
                        }

                        $messages = [
                            "Vi que você tem $product no carrinho. Que tal adicionar $complement para completar?",
                            "Você esqueceu o $complement para combinar com seu $product?",
                            "$product sem $complement? Adicione agora com um clique!",
                            "Dica: $complement combina perfeitamente com $product!",
                            "Clientes que compraram $product também levaram $complement."
                        ];
                        $suggestion = $messages[array_rand($messages)];
                        break 3;
                    }
                }
            }
        }
    }

    // Se não encontrou sugestão específica, usar genérica
    if (!$suggestion) {
        if ($total < 50) {
            $suggestion = "Faltando R$ " . number_format(50 - $total, 2, ',', '.') . " para frete grátis! Que tal adicionar mais alguns itens?";
        } elseif ($total > 150) {
            $suggestion = "Excelente carrinho! Para pedidos grandes como o seu, recomendo PIX - é instantâneo e sem taxas.";
        } else {
            $genericSuggestions = [
                "Já conferiu nossa seção de ofertas? Produtos com até 30% de desconto!",
                "Sabia que comprando hoje você ganha pontos de fidelidade?",
                "Seu pedido será entregue rapidinho! Estamos prontos para separar."
            ];
            $suggestion = $genericSuggestions[array_rand($genericSuggestions)];
        }
    }

    // Se tiver API key, usar Claude para sugestão mais inteligente
    if (!empty($apiKey) && count($items) > 0) {
        $claudeSuggestion = getClaudeSuggestion($items, $total, $apiKey);
        if ($claudeSuggestion) {
            $suggestion = $claudeSuggestion;
        }
    }

    return [
        'success' => true,
        'suggestion' => $suggestion,
        'product' => $suggestedProduct
    ];
}

/**
 * Buscar produto no banco por nome aproximado
 */
function findProductByName($pdo, $searchTerm) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.product_id as id, pd.name,
                   COALESCE(ps.price, p.price) as price,
                   p.image
            FROM oc_product p
            JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
            LEFT JOIN oc_product_special ps ON p.product_id = ps.product_id
                AND ps.date_start <= NOW() AND (ps.date_end >= NOW() OR ps.date_end = '0000-00-00')
            WHERE pd.name LIKE ? AND p.status = '1' AND p.quantity > 0
            ORDER BY p.sort_order, p.product_id
            LIMIT 1
        ");
        $stmt->execute(['%' . $searchTerm . '%']);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Sugestão de tipo de entrega
 */
function getDeliverySuggestion($items, $total) {
    $hasPerishables = false;
    $hasFrozen = false;
    $itemCount = count($items);

    $perishableKeywords = ['leite', 'iogurte', 'queijo', 'presunto', 'carne', 'frango', 'peixe', 'frios'];
    $frozenKeywords = ['sorvete', 'congelado', 'picolé', 'gelo', 'frozen'];

    foreach ($items as $item) {
        $name = strtolower($item['name'] ?? '');

        foreach ($perishableKeywords as $keyword) {
            if (stripos($name, $keyword) !== false) {
                $hasPerishables = true;
                break;
            }
        }

        foreach ($frozenKeywords as $keyword) {
            if (stripos($name, $keyword) !== false) {
                $hasFrozen = true;
                break;
            }
        }
    }

    if ($hasFrozen) {
        return [
            'success' => true,
            'recommendation' => 'express',
            'reason' => 'Você tem produtos congelados no carrinho. Entrega expressa mantém seus produtos na temperatura ideal!'
        ];
    }

    if ($hasPerishables) {
        return [
            'success' => true,
            'recommendation' => 'normal',
            'reason' => 'Para seus produtos perecíveis, recomendamos entrega em até 1 hora para manter a qualidade.'
        ];
    }

    if ($total > 100) {
        return [
            'success' => true,
            'recommendation' => 'scheduled',
            'reason' => 'Para pedidos grandes, a entrega agendada é mais econômica e você escolhe o melhor horário!'
        ];
    }

    return [
        'success' => true,
        'recommendation' => 'normal',
        'reason' => 'Entrega normal em 40-60 minutos. Perfeito para seu pedido!'
    ];
}

/**
 * Sugestão de método de pagamento
 */
function getPaymentSuggestion($total) {
    if ($total > 150) {
        return [
            'success' => true,
            'recommendation' => 'pix',
            'reason' => 'Para valores acima de R$150, PIX é ideal: instantâneo, sem taxas e seu pedido é processado na hora!'
        ];
    }

    if ($total > 100) {
        return [
            'success' => true,
            'recommendation' => 'card',
            'reason' => 'Você pode parcelar em até 3x sem juros no cartão! Ou use PIX para desconto.'
        ];
    }

    return [
        'success' => true,
        'recommendation' => 'pix',
        'reason' => 'PIX é a forma mais rápida! Pague em segundos e seu pedido já começa a ser separado.'
    ];
}

/**
 * Sugestão de substituição para produto indisponível
 */
function getSubstitutionSuggestion($productId, $pdo) {
    if (!$pdo || !$productId) {
        return ['success' => false, 'error' => 'Produto não informado'];
    }

    try {
        // Buscar categoria do produto
        $stmt = $pdo->prepare("
            SELECT p2c.category_id, pd.name as original_name, p.price as original_price
            FROM oc_product p
            JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
            JOIN oc_product_to_category p2c ON p.product_id = p2c.product_id
            WHERE p.product_id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        $original = $stmt->fetch();

        if (!$original) {
            return ['success' => false, 'error' => 'Produto não encontrado'];
        }

        // Buscar substitutos na mesma categoria
        $stmt = $pdo->prepare("
            SELECT p.product_id as id, pd.name,
                   COALESCE(ps.price, p.price) as price,
                   p.image
            FROM oc_product p
            JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
            JOIN oc_product_to_category p2c ON p.product_id = p2c.product_id
            LEFT JOIN oc_product_special ps ON p.product_id = ps.product_id
                AND ps.date_start <= NOW() AND (ps.date_end >= NOW() OR ps.date_end = '0000-00-00')
            WHERE p2c.category_id = ?
              AND p.product_id != ?
              AND p.status = '1'
              AND p.quantity > 0
            ORDER BY ABS(COALESCE(ps.price, p.price) - ?) ASC
            LIMIT 3
        ");
        $stmt->execute([$original['category_id'], $productId, $original['original_price']]);
        $substitutes = $stmt->fetchAll();

        $message = "O produto '{$original['original_name']}' está temporariamente indisponível. ";

        if (!empty($substitutes)) {
            $message .= "Que tal um destes substitutos?";
            return [
                'success' => true,
                'message' => $message,
                'substitutes' => $substitutes
            ];
        }

        return [
            'success' => true,
            'message' => $message . "Infelizmente não encontramos substitutos similares.",
            'substitutes' => []
        ];

    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Erro ao buscar substitutos'];
    }
}

/**
 * Sugestão usando Claude API
 */
function getClaudeSuggestion($items, $total, $apiKey) {
    $itemNames = array_map(function($item) {
        return $item['name'] ?? '';
    }, $items);

    $prompt = "Você é um assistente de compras de supermercado. O cliente tem no carrinho: " .
              implode(', ', $itemNames) . ". Total: R$ " . number_format($total, 2, ',', '.') .
              ". Dê UMA sugestão curta (máx 100 caracteres) de produto complementar que faz sentido. " .
              "Seja direto e amigável. Use português brasileiro.";

    try {
        $ch = curl_init('https://api.anthropic.com/v1/messages');

        $data = [
            'model' => 'claude-3-haiku-20240307',
            'max_tokens' => 100,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_TIMEOUT => 10
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $json = json_decode($result, true);
            return $json['content'][0]['text'] ?? null;
        }

    } catch (Exception $e) {
        error_log("Claude API error: " . $e->getMessage());
    }

    return null;
}

/**
 * Sugestao contextual para pagina de acompanhamento
 */
function getTrackingSuggestion($orderId, $status, $canAddItems, $scanProgress, $pdo) {
    // Sugestoes baseadas no status e contexto
    $suggestions = [];

    switch ($status) {
        case 'pending':
            $suggestions = [
                'Estamos buscando o melhor shopper para seu pedido. Isso leva apenas alguns instantes!',
                'Seu pedido esta na fila! Em breve um shopper dedicado vai aceita-lo.',
                'Relaxe! Estamos conectando voce ao shopper mais proximo.'
            ];
            break;

        case 'confirmed':
            if ($canAddItems) {
                $suggestions = [
                    'Pedido confirmado! Lembrou de algo? Adicione agora antes do shopper comecar!',
                    'Ainda da tempo de adicionar itens! O shopper ainda nao comecou as compras.',
                    'Quer adicionar mais alguma coisa? Clique no botao acima!'
                ];
            } else {
                $suggestions = [
                    'Seu shopper ja esta a caminho do mercado!',
                    'Pedido confirmado e em andamento. Acompanhe o progresso aqui!'
                ];
            }
            break;

        case 'accepted':
            if ($canAddItems) {
                $suggestions = [
                    'Shopper aceitou seu pedido! Se lembrar de algo, adicione agora - ainda da tempo!',
                    'Ultima chance de adicionar itens antes das compras comecarem!',
                    'Esqueceu o pao? O leite? Adicione agora com um clique!'
                ];
            } else {
                $suggestions = [
                    'Seu shopper ja esta no mercado e vai comecar a separar seus produtos!'
                ];
            }
            break;

        case 'shopping':
            if ($canAddItems && $scanProgress < 30) {
                $remaining = 30 - $scanProgress;
                $suggestions = [
                    "O shopper esta comprando! Voce ainda pode adicionar itens (progresso: {$scanProgress}%).",
                    "Corre! Ainda da tempo de adicionar mais {$remaining}% do pedido pode ser alterado.",
                    'Shopper esta separando seus produtos. Adicione algo rapidinho se precisar!'
                ];
            } else {
                $progressMsg = $scanProgress >= 50 ? 'Mais da metade ja foi separada!' : '';
                $suggestions = [
                    "Seu pedido esta sendo separado com carinho. {$progressMsg}",
                    "O shopper esta trabalhando no seu pedido. Progresso: {$scanProgress}%.",
                    'Quase la! Seu shopper esta finalizando a separacao dos produtos.'
                ];
            }
            break;

        case 'packing':
            $suggestions = [
                'Produtos separados! Agora o shopper esta embalando tudo com cuidado.',
                'Seu pedido esta sendo embalado. Logo estara pronto para entrega!',
                'Finalizando o empacotamento. Prepare-se para receber!'
            ];
            break;

        case 'ready':
            $suggestions = [
                'Pedido pronto! Um entregador esta sendo designado agora.',
                'Tudo embalado e esperando o entregador. Fique atento!',
                'Seu pedido esta pronto para sair. O entregador ja ja chega!'
            ];
            break;

        case 'delivering':
            $suggestions = [
                'Entregador a caminho! Fique proximo ao endereco de entrega.',
                'Seu pedido esta vindo! Verifique se o interfone esta funcionando.',
                'Quase ai! O entregador esta levando suas compras ate voce.'
            ];
            break;

        case 'delivered':
            $suggestions = [
                'Pedido entregue com sucesso! Que tal avaliar sua experiencia?',
                'Obrigado por comprar conosco! Esperamos que tenha gostado.',
                'Tudo certo! Seu pedido foi entregue. Ate a proxima!'
            ];
            break;

        case 'cancelled':
            $suggestions = [
                'Este pedido foi cancelado. Precisa de ajuda? Fale conosco!',
                'Pedido cancelado. Que tal fazer um novo pedido?'
            ];
            break;

        default:
            $suggestions = [
                'Acompanhe seu pedido em tempo real aqui!',
                'Estamos cuidando do seu pedido. Fique tranquilo!'
            ];
    }

    // Adicionar sugestao de produto se puder adicionar itens
    $productSuggestion = null;
    if ($canAddItems && $pdo && $orderId) {
        $productSuggestion = getComplementaryProductForOrder($orderId, $pdo);
    }

    return [
        'success' => true,
        'suggestion' => $suggestions[array_rand($suggestions)],
        'can_add_items' => $canAddItems,
        'product_suggestion' => $productSuggestion
    ];
}

/**
 * Buscar produto complementar baseado nos itens do pedido
 */
function getComplementaryProductForOrder($orderId, $pdo) {
    try {
        // Buscar itens do pedido
        $stmt = $pdo->prepare("
            SELECT oi.product_id, pd.name
            FROM om_market_order_items oi
            JOIN oc_product_description pd ON oi.product_id = pd.product_id AND pd.language_id = 2
            WHERE oi.order_id = ?
            LIMIT 5
        ");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();

        if (empty($items)) return null;

        // Mapear complementos
        $complementaryPairs = [
            'pao' => 'manteiga',
            'cafe' => 'acucar',
            'leite' => 'achocolatado',
            'arroz' => 'feijao',
            'macarrao' => 'molho',
            'carne' => 'tempero',
            'presunto' => 'queijo',
            'refrigerante' => 'gelo'
        ];

        foreach ($items as $item) {
            $itemName = strtolower($item['name']);
            foreach ($complementaryPairs as $product => $complement) {
                if (stripos($itemName, $product) !== false) {
                    // Buscar o produto complementar
                    return findProductByName($pdo, $complement);
                }
            }
        }

    } catch (Exception $e) {
        // Silently fail
    }

    return null;
}
