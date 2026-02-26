<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ==============================================================================
 * CRON - PROCESSAR ONDAS DE DELIVERY
 * ==============================================================================
 *
 * Este cron deve rodar a cada 30 segundos (ou 1 minuto):
 * * * * * * php /var/www/html/mercado/cron_waves_robust.php >> /var/log/cron_waves.log 2>&1
 *
 * Para rodar a cada 30 segundos, use dois crons:
 * * * * * * php /var/www/html/mercado/cron_waves_robust.php
 * * * * * * sleep 30 && php /var/www/html/mercado/cron_waves_robust.php
 *
 * Funcoes:
 * - Processa ondas de notificacao (2 min entre ondas)
 * - Onda 1: 3 deliverys mais proximos
 * - Onda 2: proximos 3 deliverys
 * - Onda 3: TODOS os deliverys restantes
 * - Envia WhatsApp + Push para cada delivery
 * - Expira ofertas nao aceitas apos 30 min
 *
 * ==============================================================================
 */

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    header('Content-Type: application/json');
}

// Configuracoes
define('WAVE_TIMEOUT', 120); // 2 minutos entre ondas
define('DELIVERYS_PER_WAVE', 3);
define('OFFER_EXPIRY_MINUTES', 30);

// Z-API
define('ZAPI_INSTANCE', '3EB5EA8848393161FED3AEC2FCF0DF7D');
define('ZAPI_TOKEN', 'F8435919A26A2D35D60DAEBE');
define('ZAPI_CLIENT_TOKEN', 'F78499793bdac4071996955c45c8511cdS');
define('ZAPI_URL', 'https://api.z-api.io/instances/' . ZAPI_INSTANCE . '/token/' . ZAPI_TOKEN);

$db_host = '147.93.12.236';
$db_name = 'love1';
$db_user = 'love1';
$db_pass_local = 'Aleff2009@';

try {
    $pdo = new PDO("pgsql:host=$db_host;port=5432;dbname=$db_name", $db_user, $db_pass_local);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    logMsg("ERRO DB: " . $e->getMessage());
    exit(1);
}

$log = [];

function logMsg($msg) {
    global $log, $is_cli;
    $timestamp = date('Y-m-d H:i:s');
    $log[] = "[$timestamp] $msg";
    if ($is_cli) {
        echo "[$timestamp] $msg\n";
    }
}

function calcularDistancia($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return 999;
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function enviarWhatsApp($telefone, $mensagem) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 11 || strlen($telefone) == 10) {
        $telefone = '55' . $telefone;
    }

    $url = ZAPI_URL . '/send-text';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['phone' => $telefone, 'message' => $mensagem]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Client-Token: ' . ZAPI_CLIENT_TOKEN
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);
    $success = isset($json['zaapId']) || isset($json['messageId']) || isset($json['id']);

    return ['success' => $success, 'http_code' => $httpCode];
}

