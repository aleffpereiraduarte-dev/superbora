<?php
/**
 * POST /api/transporte/dispatch-ai.php
 * Smart Driver Dispatch AI
 *
 * Chamado quando shopper marca pedido como pronto_coleta (pronto para coleta
 * pelo motorista). Encontra o melhor motorista de entrega.
 *
 * AUTENTICACAO: Admin auth OU header X-API-Key (para chamadas internas)
 * Body: { "order_id": 123 }
 *
 * SEGURANCA:
 * - Autenticacao admin ou API key interna
 * - Prepared statements (sem SQL injection)
 * - Fallback algoritmico se Claude API falhar
 * - Schema migration automatica (delivery_dispatch_at)
 * - Simulacao de dispatch quando nao ha motoristas reais
 */

require_once __DIR__ . '/config/database.php';

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
    if (!empty($apiKey) && !empty($adminToken) && hash_equals($adminToken, $apiKey)) {
        $isAuthenticated = true;
    }

    // Metodo 2: Bearer token admin (se auth disponivel)
    if (!$isAuthenticated) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!empty($adminToken) && preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            if (hash_equals($adminToken, $matches[1])) {
                $isAuthenticated = true;
            }
        }
    }

    if (!$isAuthenticated) {
        response(false, null, "Acesso nao autorizado. Requer autenticacao de admin ou API key valida.", 401);
    }

    // =====================================================================
    // GARANTIR COLUNA delivery_dispatch_at
    // =====================================================================
    try {
        $db->exec("ALTER TABLE om_market_orders ADD COLUMN delivery_dispatch_at DATETIME DEFAULT NULL");
    } catch (Exception $e) {
        // Coluna ja existe, ignorar
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

    // Aceitar pedidos em status pronto_coleta ou coleta_finalizada
    $statusValidos = ['pronto_coleta', 'coleta_finalizada'];
    if (!in_array($pedido['status'], $statusValidos)) {
        response(false, null, "Pedido nao esta pronto para dispatch (status: {$pedido['status']})", 400);
    }

    $parceiro_lat = floatval($pedido['parceiro_lat']);
    $parceiro_lng = floatval($pedido['parceiro_lng']);
    $cliente_lat = floatval($pedido['delivery_lat'] ?? $pedido['customer_lat'] ?? 0);
    $cliente_lng = floatval($pedido['delivery_lng'] ?? $pedido['customer_lng'] ?? 0);
    $total_itens = (int)$pedido['total_itens'];

    // Calcular distancia de entrega (parceiro -> cliente)
    $distancia_entrega = 0;
    if ($cliente_lat != 0 && $cliente_lng != 0) {
        $distancia_entrega = haversineDistanceDispatch($parceiro_lat, $parceiro_lng, $cliente_lat, $cliente_lng);
    }

    // Determinar tamanho do pedido
    $bags_count = (int)($pedido['bags_count'] ?? ceil($total_itens / 5));
    $pedido_grande = ($total_itens > 20 || $bags_count > 4);

    // =====================================================================
    // BUSCAR MOTORISTAS DISPONIVEIS
    // =====================================================================
    $motoristas = [];

    // Tentativa 1: tabela om_boraum_rides (motoristas BoraUm)
    try {
        $stmt = $db->prepare("
            SELECT
                r.driver_id AS motorista_id,
                r.driver_name AS nome,
                r.driver_lat AS latitude,
                r.driver_lng AS longitude,
                r.vehicle_type AS tipo_veiculo,
                COALESCE(
                    (SELECT COUNT(*) FROM om_boraum_rides WHERE driver_id = r.driver_id AND status = 'completed'),
                    0
                ) AS total_entregas,
                COALESCE(
                    (SELECT AVG(rating) FROM om_boraum_rides WHERE driver_id = r.driver_id AND rating > 0),
                    4.0
                ) AS rating
            FROM om_boraum_rides r
            WHERE r.status = 'available'
              AND r.driver_lat IS NOT NULL
              AND r.driver_lng IS NOT NULL
            GROUP BY r.driver_id
        ");
        $stmt->execute();
        $motoristas = $stmt->fetchAll();
    } catch (Exception $e) {
        // Tabela nao existe, tentar alternativas
    }

    // Tentativa 2: tabela om_motorista_saldo (motoristas cadastrados)
    if (empty($motoristas)) {
        try {
            $stmt = $db->prepare("
                SELECT
                    ms.motorista_id,
                    COALESCE(ms.nome, CONCAT('Motorista #', ms.motorista_id)) AS nome,
                    ms.latitude,
                    ms.longitude,
                    ms.tipo_veiculo,
                    COALESCE(ms.total_entregas, 0) AS total_entregas,
                    COALESCE(ms.rating, 4.0) AS rating
                FROM om_motorista_saldo ms
                WHERE ms.disponivel = 1
                  AND ms.latitude IS NOT NULL
                  AND ms.longitude IS NOT NULL
            ");
            $stmt->execute();
            $motoristas = $stmt->fetchAll();
        } catch (Exception $e) {
            // Tabela nao existe
        }
    }

    // Tentativa 3: tabela om_market_deliveries
    if (empty($motoristas)) {
        try {
            $stmt = $db->prepare("
                SELECT DISTINCT
                    d.driver_id AS motorista_id,
                    COALESCE(d.driver_name, CONCAT('Driver #', d.driver_id)) AS nome,
                    d.driver_lat AS latitude,
                    d.driver_lng AS longitude,
                    'motorcycle' AS tipo_veiculo,
                    COUNT(d.id) AS total_entregas,
                    4.0 AS rating
                FROM om_market_deliveries d
                WHERE d.driver_id IS NOT NULL
                GROUP BY d.driver_id
                HAVING total_entregas > 0
            ");
            $stmt->execute();
            $motoristas = $stmt->fetchAll();
        } catch (Exception $e) {
            // Tabela nao existe
        }
    }

    // =====================================================================
    // SE NAO HA MOTORISTAS REAIS: DISPATCH SIMULADO
    // =====================================================================
    if (empty($motoristas)) {
        // Atualizar status do pedido
        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                status = 'aguardando_motorista',
                delivery_dispatch_at = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$order_id]);

        // ETA estimado baseado na distancia
        $eta_minutos = max(15, round($distancia_entrega * 5 + 10));

        response(true, [
            'order_id' => $order_id,
            'dispatch' => [
                'tipo' => 'simulado',
                'motivo' => 'Pool de motoristas em construcao. Pedido na fila de dispatch.',
                'motorista' => null,
                'status' => 'aguardando_motorista'
            ],
            'eta' => [
                'minutos' => $eta_minutos,
                'texto' => "Estimativa: {$eta_minutos} minutos"
            ],
            'pedido' => [
                'parceiro' => $pedido['parceiro_nome'],
                'total_itens' => $total_itens,
                'distancia_entrega_km' => round($distancia_entrega, 2),
                'pedido_grande' => $pedido_grande
            ]
        ], "Dispatch simulado - aguardando motoristas disponiveis");

        return; // response() ja faz exit, mas deixar explicito
    }

    // =====================================================================
    // SCORING DE MOTORISTAS
    // =====================================================================
    $candidatos = [];

    foreach ($motoristas as $mot) {
        $score = 0;
        $detalhes = [];

        // 1. Distancia ate o pickup (parceiro) - max 40 pontos
        $mot_lat = floatval($mot['latitude'] ?? 0);
        $mot_lng = floatval($mot['longitude'] ?? 0);

        if ($mot_lat == 0 || $mot_lng == 0) continue;

        $distancia = haversineDistanceDispatch($parceiro_lat, $parceiro_lng, $mot_lat, $mot_lng);
        if ($distancia <= 1) {
            $score_dist = 40;
        } elseif ($distancia <= 3) {
            $score_dist = 35;
        } elseif ($distancia <= 5) {
            $score_dist = 25;
        } elseif ($distancia <= 10) {
            $score_dist = 15;
        } elseif ($distancia <= 20) {
            $score_dist = 5;
        } else {
            $score_dist = 0;
        }
        $score += $score_dist;
        $detalhes['distancia_pickup_km'] = round($distancia, 2);
        $detalhes['score_distancia'] = $score_dist;

        // 2. Rating - max 25 pontos
        $rating = floatval($mot['rating'] ?? 4.0);
        $score_rating = ($rating / 5.0) * 25;
        $score += $score_rating;
        $detalhes['rating'] = $rating;
        $detalhes['score_rating'] = round($score_rating, 1);

        // 3. Experiencia - max 20 pontos
        $total_ent = (int)($mot['total_entregas'] ?? 0);
        $score_exp = min($total_ent, 100) / 100 * 20;
        $score += $score_exp;
        $detalhes['total_entregas'] = $total_ent;
        $detalhes['score_experiencia'] = round($score_exp, 1);

        // 4. Veiculo adequado - max 15 pontos
        $tipo_veiculo = strtolower($mot['tipo_veiculo'] ?? 'motorcycle');
        $score_veiculo = 0;
        if ($pedido_grande && in_array($tipo_veiculo, ['car', 'carro', 'van'])) {
            $score_veiculo = 15; // Carro para pedidos grandes
        } elseif (!$pedido_grande && in_array($tipo_veiculo, ['motorcycle', 'moto', 'bike'])) {
            $score_veiculo = 15; // Moto para pedidos pequenos
        } else {
            $score_veiculo = 8; // Veiculo OK mas nao ideal
        }
        $score += $score_veiculo;
        $detalhes['tipo_veiculo'] = $tipo_veiculo;
        $detalhes['score_veiculo'] = $score_veiculo;
        $detalhes['score_total'] = round($score, 1);

        $candidatos[] = [
            'motorista_id' => (int)($mot['motorista_id'] ?? $mot['driver_id'] ?? 0),
            'nome' => $mot['nome'] ?? 'Motorista',
            'score' => round($score, 1),
            'detalhes' => $detalhes
        ];
    }

    if (empty($candidatos)) {
        // Nenhum candidato valido (todos sem coordenadas)
        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                status = 'aguardando_motorista',
                delivery_dispatch_at = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$order_id]);

        response(true, [
            'order_id' => $order_id,
            'dispatch' => [
                'tipo' => 'simulado',
                'motivo' => 'Nenhum motorista com localizacao valida.',
                'motorista' => null,
                'status' => 'aguardando_motorista'
            ]
        ], "Nenhum motorista disponivel com localizacao valida");

        return;
    }

    // Ordenar por score decrescente
    usort($candidatos, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    $top3 = array_slice($candidatos, 0, 3);

    // =====================================================================
    // DECISAO COM CLAUDE AI (se multiplos candidatos)
    // =====================================================================
    $ai_usado = false;
    $ai_resposta = null;
    $escolhido = $top3[0]; // Fallback algoritmico

    if (count($top3) > 1 && !empty(CLAUDE_API_KEY)) {
        try {
            $ai_resultado = consultarClaudeDispatch($pedido, $top3, $total_itens, $distancia_entrega, $pedido_grande);
            if ($ai_resultado) {
                $ai_usado = true;
                $ai_resposta = $ai_resultado['reasoning'];

                $mid_rec = $ai_resultado['motorista_id'];
                foreach ($top3 as $c) {
                    if ($c['motorista_id'] === $mid_rec) {
                        $escolhido = $c;
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[dispatch-ai] Erro Claude API: " . $e->getMessage());
        }
    }

    // =====================================================================
    // ATUALIZAR STATUS DO PEDIDO
    // =====================================================================
    $motorista_id = $escolhido['motorista_id'];

    $stmt = $db->prepare("
        UPDATE om_market_orders SET
            status = 'aguardando_motorista',
            delivery_dispatch_at = NOW(),
            delivery_driver_id = ?
        WHERE order_id = ?
    ");
    $stmt->execute([$motorista_id, $order_id]);

    // =====================================================================
    // CALCULAR ETA
    // =====================================================================
    $dist_pickup = $escolhido['detalhes']['distancia_pickup_km'];
    // ETA: tempo ate pickup + tempo de entrega
    // Moto: ~25km/h em cidade, Carro: ~20km/h em cidade
    $velocidade = in_array($escolhido['detalhes']['tipo_veiculo'] ?? '', ['car', 'carro', 'van']) ? 20 : 25;
    $eta_pickup = max(5, round(($dist_pickup / $velocidade) * 60));
    $eta_entrega = max(5, round(($distancia_entrega / $velocidade) * 60));
    $eta_total = $eta_pickup + $eta_entrega + 5; // +5 min para coleta no mercado

    // Ajuste por horario (trafego)
    $hora = (int)date('H');
    if (($hora >= 7 && $hora <= 9) || ($hora >= 17 && $hora <= 19)) {
        $eta_total = round($eta_total * 1.3); // +30% em horario de pico
    }

    // =====================================================================
    // NOTIFICAR MOTORISTA (fire-and-forget)
    // =====================================================================
    $notifPayload = json_encode([
        'title' => 'Nova Entrega!',
        'body' => "Pedido #{$order_id} - {$total_itens} itens - Retirar em {$pedido['parceiro_nome']}",
        'customer_id' => $motorista_id,
        'user_type' => 'motorista',
        'topic' => 'deliveries',
        'data' => ['order_id' => $order_id, 'url' => '/transporte/entregas.php']
    ]);

    $ch2 = curl_init('http://localhost/api/notifications/send.php');
    curl_setopt_array($ch2, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $notifPayload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
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
        'dispatch' => [
            'tipo' => 'real',
            'motorista' => [
                'motorista_id' => $escolhido['motorista_id'],
                'nome' => $escolhido['nome'],
                'score' => $escolhido['score'],
                'detalhes' => $escolhido['detalhes']
            ],
            'status' => 'aguardando_motorista'
        ],
        'ai' => [
            'utilizado' => $ai_usado,
            'modelo' => $ai_usado ? 'claude-3-haiku-20240307' : null,
            'reasoning' => $ai_resposta
        ],
        'eta' => [
            'pickup_minutos' => $eta_pickup,
            'entrega_minutos' => $eta_entrega,
            'total_minutos' => $eta_total,
            'texto' => "Estimativa: {$eta_total} minutos",
            'horario_pico' => ($hora >= 7 && $hora <= 9) || ($hora >= 17 && $hora <= 19)
        ],
        'candidatos_avaliados' => count($candidatos),
        'top3' => $top3,
        'pedido' => [
            'parceiro' => $pedido['parceiro_nome'],
            'total_itens' => $total_itens,
            'distancia_entrega_km' => round($distancia_entrega, 2),
            'pedido_grande' => $pedido_grande,
            'bags_count' => $bags_count
        ]
    ], "Motorista despachado com sucesso");

} catch (Exception $e) {
    error_log("[dispatch-ai] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar dispatch de motorista. Tente novamente.", 500);
}

// =============================================================================
// FUNCOES AUXILIARES
// =============================================================================

/**
 * Calcula distancia entre dois pontos geograficos (Haversine)
 * @return float Distancia em km
 */
function haversineDistanceDispatch(float $lat1, float $lng1, float $lat2, float $lng2): float {
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
 * Consulta Claude API para decisao de dispatch otimizada
 * @return array|null ['motorista_id' => int, 'reasoning' => string]
 */
function consultarClaudeDispatch(array $pedido, array $top3, int $total_itens, float $dist_entrega, bool $pedido_grande): ?array {
    $candidatos_texto = "";
    foreach ($top3 as $i => $c) {
        $num = $i + 1;
        $candidatos_texto .= "Candidato {$num}: {$c['nome']} (ID: {$c['motorista_id']})\n";
        $candidatos_texto .= "  - Score: {$c['score']}/100\n";
        $candidatos_texto .= "  - Distancia pickup: {$c['detalhes']['distancia_pickup_km']}km\n";
        $candidatos_texto .= "  - Rating: {$c['detalhes']['rating']}/5.0\n";
        $candidatos_texto .= "  - Entregas: {$c['detalhes']['total_entregas']}\n";
        $candidatos_texto .= "  - Veiculo: {$c['detalhes']['tipo_veiculo']}\n\n";
    }

    $hora = date('H:i');
    $dia_semana = date('l');
    $dist_fmt = number_format($dist_entrega, 1, ',', '.');
    $tamanho = $pedido_grande ? 'GRANDE (muitos itens/sacolas)' : 'PEQUENO/MEDIO';

    $prompt = "Voce e o sistema de dispatch inteligente do OneMundo Transporte. "
        . "Escolha o MELHOR motorista para esta entrega.\n\n"
        . "PEDIDO:\n"
        . "- Mercado: {$pedido['parceiro_nome']}\n"
        . "- Endereco coleta: {$pedido['parceiro_endereco']}\n"
        . "- Endereco entrega: {$pedido['delivery_address']}\n"
        . "- Total itens: {$total_itens}\n"
        . "- Distancia entrega: {$dist_fmt}km\n"
        . "- Tamanho: {$tamanho}\n"
        . "- Horario atual: {$hora} ({$dia_semana})\n\n"
        . "CANDIDATOS:\n{$candidatos_texto}"
        . "CRITERIOS:\n"
        . "1. Proximidade ao ponto de coleta\n"
        . "2. Veiculo adequado (moto=pedidos pequenos, carro=pedidos grandes)\n"
        . "3. Rating e experiencia\n"
        . "4. Considerar trafego pelo horario\n\n"
        . "Responda EXATAMENTE neste formato JSON:\n"
        . "{\"motorista_id\": <ID>, \"motivo\": \"<explicacao breve em portugues>\"}\n\n"
        . "Responda APENAS o JSON.";

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
        error_log("[dispatch-ai] Claude API erro HTTP {$httpCode}: {$curlError}");
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['content'][0]['text'])) {
        error_log("[dispatch-ai] Claude API resposta invalida: " . ($response ?: 'vazio'));
        return null;
    }

    $texto = trim($data['content'][0]['text']);

    // Extrair JSON da resposta
    $json_match = null;
    if (preg_match('/\{[^}]+\}/s', $texto, $matches)) {
        $json_match = json_decode($matches[0], true);
    }

    if (!$json_match || !isset($json_match['motorista_id'])) {
        error_log("[dispatch-ai] Claude retornou formato inesperado: " . $texto);
        return null;
    }

    // Validar que o motorista_id retornado esta entre os candidatos
    $ids_validos = array_column($top3, 'motorista_id');
    if (!in_array((int)$json_match['motorista_id'], $ids_validos)) {
        error_log("[dispatch-ai] Claude recomendou motorista_id invalido: {$json_match['motorista_id']}");
        return null;
    }

    return [
        'motorista_id' => (int)$json_match['motorista_id'],
        'reasoning' => $json_match['motivo'] ?? 'Recomendacao baseada em analise de IA'
    ];
}
