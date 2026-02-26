<?php
/**
 * Protocolo de Emergencia - Incidentes criticos de seguranca
 *
 * Arquivo de include para tratamento de emergencias reportadas por
 * shoppers (entregadores) e parceiros (lojas).
 *
 * Uso: require_once __DIR__ . '/emergency-protocol.php';
 *
 * Funcoes exportadas:
 *   emergency_check_subcategory($subcategory, $severity)
 *   emergency_create_incident($db, $params)
 *   emergency_get_active_incidents($db)
 *   emergency_acknowledge($db, $incidentId, $adminId)
 *   emergency_resolve($db, $incidentId, $adminId, $resolutionNote)
 *   emergency_check_sla($db)
 */

// ============================================================
// Subcategorias que disparam protocolo de emergencia
// ============================================================

// Shopper: sempre disparam emergencia independente da severidade
define('EMERGENCY_SHOPPER_ALWAYS', [
    'safety_assault',
    'safety_harassment',
    'safety_theft_of_delivery',
    'safety_dog_attack',
    'safety_weather_extreme',
    'safety_traffic_incident',
    'safety_threats',
    'vehicle_accident',
    'vehicle_theft',
]);

// Parceiro: sempre disparam emergencia independente da severidade
define('EMERGENCY_PARTNER_ALWAYS', [
    'customer_fraud',
    'customer_scam',
]);

// Parceiro: so disparam emergencia quando severidade = 'critical'
define('EMERGENCY_PARTNER_CRITICAL_ONLY', [
    'shopper_rude',
    'customer_rude',
]);

// ============================================================
// Inicializacao das tabelas (chamada automatica na primeira operacao)
// ============================================================

/**
 * Cria as tabelas de emergencia se nao existirem.
 * Seguro para chamar multiplas vezes (IF NOT EXISTS).
 */
function _emergency_ensure_tables(PDO $db): void {
    // Tables om_emergency_incidents, om_emergency_timeline created via migration
    return;
}

// ============================================================
// 1. Verificar se subcategoria dispara protocolo de emergencia
// ============================================================

/**
 * Verifica se uma subcategoria dispara o protocolo de emergencia.
 *
 * @param string $subcategory  Subcategoria do relatorio
 * @param string $severity     Severidade do relatorio (default: 'medium')
 * @return bool  True se deve acionar protocolo de emergencia
 */
function emergency_check_subcategory(string $subcategory, string $severity = 'medium'): bool {
    // Subcategorias de shopper que SEMPRE disparam
    if (in_array($subcategory, EMERGENCY_SHOPPER_ALWAYS, true)) {
        return true;
    }

    // Subcategorias de parceiro que SEMPRE disparam
    if (in_array($subcategory, EMERGENCY_PARTNER_ALWAYS, true)) {
        return true;
    }

    // Subcategorias de parceiro que so disparam com severidade critica
    if (in_array($subcategory, EMERGENCY_PARTNER_CRITICAL_ONLY, true)) {
        return $severity === 'critical';
    }

    return false;
}

// ============================================================
// 2. Criar incidente de emergencia
// ============================================================

/**
 * Cria um novo incidente de emergencia com notificacoes e timeline.
 *
 * @param PDO   $db      Conexao com banco de dados
 * @param array $params  Parametros do incidente:
 *   - report_type   (string) 'shopper_problem' ou 'partner_issue'
 *   - report_id     (int)    ID do relatorio original (problem_id ou issue_id)
 *   - reporter_type (string) 'shopper' ou 'partner'
 *   - reporter_id   (int)    ID do shopper ou parceiro
 *   - subcategory   (string) Subcategoria do problema
 *   - severity      (string) Severidade: critical, high, medium
 *   - order_id      (int|null) ID do pedido relacionado
 *   - latitude      (float|null) Latitude da localizacao
 *   - longitude     (float|null) Longitude da localizacao
 *
 * @return int ID do incidente criado
 * @throws Exception Em caso de erro no banco de dados
 */
