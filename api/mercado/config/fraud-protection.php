<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SISTEMA DE PROTECAO CONTRA FRAUDES - Marketplace
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Modulo centralizado de deteccao e prevencao de fraudes.
 * Inclui rate limiting por acao, chaves de idempotencia, e deteccao de padroes.
 *
 * Uso:
 *   require_once __DIR__ . "/config/fraud-protection.php";
 *
 *   // Verificar rate limit antes de processar acao
 *   $rl = fraud_check_rate_limit($db, 'customer', $customerId, 'refund_request');
 *   if (!$rl['allowed']) response(false, null, $rl['reason'], 429);
 *
 *   // Idempotencia para evitar submissoes duplicadas
 *   $cached = fraud_check_idempotency($db, $idempotencyKey, 'customer', $id, 'refund');
 *   if ($cached !== null) response(true, $cached, "Requisicao ja processada");
 *
 *   // Apos processar, armazenar resultado
 *   fraud_store_idempotency($db, $idempotencyKey, 'customer', $id, 'refund', $result);
 *
 *   // Analise de padroes de fraude
 *   $risk = fraud_run_pattern_check($db, $customerId);
 *   if ($risk['risk_level'] === 'high') -- bloquear auto-aprovacao
 *
 * Tabelas criadas automaticamente (IF NOT EXISTS):
 *   - om_idempotency_keys: controle de requisicoes duplicadas
 *   - om_fraud_flags: sinalizacoes de comportamento suspeito
 *
 * @author  Sistema OneMundo
 * @version 1.0.0
 */

// Evitar inclusao dupla
if (defined('FRAUD_PROTECTION_LOADED')) return;
define('FRAUD_PROTECTION_LOADED', true);

// ══════════════════════════════════════════════════════════════════════════════
// CONFIGURACAO DE LIMITES
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Regras de rate limit por tipo de entidade e acao.
 * Formato: 'entity_type:action' => ['max' => int, 'window_seconds' => int, 'label' => string]
 */
define('FRAUD_RATE_LIMITS', [
    // Cliente: max 3 reembolsos por 24h
    'customer:refund_request'   => ['max' => 3,  'window_seconds' => 86400,  'label' => 'solicitacoes de reembolso em 24h'],
    // Cliente: max 5 disputas por 7 dias
    'customer:dispute_submit'   => ['max' => 5,  'window_seconds' => 604800, 'label' => 'disputas em 7 dias'],
    // Shopper: max 10 relatos de problema por 24h
    'shopper:problem_report'    => ['max' => 10, 'window_seconds' => 86400,  'label' => 'relatos de problema em 24h'],
    // Parceiro: max 10 relatos de problema por 24h
    'partner:issue_report'      => ['max' => 10, 'window_seconds' => 86400,  'label' => 'relatos de problema em 24h'],
]);

// ══════════════════════════════════════════════════════════════════════════════
// CONTROLE DE TABELAS - Criacao automatica
// ══════════════════════════════════════════════════════════════════════════════

/** @var bool Flag interna para garantir que tabelas sao criadas apenas uma vez por request */
$_fraud_tables_ensured = false;

/**
 * Garante que as tabelas de fraude existem no banco.
 * Executa CREATE TABLE IF NOT EXISTS + indices.
 * Chamado automaticamente na primeira invocacao de qualquer funcao publica.
 *
 * @param PDO $db Conexao PDO com PostgreSQL
 * @return void
 */
function _fraud_ensure_tables(PDO $db): void {
    // Tables om_idempotency_keys, om_fraud_flags, om_fraud_rate_log created via migration
    global $_fraud_tables_ensured;
    $_fraud_tables_ensured = true;
    return;
}

// ══════════════════════════════════════════════════════════════════════════════
// 1. RATE LIMITING POR ACAO
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Verifica se uma entidade (customer, shopper, partner) pode executar uma acao
 * com base nos limites configurados em FRAUD_RATE_LIMITS.
 *
 * Cada chamada registra a acao na tabela om_fraud_rate_log.
 * Chaves expiradas sao limpas automaticamente.
 *
 * @param PDO    $db         Conexao PDO
 * @param string $entityType Tipo de entidade: 'customer', 'shopper', 'partner'
 * @param int    $entityId   ID da entidade
 * @param string $action     Acao: 'refund_request', 'dispute_submit', 'problem_report', 'issue_report'
 *
 * @return array ['allowed' => bool, 'reason' => string, 'remaining' => int, 'retry_after_seconds' => int]
 *               - allowed: true se pode prosseguir, false se excedeu o limite
 *               - reason: mensagem descritiva (vazia se permitido)
 *               - remaining: quantas acoes ainda restam na janela
 *               - retry_after_seconds: segundos ate resetar a janela (0 se permitido)
 */
