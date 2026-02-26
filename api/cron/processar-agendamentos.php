<?php
/**
 * ============================================================================
 * CRON: Processar Agendamentos
 * /api/cron/processar-agendamentos.php
 * ============================================================================
 *
 * Este cron deve rodar a cada 5 minutos.
 * Crontab: * /5 * * * * php /root/api/cron/processar-agendamentos.php
 *
 * Funcoes:
 * 1. Busca agendamentos onde data_agendada = hoje e horario_inicio <= agora + 10 min
 * 2. Cria o pedido real no sistema
 * 3. Dispara wave para shoppers
 * 4. Envia notificacao push para cliente
 * 5. Atualiza status para 'processando'
 */

// Pode ser executado via CLI ou HTTP
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once dirname(__DIR__, 2) . '/includes/om_bootstrap.php';

// Log helper
function cronLog($msg) {
    global $isCli;
    $timestamp = date('Y-m-d H:i:s');
    $logMsg = "[{$timestamp}] [processar-agendamentos] {$msg}";
    error_log($logMsg);
    if ($isCli) {
        echo $logMsg . "\n";
    }
}

try {
    $pdo = om_db();

    cronLog("Iniciando processamento de agendamentos...");

    $agora = new DateTime();
    $hoje = $agora->format('Y-m-d');
    $horarioLimite = (clone $agora)->modify('+10 minutes')->format('H:i:s');

    // Buscar agendamentos pendentes/confirmados que devem ser processados
    $stmt = $pdo->prepare("
        SELECT a.*,
               c.firstname, c.lastname, c.email, c.telephone,
               p.name as partner_name, p.lat as partner_lat, p.lng as partner_lng
        FROM om_agendamentos a
        JOIN oc_customer c ON c.customer_id = a.customer_id
        LEFT JOIN oc_partner p ON p.partner_id = a.partner_id
        WHERE a.data_agendada = ?
          AND a.horario_inicio <= ?
          AND a.status IN ('pendente', 'confirmado')
          AND a.processado_em IS NULL
        ORDER BY a.horario_inicio ASC
        LIMIT 50
    ");
    $stmt->execute([$hoje, $horarioLimite]);
    $agendamentos = $stmt->fetchAll();

    $processados = 0;
    $erros = 0;

    foreach ($agendamentos as $agendamento) {
        try {
            cronLog("Processando agendamento #{$agendamento['id']} - Cliente: {$agendamento['customer_id']}");

            // Iniciar transacao
            $pdo->beginTransaction();

            // Decodificar itens e endereco
            $itens = json_decode($agendamento['itens_json'], true) ?: [];
            $endereco = json_decode($agendamento['endereco_json'], true) ?: [];

            if (empty($itens)) {
                throw new Exception("Agendamento sem itens");
            }

            // Criar pedido no OpenCart
            $orderId = criarPedidoDoAgendamento($pdo, $agendamento, $itens, $endereco);

            // Atualizar agendamento com order_id
            $stmtUpdate = $pdo->prepare("
                UPDATE om_agendamentos
                SET order_id = ?,
                    status = 'processando',
                    processado_em = NOW()
                WHERE id = ?
            ");
            $stmtUpdate->execute([$orderId, $agendamento['id']]);

            // Commit da transacao
            $pdo->commit();

            // Disparar wave para shoppers (fora da transacao)
            if (!empty($agendamento['partner_lat']) && !empty($agendamento['partner_lng'])) {
                dispararWaveShopper($pdo, $orderId, $agendamento);
            }

            // Enviar notificacao push para cliente
            enviarNotificacaoCliente($pdo, $agendamento, $orderId);

            $processados++;
            cronLog("Agendamento #{$agendamento['id']} processado com sucesso - Pedido: #{$orderId}");

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erros++;
            cronLog("ERRO no agendamento #{$agendamento['id']}: " . $e->getMessage());

            // Marcar agendamento com erro (opcional - pode criar campo erro_msg)
            try {
                $pdo->prepare("
                    UPDATE om_agendamentos SET status = 'pendente' WHERE id = ?
                ")->execute([$agendamento['id']]);
            } catch (Exception $e2) {}
        }
    }

    // Processar notificacoes de lembrete (30 min antes)
    processarLembretes($pdo);

    $resultado = [
        'success' => true,
        'processados' => $processados,
        'erros' => $erros,
        'total_encontrados' => count($agendamentos),
        'timestamp' => $agora->format('Y-m-d H:i:s')
    ];

    cronLog("Finalizando: {$processados} processados, {$erros} erros de " . count($agendamentos) . " encontrados");

    if (!$isCli) {
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    cronLog("ERRO CRITICO: " . $e->getMessage());
    if (!$isCli) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
    }
    exit(1);
}

/**
 * Criar pedido no OpenCart a partir do agendamento
 */
function criarPedidoDoAgendamento($pdo, $agendamento, $itens, $endereco) {
    // Buscar dados completos do cliente
    $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
    $stmt->execute([$agendamento['customer_id']]);
    $customer = $stmt->fetch();

    if (!$customer) {
        throw new Exception("Cliente nao encontrado");
    }

    // Buscar zone_id
    $uf = strtoupper($endereco['uf'] ?? $endereco['zone'] ?? 'SP');
    $zoneStmt = $pdo->prepare("SELECT zone_id, name FROM oc_zone WHERE country_id = 30 AND code = ?");
    $zoneStmt->execute([$uf]);
    $zone = $zoneStmt->fetch();
    $zoneId = $zone['zone_id'] ?? 0;
    $zoneName = $zone['name'] ?? $uf;

    // Calcular valores
    $subtotal = 0;
    foreach ($itens as $item) {
        $subtotal += floatval($item['price'] ?? 0) * intval($item['quantity'] ?? 1);
    }
    $frete = floatval($agendamento['frete'] ?? 0);
    $total = floatval($agendamento['total']) ?: ($subtotal + $frete);

    // Criar pedido
    $stmt = $pdo->prepare("
        INSERT INTO oc_order (
            invoice_prefix, store_id, store_name, store_url,
            customer_id, customer_group_id, firstname, lastname, email, telephone,
            payment_firstname, payment_lastname, payment_address_1, payment_address_2,
            payment_city, payment_postcode, payment_zone, payment_zone_id,
            payment_country, payment_country_id, payment_method, payment_code,
            shipping_firstname, shipping_lastname, shipping_address_1, shipping_address_2,
            shipping_city, shipping_postcode, shipping_zone, shipping_zone_id,
            shipping_country, shipping_country_id, shipping_method, shipping_code,
            comment, total, order_status_id, currency_id, currency_code, currency_value,
            ip, user_agent, language_id, date_added, date_modified
        ) VALUES (
            'AG-', 0, 'OneMundo', 'https://onemundo.com.br/',
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            'Brasil', 30, 'PIX', 'pix',
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            'Brasil', 30, 'Entrega Agendada', 'agendamento',
            ?, ?, 1, 1, 'BRL', 1.00000000,
            '127.0.0.1', 'Cron Agendamento', 1, NOW(), NOW()
        )
        RETURNING order_id
    ");

    $comentario = "Pedido Agendado #{$agendamento['id']} - " .
                  "Data: {$agendamento['data_agendada']} " .
                  "Horario: " . substr($agendamento['horario_inicio'], 0, 5) . " - " . substr($agendamento['horario_fim'], 0, 5);

    $stmt->execute([
        $agendamento['customer_id'],
        $customer['customer_group_id'] ?? 1,
        $customer['firstname'],
        $customer['lastname'] ?? '',
        $customer['email'],
        $customer['telephone'] ?? '',
        $endereco['firstname'] ?? $customer['firstname'],
        $endereco['lastname'] ?? $customer['lastname'] ?? '',
        $endereco['address_1'] ?? $endereco['endereco'] ?? '',
        $endereco['address_2'] ?? $endereco['complemento'] ?? '',
        $endereco['city'] ?? $endereco['cidade'] ?? '',
        $endereco['postcode'] ?? $endereco['cep'] ?? '',
        $zoneName,
        $zoneId,
        $endereco['firstname'] ?? $customer['firstname'],
        $endereco['lastname'] ?? $customer['lastname'] ?? '',
        $endereco['address_1'] ?? $endereco['endereco'] ?? '',
        $endereco['address_2'] ?? $endereco['complemento'] ?? '',
        $endereco['city'] ?? $endereco['cidade'] ?? '',
        $endereco['postcode'] ?? $endereco['cep'] ?? '',
        $zoneName,
        $zoneId,
        $comentario,
        $total
    ]);

    $orderId = $stmt->fetchColumn();

    // Inserir produtos
    $stmtProd = $pdo->prepare("
        INSERT INTO oc_order_product
        (order_id, product_id, name, model, quantity, price, total, tax, reward)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)
    ");

    foreach ($itens as $item) {
        $qty = intval($item['quantity'] ?? 1);
        $price = floatval($item['price'] ?? 0);
        $stmtProd->execute([
            $orderId,
            $item['product_id'] ?? 0,
            $item['name'] ?? 'Produto',
            $item['model'] ?? '',
            $qty,
            $price,
            $price * $qty
        ]);
    }

    // Inserir totais
    $pdo->prepare("INSERT INTO oc_order_total (order_id, code, title, value, sort_order) VALUES (?, 'sub_total', 'Sub-Total', ?, 1)")
        ->execute([$orderId, $subtotal]);

    if ($frete > 0) {
        $pdo->prepare("INSERT INTO oc_order_total (order_id, code, title, value, sort_order) VALUES (?, 'shipping', 'Entrega Agendada', ?, 2)")
            ->execute([$orderId, $frete]);
    }

    $pdo->prepare("INSERT INTO oc_order_total (order_id, code, title, value, sort_order) VALUES (?, 'total', 'Total', ?, 9)")
        ->execute([$orderId, $total]);

    // Historico
    $pdo->prepare("INSERT INTO oc_order_history (order_id, order_status_id, notify, comment, date_added) VALUES (?, 1, 0, ?, NOW())")
        ->execute([$orderId, "Pedido criado via agendamento #{$agendamento['id']}"]);

    // Vincular ao partner/mercado
    if ($agendamento['partner_id']) {
        try {
            $pdo->prepare("
                INSERT INTO om_order_partner (order_id, partner_id, created_at)
                VALUES (?, ?, NOW())
                ON CONFLICT (order_id) DO UPDATE SET partner_id = EXCLUDED.partner_id
            ")->execute([$orderId, $agendamento['partner_id']]);
        } catch (Exception $e) {
            // Tabela pode nao existir, ignorar
        }
    }

    return $orderId;
}

/**
 * Disparar wave para shoppers
 */
function dispararWaveShopper($pdo, $orderId, $agendamento) {
    try {
        // Verificar se API de waves existe e chamar
        $waveData = [
            'order_id' => $orderId,
            'order_type' => 'mercado',
            'worker_type' => 'shopper',
            'ref_lat' => $agendamento['partner_lat'],
            'ref_lng' => $agendamento['partner_lng'],
            'ref_nome' => $agendamento['partner_name'] ?? 'Mercado'
        ];

        // Inserir diretamente no banco para dispatch
        $stmt = $pdo->prepare("
            INSERT INTO om_wave_dispatch
            (order_id, order_type, worker_type, ref_lat, ref_lng, ref_nome, current_wave, status, started_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, 'dispatching', NOW())
            ON CONFLICT (order_id, order_type) DO UPDATE SET status = 'dispatching', started_at = NOW()
        ");
        $stmt->execute([
            $orderId,
            'mercado',
            'shopper',
            $agendamento['partner_lat'],
            $agendamento['partner_lng'],
            $agendamento['partner_name'] ?? 'Mercado'
        ]);

        cronLog("Wave dispatch criado para pedido #{$orderId}");

    } catch (Exception $e) {
        cronLog("Aviso: Nao foi possivel disparar wave - " . $e->getMessage());
    }
}

/**
 * Enviar notificacao push para cliente
 */
function enviarNotificacaoCliente($pdo, $agendamento, $orderId) {
    try {
        $titulo = "Seu pedido agendado esta sendo preparado!";
        $corpo = "Pedido #{$orderId} do " . ($agendamento['partner_name'] ?? 'mercado') .
                 " agendado para " . substr($agendamento['horario_inicio'], 0, 5) .
                 " esta sendo preparado.";

        // Inserir notificacao no banco
        $stmt = $pdo->prepare("
            INSERT INTO om_notifications
            (user_id, user_type, title, body, data, created_at)
            VALUES (?, 'customer', ?, ?, ?::jsonb, NOW())
        ");
        $stmt->execute([
            $agendamento['customer_id'],
            $titulo,
            $corpo,
            json_encode([
                'order_id' => $orderId,
                'agendamento_id' => $agendamento['id'],
                'type' => 'agendamento_processando',
                'ref_type' => 'order',
                'ref_id' => $orderId
            ])
        ]);

        // Atualizar notificado_em no agendamento
        $pdo->prepare("UPDATE om_agendamentos SET notificado_em = NOW() WHERE id = ?")
            ->execute([$agendamento['id']]);

        cronLog("Notificacao enviada para cliente #{$agendamento['customer_id']}");

    } catch (Exception $e) {
        cronLog("Aviso: Nao foi possivel enviar notificacao - " . $e->getMessage());
    }
}

/**
 * Processar lembretes de agendamentos (30 min antes)
 */
function processarLembretes($pdo) {
    try {
        $agora = new DateTime();
        $hoje = $agora->format('Y-m-d');
        $daqui30min = (clone $agora)->modify('+30 minutes')->format('H:i:s');
        $daqui35min = (clone $agora)->modify('+35 minutes')->format('H:i:s');

        // Buscar agendamentos que comecam em ~30 minutos e ainda nao foram notificados
        $stmt = $pdo->prepare("
            SELECT a.*, p.name as partner_name
            FROM om_agendamentos a
            LEFT JOIN oc_partner p ON p.partner_id = a.partner_id
            WHERE a.data_agendada = ?
              AND a.horario_inicio BETWEEN ? AND ?
              AND a.status IN ('pendente', 'confirmado')
              AND a.notificado_em IS NULL
        ");
        $stmt->execute([$hoje, $daqui30min, $daqui35min]);
        $lembretes = $stmt->fetchAll();

        foreach ($lembretes as $agendamento) {
            try {
                $titulo = "Lembrete: Seu pedido sera preparado em breve!";
                $corpo = "Seu pedido no " . ($agendamento['partner_name'] ?? 'mercado') .
                         " esta agendado para " . substr($agendamento['horario_inicio'], 0, 5) . ".";

                $stmt = $pdo->prepare("
                    INSERT INTO om_notifications
                    (user_id, user_type, title, body, data, created_at)
                    VALUES (?, 'customer', ?, ?, ?::jsonb, NOW())
                ");
                $stmt->execute([
                    $agendamento['customer_id'],
                    $titulo,
                    $corpo,
                    json_encode([
                        'agendamento_id' => $agendamento['id'],
                        'type' => 'agendamento_lembrete',
                        'ref_type' => 'agendamento',
                        'ref_id' => $agendamento['id']
                    ])
                ]);

                cronLog("Lembrete enviado para agendamento #{$agendamento['id']}");

            } catch (Exception $e) {
                cronLog("Erro ao enviar lembrete: " . $e->getMessage());
            }
        }

    } catch (Exception $e) {
        cronLog("Erro ao processar lembretes: " . $e->getMessage());
    }
}
