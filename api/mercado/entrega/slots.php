<?php
/**
 * API - Slots de Entrega Disponíveis
 * Sistema inteligente que considera:
 * - Horário de funcionamento do mercado
 * - Fechamentos especiais (feriados, férias)
 * - Capacidade de entregas por slot
 * - Tempo de preparo
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/rate-limit/RateLimiter.php';
setCorsHeaders();

// SECURITY: Rate limiting — 20 req/min per IP
if (!RateLimiter::check(20, 60)) {
    exit;
}

$db = getDB();

$partner_id = intval($_GET['partner_id'] ?? $_GET['mercado_id'] ?? 0);
$dias_antecedencia = min(7, max(1, intval($_GET['dias'] ?? 5))); // Máximo 7 dias

if (!$partner_id) {
    echo json_encode(['success' => false, 'error' => 'partner_id obrigatório']);
    exit;
}

try {
    // Buscar dados do mercado
    $stmt = $db->prepare("
        SELECT partner_id, name, is_open,
               open_time, close_time,
               delivery_time_min, delivery_time_max,
               avg_prep_time, tempo_preparo
        FROM om_market_partners
        WHERE partner_id = ? AND status::text = '1'
    ");
    $stmt->execute([$partner_id]);
    $mercado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mercado) {
        echo json_encode(['success' => false, 'error' => 'Mercado não encontrado']);
        exit;
    }

    $tempo_preparo = $mercado['avg_prep_time'] ?? $mercado['tempo_preparo'] ?? 45; // minutos

    // Buscar horários por dia da semana
    $stmt = $db->prepare("SELECT * FROM om_partner_hours WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $horarios_semana = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $horarios_semana[$h['day_of_week']] = $h;
    }

    // Buscar fechamentos especiais
    $data_inicio = date('Y-m-d');
    $data_fim = date('Y-m-d', strtotime("+$dias_antecedencia days"));

    $stmt = $db->prepare("
        SELECT * FROM om_partner_closures
        WHERE partner_id = ?
        AND (
            (closure_date BETWEEN ? AND ?)
            OR (closure_date <= ? AND closure_end >= ?)
        )
    ");
    $stmt->execute([$partner_id, $data_inicio, $data_fim, $data_fim, $data_inicio]);
    $fechamentos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        // Se for período, expandir todas as datas
        $inicio = $f['closure_date'];
        $fim = $f['closure_end'] ?? $f['closure_date'];
        $current = $inicio;
        while ($current <= $fim) {
            $fechamentos[$current] = $f;
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }
    }

    // Gerar slots disponíveis
    $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $slots = [];
    $dias_nomes = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

    for ($i = 0; $i < $dias_antecedencia; $i++) {
        $data = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $data->modify("+$i days");
        $data_str = $data->format('Y-m-d');
        $dia_semana = (int)$data->format('w');
        $is_hoje = $i === 0;

        // Verificar fechamento especial
        if (isset($fechamentos[$data_str])) {
            $f = $fechamentos[$data_str];
            if ($f['all_day']) {
                $slots[] = [
                    'data' => $data_str,
                    'data_formatada' => $data->format('d/m'),
                    'dia_semana' => $dias_nomes[$dia_semana],
                    'is_hoje' => $is_hoje,
                    'disponivel' => false,
                    'motivo' => $f['reason'] ?? 'Fechado',
                    'horarios' => []
                ];
                continue;
            }
            // Horário especial
            $abre = $f['open_time'];
            $fecha = $f['close_time'];
        } else {
            // Horário normal
            $horario = $horarios_semana[$dia_semana] ?? null;
            if ($horario && $horario['is_closed']) {
                $slots[] = [
                    'data' => $data_str,
                    'data_formatada' => $data->format('d/m'),
                    'dia_semana' => $dias_nomes[$dia_semana],
                    'is_hoje' => $is_hoje,
                    'disponivel' => false,
                    'motivo' => 'Fechado',
                    'horarios' => []
                ];
                continue;
            }
            $abre = $horario['open_time'] ?? $mercado['open_time'] ?? '08:00:00';
            $fecha = $horario['close_time'] ?? $mercado['close_time'] ?? '22:00:00';
        }

        // Gerar slots de horário (a cada 30 minutos)
        $horarios_slot = [];
        $slot_inicio = new DateTime($data_str . ' ' . $abre);
        $slot_fim = new DateTime($data_str . ' ' . $fecha);

        // Se for hoje, começar do horário atual + tempo de preparo
        if ($is_hoje) {
            $minimo = clone $agora;
            $minimo->modify("+{$tempo_preparo} minutes");
            // Arredondar para próximo slot de 30 min
            $minutos = (int)$minimo->format('i');
            if ($minutos > 0 && $minutos <= 30) {
                $minimo->setTime((int)$minimo->format('H'), 30);
            } elseif ($minutos > 30) {
                $minimo->modify('+1 hour');
                $minimo->setTime((int)$minimo->format('H'), 0);
            }

            if ($minimo > $slot_inicio) {
                $slot_inicio = $minimo;
            }

            // Verificar se mercado está aberto agora
            $hora_atual = $agora->format('H:i:s');
            $mercado_aberto = $hora_atual >= $abre && $hora_atual <= $fecha && $mercado['is_open'];
        } else {
            $mercado_aberto = true; // Para dias futuros, consideramos como "vai estar aberto"
        }

        // Último slot deve permitir tempo para preparar e entregar
        $slot_fim->modify("-{$tempo_preparo} minutes");

        while ($slot_inicio <= $slot_fim) {
            $horarios_slot[] = [
                'hora' => $slot_inicio->format('H:i'),
                'tipo' => $is_hoje && $slot_inicio <= (clone $agora)->modify("+60 minutes") ? 'imediato' : 'agendado'
            ];
            $slot_inicio->modify('+30 minutes');
            // Reset agora para comparações corretas
            $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        }

        $slots[] = [
            'data' => $data_str,
            'data_formatada' => $data->format('d/m'),
            'dia_semana' => $dias_nomes[$dia_semana],
            'is_hoje' => $is_hoje,
            'disponivel' => !empty($horarios_slot),
            'mercado_aberto' => $is_hoje ? $mercado_aberto : true,
            'horario' => substr($abre, 0, 5) . ' - ' . substr($fecha, 0, 5),
            'horarios' => $horarios_slot
        ];
    }

    // Verificar status atual do mercado
    $hora_atual = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('H:i:s');
    $hoje = date('Y-m-d');
    $dia_semana_hoje = (int)date('w');
    $horario_hoje = $horarios_semana[$dia_semana_hoje] ?? null;

    $mercado_aberto_agora = false;
    if ($mercado['is_open'] && !isset($fechamentos[$hoje])) {
        $abre = $horario_hoje['open_time'] ?? $mercado['open_time'] ?? '08:00:00';
        $fecha = $horario_hoje['close_time'] ?? $mercado['close_time'] ?? '22:00:00';
        $mercado_aberto_agora = !($horario_hoje['is_closed'] ?? false) && $hora_atual >= $abre && $hora_atual <= $fecha;
    }

    echo json_encode([
        'success' => true,
        'mercado' => [
            'id' => $mercado['partner_id'],
            'nome' => $mercado['name'],
            'aberto_agora' => $mercado_aberto_agora,
            'tempo_preparo' => $tempo_preparo,
            'entrega_min' => $mercado['delivery_time_min'] ?? 25,
            'entrega_max' => $mercado['delivery_time_max'] ?? 45
        ],
        'permite_entrega_imediata' => $mercado_aberto_agora,
        'slots' => $slots
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("API slots entrega: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