function emergency_create_incident(PDO $db, array $params): int {
    _emergency_ensure_tables($db);

    $reportType   = $params['report_type']   ?? '';
    $reportId     = (int)($params['report_id']     ?? 0);
    $reporterType = $params['reporter_type'] ?? '';
    $reporterId   = (int)($params['reporter_id']   ?? 0);
    $subcategory  = $params['subcategory']   ?? '';
    $severity     = $params['severity']      ?? 'critical';
    $orderId      = !empty($params['order_id']) ? (int)$params['order_id'] : null;
    $latitude     = isset($params['latitude']) && $params['latitude'] !== null ? (float)$params['latitude'] : null;
    $longitude    = isset($params['longitude']) && $params['longitude'] !== null ? (float)$params['longitude'] : null;

    // Validacao basica
    if (!$reportType || !$reportId || !$reporterType || !$reporterId || !$subcategory) {
        throw new InvalidArgumentException("Parametros obrigatorios faltando para criar incidente de emergencia");
    }

    // SLA baseado na severidade
    $slaMinutes = match ($severity) {
        'critical' => 15,
        'high'     => 30,
        default    => 60,
    };

    $db->beginTransaction();

    try {
        // 1. Inserir incidente
        $stmt = $db->prepare("
            INSERT INTO om_emergency_incidents
                (report_type, report_id, reporter_type, reporter_id, subcategory, severity,
                 order_id, latitude, longitude, status, sla_minutes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())
        ");
        $stmt->execute([
            $reportType,
            $reportId,
            $reporterType,
            $reporterId,
            $subcategory,
            $severity,
            $orderId,
            $latitude,
            $longitude,
            $slaMinutes,
        ]);
        $incidentId = (int)$db->lastInsertId('om_emergency_incidents_incident_id_seq');

        // 2. Registrar na timeline
        _emergency_add_timeline($db, $incidentId, 'system', null, 'incident_created',
            "Incidente de emergencia criado. Tipo: {$subcategory}, Severidade: {$severity}, SLA: {$slaMinutes}min"
        );

        // 3. Notificar TODOS os admins (user_id=1, user_type='admin')
        $reporterLabel = $reporterType === 'shopper' ? 'Shopper' : 'Parceiro';
        $titleNotif = "URGENTE: Emergencia #{$incidentId} - {$subcategory}";
        $messageNotif = "{$reporterLabel} #{$reporterId} reportou: {$subcategory} (severidade: {$severity})."
            . ($orderId ? " Pedido #{$orderId}." : "")
            . " SLA: {$slaMinutes}min.";

        $db->prepare("
            INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
            VALUES (1, 'admin', ?, ?, 'emergency', 'emergency_incident', ?, NOW())
        ")->execute([$titleNotif, $messageNotif, $incidentId]);

        // 4. Se houver pedido, notificar a outra parte envolvida
        if ($orderId) {
            _emergency_notify_other_party($db, $orderId, $reporterType, $incidentId, $subcategory);
        }

        $db->commit();

        error_log("[emergency] Incidente #{$incidentId} criado: {$subcategory} por {$reporterType} #{$reporterId}");

        return $incidentId;

    } catch (Exception $e) {
        $db->rollBack();
        error_log("[emergency] Erro ao criar incidente: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Notifica a outra parte envolvida no pedido.
 * Se shopper reportou, notifica o parceiro. Se parceiro reportou, notifica o shopper.
 */
function _emergency_notify_other_party(PDO $db, int $orderId, string $reporterType, int $incidentId, string $subcategory): void {
    try {
        $stmt = $db->prepare("
            SELECT partner_id, shopper_id FROM om_market_orders WHERE order_id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) return;

        if ($reporterType === 'shopper' && !empty($order['partner_id'])) {
            // Shopper reportou -> notificar parceiro
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (?, 'partner', ?, ?, 'emergency_alert', 'emergency_incident', ?, NOW())
            ")->execute([
                (int)$order['partner_id'],
                "Alerta de seguranca no pedido #{$orderId}",
                "Um incidente de seguranca ({$subcategory}) foi reportado no pedido #{$orderId}. A equipe de suporte ja foi acionada.",
                $incidentId,
            ]);
        } elseif ($reporterType === 'partner' && !empty($order['shopper_id'])) {
            // Parceiro reportou -> notificar shopper
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (?, 'shopper', ?, ?, 'emergency_alert', 'emergency_incident', ?, NOW())
            ")->execute([
                (int)$order['shopper_id'],
                "Alerta de seguranca no pedido #{$orderId}",
                "Um incidente de seguranca ({$subcategory}) foi reportado no pedido #{$orderId}. A equipe de suporte ja foi acionada.",
                $incidentId,
            ]);
        }
    } catch (Exception $e) {
        error_log("[emergency] Falha ao notificar outra parte: " . $e->getMessage());
    }
}

// ============================================================
// 3. Listar incidentes ativos
// ============================================================

/**
 * Retorna todos os incidentes ativos/acknowledged ordenados por severidade e data.
 *
 * @param PDO $db Conexao com banco de dados
 * @return array Lista de incidentes com informacoes do reporter
 */
function emergency_get_active_incidents(PDO $db): array {
    _emergency_ensure_tables($db);

    $stmt = $db->prepare("
        SELECT
            ei.*,
            CASE
                WHEN ei.reporter_type = 'shopper' THEN s.name
                WHEN ei.reporter_type = 'partner' THEN p.name
                ELSE NULL
            END AS reporter_name,
            CASE
                WHEN ei.reporter_type = 'shopper' THEN s.phone
                WHEN ei.reporter_type = 'partner' THEN p.phone
                ELSE NULL
            END AS reporter_phone,
            CASE
                WHEN ei.created_at + (ei.sla_minutes || ' minutes')::INTERVAL < NOW()
                THEN TRUE ELSE FALSE
            END AS sla_expired
        FROM om_emergency_incidents ei
        LEFT JOIN om_market_shoppers s
            ON ei.reporter_type = 'shopper' AND ei.reporter_id = s.shopper_id
        LEFT JOIN om_market_partners p
            ON ei.reporter_type = 'partner' AND ei.reporter_id = p.partner_id
        WHERE ei.status IN ('active', 'acknowledged')
        ORDER BY
            CASE ei.severity
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                ELSE 4
            END ASC,
            ei.created_at ASC
    ");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// 4. Reconhecer incidente (admin)
// ============================================================

/**
 * Marca um incidente como reconhecido por um administrador.
 *
 * @param PDO $db          Conexao com banco de dados
 * @param int $incidentId  ID do incidente
 * @param int $adminId     ID do administrador
 * @return bool True se atualizado com sucesso
 */
function emergency_acknowledge(PDO $db, int $incidentId, int $adminId): bool {
    _emergency_ensure_tables($db);

    $db->beginTransaction();

    try {
        // Verificar se incidente existe e esta ativo
        $stmt = $db->prepare("
            SELECT incident_id, reporter_type, reporter_id, status
            FROM om_emergency_incidents
            WHERE incident_id = ?
        ");
        $stmt->execute([$incidentId]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) {
            $db->rollBack();
            return false;
        }

        if ($incident['status'] !== 'active') {
            // Ja foi reconhecido ou resolvido, nao precisa atualizar
            $db->rollBack();
            return false;
        }

        // Atualizar status
        $db->prepare("
            UPDATE om_emergency_incidents
            SET status = 'acknowledged',
                acknowledged_by = ?,
                acknowledged_at = NOW(),
                updated_at = NOW()
            WHERE incident_id = ?
        ")->execute([$adminId, $incidentId]);

        // Registrar na timeline
        _emergency_add_timeline($db, $incidentId, 'admin', $adminId, 'acknowledged',
            "Incidente reconhecido pelo admin #{$adminId}"
        );

        // Notificar o reporter que a emergencia esta sendo tratada
        $db->prepare("
            INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
            VALUES (?, ?, ?, ?, 'emergency_update', 'emergency_incident', ?, NOW())
        ")->execute([
            (int)$incident['reporter_id'],
            $incident['reporter_type'],
            "Emergencia em atendimento",
            "Sua emergencia esta sendo tratada. Nossa equipe ja esta cuidando do caso. Incidente #{$incidentId}.",
            $incidentId,
        ]);

        $db->commit();

        error_log("[emergency] Incidente #{$incidentId} reconhecido por admin #{$adminId}");
        return true;

    } catch (Exception $e) {
        $db->rollBack();
        error_log("[emergency] Erro ao reconhecer incidente #{$incidentId}: " . $e->getMessage());
        throw $e;
    }
}

// ============================================================
// 5. Resolver incidente (admin)
// ============================================================

/**
 * Marca um incidente como resolvido com nota de resolucao.
 *
 * @param PDO    $db              Conexao com banco de dados
 * @param int    $incidentId      ID do incidente
 * @param int    $adminId         ID do administrador
 * @param string $resolutionNote  Nota explicando a resolucao
 * @return bool True se resolvido com sucesso
 */
function emergency_resolve(PDO $db, int $incidentId, int $adminId, string $resolutionNote): bool {
    _emergency_ensure_tables($db);

    $db->beginTransaction();

    try {
        // Verificar se incidente existe e nao esta fechado
        $stmt = $db->prepare("
            SELECT incident_id, reporter_type, reporter_id, status, subcategory
            FROM om_emergency_incidents
            WHERE incident_id = ?
        ");
        $stmt->execute([$incidentId]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) {
            $db->rollBack();
            return false;
        }

        if (in_array($incident['status'], ['resolved', 'closed'], true)) {
            // Ja resolvido ou fechado
            $db->rollBack();
            return false;
        }

        $resolutionNote = trim($resolutionNote);

        // Atualizar status
        $db->prepare("
            UPDATE om_emergency_incidents
            SET status = 'resolved',
                resolution_note = ?,
                resolved_by = ?,
                resolved_at = NOW(),
                updated_at = NOW()
            WHERE incident_id = ?
        ")->execute([$resolutionNote, $adminId, $incidentId]);

        // Registrar na timeline
        _emergency_add_timeline($db, $incidentId, 'admin', $adminId, 'resolved',
            "Incidente resolvido. Resolucao: " . mb_substr($resolutionNote, 0, 500)
        );

        // Notificar o reporter com a resolucao
        $db->prepare("
            INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
            VALUES (?, ?, ?, ?, 'emergency_resolved', 'emergency_incident', ?, NOW())
        ")->execute([
            (int)$incident['reporter_id'],
            $incident['reporter_type'],
            "Emergencia resolvida - #{$incidentId}",
            "Sua emergencia foi resolvida. Resolucao: " . mb_substr($resolutionNote, 0, 300),
            $incidentId,
        ]);

        $db->commit();

        error_log("[emergency] Incidente #{$incidentId} resolvido por admin #{$adminId}");
        return true;

    } catch (Exception $e) {
        $db->rollBack();
        error_log("[emergency] Erro ao resolver incidente #{$incidentId}: " . $e->getMessage());
        throw $e;
    }
}

// ============================================================
// 6. Verificar SLA (para cron ou chamada avulsa)
// ============================================================

/**
 * Verifica e marca incidentes que ultrapassaram o SLA.
 * Envia notificacao de escalacao para admin.
 * Pode ser chamado por cron ou manualmente.
 *
 * @param PDO $db Conexao com banco de dados
 * @return int Quantidade de incidentes que violaram o SLA nesta verificacao
 */
function emergency_check_sla(PDO $db): int {
    _emergency_ensure_tables($db);

    // Buscar incidentes ativos/acknowledged que passaram do SLA e ainda nao marcados
    $stmt = $db->prepare("
        SELECT incident_id, subcategory, severity, reporter_type, reporter_id,
               sla_minutes, created_at, status
        FROM om_emergency_incidents
        WHERE sla_breached = FALSE
          AND status IN ('active', 'acknowledged')
          AND created_at + (sla_minutes || ' minutes')::INTERVAL < NOW()
    ");
    $stmt->execute();
    $breached = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;

    foreach ($breached as $incident) {
        try {
            $db->beginTransaction();

            // Marcar como SLA violado
            $db->prepare("
                UPDATE om_emergency_incidents
                SET sla_breached = TRUE,
                    updated_at = NOW()
                WHERE incident_id = ?
            ")->execute([(int)$incident['incident_id']]);

            // Timeline
            _emergency_add_timeline(
                $db,
                (int)$incident['incident_id'],
                'system',
                null,
                'sla_breached',
                "SLA de {$incident['sla_minutes']}min violado. Incidente ainda em status: {$incident['status']}"
            );

            // Notificacao de escalacao para admin
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, reference_type, reference_id, created_at)
                VALUES (1, 'admin', ?, ?, 'emergency_escalation', 'emergency_incident', ?, NOW())
            ")->execute([
                "ESCALACAO: Emergencia #{$incident['incident_id']} - SLA violado",
                "O incidente #{$incident['incident_id']} ({$incident['subcategory']}) ultrapassou o SLA de {$incident['sla_minutes']}min. "
                    . "Status atual: {$incident['status']}. Severidade: {$incident['severity']}. "
                    . "Acao imediata necessaria.",
                (int)$incident['incident_id'],
            ]);

            $db->commit();
            $count++;

            error_log("[emergency] SLA violado no incidente #{$incident['incident_id']} ({$incident['subcategory']})");

        } catch (Exception $e) {
            $db->rollBack();
            error_log("[emergency] Erro ao marcar SLA violado no incidente #{$incident['incident_id']}: " . $e->getMessage());
        }
    }

    if ($count > 0) {
        error_log("[emergency] Verificacao de SLA: {$count} incidente(s) com SLA violado");
    }

    return $count;
}

// ============================================================
// Funcao auxiliar: adicionar entrada na timeline
// ============================================================

/**
 * Adiciona uma entrada na timeline do incidente.
 *
 * @param PDO         $db          Conexao com banco de dados
 * @param int         $incidentId  ID do incidente
 * @param string      $actorType   Tipo do ator (system, admin, shopper, partner)
 * @param int|null    $actorId     ID do ator (null para sistema)
 * @param string      $action      Acao realizada
 * @param string|null $description Descricao detalhada
 */
function _emergency_add_timeline(PDO $db, int $incidentId, string $actorType, ?int $actorId, string $action, ?string $description = null): void {
    $db->prepare("
        INSERT INTO om_emergency_timeline (incident_id, actor_type, actor_id, action, description, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ")->execute([
        $incidentId,
        $actorType,
        $actorId,
        $action,
        $description,
    ]);
}
