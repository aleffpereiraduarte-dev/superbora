<?php
/**
 * POST /api/mercado/matching-ai.php
 * Smart Shopper-Order Matching AI
 *
 * Quando um novo pedido e criado (status=pendente), este endpoint usa IA para
 * recomendar o melhor shopper disponivel.
 *
 * AUTENTICACAO: Admin auth OU header X-API-Key (para chamadas internas)
 * Body: { "order_id": 123 }
 *
 * SEGURANCA:
 * - Autenticacao admin ou API key interna
 * - Prepared statements (sem SQL injection)
 * - Fallback algoritmico se Claude API falhar
 * - Timeout de 10s na chamada Claude
 */

require_once __DIR__ . "/config/auth.php";

// Definir CLAUDE_API_KEY como constante se ainda nao estiver definida
if (!defined('CLAUDE_API_KEY')) {
    define('CLAUDE_API_KEY', $_ENV['CLAUDE_API_KEY'] ?? '');
}

try {
    $input = getInput();
    $db = getDB();

    // =====================================================================
    // AUTENTICACAO - Admin auth OU API Key interna
    // =====================================================================
    $isAuthenticated = false;

    // Metodo 1: Header X-API-Key
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $adminToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
    // SECURITY: Require minimum token length to prevent bypass with empty/short tokens
    if (!empty($apiKey) && !empty($adminToken) && strlen($adminToken) >= 32 && hash_equals($adminToken, $apiKey)) {
        $isAuthenticated = true;
    }

    // Metodo 2: Admin auth via token Bearer
    if (!$isAuthenticated) {
        try {
            requireAdminAuth();
            $isAuthenticated = true;
        } catch (Exception $e) {
            // Nao autenticado via admin
        }
    }

    if (!$isAuthenticated) {
        response(false, null, "Acesso nao autorizado. Requer autenticacao de admin ou API key valida.", 401);
    }

    // =====================================================================
    // VALIDACAO DE INPUT
    // =====================================================================
    $order_id = (int)($input['order_id'] ?? 0);

    if (!$order_id) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    // =====================================================================
    // BUSCAR DADOS DO PEDIDO
    // =====================================================================
    $stmt = $db->prepare("
        SELECT o.*,
            p.name AS parceiro_nome,
            p.address AS parceiro_endereco,
            p.latitude AS parceiro_lat,
            p.longitude AS parceiro_lng,
            (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) AS total_itens
        FROM om_market_orders o
        INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    if ($pedido['status'] !== 'pendente') {
        response(false, null, "Pedido nao esta com status pendente (atual: {$pedido['status']})", 400);
    }

    if ($pedido['shopper_id']) {
        response(false, null, "Pedido ja foi atribuido a um shopper", 409);
    }

    $parceiro_lat = floatval($pedido['parceiro_lat']);
    $parceiro_lng = floatval($pedido['parceiro_lng']);
    $total_itens = (int)$pedido['total_itens'];
    $valor_total = floatval($pedido['total']);

    // =====================================================================
    // BUSCAR SHOPPERS DISPONIVEIS
    // =====================================================================
    $stmt = $db->prepare("
        SELECT
            s.shopper_id,
            s.nome,
            s.latitude,
            s.longitude,
            s.total_entregas,
            s.rating,
            s.ultima_atividade
        FROM om_market_shoppers s
        WHERE s.disponivel = 1
          AND s.status = '1'
          AND s.latitude IS NOT NULL
          AND s.longitude IS NOT NULL
    ");
    $stmt->execute();
    $shoppers = $stmt->fetchAll();

    if (empty($shoppers)) {
        response(false, [
            'order_id' => $order_id,
            'motivo' => 'nenhum_shopper_disponivel'
        ], "Nenhum shopper disponivel no momento", 200);
    }

    // =====================================================================
    // SCORING ALGORITMICO (RAPIDO)
    // =====================================================================
    $candidatos = [];

    foreach ($shoppers as $shopper) {
        $score = 0;
        $detalhes = [];

        // 1. Distancia ate a loja (Haversine) - max 40 pontos
        $distancia = haversineDistance(
            $parceiro_lat, $parceiro_lng,
            floatval($shopper['latitude']), floatval($shopper['longitude'])
        );

        if ($distancia <= 1) {
            $score_distancia = 40;
        } elseif ($distancia <= 3) {
            $score_distancia = 35;
        } elseif ($distancia <= 5) {
            $score_distancia = 25;
        } elseif ($distancia <= 10) {
            $score_distancia = 15;
        } elseif ($distancia <= 20) {
            $score_distancia = 5;
        } else {
            $score_distancia = 0;
        }
        $score += $score_distancia;
        $detalhes['distancia_km'] = round($distancia, 2);
        $detalhes['score_distancia'] = $score_distancia;

        // 2. Rating - max 25 pontos
        $rating = floatval($shopper['rating'] ?? 0);
        $score_rating = ($rating / 5.0) * 25;
        $score += $score_rating;
        $detalhes['rating'] = $rating;
        $detalhes['score_rating'] = round($score_rating, 1);

        // 3. Experiencia (entregas completadas) - max 20 pontos
        $total_entregas = (int)($shopper['total_entregas'] ?? 0);
        $score_experiencia = min($total_entregas, 100) / 100 * 20;
        $score += $score_experiencia;
        $detalhes['total_entregas'] = $total_entregas;
        $detalhes['score_experiencia'] = round($score_experiencia, 1);

        // 4. Recencia de atividade - max 15 pontos
        $ultima_atividade = $shopper['ultima_atividade'];
        $score_recencia = 0;
        if ($ultima_atividade) {
            $diff_minutos = (time() - strtotime($ultima_atividade)) / 60;
            if ($diff_minutos <= 10) {
                $score_recencia = 15;
            } elseif ($diff_minutos <= 30) {
                $score_recencia = 10;
            } elseif ($diff_minutos <= 60) {
                $score_recencia = 5;
            }
        }
        $score += $score_recencia;
        $detalhes['ultima_atividade'] = $ultima_atividade;
        $detalhes['score_recencia'] = $score_recencia;
        $detalhes['score_total'] = round($score, 1);

        $candidatos[] = [
            'shopper_id' => (int)$shopper['shopper_id'],
            'nome' => $shopper['nome'],
            'score' => round($score, 1),
            'detalhes' => $detalhes
        ];
    }

    // Ordenar por score decrescente
    usort($candidatos, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    // Top 3 candidatos
    $top3 = array_slice($candidatos, 0, 3);

    // =====================================================================
    // DECISAO FINAL COM CLAUDE AI
    // =====================================================================
    $ai_usado = false;
    $ai_resposta = null;
    $escolhido = $top3[0]; // Fallback: melhor score algoritmico

    if (count($top3) > 1 && !empty(CLAUDE_API_KEY)) {
        try {
            $ai_resultado = consultarClaudeMatching($pedido, $top3, $total_itens, $valor_total);
            if ($ai_resultado) {
                $ai_usado = true;
                $ai_resposta = $ai_resultado['reasoning'];

                // Claude recomendou um shopper especifico
                $shopper_id_recomendado = $ai_resultado['shopper_id'];
                foreach ($top3 as $c) {
                    if ($c['shopper_id'] === $shopper_id_recomendado) {
                        $escolhido = $c;
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[matching-ai] Erro Claude API: " . $e->getMessage());
            // Fallback: usar score algoritmico (ja definido)
        }
    }

    // =====================================================================
    // NOTIFICAR SHOPPER ESCOLHIDO (fire-and-forget)
    // =====================================================================
    $shopper_id = $escolhido['shopper_id'];

    $notifPayload = json_encode([
        'title' => 'Novo Pedido!',
        'body' => "Pedido #{$order_id} disponivel - {$total_itens} itens",
        'customer_id' => $shopper_id,
        'user_type' => 'shopper',
        'topic' => 'orders',
        'data' => ['order_id' => $order_id, 'url' => '/mercado/shopper/pedidos.php']
    ]);

    $ch2 = curl_init('http://localhost/api/notifications/send.php');
    curl_setopt_array($ch2, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $notifPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . ($adminToken ?: ($_ENV['ADMIN_API_TOKEN'] ?? ''))
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3
    ]);
    curl_exec($ch2);
    curl_close($ch2);

    // =====================================================================
    // RESPOSTA
    // =====================================================================
    response(true, [
        'order_id' => $order_id,
        'recomendacao' => [
            'shopper_id' => $escolhido['shopper_id'],
            'nome' => $escolhido['nome'],
            'score' => $escolhido['score'],
            'detalhes' => $escolhido['detalhes']
        ],
        'ai' => [
            'utilizado' => $ai_usado,
            'modelo' => $ai_usado ? 'claude-3-haiku-20240307' : null,
            'reasoning' => $ai_resposta
        ],
        'candidatos_avaliados' => count($candidatos),
        'top3' => $top3,
        'pedido' => [
            'parceiro' => $pedido['parceiro_nome'],
            'total_itens' => $total_itens,
            'valor_total' => $valor_total
            // SECURITY: delivery_address removed — customer PII should not be in matching API response
        ]
    ], "Shopper recomendado com sucesso");

} catch (Exception $e) {
    error_log("[matching-ai] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar matching de shopper. Tente novamente.", 500);
}

// =============================================================================
// FUNCOES AUXILIARES
// =============================================================================

/**
 * Calcula distancia entre dois pontos geograficos (Haversine)
 * @return float Distancia em km
 */
function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371; // Raio da Terra em km

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $R * $c;
}

/**
 * Consulta Claude API para decisao final de matching
 * @return array|null ['shopper_id' => int, 'reasoning' => string]
 */
function consultarClaudeMatching(array $pedido, array $top3, int $total_itens, float $valor_total): ?array {
    $candidatos_texto = "";
    foreach ($top3 as $i => $c) {
        $num = $i + 1;
        $candidatos_texto .= "Candidato {$num}: {$c['nome']} (ID: {$c['shopper_id']})\n";
        $candidatos_texto .= "  - Score algoritmico: {$c['score']}/100\n";
        $candidatos_texto .= "  - Distancia ate a loja: {$c['detalhes']['distancia_km']}km\n";
        $candidatos_texto .= "  - Avaliacao: {$c['detalhes']['rating']}/5.0\n";
        $candidatos_texto .= "  - Entregas completadas: {$c['detalhes']['total_entregas']}\n";
        $candidatos_texto .= "  - Ultima atividade: {$c['detalhes']['ultima_atividade']}\n\n";
    }

    $valor_fmt = number_format($valor_total, 2, ',', '.');

    $prompt = "Voce e o sistema de matching inteligente do OneMundo Mercado. "
        . "Analise os dados abaixo e escolha o MELHOR shopper para este pedido.\n\n"
        . "PEDIDO:\n"
        . "- Mercado: {$pedido['parceiro_nome']}\n"
        . "- Endereco do mercado: {$pedido['parceiro_endereco']}\n"
        . "- Total de itens: {$total_itens}\n"
        . "- Valor total: R\$ {$valor_fmt}\n"
        . "- Endereco de entrega: {$pedido['delivery_address']}\n\n"
        . "CANDIDATOS (ja ordenados por score algoritmico):\n{$candidatos_texto}"
        . "CRITERIOS DE DECISAO:\n"
        . "1. Proximidade ao mercado (mais importante para tempo de resposta)\n"
        . "2. Avaliacao do shopper (qualidade do servico)\n"
        . "3. Experiencia (entregas completadas)\n"
        . "4. Atividade recente (shopper ativo e disponivel)\n\n"
        . "Responda EXATAMENTE neste formato JSON:\n"
        . "{\"shopper_id\": <ID_DO_SHOPPER_ESCOLHIDO>, \"motivo\": \"<Explicacao breve em portugues>\"}\n\n"
        . "Responda APENAS o JSON, sem texto adicional.";

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-3-haiku-20240307',
            'max_tokens' => 500,
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        error_log("[matching-ai] Claude API erro HTTP {$httpCode}: {$curlError}");
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['content'][0]['text'])) {
        error_log("[matching-ai] Claude API resposta invalida: " . ($response ?: 'vazio'));
        return null;
    }

    $texto = trim($data['content'][0]['text']);

    // Extrair JSON da resposta — try json_decode first, then regex fallback
    $json_match = json_decode($texto, true);
    if (!$json_match || !isset($json_match['shopper_id'])) {
        $json_match = null;
        if (preg_match('/\{[^}]+\}/s', $texto, $matches)) {
            $json_match = json_decode($matches[0], true);
        }
    }

    if (!$json_match || !isset($json_match['shopper_id'])) {
        error_log("[matching-ai] Claude retornou formato inesperado: " . $texto);
        return null;
    }

    // Validar que o shopper_id retornado esta entre os candidatos
    $ids_validos = array_column($top3, 'shopper_id');
    if (!in_array((int)$json_match['shopper_id'], $ids_validos)) {
        error_log("[matching-ai] Claude recomendou shopper_id invalido: {$json_match['shopper_id']}");
        return null;
    }

    return [
        'shopper_id' => (int)$json_match['shopper_id'],
        'reasoning' => htmlspecialchars($json_match['motivo'] ?? 'Recomendacao baseada em analise de IA', ENT_QUOTES, 'UTF-8')
    ];
}
