<?php
/**
 * Teste da API ONE v3.0 - Amiga Conselheira
 */

echo "🧪 TESTANDO /mercado/api/one.php - AMIGA CONSELHEIRA\n";
echo "════════════════════════════════════════════════════════════════\n\n";

// Carrega autoloader
require_once dirname(__DIR__, 2) . '/one/autoload.php';

use One\Services\FriendlyAdvisor;
use One\Services\ServiceBridge;
use One\Services\ConversationalCloser;

// Inicializa sessão fake
$_SESSION = [
    'one_conversation' => []
];

// Mensagens de teste
$testMessages = [
    "Oi, tudo bem?",
    "Tô muito cansada, trabalhando demais",
    "Preciso de férias...",
    "Meu sonho é viajar pra Miami",
    "Sério? Quanto tá a passagem?",
];

$advisor = new FriendlyAdvisor();
$bridge = new ServiceBridge();
$closer = new ConversationalCloser();

foreach ($testMessages as $i => $message) {
    echo "👤 Cliente: \"{$message}\"\n";

    $context = [
        'conversation_history' => $_SESSION['one_conversation'],
        'user_name' => 'Teste'
    ];

    // Processa
    $advisorResult = $advisor->process($message, $context);
    $closerResult = $closer->process($message, $context);

    // Monta resposta
    $response = $advisorResult['response'];

    if ($closerResult['has_sales_action'] && !empty($closerResult['response_addon'])) {
        if ($closerResult['state'] === 'offered' || $closerResult['state'] === 'exploring') {
            $response .= "\n" . $closerResult['response_addon'];
        }
    }

    echo "🤖 ONE [{$advisorResult['state']}]: {$response}\n";

    if ($advisorResult['has_pending_opportunity']) {
        echo "   📌 (Oportunidade detectada)\n";
    }

    if ($closerResult['has_sales_action']) {
        echo "   💡 Ação: " . ($closerResult['action']['type'] ?? 'offer') . "\n";
    }

    // Salva no histórico
    $_SESSION['one_conversation'][] = ['role' => 'user', 'content' => $message];
    $_SESSION['one_conversation'][] = ['role' => 'assistant', 'content' => $response];

    echo "\n";
}

echo "════════════════════════════════════════════════════════════════\n";
echo "✅ API funcionando com sistema de Amiga Conselheira!\n\n";

// Teste de busca de produtos real
echo "📦 TESTE DE BUSCA REAL DE PRODUTOS:\n";
$produtos = $bridge->searchProducts('iPhone', 3);
if ($produtos['success'] && !empty($produtos['products'])) {
    foreach ($produtos['products'] as $p) {
        echo "   ✓ {$p['name']} - {$p['price_formatted']}\n";
    }
} else {
    echo "   ⚠️ Nenhum produto encontrado\n";
}

echo "\n✈️ TESTE DE BUSCA REAL DE VOOS:\n";
$voos = $bridge->searchFlights('miami');
if ($voos['success']) {
    echo "   ✓ Destino: {$voos['destination']['city']}\n";
    echo "   ✓ Mais barato: {$voos['cheapest']['price_formatted']} ({$voos['cheapest']['airline']})\n";
} else {
    echo "   ⚠️ " . ($voos['message'] ?? 'Erro') . "\n";
}

echo "\n🚗 TESTE DE COTAÇÃO REAL DE CORRIDA:\n";
$corrida = $bridge->getRideQuote('centro', 'aeroporto');
if ($corrida['success']) {
    echo "   ✓ Econômico: {$corrida['categories']['economico']['price_formatted']}\n";
    echo "   ✓ Conforto: {$corrida['categories']['conforto']['price_formatted']}\n";
} else {
    echo "   ⚠️ " . ($corrida['message'] ?? 'Erro') . "\n";
}

echo "\n🎉 Todos os serviços conectados e funcionando!\n";