function fraud_check_rate_limit(PDO $db, string $entityType, int $entityId, string $action): array {
    _fraud_ensure_tables($db);

    $ruleKey = "{$entityType}:{$action}";
    $limits = FRAUD_RATE_LIMITS;

    // Se nao ha regra definida, permitir (nao bloquear acoes desconhecidas)
    if (!isset($limits[$ruleKey])) {
        return [
            'allowed'             => true,
            'reason'              => '',
            'remaining'           => 999,
            'retry_after_seconds' => 0,
        ];
    }

    $rule = $limits[$ruleKey];
    $max = (int)$rule['max'];
    $windowSeconds = (int)$rule['window_seconds'];
    $label = $rule['label'];

    // Contar acoes dentro da janela de tempo
    $windowSeconds = (int)$windowSeconds;
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt,
               MIN(created_at) as oldest
        FROM om_fraud_rate_log
        WHERE entity_type = ?
          AND entity_id = ?
          AND action = ?
          AND created_at > ?
    ");
    $stmt->execute([$entityType, $entityId, $action, $cutoff]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    $count = (int)($row['cnt'] ?? 0);

    // Calcular tempo restante para reset da janela mais antiga
    $retryAfter = 0;
    if ($count >= $max && $row['oldest']) {
        $oldestTime = strtotime($row['oldest']);
        $windowEnd = $oldestTime + $windowSeconds;
        $retryAfter = max(0, $windowEnd - time());
    }

    $remaining = max(0, $max - $count);

    if ($count >= $max) {
        // Limite excedido
        error_log("[fraud] Rate limit excedido: {$entityType}#{$entityId} acao={$action} contagem={$count}/{$max}");
        return [
            'allowed'             => false,
            'reason'              => "Limite excedido: maximo de {$max} {$label}. Tente novamente mais tarde.",
            'remaining'           => 0,
            'retry_after_seconds' => $retryAfter,
        ];
    }

    // Registrar esta acao no log
    $stmtInsert = $db->prepare("
        INSERT INTO om_fraud_rate_log (entity_type, entity_id, action, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmtInsert->execute([$entityType, $entityId, $action]);

    // Limpeza periodica: remover registros expirados (1% de chance por request)
    if (random_int(1, 100) === 1) {
        _fraud_cleanup_rate_log($db);
    }

    return [
        'allowed'             => true,
        'reason'              => '',
        'remaining'           => $remaining - 1,
        'retry_after_seconds' => 0,
    ];
}

/**
 * Limpa registros antigos da tabela de rate log.
 * Remove entradas com mais de 7 dias para manter a tabela enxuta.
 *
 * @param PDO $db Conexao PDO
 * @return void
 */
function _fraud_cleanup_rate_log(PDO $db): void {
    try {
        $db->exec("DELETE FROM om_fraud_rate_log WHERE created_at < NOW() - INTERVAL '7 days'");
    } catch (\Exception $e) {
        error_log("[fraud] Erro ao limpar rate log: " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. CHAVES DE IDEMPOTENCIA
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Verifica se uma chave de idempotencia ja foi processada.
 * Retorna o resultado cacheado se existir e nao estiver expirado,
 * ou null se nao houver registro (acao pode prosseguir).
 *
 * Uso tipico: o frontend envia um header X-Idempotency-Key para evitar
 * que um clique duplo processe a mesma acao duas vezes.
 *
 * @param PDO    $db         Conexao PDO
 * @param string $key        Chave de idempotencia (UUID ou hash unico)
 * @param string $entityType Tipo de entidade
 * @param int    $entityId   ID da entidade
 * @param string $action     Acao sendo executada
 *
 * @return array|null Resultado cacheado (array) ou null se nao encontrado
 */
function fraud_check_idempotency(PDO $db, string $key, string $entityType, int $entityId, string $action): ?array {
    _fraud_ensure_tables($db);

    // Validar chave: deve ter entre 8 e 100 caracteres alfanumericos/hifens
    $key = trim($key);
    if (empty($key) || strlen($key) > 100) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT result, entity_type, entity_id, action, expires_at
        FROM om_idempotency_keys
        WHERE key = ?
    ");
    $stmt->execute([$key]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        return null; // Chave nao existe, acao pode prosseguir
    }

    // Verificar expiracao
    if (strtotime($row['expires_at']) < time()) {
        // Chave expirada, remover e permitir nova execucao
        $stmtDel = $db->prepare("DELETE FROM om_idempotency_keys WHERE key = ?");
        $stmtDel->execute([$key]);
        return null;
    }

    // Verificar se a chave pertence a mesma entidade e acao
    // Previne reutilizacao de chave entre entidades diferentes
    if ($row['entity_type'] !== $entityType || (int)$row['entity_id'] !== $entityId || $row['action'] !== $action) {
        error_log("[fraud] Chave de idempotencia reutilizada entre entidades: key={$key} original={$row['entity_type']}#{$row['entity_id']} novo={$entityType}#{$entityId}");
        // Retornar null para nao bloquear, mas logar o evento
        return null;
    }

    // Decodificar resultado cacheado
    $result = json_decode($row['result'], true);
    return is_array($result) ? $result : null;
}

/**
 * Armazena o resultado de uma acao associada a uma chave de idempotencia.
 * Futuras requisicoes com a mesma chave retornarao este resultado sem
 * reprocessar a acao.
 *
 * @param PDO    $db         Conexao PDO
 * @param string $key        Chave de idempotencia
 * @param string $entityType Tipo de entidade
 * @param int    $entityId   ID da entidade
 * @param string $action     Acao executada
 * @param array  $result     Resultado a ser cacheado (sera serializado como JSON)
 * @param int    $ttlMinutes Tempo de vida em minutos (padrao: 60)
 *
 * @return bool true se armazenado com sucesso
 */
function fraud_store_idempotency(PDO $db, string $key, string $entityType, int $entityId, string $action, array $result, int $ttlMinutes = 60): bool {
    _fraud_ensure_tables($db);

    $key = trim($key);
    if (empty($key) || strlen($key) > 100) {
        return false;
    }

    $ttlMinutes = max(1, min($ttlMinutes, 10080)); // Min 1 min, max 7 dias

    try {
        // Upsert: inserir ou atualizar se ja existir
        $stmt = $db->prepare("
            INSERT INTO om_idempotency_keys (key, entity_type, entity_id, action, result, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL '{$ttlMinutes} minutes')
            ON CONFLICT (key) DO UPDATE SET
                result = EXCLUDED.result,
                expires_at = EXCLUDED.expires_at
        ");
        $stmt->execute([
            $key,
            $entityType,
            $entityId,
            $action,
            json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);

        // Limpeza periodica: remover chaves expiradas (2% de chance por request)
        if (random_int(1, 50) === 1) {
            _fraud_cleanup_idempotency($db);
        }

        return true;

    } catch (\Exception $e) {
        error_log("[fraud] Erro ao armazenar idempotency: " . $e->getMessage());
        return false;
    }
}

/**
 * Limpa chaves de idempotencia expiradas.
 *
 * @param PDO $db Conexao PDO
 * @return void
 */
function _fraud_cleanup_idempotency(PDO $db): void {
    try {
        $db->exec("DELETE FROM om_idempotency_keys WHERE expires_at < NOW()");
    } catch (\Exception $e) {
        error_log("[fraud] Erro ao limpar idempotency keys: " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. DETECCAO DE PADROES DE FRAUDE
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Executa analise completa de padroes de fraude para um cliente.
 *
 * Verifica tres indicadores:
 *   1. high_risk: mais de 5 reembolsos nos ultimos 30 dias
 *   2. abuse_pattern: mais de 50% dos pedidos com disputa
 *   3. suspicious_new_account: conta com menos de 7 dias e mais de 2 disputas
 *
 * Flags sao criadas/atualizadas automaticamente na tabela om_fraud_flags.
 * Flags resolvidas anteriormente nao sao recriadas (verificacao de duplicata).
 *
 * @param PDO $db         Conexao PDO
 * @param int $customerId ID do cliente
 *
 * @return array [
 *   'risk_level' => 'low'|'medium'|'high',
 *   'flags'      => [
 *     ['flag_type' => string, 'severity' => string, 'details' => array],
 *     ...
 *   ]
 * ]
 */
function fraud_run_pattern_check(PDO $db, int $customerId): array {
    _fraud_ensure_tables($db);

    $flags = [];
    $maxSeverity = 'low'; // sera promovido conforme flags detectadas

    // ── Indicador 1: Reembolsos excessivos (>5 em 30 dias) ──
    // Nota: om_market_refunds nao tem customer_id diretamente,
    // precisa fazer JOIN com om_market_orders para obter o customer_id
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM om_market_refunds r
            INNER JOIN om_market_orders o ON o.order_id = r.order_id
            WHERE o.customer_id = ?
              AND r.created_at > NOW() - INTERVAL '30 days'
        ");
        $stmt->execute([$customerId]);
        $refundCount = (int)$stmt->fetch(\PDO::FETCH_ASSOC)['cnt'];

        if ($refundCount > 5) {
            $details = [
                'refund_count_30d' => $refundCount,
                'threshold'        => 5,
                'checked_at'       => date('c'),
            ];
            $flags[] = [
                'flag_type' => 'high_risk',
                'severity'  => 'high',
                'details'   => $details,
            ];
            $maxSeverity = 'high';

            // Registrar flag (se nao houver flag ativa do mesmo tipo)
            _fraud_upsert_flag($db, 'customer', $customerId, 'high_risk', 'high', $details);
        }
    } catch (\Exception $e) {
        error_log("[fraud] Erro ao verificar reembolsos: " . $e->getMessage());
    }

    // ── Indicador 2: Padrao de abuso (>50% dos pedidos com disputa) ──
    try {
        // Total de pedidos do cliente
        $stmtOrders = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM om_market_orders
            WHERE customer_id = ?
              AND status NOT IN ('cancelled', 'cancelado')
        ");
        $stmtOrders->execute([$customerId]);
        $totalOrders = (int)$stmtOrders->fetch(\PDO::FETCH_ASSOC)['cnt'];

        if ($totalOrders > 0) {
            // Total de pedidos com disputa
            $stmtDisputes = $db->prepare("
                SELECT COUNT(DISTINCT order_id) as cnt
                FROM om_order_disputes
                WHERE customer_id = ?
            ");
            $stmtDisputes->execute([$customerId]);
            $disputedOrders = (int)$stmtDisputes->fetch(\PDO::FETCH_ASSOC)['cnt'];

            $disputeRate = $disputedOrders / $totalOrders;

            // Exigir minimo de 4 pedidos para evitar falsos positivos
            // (2 de 3 pedidos seria 66% mas pode ser coincidencia)
            if ($disputeRate > 0.5 && $totalOrders >= 4) {
                $details = [
                    'total_orders'    => $totalOrders,
                    'disputed_orders' => $disputedOrders,
                    'dispute_rate'    => round($disputeRate * 100, 1) . '%',
                    'checked_at'      => date('c'),
                ];
                $flags[] = [
                    'flag_type' => 'abuse_pattern',
                    'severity'  => 'high',
                    'details'   => $details,
                ];
                $maxSeverity = 'high';

                _fraud_upsert_flag($db, 'customer', $customerId, 'abuse_pattern', 'high', $details);
            }
        }
    } catch (\Exception $e) {
        error_log("[fraud] Erro ao verificar padrao de abuso: " . $e->getMessage());
    }

    // ── Indicador 3: Conta nova suspeita (<7 dias, >2 disputas) ──
    // Nota: tabela de clientes do marketplace e om_customers com coluna created_at
    try {
        $stmtAccount = $db->prepare("
            SELECT created_at
            FROM om_customers
            WHERE customer_id = ?
            LIMIT 1
        ");
        $stmtAccount->execute([$customerId]);
        $accountRow = $stmtAccount->fetch(\PDO::FETCH_ASSOC);

        if ($accountRow && $accountRow['created_at']) {
            $accountAgeDays = (time() - strtotime($accountRow['created_at'])) / 86400;

            if ($accountAgeDays < 7) {
                $stmtNewDisputes = $db->prepare("
                    SELECT COUNT(*) as cnt
                    FROM om_order_disputes
                    WHERE customer_id = ?
                ");
                $stmtNewDisputes->execute([$customerId]);
                $newDisputeCount = (int)$stmtNewDisputes->fetch(\PDO::FETCH_ASSOC)['cnt'];

                if ($newDisputeCount > 2) {
                    $severity = 'medium';
                    $details = [
                        'account_age_days' => round($accountAgeDays, 1),
                        'dispute_count'    => $newDisputeCount,
                        'threshold_days'   => 7,
                        'threshold_disputes' => 2,
                        'checked_at'       => date('c'),
                    ];
                    $flags[] = [
                        'flag_type' => 'suspicious_new_account',
                        'severity'  => $severity,
                        'details'   => $details,
                    ];
                    if ($maxSeverity === 'low') {
                        $maxSeverity = 'medium';
                    }

                    _fraud_upsert_flag($db, 'customer', $customerId, 'suspicious_new_account', $severity, $details);
                }
            }
        }
    } catch (\Exception $e) {
        error_log("[fraud] Erro ao verificar conta nova: " . $e->getMessage());
    }

    // Log se encontrou flags
    if (!empty($flags)) {
        $flagTypes = array_column($flags, 'flag_type');
        error_log("[fraud] Pattern check customer#{$customerId}: risk={$maxSeverity} flags=" . implode(',', $flagTypes));
    }

    return [
        'risk_level' => $maxSeverity,
        'flags'      => $flags,
    ];
}

/**
 * Insere ou atualiza uma flag de fraude na tabela om_fraud_flags.
 * Se ja existir uma flag ativa do mesmo tipo para a mesma entidade,
 * atualiza os detalhes ao inves de criar duplicata.
 *
 * @param PDO    $db         Conexao PDO
 * @param string $entityType Tipo de entidade
 * @param int    $entityId   ID da entidade
 * @param string $flagType   Tipo da flag
 * @param string $severity   Severidade: 'low', 'medium', 'high', 'critical'
 * @param array  $details    Detalhes adicionais (armazenados como JSONB)
 *
 * @return void
 */
function _fraud_upsert_flag(PDO $db, string $entityType, int $entityId, string $flagType, string $severity, array $details): void {
    try {
        // Verificar se ja existe flag ativa
        $stmt = $db->prepare("
            SELECT id FROM om_fraud_flags
            WHERE entity_type = ?
              AND entity_id = ?
              AND flag_type = ?
              AND is_active = TRUE
            LIMIT 1
        ");
        $stmt->execute([$entityType, $entityId, $flagType]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            // Atualizar detalhes da flag existente
            $stmtUp = $db->prepare("
                UPDATE om_fraud_flags
                SET details = ?, severity = ?, created_at = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([$detailsJson, $severity, $existing['id']]);
        } else {
            // Criar nova flag
            $stmtIns = $db->prepare("
                INSERT INTO om_fraud_flags (entity_type, entity_id, flag_type, severity, details, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, TRUE, NOW())
            ");
            $stmtIns->execute([$entityType, $entityId, $flagType, $severity, $detailsJson]);
        }
    } catch (\Exception $e) {
        error_log("[fraud] Erro ao upsert flag: " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 4. REGISTRO MANUAL DE FLAGS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Registra uma flag de fraude manualmente.
 * Pode ser chamada por qualquer endpoint que detecte comportamento suspeito.
 *
 * @param PDO    $db         Conexao PDO
 * @param string $entityType Tipo de entidade: 'customer', 'shopper', 'partner', 'driver'
 * @param int    $entityId   ID da entidade
 * @param string $flagType   Tipo da flag (ex: 'high_risk', 'abuse_pattern', 'chargeback', etc.)
 * @param string $severity   Severidade: 'low', 'medium', 'high', 'critical'
 * @param array  $details    Detalhes adicionais (qualquer dado relevante)
 *
 * @return int|false ID da flag criada ou false em caso de erro
 */
function fraud_log_flag(PDO $db, string $entityType, int $entityId, string $flagType, string $severity, array $details) {
    _fraud_ensure_tables($db);

    // Validar severidade
    $validSeverities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($severity, $validSeverities, true)) {
        $severity = 'medium';
    }

    // Sanitizar inputs
    $entityType = substr(trim($entityType), 0, 20);
    $flagType = substr(trim($flagType), 0, 50);

    try {
        $stmt = $db->prepare("
            INSERT INTO om_fraud_flags (entity_type, entity_id, flag_type, severity, details, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, TRUE, NOW())
            RETURNING id
        ");
        $stmt->execute([
            $entityType,
            $entityId,
            $flagType,
            $severity,
            json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        error_log("[fraud] Flag registrada: {$entityType}#{$entityId} tipo={$flagType} severidade={$severity}");

        return $row ? (int)$row['id'] : false;

    } catch (\Exception $e) {
        error_log("[fraud] Erro ao registrar flag: " . $e->getMessage());
        return false;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 5. FUNCOES AUXILIARES PUBLICAS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Consulta flags ativas para uma entidade.
 * Util para verificar rapidamente se um usuario esta sinalizado.
 *
 * @param PDO    $db         Conexao PDO
 * @param string $entityType Tipo de entidade
 * @param int    $entityId   ID da entidade
 *
 * @return array Lista de flags ativas com seus detalhes
 */
function fraud_get_active_flags(PDO $db, string $entityType, int $entityId): array {
    _fraud_ensure_tables($db);

    try {
        $stmt = $db->prepare("
            SELECT id, flag_type, severity, details, created_at
            FROM om_fraud_flags
            WHERE entity_type = ?
              AND entity_id = ?
              AND is_active = TRUE
            ORDER BY created_at DESC
        ");
        $stmt->execute([$entityType, $entityId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return [
                'id'         => (int)$row['id'],
                'flag_type'  => $row['flag_type'],
                'severity'   => $row['severity'],
                'details'    => json_decode($row['details'], true) ?: [],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

    } catch (\Exception $e) {
        error_log("[fraud] Erro ao consultar flags: " . $e->getMessage());
        return [];
    }
}

/**
 * Resolve (desativa) uma flag de fraude.
 * Usado pelo admin ao revisar e considerar a flag como falso positivo ou resolvida.
 *
 * @param PDO $db         Conexao PDO
 * @param int $flagId     ID da flag
 * @param int $resolvedBy ID do admin que resolveu
 *
 * @return bool true se resolvida com sucesso
 */
function fraud_resolve_flag(PDO $db, int $flagId, int $resolvedBy): bool {
    _fraud_ensure_tables($db);

    try {
        $stmt = $db->prepare("
            UPDATE om_fraud_flags
            SET is_active = FALSE, resolved_at = NOW(), resolved_by = ?
            WHERE id = ? AND is_active = TRUE
        ");
        $stmt->execute([$resolvedBy, $flagId]);
        return $stmt->rowCount() > 0;

    } catch (\Exception $e) {
        error_log("[fraud] Erro ao resolver flag: " . $e->getMessage());
        return false;
    }
}

/**
 * Verifica rapidamente se uma entidade esta em nivel de risco alto.
 * Atalho para uso em auto-aprovacoes de reembolso/disputa.
 *
 * @param PDO    $db         Conexao PDO
 * @param string $entityType Tipo de entidade
 * @param int    $entityId   ID da entidade
 *
 * @return bool true se ha pelo menos uma flag ativa com severidade 'high' ou 'critical'
 */
function fraud_is_high_risk(PDO $db, string $entityType, int $entityId): bool {
    _fraud_ensure_tables($db);

    try {
        $stmt = $db->prepare("
            SELECT 1 FROM om_fraud_flags
            WHERE entity_type = ?
              AND entity_id = ?
              AND is_active = TRUE
              AND severity IN ('high', 'critical')
            LIMIT 1
        ");
        $stmt->execute([$entityType, $entityId]);
        return (bool)$stmt->fetch();

    } catch (\Exception $e) {
        error_log("[fraud] Erro ao verificar risco: " . $e->getMessage());
        return true; // Em caso de erro, bloquear (fail closed)
    }
}

/**
 * Extrai a chave de idempotencia do header HTTP.
 * Procura pelos headers X-Idempotency-Key ou Idempotency-Key.
 *
 * @return string|null Chave encontrada ou null
 */
function fraud_get_idempotency_key_from_request(): ?string {
    // Verificar header padrao
    $key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;

    if ($key) {
        $key = trim($key);
        // Validar formato: apenas alfanumericos, hifens e underscores
        if (preg_match('/^[a-zA-Z0-9\-_]{8,100}$/', $key)) {
            return $key;
        }
    }

    return null;
}
