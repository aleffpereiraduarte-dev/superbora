<?php
/**
 * ONE Market Smart API
 *
 * API principal do mercado com:
 * - Detecção de localização → mercado mais próximo
 * - SmartConversation (100% IA)
 * - Salva no brain pessoal (preferências, buscas)
 * - Memória de contexto
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once dirname(__DIR__, 2) . '/one/autoload.php';

use One\Services\SmartConversation;
use One\Services\MarketService;
use One\Utils\Database;

// ============================================================
// INPUT
// ============================================================
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$message = trim($input['message'] ?? '');
$action = $input['action'] ?? 'chat';

// Localização do usuário
$userLat = $input['latitude'] ?? $input['lat'] ?? $_SESSION['user_lat'] ?? null;
$userLng = $input['longitude'] ?? $input['lng'] ?? $_SESSION['user_lng'] ?? null;

// Dados do usuário
$customerId = $_SESSION['customer_id'] ?? $input['customer_id'] ?? null;
$partnerId = $_SESSION['market_partner_id'] ?? $input['partner_id'] ?? null;

// ============================================================
// ACTIONS
// ============================================================

// Ação: Definir localização
if ($action === 'set_location') {
    if ($userLat && $userLng) {
        $_SESSION['user_lat'] = (float) $userLat;
        $_SESSION['user_lng'] = (float) $userLng;

        // Encontra mercado mais próximo
        $nearest = findNearestPartner($userLat, $userLng);

        if ($nearest) {
            $_SESSION['market_partner_id'] = $nearest['partner_id'];
            $_SESSION['market_partner_name'] = $nearest['name'];

            // Salva no brain pessoal se tiver customer
            if ($customerId) {
                saveToMemory($customerId, 'preference', 'location', 'last_location', json_encode([
                    'lat' => $userLat,
                    'lng' => $userLng
                ]));
                saveToMemory($customerId, 'preference', 'market', 'preferred_partner', $nearest['partner_id']);
            }

            echo json_encode([
                'success' => true,
                'action' => 'location_set',
                'partner' => [
                    'id' => $nearest['partner_id'],
                    'name' => $nearest['name'],
                    'distance' => $nearest['distance_km'],
                    'distance_text' => $nearest['distance_text'],
                    'delivery_time' => $nearest['delivery_time_min'] . '-' . $nearest['delivery_time_max'] . ' min',
                    'min_order' => 'R$ ' . number_format($nearest['min_order'] ?? 30, 2, ',', '.')
                ],
                'message' => "Encontrei o {$nearest['name']} a {$nearest['distance_text']} de você! 🛒"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'success' => false,
            'error' => 'no_partner_nearby',
            'message' => 'Não encontrei mercados próximos a você ainda 😕'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => false,
        'error' => 'missing_location',
        'message' => 'Preciso da sua localização pra encontrar o mercado mais perto!'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ação: Login
if ($action === 'login') {
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    if ($email && $password) {
        $result = processLogin($email, $password);

        if ($result['success'] && $customerId = $result['customer']['id'] ?? null) {
            // Carrega preferências do brain
            $prefs = loadUserPreferences($customerId);
            $result['preferences'] = $prefs;

            // Se tinha localização salva, restaura
            if (!empty($prefs['last_location'])) {
                $loc = json_decode($prefs['last_location'], true);
                if ($loc) {
                    $_SESSION['user_lat'] = $loc['lat'];
                    $_SESSION['user_lng'] = $loc['lng'];
                }
            }
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Ação: Histórico de buscas
if ($action === 'get_history') {
    if ($customerId) {
        $history = getSearchHistory($customerId);
        echo json_encode([
            'success' => true,
            'history' => $history
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Faça login pra ver seu histórico'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Ação: Preferências
if ($action === 'get_preferences') {
    if ($customerId) {
        $prefs = loadUserPreferences($customerId);
        echo json_encode([
            'success' => true,
            'preferences' => $prefs
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Faça login pra ver suas preferências'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Ação: Limpar conversa
if ($action === 'clear') {
    $_SESSION['one_smart_history'] = [];
    echo json_encode([
        'success' => true,
        'message' => 'Conversa limpa! Bora recomeçar? 😊'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// CHAT PRINCIPAL
// ============================================================

if (empty($message)) {
    // Mensagem de boas-vindas
    $welcome = getWelcomeMessage($customerId, $partnerId);
    echo json_encode($welcome, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Se não tem parceiro definido, tenta encontrar
if (!$partnerId && $userLat && $userLng) {
    $nearest = findNearestPartner($userLat, $userLng);
    if ($nearest) {
        $partnerId = $nearest['partner_id'];
        $_SESSION['market_partner_id'] = $partnerId;
    }
}

// Fallback para parceiro padrão
$partnerId = $partnerId ?? 100;

try {
    // Processa com SmartConversation
    $smart = new SmartConversation($customerId, $partnerId);
    $result = $smart->process($message);

    // Salva no brain pessoal
    if ($customerId) {
        // Salva busca
        saveToBrain($customerId, $message, $result['response'], $result['functions_called'][0] ?? 'chat');

        // Salva preferências detectadas
        saveDetectedPreferences($customerId, $message, $result);
    }

    // Adiciona contexto do parceiro
    if ($partnerId) {
        $partner = getPartnerInfo($partnerId);
        if ($partner) {
            $result['partner'] = [
                'id' => $partner['partner_id'],
                'name' => $partner['name'],
                'city' => $partner['city']
            ];
        }
    }

    // Se precisa de login, adiciona mensagem amigável
    if ($result['requires_login']) {
        $result['show_login'] = true;
        $result['login_message'] = getLoginMessage($result['login_reason']);
    }

    // Adiciona sugestões contextuais
    if (empty($result['suggestions'])) {
        $result['suggestions'] = getContextualSuggestions($message, $result);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'internal_error',
        'response' => 'Ops, tive um probleminha... pode tentar de novo? 😅',
        'debug' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// ============================================================
// FUNÇÕES AUXILIARES
// ============================================================

/**
 * Encontra o parceiro mais próximo usando Haversine
 */
