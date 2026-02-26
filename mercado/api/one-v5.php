<?php
/**
 * ONE 5.0 - API Avançada
 *
 * Tecnologias:
 * - Function Calling Nativo
 * - RAG + Embeddings (busca semântica)
 * - Streaming Responses
 * - Multi-Agent System
 * - Multimodal (Vision)
 * - Voice (STT/TTS)
 *
 * Endpoints:
 * POST /chat         - Chat normal
 * POST /chat/stream  - Chat com streaming
 * POST /chat/voice   - Chat por voz
 * POST /chat/image   - Chat com imagem
 * POST /chat/agents  - Chat com multi-agentes
 *
 * @version 5.0
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

use One\Services\AdvancedAI;
use One\Services\AgentSystem;
use One\Services\SmartConversation;
use One\Services\ServiceBridge;
use One\Utils\Database;

// ============================================================
// INPUT
// ============================================================
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$action = $input['action'] ?? $_GET['action'] ?? 'chat';
$message = trim($input['message'] ?? '');
$customerId = $_SESSION['customer_id'] ?? $input['customer_id'] ?? null;
$partnerId = $_SESSION['market_partner_id'] ?? $input['partner_id'] ?? 100;

// Localização
$userLat = $input['latitude'] ?? $input['lat'] ?? $_SESSION['user_lat'] ?? null;
$userLng = $input['longitude'] ?? $input['lng'] ?? $_SESSION['user_lng'] ?? null;

// ============================================================
// ROUTING
// ============================================================

try {
    switch ($action) {

        // ========================================
        // CHAT NORMAL (SmartConversation)
        // ========================================
        case 'chat':
            if (empty($message)) {
                outputJSON(getWelcomeMessage($customerId, $partnerId));
                break;
            }

            $smart = new SmartConversation($customerId, $partnerId);
            $result = $smart->process($message);

            // Adiciona info do parceiro
            $result['partner'] = getPartnerInfo($partnerId);
            $result['version'] = '5.0';

            outputJSON($result);
            break;

        // ========================================
        // CHAT COM MULTI-AGENTES
        // ========================================
        case 'agents':
        case 'chat/agents':
            if (empty($message)) {
                outputJSON(['error' => 'Mensagem vazia']);
                break;
            }

            $agents = new AgentSystem($customerId, $partnerId);
            $result = $agents->process($message, $_SESSION['one_context'] ?? []);

            // Salva contexto
            $_SESSION['one_context'][] = ['role' => 'user', 'content' => $message];
            $_SESSION['one_context'][] = ['role' => 'assistant', 'content' => $result['response']];

            // Limita contexto
            if (count($_SESSION['one_context']) > 20) {
                $_SESSION['one_context'] = array_slice($_SESSION['one_context'], -20);
            }

            $result['partner'] = getPartnerInfo($partnerId);
            $result['version'] = '5.0-agents';

            outputJSON($result);
            break;

        // ========================================
        // CHAT COM STREAMING
        // ========================================
        case 'stream':
        case 'chat/stream':
            if (empty($message)) {
                outputJSON(['error' => 'Mensagem vazia']);
                break;
            }

            // Configura para streaming
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            $context = $_SESSION['one_context'] ?? [];

            echo "event: start\n";
            echo "data: " . json_encode(['status' => 'streaming']) . "\n\n";
            flush();

            $fullResponse = '';

            foreach (AdvancedAI::chatStream($message, $context) as $chunk) {
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
        // CHAT POR VOZ
        // ========================================
        case 'voice':
        case 'chat/voice':
            // Recebe arquivo de áudio
            $audioFile = null;

            if (!empty($_FILES['audio'])) {
                $audioFile = $_FILES['audio']['tmp_name'];
            } elseif (!empty($input['audio_base64'])) {
                // Decodifica base64
                $audioFile = sys_get_temp_dir() . '/one_audio_' . uniqid() . '.webm';
                file_put_contents($audioFile, base64_decode($input['audio_base64']));
            }

            if (!$audioFile || !file_exists($audioFile)) {
                outputJSON(['error' => 'Arquivo de áudio não recebido']);
                break;
            }

            // Processa áudio
            $result = AdvancedAI::processVoiceMessage($audioFile, $_SESSION['one_context'] ?? []);

            if ($result['success']) {
                // Salva contexto
                $_SESSION['one_context'][] = ['role' => 'user', 'content' => $result['text_input']];
                $_SESSION['one_context'][] = ['role' => 'assistant', 'content' => $result['text_response']];

                // Converte áudio para base64 para enviar
                if ($result['audio_response'] && file_exists($result['audio_response'])) {
                    $result['audio_base64'] = base64_encode(file_get_contents($result['audio_response']));
                    unlink($result['audio_response']); // Remove arquivo temporário
                }
            }

            $result['version'] = '5.0-voice';
            outputJSON($result);
            break;

        // ========================================
        // CHAT COM IMAGEM
        // ========================================
        case 'image':
        case 'chat/image':
            $imageData = null;

            if (!empty($_FILES['image'])) {
                $imageData = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($_FILES['image']['tmp_name']));
            } elseif (!empty($input['image_base64'])) {
                $imageData = $input['image_base64'];
                if (strpos($imageData, 'data:image') !== 0) {
                    $imageData = 'data:image/jpeg;base64,' . $imageData;
                }
            } elseif (!empty($input['image_url'])) {
                $imageData = $input['image_url'];
            }

            if (!$imageData) {
                outputJSON(['error' => 'Imagem não recebida']);
                break;
            }

            $prompt = $message ?: 'O que você vê nesta imagem? Se forem produtos, identifique-os.';

            // Analisa imagem
            $analysis = AdvancedAI::analyzeImage($imageData, $prompt);

            $result = [
                'success' => true,
                'analysis' => $analysis,
                'version' => '5.0-vision'
            ];

            // Se identificou produtos, busca no mercado
            if (preg_match('/produto|leite|arroz|carne|comida/i', $prompt) || empty($message)) {
                $products = AdvancedAI::identifyProductsInImage($imageData);

                if ($products) {
                    $result['identified_products'] = $products;

                    // Busca produtos no mercado
                    $bridge = new ServiceBridge($customerId);
                    $result['available_products'] = [];

                    foreach (array_slice($products, 0, 5) as $p) {
                        $found = $bridge->searchProducts($p['nome'], 2, $partnerId);
                        if (!empty($found['products'])) {
                            $result['available_products'][] = [
                                'identified' => $p['nome'],
                                'found' => $found['products']
                            ];
                        }
                    }
                }
            }

            outputJSON($result);
            break;

        // ========================================
        // BUSCA SEMÂNTICA (RAG)
        // ========================================
        case 'semantic_search':
        case 'search/semantic':
            if (empty($message)) {
                outputJSON(['error' => 'Termo de busca vazio']);
                break;
            }

            $products = AdvancedAI::semanticSearchProducts($message, 10, $partnerId);

            outputJSON([
                'success' => true,
                'query' => $message,
                'products' => $products,
                'count' => count($products),
                'type' => 'semantic',
                'version' => '5.0-rag'
            ]);
            break;

        // ========================================
        // INDEXAR EMBEDDINGS (Admin)
        // ========================================
        case 'index_embeddings':
            $stats = AdvancedAI::indexProductEmbeddings(50);
            outputJSON([
                'success' => true,
                'stats' => $stats,
                'message' => "Indexados: {$stats['indexed']}, Erros: {$stats['errors']}"
            ]);
            break;

        // ========================================
        // FUNCTION CALLING (Teste)
        // ========================================
        case 'function_call':
        case 'test/functions':
            if (empty($message)) {
                outputJSON(['error' => 'Mensagem vazia']);
                break;
            }

            $functions = [
                'search_products' => [
                    'description' => 'Busca produtos no supermercado',
                    'parameters' => ['query' => 'string', 'limit' => 'int']
                ],
                'get_ride_quote' => [
                    'description' => 'Calcula preço de corrida',
                    'parameters' => ['origin' => 'string', 'destination' => 'string']
                ],
                'search_flights' => [
                    'description' => 'Busca voos para um destino',
                    'parameters' => ['destination' => 'string']
                ]
            ];

            $result = AdvancedAI::chatWithFunctions($message, $functions);

            // Executa funções chamadas
            $bridge = new ServiceBridge($customerId);
            $functionResults = [];

            foreach ($result['function_calls'] as $call) {
                switch ($call['name']) {
                    case 'search_products':
                        $functionResults[$call['name']] = $bridge->searchProducts(
                            $call['arguments']['query'] ?? '',
                            $call['arguments']['limit'] ?? 5,
                            $partnerId
                        );
                        break;
                    case 'get_ride_quote':
                        $functionResults[$call['name']] = $bridge->getRideQuote(
                            $call['arguments']['origin'] ?? 'casa',
                            $call['arguments']['destination'] ?? 'centro'
                        );
                        break;
                    case 'search_flights':
                        $functionResults[$call['name']] = $bridge->searchFlights(
                            $call['arguments']['destination'] ?? 'miami'
                        );
                        break;
                }
            }

            outputJSON([
                'success' => true,
                'response' => $result['message'],
                'function_calls' => $result['function_calls'],
                'function_results' => $functionResults,
                'version' => '5.0-functions'
            ]);
            break;

        // ========================================
        // TEXT TO SPEECH
        // ========================================
        case 'tts':
        case 'speech':
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
            } else {
                $result = ['success' => false, 'error' => 'Erro ao gerar áudio'];
            }

            outputJSON($result);
            break;

        // ========================================
        // SPEECH TO TEXT
        // ========================================
        case 'stt':
        case 'transcribe':
            $audioFile = null;

            if (!empty($_FILES['audio'])) {
                $audioFile = $_FILES['audio']['tmp_name'];
            }

            if (!$audioFile) {
                outputJSON(['error' => 'Arquivo de áudio não recebido']);
                break;
            }

            $text = AdvancedAI::speechToText($audioFile);

            outputJSON([
                'success' => (bool) $text,
                'text' => $text,
                'error' => $text ? null : 'Não consegui transcrever'
            ]);
            break;

        // ========================================
        // SET LOCATION
        // ========================================
        case 'set_location':
            if ($userLat && $userLng) {
                $_SESSION['user_lat'] = (float) $userLat;
                $_SESSION['user_lng'] = (float) $userLng;

                $nearest = findNearestPartner($userLat, $userLng);

                if ($nearest) {
                    $_SESSION['market_partner_id'] = $nearest['partner_id'];
                    $partnerId = $nearest['partner_id'];

                    outputJSON([
                        'success' => true,
                        'partner' => $nearest,
                        'message' => "Encontrei o {$nearest['name']} pertinho de você! 🛒"
                    ]);
                } else {
                    outputJSON([
                        'success' => false,
                        'message' => 'Não encontrei mercados por perto...'
                    ]);
                }
            } else {
                outputJSON(['error' => 'Localização não fornecida']);
            }
            break;

        // ========================================
        // STATUS / INFO
        // ========================================
        case 'status':
        case 'info':
            outputJSON([
                'success' => true,
                'version' => '5.0',
                'features' => [
                    'function_calling' => true,
                    'rag_embeddings' => true,
                    'streaming' => true,
                    'multi_agent' => true,
                    'vision' => true,
                    'voice' => true
                ],
                'endpoints' => [
                    '/chat' => 'Chat normal',
                    '/chat/agents' => 'Chat com multi-agentes',
                    '/chat/stream' => 'Chat com streaming',
                    '/chat/voice' => 'Chat por voz',
                    '/chat/image' => 'Chat com imagem',
                    '/search/semantic' => 'Busca semântica'
                ]
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

function getWelcomeMessage(?int $customerId, ?int $partnerId): array
{
    $customerName = null;
    if ($customerId) {
        $customer = Database::fetchOne(
            "SELECT name FROM om_market_customers WHERE customer_id = ?",
            [$customerId]
        );
        $customerName = explode(' ', $customer['name'] ?? '')[0];
    }

    $partnerName = null;
    if ($partnerId) {
        $partner = Database::fetchOne(
            "SELECT name FROM om_market_partners WHERE partner_id = ?",
            [$partnerId]
        );
        $partnerName = $partner['name'] ?? null;
    }

    $greeting = $customerName
        ? "Oi {$customerName}! 😊"
        : "Oi! Sou a ONE 5.0 🚀";

    $context = $partnerName
        ? "Você tá no {$partnerName}."
        : "Me conta sua localização pra achar o mercado mais perto!";

    return [
        'success' => true,
        'response' => "{$greeting}\n{$context}",
        'suggestions' => ['Ver promoções', 'Buscar produto', 'Meu carrinho'],
        'version' => '5.0',
        'features' => ['text', 'voice', 'image', 'agents']
    ];
}

function getPartnerInfo(?int $partnerId): ?array
{
    if (!$partnerId) return null;

    return Database::fetchOne(
        "SELECT partner_id, name, city FROM om_market_partners WHERE partner_id = ?",
        [$partnerId]
    );
}

function findNearestPartner(float $lat, float $lng): ?array
{
    $sql = "
        SELECT partner_id, name, city,
               COALESCE(lat, latitude) as lat,
               COALESCE(lng, longitude) as lng,
               (6371 * acos(cos(radians(?)) * cos(radians(COALESCE(lat, latitude))) *
                cos(radians(COALESCE(lng, longitude)) - radians(?)) +
                sin(radians(?)) * sin(radians(COALESCE(lat, latitude))))) AS distance_km
        FROM om_market_partners
        WHERE status = '1'
        HAVING distance_km <= 50
        ORDER BY distance_km ASC
        LIMIT 1
    ";

    return Database::fetchOne($sql, [$lat, $lng, $lat]);
}
