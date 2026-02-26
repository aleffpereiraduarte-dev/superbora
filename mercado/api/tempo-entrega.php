<?php
/**
 * API de Cálculo de Tempo de Entrega
 * Calcula tempo estimado baseado em fatores reais
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Conexão
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

$partner_id = intval($_GET['partner_id'] ?? 100);
$lat_cliente = floatval($_GET['lat'] ?? 0);
$lng_cliente = floatval($_GET['lng'] ?? 0);

// Buscar dados do mercado
$stmt = $pdo->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
$stmt->execute([$partner_id]);
$mercado = $stmt->fetch();

if (!$mercado) {
    echo json_encode(['success' => false, 'error' => 'Mercado não encontrado']);
    exit;
}

// ========================================
// FATORES PARA CÁLCULO DO TEMPO
// ========================================

// 1. TEMPO BASE DE SEPARAÇÃO (baseado na média de itens)
$tempo_separacao_base = 15; // minutos

// 2. SHOPPERS DISPONÍVEIS
$stmt = $pdo->query("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as disponiveis,
           SUM(CASE WHEN status = 'busy' THEN 1 ELSE 0 END) as ocupados
    FROM om_market_shoppers
    WHERE partner_id = {$partner_id} OR partner_id IS NULL
");
$shoppers = $stmt->fetch();
$shoppers_disponiveis = max(1, $shoppers['disponiveis'] ?? 1);
$shoppers_ocupados = $shoppers['ocupados'] ?? 0;

// 3. PEDIDOS NA FILA (aguardando shopper)
$stmt = $pdo->query("
    SELECT COUNT(*) as fila
    FROM om_market_orders
    WHERE partner_id = {$partner_id}
      AND status IN ('pending', 'confirmed', 'shopping')
      AND date_added > DATE_SUB(NOW(), INTERVAL 4 HOUR)
");
$fila = $stmt->fetch()['fila'] ?? 0;

// 4. TEMPO MÉDIO HISTÓRICO DE ENTREGA
$stmt = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, date_added, delivered_at)) as tempo_medio
    FROM om_market_orders
    WHERE partner_id = {$partner_id}
      AND delivered_at IS NOT NULL
      AND date_added > DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$historico = $stmt->fetch();
$tempo_medio_historico = $historico['tempo_medio'] ? round($historico['tempo_medio']) : 35;

// 5. DISTÂNCIA (se coordenadas fornecidas)
$distancia_km = 0;
$tempo_deslocamento = 10; // padrão

if ($lat_cliente && $lng_cliente && $mercado['lat'] && $mercado['lng']) {
    // Fórmula de Haversine
    $lat1 = deg2rad($mercado['lat']);
    $lat2 = deg2rad($lat_cliente);
    $dlat = $lat2 - $lat1;
    $dlng = deg2rad($lng_cliente - $mercado['lng']);

    $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlng/2) * sin($dlng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    $distancia_km = 6371 * $c;

    // Tempo de deslocamento: ~3 min/km em área urbana
    $tempo_deslocamento = max(5, round($distancia_km * 3));
}

// 6. HORÁRIO (pico = mais demorado)
$hora = (int)date('H');
$fator_horario = 1.0;
if (($hora >= 11 && $hora <= 13) || ($hora >= 18 && $hora <= 20)) {
    $fator_horario = 1.2; // 20% mais no horário de pico
}

// 7. DIA DA SEMANA (fim de semana = mais demorado)
$dia_semana = date('N');
$fator_dia = ($dia_semana >= 6) ? 1.15 : 1.0;

// ========================================
// CÁLCULO FINAL
// ========================================

// Tempo de espera na fila
$tempo_fila = ($fila > 0 && $shoppers_disponiveis > 0)
    ? ceil($fila / $shoppers_disponiveis) * 10
    : 0;

// Tempo total estimado
$tempo_min = round(($tempo_separacao_base + $tempo_fila + $tempo_deslocamento) * $fator_horario * $fator_dia);
$tempo_max = round($tempo_min * 1.3); // margem de 30%

// Ajustar com base no histórico (se disponível e razoável)
if ($tempo_medio_historico > 10 && $tempo_medio_historico < 120) {
    $tempo_min = round(($tempo_min + $tempo_medio_historico) / 2);
    $tempo_max = round($tempo_min * 1.3);
}

// Limites mínimos e máximos
$tempo_min = max(15, min(60, $tempo_min));
$tempo_max = max($tempo_min + 10, min(90, $tempo_max));

// ========================================
// RESPOSTA
// ========================================

echo json_encode([
    'success' => true,
    'tempo_min' => $tempo_min,
    'tempo_max' => $tempo_max,
    'tempo_texto' => "{$tempo_min}-{$tempo_max} min",
    'fatores' => [
        'shoppers_disponiveis' => $shoppers_disponiveis,
        'pedidos_fila' => $fila,
        'distancia_km' => round($distancia_km, 1),
        'horario_pico' => $fator_horario > 1,
        'fim_semana' => $fator_dia > 1
    ]
]);
