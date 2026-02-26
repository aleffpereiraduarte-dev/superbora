<?php
/**
 * ONE 6.0 - Ultra API
 *
 * API unificada com TODAS as tecnologias avançadas
 *
 * Endpoints:
 * POST /chat          - Chat ultra-inteligente
 * POST /search        - Busca híbrida
 * POST /feedback      - Registra feedback
 * POST /notifications - Notificações proativas
 * POST /predict       - Previsões de recompra
 * GET  /stats         - Estatísticas do sistema
 * GET  /status        - Status e features
 *
 * @version 6.0
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once dirname(__DIR__, 2) . '/one/autoload.php';

use One\Services\OneUltraEngine;
use One\Services\HybridSearch;
use One\Services\PurchasePrediction;
use One\Services\ProactiveNotifications;
use One\Services\SelfLearning;
use One\Services\AdvancedAI;
use One\Services\LiveEmbeddingService;

// ============================================================
// INPUT
// ============================================================
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$action = $input['action'] ?? $_GET['action'] ?? 'chat';
$message = trim($input['message'] ?? '');
$customerId = $_SESSION['customer_id'] ?? $input['customer_id'] ?? null;
$partnerId = $_SESSION['market_partner_id'] ?? $input['partner_id'] ?? 100;

// ============================================================
// ROUTING
// ============================================================

try {
    switch ($action) {

        // ========================================
        // CHAT ULTRA-INTELIGENTE
        // ========================================
        case 'chat':
            $engine = new OneUltraEngine($customerId, $partnerId);

            if (empty($message)) {
                // Retorna saudação + sugestões proativas
                $result = $engine->process('oi');
                $result['is_greeting'] = true;
            } else {
                $result = $engine->process($message, [
                    'skip_cache' => $input['skip_cache'] ?? false
                ]);
            }

            $result['partner_id'] = $partnerId;
            outputJSON($result);
            break;

        // ========================================
        // BUSCA HÍBRIDA
        // ========================================
        case 'search':
            if (empty($message)) {
                outputJSON(['error' => 'Query vazia']);
                break;
            }

            $search = new HybridSearch($partnerId);
            $limit = $input['limit'] ?? 10;

            $result = $search->searchProducts($message, $limit);
            outputJSON($result);
            break;

        // ========================================
        // BUSCA SEMÂNTICA (RAG)
        // ========================================
        case 'semantic':
        case 'search/semantic':
            if (empty($message)) {
                outputJSON(['error' => 'Query vazia']);
                break;
            }

            $products = AdvancedAI::semanticSearchProducts($message, 10, $partnerId);
            outputJSON([
                'success' => true,
                'query' => $message,
                'products' => $products,
                'count' => count($products),
                'type' => 'semantic'
            ]);
            break;

        // ========================================
        // FEEDBACK
        // ========================================
        case 'feedback':
            $interactionId = $input['interaction_id'] ?? 0;
            $feedbackType = $input['feedback'] ?? 'positive'; // positive/negative
            $comment = $input['comment'] ?? null;

            if (!$interactionId) {
                outputJSON(['error' => 'interaction_id obrigatório']);
                break;
            }

            $learning = new SelfLearning($customerId);
            $success = $learning->recordFeedback($interactionId, $feedbackType, $comment);

            outputJSON([
                'success' => $success,
                'message' => $success ? 'Feedback registrado! Obrigado!' : 'Erro ao registrar'
            ]);
            break;

        // ========================================
        // NOTIFICAÇÕES PROATIVAS
        // ========================================
        case 'notifications':
            if (!$customerId) {
                outputJSON(['error' => 'customer_id obrigatório']);
                break;
            }

            $notifications = new ProactiveNotifications($customerId);
            $notifs = $notifications->generateNotifications();

            outputJSON([
                'success' => true,
                'notifications' => $notifs,
                'count' => count($notifs)
            ]);
            break;

        // ========================================
        // PREVISÃO DE RECOMPRA
        // ========================================
        case 'predict':
        case 'predictions':
            if (!$customerId) {
                outputJSON(['error' => 'customer_id obrigatório']);
                break;
            }

            $prediction = new PurchasePrediction($customerId);
            $daysAhead = $input['days'] ?? 7;

            $predictions = $prediction->getPredictedRebuys($daysAhead);
            $shoppingList = $prediction->generateShoppingList($daysAhead);

            outputJSON([
                'success' => true,
                'predictions' => $predictions,
                'shopping_list' => $shoppingList,
                'customer_stats' => $prediction->getCustomerStats()
            ]);
            break;

        // ========================================
        // STREAMING
        // ========================================
        case 'stream':
            if (empty($message)) {
                outputJSON(['error' => 'Mensagem vazia']);
                break;
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            echo "event: start\n";
            echo "data: " . json_encode(['status' => 'streaming']) . "\n\n";
            flush();

            $fullResponse = '';
            foreach (AdvancedAI::chatStream($message) as $chunk) {
                $fullResponse .= $chunk;
                echo "event: chunk\n";
                echo "data: " . json_encode(['content' => $chunk]) . "\n\n";
                flush();
            }

            echo "event: done\n";
            echo "data: " . json_encode(['full_response' => $fullResponse]) . "\n\n";
            flush();
            break;

        // ========================================
        // ESTATÍSTICAS
        // ========================================
        case 'stats':
            $engine = new OneUltraEngine($customerId, $partnerId);
            $stats = $engine->getStats();

            // Adiciona stats de embedding
            $stats['embeddings'] = LiveEmbeddingService::getStats();

            outputJSON($stats);
            break;

        // ========================================
        // STATUS / INFO
        // ========================================
        case 'status':
        case 'info':
            $embeddingStats = LiveEmbeddingService::getStats();

            outputJSON([
                'success' => true,
                'version' => '6.0',
                'codename' => 'Ultra',
                'features' => [
                    // Inteligência
                    'react_reasoning' => true,
                    'chain_of_thought' => true,
                    'self_learning' => true,
                    'sentiment_analysis' => true,

                    // Busca
                    'hybrid_search' => true,
                    'semantic_search' => true,
                    'smart_cache' => true,

                    // Personalização
                    'purchase_prediction' => true,
                    'proactive_notifications' => true,
                    'unified_memory' => true,
                    'conversation_summary' => true,

                    // Multimodal
                    'streaming' => true,
                    'vision' => true,
                    'voice' => true,
                    'multi_agent' => true
                ],
                'embeddings' => [
                    'indexed' => $embeddingStats['indexed_products'],
                    'total' => $embeddingStats['total_products'],
                    'coverage' => $embeddingStats['coverage']
                ],
                'endpoints' => [
                    '/chat' => 'Chat ultra-inteligente',
                    '/search' => 'Busca híbrida',
                    '/semantic' => 'Busca semântica (RAG)',
                    '/feedback' => 'Registrar feedback',
                    '/notifications' => 'Notificações proativas',
                    '/predict' => 'Previsões de recompra',
                    '/stream' => 'Streaming de respostas',
                    '/stats' => 'Estatísticas do sistema'
                ]
            ]);
            break;

        // ========================================
        // INDEXAR EMBEDDINGS
        // ========================================
        case 'index':
        case 'index_embeddings':
            $limit = $input['limit'] ?? 100;
            $stats = LiveEmbeddingService::indexNewProducts($limit);

            outputJSON([
                'success' => true,
                'indexed' => $stats['indexed'],
                'errors' => $stats['errors'],
                'message' => "Indexados {$stats['indexed']} produtos"
            ]);
            break;

        // ========================================
        // MULTI-AGENTES
        // ========================================
        case 'agents':
            if (empty($message)) {
                outputJSON(['error' => 'Mensagem vazia']);
                break;
            }

            $agents = new \One\Services\AgentSystem($customerId, $partnerId);
            $result = $agents->process($message);

            $result['version'] = '6.0-agents';
            outputJSON($result);
            break;

        // ========================================
        // VOZ (TTS)
        // ========================================
        case 'tts':
        case 'speak':
            if (empty($message)) {
                outputJSON(['error' => 'Texto vazio']);
                break;
            }

            $voice = $input['voice'] ?? 'nova';
            $audioFile = AdvancedAI::textToSpeech($message, $voice);

            if ($audioFile && file_exists($audioFile)) {
                $result = [
                    'success' => true,
                    'audio_base64' => base64_encode(file_get_contents($audioFile)),
                    'format' => 'mp3'
                ];
                unlink($audioFile);
                outputJSON($result);
            } else {
                outputJSON(['success' => false, 'error' => 'Erro ao gerar áudio']);
            }
            break;

        // ========================================
        // IMAGEM (Vision)
        // ========================================
        case 'image':
        case 'vision':
            $imageData = $input['image_base64'] ?? $input['image_url'] ?? null;

            if (!$imageData) {
                outputJSON(['error' => 'Imagem não fornecida']);
                break;
            }

            $prompt = $message ?: 'O que você vê nesta imagem?';
            $analysis = AdvancedAI::analyzeImage($imageData, $prompt);

            outputJSON([
                'success' => (bool) $analysis,
                'analysis' => $analysis,
                'version' => '6.0-vision'
            ]);
            break;

        default:
            outputJSON(['error' => 'Ação não reconhecida', 'action' => $action]);
    }

} catch (Exception $e) {
    outputJSON([
        'success' => false,
        'error' => 'Erro interno',
        'message' => $e->getMessage()
    ]);
}

// ============================================================
// FUNÇÕES AUXILIARES
// ============================================================

function outputJSON(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