function findNearestPartner(float $lat, float $lng, float $maxRadius = 50): ?array
{
    try {
        // Haversine formula in SQL
        $sql = "
            SELECT
                partner_id, name, city, state,
                COALESCE(lat, latitude) as lat,
                COALESCE(lng, longitude) as lng,
                raio_entrega_km, delivery_time_min, delivery_time_max, min_order, delivery_fee,
                (
                    6371 * acos(
                        cos(radians(?)) * cos(radians(COALESCE(lat, latitude))) *
                        cos(radians(COALESCE(lng, longitude)) - radians(?)) +
                        sin(radians(?)) * sin(radians(COALESCE(lat, latitude)))
                    )
                ) AS distance_km
            FROM om_market_partners
            WHERE status = '1'
            AND (COALESCE(lat, latitude) IS NOT NULL AND COALESCE(lng, longitude) IS NOT NULL)
            HAVING distance_km <= ?
            ORDER BY distance_km ASC
            LIMIT 1
        ";

        $partner = Database::fetchOne($sql, [$lat, $lng, $lat, $maxRadius]);

        if ($partner) {
            $partner['distance_text'] = formatDistance($partner['distance_km']);
        }

        return $partner;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Formata distância
 */
function formatDistance(float $km): string
{
    if ($km < 1) {
        return round($km * 1000) . 'm';
    }
    return number_format($km, 1, ',', '') . 'km';
}

/**
 * Salva no brain pessoal
 */
function saveToBrain(int $customerId, string $pergunta, string $resposta, string $intent): void
{
    try {
        Database::execute(
            "INSERT INTO om_one_user_brain (user_id, pergunta, resposta, intent, modulo, uso_count, created_at)
             VALUES (?, ?, ?, ?, 'mercado', 1, NOW())
             ON DUPLICATE KEY UPDATE uso_count = uso_count + 1, updated_at = NOW()",
            [$customerId, $pergunta, $resposta, $intent]
        );
    } catch (Exception $e) {
        // Ignora
    }
}

/**
 * Salva na memória unificada
 */
function saveToMemory(int $customerId, string $type, string $category, string $key, $value, float $confidence = 0.8): void
{
    try {
        Database::execute(
            "INSERT INTO om_one_unified_memory (customer_id, memory_type, category, key_name, value, confidence, source, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'inferred', NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), times_confirmed = times_confirmed + 1, confidence = LEAST(confidence + 0.05, 1.0), updated_at = NOW()",
            [$customerId, $type, $category, $key, is_string($value) ? $value : json_encode($value), $confidence]
        );
    } catch (Exception $e) {
        // Ignora
    }
}

/**
 * Detecta e salva preferências da mensagem
 */
function saveDetectedPreferences(int $customerId, string $message, array $result): void
{
    $msgLower = mb_strtolower($message);

    // Detecta preferências de produto
    if (!empty($result['data']['search_products']['products'])) {
        $query = $result['data']['search_products']['query'] ?? '';
        if ($query) {
            saveToMemory($customerId, 'pattern', 'search', 'recent_search_' . md5($query), $query, 0.6);

            // Salva categoria se detectada
            $firstProduct = $result['data']['search_products']['products'][0] ?? null;
            if ($firstProduct && !empty($firstProduct['category'])) {
                saveToMemory($customerId, 'preference', 'category', 'interested_' . $firstProduct['category'], '1', 0.5);
            }
        }
    }

    // Detecta preferências de evento
    if (!empty($result['data']['suggest_for_event'])) {
        $event = $result['data']['suggest_for_event']['event'] ?? '';
        if ($event) {
            saveToMemory($customerId, 'event', 'planning', $event, date('Y-m-d'), 0.7);
        }
    }

    // Detecta preferências de marca (se mencionar)
    $brands = ['nestle', 'italac', 'piracanjuba', 'aurora', 'sadia', 'perdigao', 'seara'];
    foreach ($brands as $brand) {
        if (strpos($msgLower, $brand) !== false) {
            saveToMemory($customerId, 'preference', 'brand', $brand, '1', 0.7);
        }
    }
}

/**
 * Carrega preferências do usuário
 */
function loadUserPreferences(int $customerId): array
{
    try {
        $rows = Database::fetchAll(
            "SELECT category, key_name, value, confidence
             FROM om_one_unified_memory
             WHERE customer_id = ? AND confidence >= 0.5
             ORDER BY updated_at DESC
             LIMIT 50",
            [$customerId]
        );

        $prefs = [];
        foreach ($rows as $row) {
            $prefs[$row['key_name']] = $row['value'];
        }
        return $prefs;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Histórico de buscas
 */
function getSearchHistory(int $customerId): array
{
    try {
        return Database::fetchAll(
            "SELECT pergunta, intent, uso_count, created_at
             FROM om_one_user_brain
             WHERE user_id = ? AND modulo = 'mercado'
             ORDER BY updated_at DESC
             LIMIT 20",
            [$customerId]
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Info do parceiro
 */
function getPartnerInfo(int $partnerId): ?array
{
    try {
        return Database::fetchOne(
            "SELECT partner_id, name, city, state FROM om_market_partners WHERE partner_id = ?",
            [$partnerId]
        );
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Mensagem de boas-vindas
 */
function getWelcomeMessage(?int $customerId, ?int $partnerId): array
{
    $customerName = null;
    if ($customerId) {
        try {
            $customer = Database::fetchOne(
                "SELECT name FROM om_market_customers WHERE customer_id = ?",
                [$customerId]
            );
            $customerName = explode(' ', $customer['name'] ?? '')[0];
        } catch (Exception $e) {}
    }

    $partnerName = null;
    if ($partnerId) {
        try {
            $partner = Database::fetchOne(
                "SELECT name FROM om_market_partners WHERE partner_id = ?",
                [$partnerId]
            );
            $partnerName = $partner['name'] ?? null;
        } catch (Exception $e) {}
    }

    $greeting = $customerName
        ? "Oi {$customerName}! Que bom te ver de novo 😊"
        : "Oi! Sou a ONE, sua assistente de compras 🛒";

    $context = $partnerName
        ? "Você tá no {$partnerName}."
        : "Me conta sua localização pra eu achar o mercado mais perto!";

    return [
        'success' => true,
        'response' => "{$greeting}\n{$context}",
        'suggestions' => ['Ver promoções', 'Buscar produto', 'Meu carrinho'],
        'needs_location' => !$partnerId
    ];
}

/**
 * Mensagem de login amigável
 */
function getLoginMessage(?string $reason): string
{
    $messages = [
        'add_to_cart' => 'Pra eu guardar os produtos no seu carrinho, preciso que você entre na sua conta rapidinho! É bem rápido, prometo 😊',
        'show_cart' => 'Pra ver seu carrinho, precisa entrar na conta primeiro!',
        'checkout' => 'Pra finalizar a compra, preciso que você faça login!',
        'history' => 'Pra ver seu histórico, precisa entrar na conta!',
        'default' => 'Pra continuar, preciso que você entre na sua conta. É rapidinho!'
    ];

    return $messages[$reason] ?? $messages['default'];
}

/**
 * Sugestões contextuais
 */
function getContextualSuggestions(string $message, array $result): array
{
    $suggestions = [];
    $msgLower = mb_strtolower($message);

    // Se buscou produto
    if (!empty($result['data']['search_products']['products'])) {
        $suggestions = ['Adicionar ao carrinho', 'Ver mais opções', 'Ver promoções'];
    }
    // Se falou de evento
    elseif (preg_match('/(churrasco|festa|aniversário|almoço|jantar)/i', $msgLower)) {
        $suggestions = ['Montar lista', 'Ver sugestões', 'Calcular quantidade'];
    }
    // Se falou de viagem
    elseif (preg_match('/(viagem|viajar|férias|miami|orlando|disney)/i', $msgLower)) {
        $suggestions = ['Ver voos', 'Buscar hotéis', 'Dicas de destino'];
    }
    // Padrão
    else {
        $suggestions = ['Ver promoções', 'Buscar produto', 'Meu carrinho'];
    }

    return array_slice($suggestions, 0, 3);
}

/**
 * Processa login
 */
function processLogin(string $email, string $password): array
{
    try {
        $customer = Database::fetchOne(
            "SELECT customer_id, name, email, password_hash FROM om_market_customers WHERE email = ?",
            [$email]
        );

        if ($customer && password_verify($password, $customer['password_hash'])) {
            $_SESSION['customer_id'] = $customer['customer_id'];
            $_SESSION['customer_name'] = $customer['name'];

            return [
                'success' => true,
                'message' => "Oba, {$customer['name']}! Agora sim podemos continuar 😊",
                'customer' => [
                    'id' => $customer['customer_id'],
                    'name' => $customer['name'],
                    'email' => $customer['email']
                ]
            ];
        }

        return [
            'success' => false,
            'message' => 'Hmm, email ou senha não bateram... quer tentar de novo?'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Ops, tive um problema no login... tenta de novo?'
        ];
    }
}
