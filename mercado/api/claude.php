<?php
/**
 * API Claude - Assistente IA para Shoppers
 * /mercado/shopper/api/claude.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuração da API Claude
$ANTHROPIC_API_KEY = ''; // Deixe vazio para usar respostas simuladas

// Receber dados
$input = json_decode(file_get_contents('php://input'), true);
$prompt = $input['prompt'] ?? '';
$system = $input['system'] ?? 'Você é um assistente de compras de supermercado. Seja conciso e use emojis.';

if (empty($prompt)) {
    echo json_encode(['error' => 'Prompt vazio']);
    exit;
}

// Se não tem API key, usar respostas simuladas inteligentes
if (empty($ANTHROPIC_API_KEY)) {
    $response = getSimulatedResponse($prompt, $system);
    echo json_encode(['response' => $response, 'simulated' => true]);
    exit;
}

// Chamar API real do Claude
try {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    
    $data = [
        'model' => 'claude-3-haiku-20240307',
        'max_tokens' => 500,
        'system' => $system,
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
            'x-api-key: ' . $ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $json = json_decode($result, true);
        $text = $json['content'][0]['text'] ?? 'Erro ao processar resposta.';
        echo json_encode(['response' => $text]);
    } else {
        $response = getSimulatedResponse($prompt, $system);
        echo json_encode(['response' => $response, 'fallback' => true]);
    }
    
} catch (Exception $e) {
    $response = getSimulatedResponse($prompt, $system);
    echo json_encode(['response' => $response, 'error' => $e->getMessage()]);
}

/**
 * Respostas simuladas inteligentes baseadas no contexto
 */
function getSimulatedResponse($prompt, $system) {
    $prompt_lower = mb_strtolower($prompt);
    
    // Saudações
    if (preg_match('/(olá|oi|bom dia|boa tarde|boa noite|hey|hi)/i', $prompt)) {
        return "Olá! 👋 Como posso ajudar com suas compras hoje?";
    }
    
    // Encontrar produto
    if (preg_match('/(onde|encontr|ach|local|corredor|seção)/i', $prompt)) {
        $responses = [
            "🔍 Geralmente esse produto fica na seção de mercearia, corredor central. Se não encontrar, pergunte a um funcionário!",
            "📍 Procure na seção correspondente à categoria do produto. Verifique também as pontas de gôndola!",
            "🛒 Tente verificar a placa do corredor. Se for refrigerado, fica nos freezers ao fundo.",
            "💡 Dica: produtos similares ficam próximos. Se não achar a marca, olhe ao redor."
        ];
        return $responses[array_rand($responses)];
    }
    
    // Substituição
    if (preg_match('/(substitu|troc|altern|similar|outr|não tem|acabou|indisponível)/i', $prompt)) {
        $responses = [
            "🔄 Sugiro procurar marca similar com mesmo peso. Cliente aceita bem quando preço é próximo.",
            "💡 Alternativas: 1) Mesma marca, tamanho diferente 2) Marca concorrente 3) Versão orgânica. Avise o cliente!",
            "✅ Para substituição: mesmo tipo → mesma quantidade → preço similar. Tire foto pro cliente!",
            "📱 Mande mensagem pro cliente com foto do substituto antes de colocar no carrinho."
        ];
        return $responses[array_rand($responses)];
    }
    
    // Mensagem para cliente
    if (preg_match('/(mensagem|msg|avisar|cliente|mandar|enviar|gere|apresent|finaliz)/i', $prompt)) {
        $templates = [
            "Olá! Sou seu shopper e já comecei suas compras! 🛒 Qualquer dúvida, estou aqui!",
            "Oi! Encontrei quase tudo! Só [produto] que não tinha. Posso substituir? 😊",
            "Suas compras estão quase prontas! Só mais alguns itens e já vou pro caixa! 🎉",
            "Olá! Finalizei suas compras e já estou saindo do mercado. Logo seu pedido chega! 🚀"
        ];
        return $templates[array_rand($templates)];
    }
    
    // Próximo item / Rota
    if (preg_match('/(próximo|proximo|perto|rota|ordem)/i', $prompt)) {
        return "📍 Siga a ordem da lista otimizada! Congelados sempre por último! 🧊";
    }
    
    // Finalizar
    if (preg_match('/(finaliz|termin|conclu|pronto)/i', $prompt)) {
        return "✅ Antes de finalizar: confira itens, verifique validades e organize as sacolas! 🛒";
    }
    
    // Resposta genérica
    $generic = [
        "🛒 Entendi! Continue focado. Se precisar de algo específico, me pergunte!",
        "👍 Qualquer dúvida sobre produtos ou substituições, estou aqui!",
        "✨ Perfeito! Qualidade primeiro, velocidade segundo!",
        "💪 Você está indo bem! Continue seguindo a lista!"
    ];
    
    return $generic[array_rand($generic)];
}
?>