function enviarPushNotification($pdo, $user_type, $user_id, $title, $body, $data = null, $priority = 'normal') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO om_notifications_queue (user_type, user_id, title, body, icon, data, priority, sound)
            VALUES (?, ?, ?, ?, 'delivery', ?, ?, 'alert')
        ");
        $stmt->execute([
            $user_type,
            $user_id,
            $title,
            $body,
            $data ? json_encode($data) : null,
            $priority
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function buscarDeliverysDisponiveis($pdo, $partner_id, $vehicle_required = 'any') {
    // Buscar localizacao do mercado
    $stmt = $pdo->prepare("SELECT latitude, longitude FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $mercado = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mercado) return [];

    $vehicle_filter = ($vehicle_required === 'carro') ? "AND (vehicle_type = 'carro' OR vehicle_type IS NULL)" : "";

    $sql = "SELECT delivery_id, name, phone, whatsapp, vehicle_type, current_latitude, current_longitude
            FROM om_market_deliveries
            WHERE is_online = 1 AND (status = '1' OR status = 'disponivel' OR status IS NULL)
            AND (current_deliveries < max_simultaneous OR max_simultaneous IS NULL OR current_deliveries IS NULL)
            $vehicle_filter";

    $deliverys = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deliverys as &$d) {
        $d['distance_km'] = calcularDistancia(
            $mercado['latitude'], $mercado['longitude'],
            $d['current_latitude'], $d['current_longitude']
        );
    }

    usort($deliverys, function($a, $b) {
        return $a['distance_km'] <=> $b['distance_km'];
    });

    return array_values($deliverys);
}

// ==============================================================================
// INICIO DO PROCESSAMENTO
// ==============================================================================

logMsg("========================================");
logMsg("CRON WAVES - INICIANDO");
logMsg("========================================");

$stats = [
    'waves_processed' => 0,
    'deliverys_notified' => 0,
    'whatsapp_sent' => 0,
    'push_sent' => 0,
    'offers_expired' => 0
];

// ==============================================================================
// 1. PROCESSAR ONDAS PENDENTES
// ==============================================================================

$wave_timeout = WAVE_TIMEOUT;

try {
    // Buscar ofertas que precisam avancar de onda
    $stmt = $pdo->query("
        SELECT
            o.offer_id, o.order_id, o.partner_id, o.delivery_earning, o.current_wave,
            o.wave_started_at, ord.vehicle_required,
            COALESCE(p.trade_name, p.name) as mercado_nome,
            EXTRACT(EPOCH FROM (NOW() - o.wave_started_at)) as seconds_since_wave
        FROM om_delivery_offers o
        JOIN om_market_orders ord ON o.order_id = ord.order_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.status = 'pending'
        AND o.wave_started_at < NOW() - INTERVAL '{$wave_timeout} seconds'
        AND o.current_wave < 3
        AND o.expires_at > NOW()
        LIMIT 20
    ");

    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    logMsg("Ofertas para processar: " . count($offers));

    foreach ($offers as $offer) {
        $offer_id = $offer['offer_id'];
        $new_wave = $offer['current_wave'] + 1;

        logMsg("  Oferta #{$offer_id} -> Onda {$new_wave}");

        // Atualizar para nova onda
        $pdo->prepare("UPDATE om_delivery_offers SET current_wave = ?, wave_started_at = NOW() WHERE offer_id = ?")
            ->execute([$new_wave, $offer_id]);

        // Buscar quem ja foi notificado
        $stmt = $pdo->prepare("SELECT delivery_id FROM om_delivery_notifications WHERE offer_id = ?");
        $stmt->execute([$offer_id]);
        $already_notified = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Buscar deliverys disponiveis
        $deliverys = buscarDeliverysDisponiveis($pdo, $offer['partner_id'], $offer['vehicle_required']);

        // Filtrar quem ja foi notificado
        $deliverys = array_values(array_filter($deliverys, function($d) use ($already_notified) {
            return !in_array($d['delivery_id'], $already_notified);
        }));

        // Onda 3 = todos, outras = 3 por vez
        $to_notify = ($new_wave >= 3) ? $deliverys : array_slice($deliverys, 0, DELIVERYS_PER_WAVE);

        logMsg("    Notificando " . count($to_notify) . " deliverys");

        foreach ($to_notify as $d) {
            // Registrar notificacao
            $pdo->prepare("INSERT INTO om_delivery_notifications (delivery_id, offer_id, order_id) VALUES (?, ?, ?)")
                ->execute([$d['delivery_id'], $offer_id, $offer['order_id']]);

            $stats['deliverys_notified']++;

            // WhatsApp
            $whatsapp = $d['whatsapp'] ?: $d['phone'];
            if (!empty($whatsapp)) {
                $dist = $d['distance_km'] < 999 ? ' (' . round($d['distance_km'], 1) . 'km)' : '';
                $veiculo = $offer['vehicle_required'] === 'carro' ? 'CARRO' : 'MOTO';

                $msg = "*ENTREGA DISPONIVEL!*\n\n";
                $msg .= "Mercado: {$offer['mercado_nome']}{$dist}\n";
                $msg .= "Ganho: R$ " . number_format($offer['delivery_earning'], 2, ',', '.') . "\n";
                $msg .= "Veiculo: {$veiculo}\n\n";
                $msg .= "Onda {$new_wave} - Aceite rapido!\n\n";
                $msg .= "Abra o app OneMundo Delivery";

                $result = enviarWhatsApp($whatsapp, $msg);
                if ($result['success']) {
                    $pdo->prepare("UPDATE om_delivery_notifications SET whatsapp_sent = 1, whatsapp_sent_at = NOW() WHERE offer_id = ? AND delivery_id = ?")
                        ->execute([$offer_id, $d['delivery_id']]);
                    $stats['whatsapp_sent']++;
                }
            }

            // Push notification
            $push_data = [
                'type' => 'delivery_offer',
                'offer_id' => $offer_id,
                'order_id' => $offer['order_id'],
                'earning' => $offer['delivery_earning'],
                'market_name' => $offer['mercado_nome'],
                'vehicle_required' => $offer['vehicle_required'],
                'wave' => $new_wave,
                'seconds_remaining' => 120
            ];

            if (enviarPushNotification($pdo, 'delivery', $d['delivery_id'],
                'Nova Entrega!',
                'R$ ' . number_format($offer['delivery_earning'], 2, ',', '.') . ' - ' . $offer['mercado_nome'],
                $push_data, 'urgent')) {
                $stats['push_sent']++;
            }
        }

        $stats['waves_processed']++;
    }

} catch (Exception $e) {
    logMsg("ERRO ao processar ondas: " . $e->getMessage());
}

// ==============================================================================
// 2. EXPIRAR OFERTAS ANTIGAS
// ==============================================================================

try {
    $stmt = $pdo->query("
        SELECT offer_id, order_id
        FROM om_delivery_offers
        WHERE status = 'pending'
        AND expires_at < NOW()
    ");

    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($expired) > 0) {
        logMsg("Expirando " . count($expired) . " ofertas antigas");

        foreach ($expired as $exp) {
            $pdo->prepare("UPDATE om_delivery_offers SET status = 'expired' WHERE offer_id = ?")
                ->execute([$exp['offer_id']]);

            // Adicionar mensagem no chat
            $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message) VALUES (?, 'system', 0, ?)")
                ->execute([$exp['order_id'], 'Nenhum entregador disponivel no momento. Estamos buscando novamente...']);

            $stats['offers_expired']++;
        }
    }

} catch (Exception $e) {
    logMsg("ERRO ao expirar ofertas: " . $e->getMessage());
}

// ==============================================================================
// RESULTADO
// ==============================================================================

logMsg("========================================");
logMsg("ESTATISTICAS:");
logMsg("   Ondas processadas: " . $stats['waves_processed']);
logMsg("   Deliverys notificados: " . $stats['deliverys_notified']);
logMsg("   WhatsApp enviados: " . $stats['whatsapp_sent']);
logMsg("   Push enviados: " . $stats['push_sent']);
logMsg("   Ofertas expiradas: " . $stats['offers_expired']);
logMsg("========================================");
logMsg("CRON FINALIZADO");

if (!$is_cli) {
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'log' => $log
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
