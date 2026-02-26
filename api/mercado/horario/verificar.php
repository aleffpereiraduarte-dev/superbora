<?php
/**
 * VERIFICAR HORARIO DO MERCADO
 *
 * GET /api/mercado/horario/verificar.php?partner_id=1
 *
 * Retorna:
 * - Se esta aberto agora
 * - Se fechado, quando vai abrir
 * - Se e feriado/excecao
 * - Oferece opcao de agendar
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . "/../config/database.php";

try {
    $db = getDB();
    $partner_id = (int)($_GET['partner_id'] ?? 0);

    if (!$partner_id) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    // Buscar dados do mercado
    $stmt = $db->prepare("SELECT * FROM om_market_partners WHERE partner_id = ? AND status::text = '1'");
    $stmt->execute([$partner_id]);
    $mercado = $stmt->fetch();

    if (!$mercado) {
        response(false, null, "Mercado nao encontrado", 404);
    }

    // Verificar se mercado esta aceitando pedidos
    if (!$mercado['accepting_orders']) {
        response(true, [
            "aberto" => false,
            "motivo" => "nao_aceita_pedidos",
            "mensagem" => "Este mercado nao esta aceitando pedidos no momento",
            "pode_agendar" => false
        ]);
    }

    $hoje = date('Y-m-d');
    $agora = date('H:i:s');
    $dia_semana = (int)date('w'); // 0=Dom, 1=Seg, ..., 6=Sab

    // 1. Verificar se e feriado/excecao
    $stmt = $db->prepare("
        SELECT * FROM om_partner_holidays
        WHERE partner_id = ? AND date = ?
    ");
    $stmt->execute([$partner_id, $hoje]);
    $feriado = $stmt->fetch();

    if ($feriado) {
        if ($feriado['is_closed']) {
            // Fechado por feriado
            $proximo = buscarProximaAbertura($db, $partner_id, $hoje);
            response(true, [
                "aberto" => false,
                "motivo" => "feriado",
                "feriado" => $feriado['reason'] ?? "Feriado",
                "mensagem" => "Fechado hoje: " . ($feriado['reason'] ?? "Feriado"),
                "proxima_abertura" => $proximo,
                "pode_agendar" => true
            ]);
        } else {
            // Horario especial
            if ($agora >= $feriado['open_time'] && $agora <= $feriado['close_time']) {
                response(true, [
                    "aberto" => true,
                    "horario_especial" => true,
                    "fecha_as" => substr($feriado['close_time'], 0, 5),
                    "motivo" => $feriado['reason'] ?? "Horario especial"
                ]);
            } else {
                $proximo = [
                    "data" => $hoje,
                    "hora" => substr($feriado['open_time'], 0, 5),
                    "mensagem" => "Abre hoje as " . substr($feriado['open_time'], 0, 5)
                ];
                response(true, [
                    "aberto" => false,
                    "motivo" => "fora_horario_especial",
                    "proxima_abertura" => $proximo,
                    "pode_agendar" => true
                ]);
            }
        }
    }

    // 2. Verificar horario normal do dia
    $stmt = $db->prepare("
        SELECT * FROM om_partner_hours
        WHERE partner_id = ? AND day_of_week = ?
    ");
    $stmt->execute([$partner_id, $dia_semana]);
    $horario = $stmt->fetch();

    if (!$horario || $horario['is_closed']) {
        // Fechado neste dia da semana
        $proximo = buscarProximaAbertura($db, $partner_id, $hoje);
        response(true, [
            "aberto" => false,
            "motivo" => "dia_fechado",
            "mensagem" => "Fechado " . nomeDiaSemana($dia_semana),
            "proxima_abertura" => $proximo,
            "pode_agendar" => true
        ]);
    }

    // Verificar se esta dentro do horario
    if ($agora >= $horario['open_time'] && $agora <= $horario['close_time']) {
        // Aberto!
        response(true, [
            "aberto" => true,
            "fecha_as" => substr($horario['close_time'], 0, 5),
            "mensagem" => "Aberto ate " . substr($horario['close_time'], 0, 5)
        ]);
    }

    // Fora do horario
    if ($agora < $horario['open_time']) {
        // Ainda nao abriu hoje
        response(true, [
            "aberto" => false,
            "motivo" => "antes_abertura",
            "proxima_abertura" => [
                "data" => $hoje,
                "hora" => substr($horario['open_time'], 0, 5),
                "mensagem" => "Abre hoje as " . substr($horario['open_time'], 0, 5)
            ],
            "pode_agendar" => true
        ]);
    } else {
        // Ja fechou hoje
        $proximo = buscarProximaAbertura($db, $partner_id, $hoje);
        response(true, [
            "aberto" => false,
            "motivo" => "depois_fechamento",
            "proxima_abertura" => $proximo,
            "pode_agendar" => true
        ]);
    }

} catch (Exception $e) {
    error_log("Erro verificar horario: " . $e->getMessage());
    response(false, null, "Erro ao verificar horario", 500);
}

/**
 * Buscar proxima abertura do mercado
 */
function buscarProximaAbertura($db, $partner_id, $a_partir_de) {
    // Verificar proximos 7 dias
    for ($i = 1; $i <= 7; $i++) {
        $data = date('Y-m-d', strtotime($a_partir_de . " +{$i} days"));
        $dia_semana = (int)date('w', strtotime($data));

        // Verificar se tem feriado
        $stmt = $db->prepare("SELECT * FROM om_partner_holidays WHERE partner_id = ? AND date = ?");
        $stmt->execute([$partner_id, $data]);
        $feriado = $stmt->fetch();

        if ($feriado && $feriado['is_closed']) {
            continue; // Pular feriado fechado
        }

        if ($feriado && !$feriado['is_closed']) {
            // Horario especial
            return [
                "data" => $data,
                "data_formatada" => formatarData($data),
                "hora" => substr($feriado['open_time'], 0, 5),
                "mensagem" => "Abre " . formatarData($data) . " as " . substr($feriado['open_time'], 0, 5)
            ];
        }

        // Verificar horario normal
        $stmt = $db->prepare("SELECT * FROM om_partner_hours WHERE partner_id = ? AND day_of_week = ?");
        $stmt->execute([$partner_id, $dia_semana]);
        $horario = $stmt->fetch();

        if ($horario && !$horario['is_closed']) {
            return [
                "data" => $data,
                "data_formatada" => formatarData($data),
                "hora" => substr($horario['open_time'], 0, 5),
                "mensagem" => "Abre " . formatarData($data) . " as " . substr($horario['open_time'], 0, 5)
            ];
        }
    }

    return [
        "data" => null,
        "mensagem" => "Horario nao disponivel. Entre em contato."
    ];
}

/**
 * Formatar data para exibicao
 */
function formatarData($data) {
    $hoje = date('Y-m-d');
    $amanha = date('Y-m-d', strtotime('+1 day'));

    if ($data === $hoje) return "hoje";
    if ($data === $amanha) return "amanha";

    $dias = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
    $dia_semana = (int)date('w', strtotime($data));

    return $dias[$dia_semana] . " (" . date('d/m', strtotime($data)) . ")";
}

/**
 * Nome do dia da semana
 */
function nomeDiaSemana($dia) {
    $dias = ['aos Domingos', 'as Segundas', 'as Tercas', 'as Quartas', 'as Quintas', 'as Sextas', 'aos Sabados'];
    return $dias[$dia] ?? '';
}

/**
 * Resposta padronizada
 */
function response($success, $data = null, $message = null, $httpCode = 200) {
    http_response_code($httpCode);
    $response = ["success" => $success];
    if ($message) $response["message"] = $message;
    if ($data) $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
