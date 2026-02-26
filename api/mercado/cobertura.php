<?php
/**
 * API - Verificar Cobertura de Entrega
 * Encontra mercados que atendem um CEP/endereço
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';
setCorsHeaders();
$db = getDB();

$cep = preg_replace('/[^0-9]/', '', $_GET['cep'] ?? '');
$lat = floatval($_GET['lat'] ?? 0);
$lng = floatval($_GET['lng'] ?? 0);

if (!$cep && (!$lat || !$lng)) {
    echo json_encode(['success' => false, 'error' => 'Informe CEP ou coordenadas (lat/lng)']);
    exit;
}

try {
    // Se informou CEP, buscar coordenadas via ViaCEP
    $endereco = null;
    if ($cep && strlen($cep) == 8) {
        $cache_file = "/tmp/viacep_$cep.json";

        if (file_exists($cache_file) && filemtime($cache_file) > time() - 86400) {
            $endereco = json_decode(file_get_contents($cache_file), true);
        } else {
            $via_cep = @file_get_contents("https://viacep.com.br/ws/$cep/json/");
            if ($via_cep) {
                $endereco = json_decode($via_cep, true);
                if (!isset($endereco['erro'])) {
                    file_put_contents($cache_file, $via_cep, LOCK_EX);
                }
            }
        }

        if (!$endereco || isset($endereco['erro'])) {
            echo json_encode(['success' => false, 'error' => 'CEP não encontrado']);
            exit;
        }

        // Geocodificar endereço (aproximação por cidade/estado)
        // Em produção, usar Google Maps ou Nominatim
        $cidade = $endereco['localidade'] ?? '';
        $estado = $endereco['uf'] ?? '';
    }

    // Inicializar variáveis caso o bloco ViaCEP não execute
    $cidade = $cidade ?? '';
    $estado = $estado ?? '';

    // Buscar mercados ativos
    $mercados = [];

    if ($lat && $lng) {
        // Buscar por distância (Haversine)
        $stmt = $db->prepare("
            SELECT * FROM (
                SELECT
                    p.partner_id, p.name, p.nome, p.logo, p.banner, p.categoria,
                    p.delivery_fee, p.taxa_entrega, p.min_order, p.min_order_value,
                    p.delivery_time_min, p.delivery_time_max, p.rating, p.delivery_radius,
                    p.free_delivery_min, p.free_delivery_above,
                    p.open_time, p.close_time,
                    p.cupom_clube, p.cupom_app, p.cupom_promo, p.mais_pedido, p.total_pedidos,
                    p.lat, p.latitude, p.lng, p.longitude, p.city, p.cidade, p.cep_coverage,
                    (6371 * acos(
                        LEAST(1.0, cos(radians(?)) * cos(radians(COALESCE(p.lat, p.latitude, 0)))
                        * cos(radians(COALESCE(p.lng, p.longitude, 0)) - radians(?))
                        + sin(radians(?)) * sin(radians(COALESCE(p.lat, p.latitude, 0))))
                    )) AS distancia_km
                FROM om_market_partners p
                WHERE p.status::text = '1'
                AND (p.lat IS NOT NULL OR p.latitude IS NOT NULL)
            ) sub
            WHERE distancia_km <= COALESCE(sub.delivery_radius, 15)
            ORDER BY distancia_km ASC
            LIMIT 10
        ");
        $stmt->execute([$lat, $lng, $lat]);
        $mercados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Buscar por cidade
        $stmt = $db->prepare("
            SELECT p.partner_id, p.name, p.nome, p.logo, p.banner, p.categoria,
                   p.delivery_fee, p.taxa_entrega, p.min_order, p.min_order_value,
                   p.delivery_time_min, p.delivery_time_max, p.rating, p.delivery_radius,
                   p.free_delivery_min, p.free_delivery_above,
                   p.open_time, p.close_time,
                   p.cupom_clube, p.cupom_app, p.cupom_promo, p.mais_pedido, p.total_pedidos,
                   p.city, p.cidade, p.cep_coverage
            FROM om_market_partners p
            WHERE p.status::text = '1'
            AND (
                LOWER(p.city) = LOWER(?)
                OR LOWER(p.cidade) = LOWER(?)
                OR p.cep_coverage LIKE ?
            )
            ORDER BY p.rating DESC, p.total_orders DESC
            LIMIT 10
        ");
        $stmt->execute([$cidade, $cidade, "%$cep%"]);
        $mercados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($mercados)) {
        echo json_encode([
            'success' => true,
            'disponivel' => false,
            'endereco' => $endereco,
            'mensagem' => 'Ainda não atendemos sua região. Deixe seu email para ser avisado!',
            'mercados' => []
        ]);
        exit;
    }

    // Formatar resposta
    $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $hora_atual = $agora->format('H:i:s');
    $dia_semana = (int)$agora->format('w');

    $mercados_formatados = [];
    foreach ($mercados as $m) {
        // Verificar se está aberto
        $stmt = $db->prepare("SELECT * FROM om_partner_hours WHERE partner_id = ? AND day_of_week = ?");
        $stmt->execute([$m['partner_id'], $dia_semana]);
        $horario = $stmt->fetch(PDO::FETCH_ASSOC);

        $abre = $horario['open_time'] ?? $m['open_time'] ?? '08:00:00';
        $fecha = $horario['close_time'] ?? $m['close_time'] ?? '22:00:00';
        $fechado_hoje = $horario['is_closed'] ?? false;

        $aberto = !$fechado_hoje && $hora_atual >= $abre && $hora_atual <= $fecha;

        $mercados_formatados[] = [
            'id' => $m['partner_id'],
            'nome' => $m['name'] ?: $m['nome'],
            'logo' => $m['logo'],
            'banner' => $m['banner'] ?? null,
            'categoria' => $m['categoria'] ?? 'supermercado',
            'aberto' => $aberto,
            'horario' => $fechado_hoje ? 'Fechado hoje' : substr($abre, 0, 5) . ' - ' . substr($fecha, 0, 5),
            'distancia_km' => isset($m['distancia_km']) ? round($m['distancia_km'], 1) : null,
            'distancia' => isset($m['distancia_km']) ? round($m['distancia_km'], 1) : null,
            'taxa_entrega' => floatval($m['delivery_fee'] ?? $m['taxa_entrega'] ?? 0),
            'pedido_minimo' => floatval($m['min_order'] ?? $m['min_order_value'] ?? 0),
            'pedido_min' => floatval($m['min_order'] ?? $m['min_order_value'] ?? 0),
            'tempo_entrega' => ($m['delivery_time_min'] ?? 25) . '-' . ($m['delivery_time_max'] ?? 45) . ' min',
            'tempo_estimado' => (int)($m['delivery_time_min'] ?? 30),
            'rating' => floatval($m['rating'] ?? 5),
            'avaliacao' => floatval($m['rating'] ?? 5),
            'entrega_gratis_acima' => floatval($m['free_delivery_min'] ?? $m['free_delivery_above'] ?? 0),
            // Discount badges - iFood style
            'cupom_clube' => !empty($m['cupom_clube']) ? floatval($m['cupom_clube']) : null,
            'cupom_app' => !empty($m['cupom_app']) ? floatval($m['cupom_app']) : null,
            'cupom_promo' => !empty($m['cupom_promo']) ? floatval($m['cupom_promo']) : null,
            'mais_pedido' => (bool)($m['mais_pedido'] ?? false),
            'total_pedidos' => (int)($m['total_pedidos'] ?? 0)
        ];
    }

    echo json_encode([
        'success' => true,
        'disponivel' => true,
        'endereco' => $endereco,
        'total' => count($mercados_formatados),
        'mercados' => $mercados_formatados
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("API cobertura: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
