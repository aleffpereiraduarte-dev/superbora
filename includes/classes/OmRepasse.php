<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * OmRepasse - Classe de Gerenciamento de Repasses
 * Sistema de Hold de 2 Horas
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Gerencia o fluxo de repasses:
 * - Criar repasse com hold
 * - Liberar repasse apos hold
 * - Cancelar repasse
 * - Estornar repasse
 * - Consultar status
 *
 * Exemplo de uso:
 *   om_repasse()->setDb($db);
 *   $resultado = om_repasse()->criar($orderId, 'shopper', $shopperId, $valor);
 */

class OmRepasse {
    private static $instance = null;
    private $db;

    // Configuracoes padrao
    private $holdHoras = 2;
    private $notificarLiberacao = true;
    private $notificarCancelamento = true;

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setDb(PDO $db): self {
        $this->db = $db;
        $this->loadConfig();
        return $this;
    }

    /**
     * Carrega configuracoes do banco
     */
    private function loadConfig(): void {
        if (!$this->db) return;

        try {
            $stmt = $this->db->query("SELECT chave, valor FROM om_repasses_config");
            $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $this->holdHoras = (int)($configs['hold_horas'] ?? 2);
            $this->notificarLiberacao = (bool)($configs['notificar_liberacao'] ?? true);
            $this->notificarCancelamento = (bool)($configs['notificar_cancelamento'] ?? true);
        } catch (Exception $e) {
            // Usar valores padrao
        }
    }

    /**
     * Cria um repasse com hold
     *
     * @param int $orderId ID do pedido
     * @param string $tipo 'shopper', 'motorista' ou 'mercado'
     * @param int $destinatarioId ID do destinatario
     * @param float $valor Valor do repasse
     * @param array $calculo Detalhes do calculo (opcional)
     * @param string $orderType Tipo do pedido (default: 'mercado')
     * @return array Resultado da operacao
     */
    public function criar(
        int $orderId,
        string $tipo,
        int $destinatarioId,
        float $valor,
        array $calculo = [],
        string $orderType = 'mercado'
    ): array {
        if (!$this->db) {
            return ['success' => false, 'error' => 'Database not configured'];
        }

        if (!in_array($tipo, ['shopper', 'motorista', 'mercado'])) {
            return ['success' => false, 'error' => 'Tipo invalido'];
        }

        if ($valor <= 0) {
            return ['success' => false, 'error' => 'Valor deve ser positivo'];
        }

        try {
            $holdUntil = date('Y-m-d H:i:s', strtotime("+{$this->holdHoras} hours"));

            // Use savepoints when already inside an outer transaction (e.g. webhook)
            $outerTransaction = $this->db->inTransaction();
            if ($outerTransaction) {
                $this->db->exec("SAVEPOINT repasse_criar");
            } else {
                $this->db->beginTransaction();
            }

            // 1. Criar ou atualizar registro de repasse
            // Check if repasse already exists
            $stmtCheck = $this->db->prepare("SELECT id FROM om_repasses WHERE order_id = ? AND order_type = ? AND tipo = ? AND destinatario_id = ?");
            $stmtCheck->execute([$orderId, $orderType, $tipo, $destinatarioId]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                // Fetch old valor_liquido to calculate delta
                $stmtOld = $this->db->prepare("SELECT valor_liquido FROM om_repasses WHERE id = ? FOR UPDATE");
                $stmtOld->execute([$existingId]);
                $oldValor = floatval($stmtOld->fetchColumn());

                // Update existing
                $this->db->prepare("
                    UPDATE om_repasses SET valor_bruto = ?, valor_liquido = ?, calculo_json = ?, status = 'hold', hold_until = ?
                    WHERE id = ?
                ")->execute([$valor, $valor, json_encode($calculo, JSON_UNESCAPED_UNICODE), $holdUntil, $existingId]);
                $repasseId = (int)$existingId;

                // Only adjust saldo_pendente by the delta (new - old)
                $delta = $valor - $oldValor;
                if (abs($delta) > 0.001) {
                    if ($delta > 0) {
                        $this->adicionarSaldoPendente($tipo, $destinatarioId, $delta);
                    } else {
                        $this->removerSaldoPendente($tipo, $destinatarioId, abs($delta));
                    }
                }
            } else {
                // Insert new
                $stmt = $this->db->prepare("
                    INSERT INTO om_repasses
                    (order_id, order_type, tipo, destinatario_id, valor_bruto, valor_liquido, calculo_json, status, hold_until, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'hold', ?, NOW())
                    RETURNING id
                ");
                $stmt->execute([
                    $orderId,
                    $orderType,
                    $tipo,
                    $destinatarioId,
                    $valor,
                    $valor,
                    json_encode($calculo, JSON_UNESCAPED_UNICODE),
                    $holdUntil
                ]);
                $repasseId = (int)$stmt->fetchColumn();

                // 2. Adicionar ao saldo_pendente (only for new repasses)
                $this->adicionarSaldoPendente($tipo, $destinatarioId, $valor);
            }

            if ($outerTransaction) {
                $this->db->exec("RELEASE SAVEPOINT repasse_criar");
            } else {
                $this->db->commit();
            }

            return [
                'success' => true,
                'repasse_id' => $repasseId,
                'valor' => $valor,
                'hold_until' => $holdUntil,
                'hold_horas' => $this->holdHoras
            ];

        } catch (Exception $e) {
            if ($outerTransaction) {
                try { $this->db->exec("ROLLBACK TO SAVEPOINT repasse_criar"); } catch (Exception $ignore) {}
            } else {
                if ($this->db->inTransaction()) $this->db->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Libera um repasse do hold
     *
     * @param int $repasseId ID do repasse
     * @return array Resultado da operacao
     */
    public function liberar(int $repasseId): array {
        if (!$this->db) {
            return ['success' => false, 'error' => 'Database not configured'];
        }

        try {
            $this->db->beginTransaction();

            // Buscar repasse (FOR UPDATE requires active transaction)
            $stmt = $this->db->prepare("
                SELECT * FROM om_repasses
                WHERE id = ? AND status = 'hold'
                FOR UPDATE
            ");
            $stmt->execute([$repasseId]);
            $repasse = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$repasse) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Repasse nao encontrado ou ja processado'];
            }

            $tipo = $repasse['tipo'];
            $destinatarioId = $repasse['destinatario_id'];
            $valor = floatval($repasse['valor_liquido']);

            // 1. Mover de pendente para disponivel
            $saldoPosterior = $this->moverParaDisponivel($tipo, $destinatarioId, $valor);

            // 2. Registrar na wallet
            $this->registrarWallet($tipo, $destinatarioId, $valor, $saldoPosterior - $valor, $saldoPosterior, $repasseId, $repasse['order_id']);

            // 3. Atualizar status do repasse
            $stmt = $this->db->prepare("
                UPDATE om_repasses SET
                    status = 'liberado',
                    liberado_em = NOW(),
                    pago_em = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$repasseId]);

            // 4. Log
            $this->registrarLog($repasseId, 'hold', 'liberado', 'sistema', null, "Liberado automaticamente");

            $this->db->commit();

            return [
                'success' => true,
                'repasse_id' => $repasseId,
                'valor' => $valor,
                'saldo_disponivel' => $saldoPosterior,
                'liberado_em' => date('c')
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cancela um repasse em hold
     *
     * @param int $repasseId ID do repasse
     * @param string $motivo Motivo do cancelamento
     * @param string $canceladoPorTipo 'admin', 'sistema' ou 'cliente'
     * @param int|null $canceladoPorId ID de quem cancelou
     * @return array Resultado da operacao
     */
    public function cancelar(int $repasseId, string $motivo, string $canceladoPorTipo = 'sistema', ?int $canceladoPorId = null): array {
        if (!$this->db) {
            return ['success' => false, 'error' => 'Database not configured'];
        }

        try {
            $this->db->beginTransaction();

            // Buscar repasse (FOR UPDATE requires active transaction)
            $stmt = $this->db->prepare("
                SELECT * FROM om_repasses
                WHERE id = ? AND status IN ('hold', 'pendente')
                FOR UPDATE
            ");
            $stmt->execute([$repasseId]);
            $repasse = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$repasse) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Repasse nao encontrado ou nao pode ser cancelado'];
            }

            $tipo = $repasse['tipo'];
            $destinatarioId = $repasse['destinatario_id'];
            $valor = floatval($repasse['valor_liquido']);

            // 1. Remover do saldo_pendente
            $this->removerSaldoPendente($tipo, $destinatarioId, $valor);

            // 2. Atualizar status do repasse
            $stmt = $this->db->prepare("
                UPDATE om_repasses SET
                    status = 'cancelado',
                    motivo_cancelamento = ?,
                    cancelado_por_tipo = ?,
                    cancelado_por_id = ?,
                    cancelado_em = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$motivo, $canceladoPorTipo, $canceladoPorId, $repasseId]);

            // 3. Log
            $this->registrarLog($repasseId, $repasse['status'], 'cancelado', $canceladoPorTipo, $canceladoPorId, $motivo);

            $this->db->commit();

            return [
                'success' => true,
                'repasse_id' => $repasseId,
                'valor' => $valor,
                'motivo' => $motivo,
                'cancelado_em' => date('c')
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Estorna um repasse ja liberado
     */
    public function estornar(int $repasseId, string $motivo, int $adminId): array {
        if (!$this->db) {
            return ['success' => false, 'error' => 'Database not configured'];
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT * FROM om_repasses
                WHERE id = ? AND status IN ('liberado', 'pago')
                FOR UPDATE
            ");
            $stmt->execute([$repasseId]);
            $repasse = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$repasse) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Repasse nao encontrado ou nao pode ser estornado'];
            }

            $tipo = $repasse['tipo'];
            $destinatarioId = $repasse['destinatario_id'];
            $valor = floatval($repasse['valor_liquido']);

            // 1. Remover do saldo_disponivel
            $saldoPosterior = $this->removerDoDisponivel($tipo, $destinatarioId, $valor);

            // 2. Registrar estorno na wallet
            $this->registrarWalletEstorno($tipo, $destinatarioId, $valor, $saldoPosterior + $valor, $saldoPosterior, $repasseId, $repasse['order_id'], $motivo);

            // 3. Atualizar status
            $stmt = $this->db->prepare("
                UPDATE om_repasses SET
                    status = 'estornado',
                    motivo_cancelamento = ?,
                    cancelado_por_tipo = 'admin',
                    cancelado_por_id = ?,
                    estornado_em = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$motivo, $adminId, $repasseId]);

            // 4. Log
            $this->registrarLog($repasseId, $repasse['status'], 'estornado', 'admin', $adminId, "ESTORNO: $motivo");

            $this->db->commit();

            return [
                'success' => true,
                'repasse_id' => $repasseId,
                'valor' => $valor,
                'saldo_posterior' => $saldoPosterior,
                'motivo' => $motivo,
                'estornado_em' => date('c')
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Busca repasses pendentes de um destinatario
     */
    public function buscarPendentes(string $tipo, int $destinatarioId): array {
        if (!$this->db) return [];

        $stmt = $this->db->prepare("
            SELECT r.*, o.status as order_status
            FROM om_repasses r
            LEFT JOIN om_market_orders o ON o.order_id = r.order_id
            WHERE r.tipo = ?
            AND r.destinatario_id = ?
            AND r.status IN ('hold', 'pendente')
            ORDER BY r.hold_until ASC
        ");
        $stmt->execute([$tipo, $destinatarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca repasses prontos para liberar
     */
    public function buscarProntosParaLiberar(int $limit = 100): array {
        if (!$this->db) return [];

        $stmt = $this->db->prepare("
            SELECT * FROM om_repasses
            WHERE status = 'hold'
            AND hold_until <= NOW()
            ORDER BY hold_until ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // METODOS PRIVADOS
    // ═══════════════════════════════════════════════════════════════════════════

    private function getRepasseId(int $orderId, string $orderType, string $tipo, int $destinatarioId): int {
        $stmt = $this->db->prepare("
            SELECT id FROM om_repasses
            WHERE order_id = ? AND order_type = ? AND tipo = ? AND destinatario_id = ?
        ");
        $stmt->execute([$orderId, $orderType, $tipo, $destinatarioId]);
        return (int)$stmt->fetchColumn();
    }

    private function adicionarSaldoPendente(string $tipo, int $id, float $valor): void {
        switch ($tipo) {
            case 'shopper':
                $stmt = $this->db->prepare("
                    INSERT INTO om_shopper_saldo (shopper_id, saldo_pendente, total_ganhos)
                    VALUES (?, ?, ?)
                    ON CONFLICT (shopper_id) DO UPDATE SET
                        saldo_pendente = om_shopper_saldo.saldo_pendente + EXCLUDED.saldo_pendente,
                        total_ganhos = om_shopper_saldo.total_ganhos + EXCLUDED.total_ganhos
                ");
                $stmt->execute([$id, $valor, $valor]);
                break;

            case 'motorista':
                $stmt = $this->db->prepare("
                    INSERT INTO om_motorista_saldo (motorista_id, saldo_pendente, total_ganhos)
                    VALUES (?, ?, ?)
                    ON CONFLICT (motorista_id) DO UPDATE SET
                        saldo_pendente = om_motorista_saldo.saldo_pendente + EXCLUDED.saldo_pendente,
                        total_ganhos = om_motorista_saldo.total_ganhos + EXCLUDED.total_ganhos
                ");
                $stmt->execute([$id, $valor, $valor]);
                break;

            case 'mercado':
                $stmt = $this->db->prepare("
                    INSERT INTO om_mercado_saldo (partner_id, saldo_pendente, total_recebido)
                    VALUES (?, ?, ?)
                    ON CONFLICT (partner_id) DO UPDATE SET
                        saldo_pendente = om_mercado_saldo.saldo_pendente + EXCLUDED.saldo_pendente,
                        total_recebido = om_mercado_saldo.total_recebido + EXCLUDED.total_recebido
                ");
                $stmt->execute([$id, $valor, $valor]);
                break;
        }
    }

    private function removerSaldoPendente(string $tipo, int $id, float $valor): void {
        switch ($tipo) {
            case 'shopper':
                $stmt = $this->db->prepare("
                    UPDATE om_shopper_saldo SET
                        saldo_pendente = GREATEST(0, saldo_pendente - ?),
                        total_ganhos = GREATEST(0, total_ganhos - ?)
                    WHERE shopper_id = ?
                ");
                $stmt->execute([$valor, $valor, $id]);
                break;

            case 'motorista':
                $stmt = $this->db->prepare("
                    UPDATE om_motorista_saldo SET
                        saldo_pendente = GREATEST(0, saldo_pendente - ?),
                        total_ganhos = GREATEST(0, total_ganhos - ?)
                    WHERE motorista_id = ?
                ");
                $stmt->execute([$valor, $valor, $id]);
                break;

            case 'mercado':
                $stmt = $this->db->prepare("
                    UPDATE om_mercado_saldo SET
                        saldo_pendente = GREATEST(0, saldo_pendente - ?),
                        total_recebido = GREATEST(0, total_recebido - ?)
                    WHERE partner_id = ?
                ");
                $stmt->execute([$valor, $valor, $id]);
                break;
        }
    }

    private function moverParaDisponivel(string $tipo, int $id, float $valor): float {
        switch ($tipo) {
            case 'shopper':
                $stmt = $this->db->prepare("
                    UPDATE om_shopper_saldo SET
                        saldo_disponivel = saldo_disponivel + ?,
                        saldo_pendente = GREATEST(0, saldo_pendente - ?)
                    WHERE shopper_id = ?
                ");
                $stmt->execute([$valor, $valor, $id]);

                $stmt = $this->db->prepare("SELECT saldo_disponivel FROM om_shopper_saldo WHERE shopper_id = ?");
                $stmt->execute([$id]);
                return floatval($stmt->fetchColumn());

            case 'motorista':
                $stmt = $this->db->prepare("
                    UPDATE om_motorista_saldo SET
                        saldo_disponivel = saldo_disponivel + ?,
                        saldo_pendente = GREATEST(0, saldo_pendente - ?)
                    WHERE motorista_id = ?
                ");
                $stmt->execute([$valor, $valor, $id]);

                $stmt = $this->db->prepare("SELECT saldo_disponivel FROM om_motorista_saldo WHERE motorista_id = ?");
                $stmt->execute([$id]);
                return floatval($stmt->fetchColumn());

            case 'mercado':
                // Verificar divida pendente (comissoes de pedidos cash)
                $stmtDiv = $this->db->prepare("SELECT COALESCE(saldo_devedor, 0) FROM om_mercado_saldo WHERE partner_id = ?");
                $stmtDiv->execute([$id]);
                $divida = (float)$stmtDiv->fetchColumn();

                if ($divida > 0) {
                    // Descontar divida antes de creditar
                    $desconto = min($divida, $valor);
                    $valorFinal = round($valor - $desconto, 2);

                    $stmt = $this->db->prepare("
                        UPDATE om_mercado_saldo SET
                            saldo_disponivel = saldo_disponivel + ?,
                            saldo_pendente = GREATEST(0, saldo_pendente - ?),
                            saldo_devedor = GREATEST(0, saldo_devedor - ?)
                        WHERE partner_id = ?
                    ");
                    $stmt->execute([$valorFinal, $valor, $desconto, $id]);

                    // Log do desconto
                    try {
                        $this->db->prepare("
                            INSERT INTO om_mercado_wallet (partner_id, tipo, valor, descricao, status, created_at)
                            VALUES (?, 'ajuste', ?, ?, 'concluido', NOW())
                        ")->execute([$id, -$desconto, "Desconto divida comissao cash (R$" . number_format($desconto, 2) . ")"]);
                    } catch (Exception $e) {
                        error_log("[OmRepasse] Erro log desconto divida: " . $e->getMessage());
                    }

                    error_log("[OmRepasse] Parceiro #$id: divida R$" . number_format($divida, 2) . " → descontado R$" . number_format($desconto, 2) . " do repasse R$" . number_format($valor, 2));
                } else {
                    $stmt = $this->db->prepare("
                        UPDATE om_mercado_saldo SET
                            saldo_disponivel = saldo_disponivel + ?,
                            saldo_pendente = GREATEST(0, saldo_pendente - ?)
                        WHERE partner_id = ?
                    ");
                    $stmt->execute([$valor, $valor, $id]);
                }

                // Auto-desbloqueio: checar se saldo melhorou acima do limite
                try {
                    $stmtWallet = $this->db->prepare("
                        SELECT saldo_disponivel, saldo_devedor, limite_negativo, cash_bloqueado
                        FROM om_mercado_saldo WHERE partner_id = ?
                    ");
                    $stmtWallet->execute([$id]);
                    $walletRow = $stmtWallet->fetch(PDO::FETCH_ASSOC);

                    if ($walletRow && $walletRow['cash_bloqueado']) {
                        $saldoAtual = (float)$walletRow['saldo_disponivel'] - (float)$walletRow['saldo_devedor'];
                        $limiteNeg = (float)($walletRow['limite_negativo'] ?? -200);

                        if ($saldoAtual > $limiteNeg) {
                            $this->db->prepare("UPDATE om_mercado_saldo SET cash_bloqueado = false WHERE partner_id = ?")
                                ->execute([$id]);
                            error_log("[OmRepasse] Parceiro #$id: cash desbloqueado (saldo R$" . number_format($saldoAtual, 2) . " > limite R$" . number_format($limiteNeg, 2) . ")");
                        }
                    }
                } catch (Exception $unlockErr) {
                    error_log("[OmRepasse] Erro auto-desbloqueio: " . $unlockErr->getMessage());
                }

                $stmt = $this->db->prepare("SELECT saldo_disponivel FROM om_mercado_saldo WHERE partner_id = ?");
                $stmt->execute([$id]);
                return floatval($stmt->fetchColumn());
        }
        return 0;
    }

    private function removerDoDisponivel(string $tipo, int $id, float $valor): float {
        switch ($tipo) {
            case 'shopper':
                $stmt = $this->db->prepare("
                    UPDATE om_shopper_saldo SET
                        saldo_disponivel = saldo_disponivel - ?
                    WHERE shopper_id = ?
                ");
                $stmt->execute([$valor, $id]);

                $stmt = $this->db->prepare("SELECT saldo_disponivel FROM om_shopper_saldo WHERE shopper_id = ?");
                $stmt->execute([$id]);
                return floatval($stmt->fetchColumn());

            case 'motorista':
                $stmt = $this->db->prepare("
                    UPDATE om_motorista_saldo SET
                        saldo_disponivel = saldo_disponivel - ?
                    WHERE motorista_id = ?
                ");
                $stmt->execute([$valor, $id]);

                $stmt = $this->db->prepare("SELECT saldo_disponivel FROM om_motorista_saldo WHERE motorista_id = ?");
                $stmt->execute([$id]);
                return floatval($stmt->fetchColumn());

            case 'mercado':
                $stmt = $this->db->prepare("
                    UPDATE om_mercado_saldo SET
                        saldo_disponivel = saldo_disponivel - ?
                    WHERE partner_id = ?
                ");
                $stmt->execute([$valor, $id]);

                $stmt = $this->db->prepare("SELECT saldo_disponivel FROM om_mercado_saldo WHERE partner_id = ?");
                $stmt->execute([$id]);
                return floatval($stmt->fetchColumn());
        }
        return 0;
    }

    private function registrarWallet(string $tipo, int $id, float $valor, float $saldoAnterior, float $saldoPosterior, int $repasseId, int $orderId): void {
        switch ($tipo) {
            case 'shopper':
                $stmt = $this->db->prepare("
                    INSERT INTO om_shopper_wallet
                    (shopper_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                    VALUES (?, 'ganho', ?, ?, ?, 'om_repasses', ?, ?, 'concluido', NOW())
                ");
                $stmt->execute([$id, $valor, $saldoAnterior, $saldoPosterior, $repasseId, "Liberacao Pedido #$orderId"]);
                break;

            case 'motorista':
                $stmt = $this->db->prepare("
                    INSERT INTO om_motorista_wallet
                    (motorista_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                    VALUES (?, 'ganho', ?, ?, ?, 'om_repasses', ?, ?, 'concluido', NOW())
                ");
                $stmt->execute([$id, $valor, $saldoAnterior, $saldoPosterior, $repasseId, "Liberacao Entrega #$orderId"]);
                break;

            case 'mercado':
                $stmt = $this->db->prepare("
                    INSERT INTO om_mercado_wallet
                    (partner_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                    VALUES (?, 'repasse', ?, ?, ?, 'om_repasses', ?, ?, 'concluido', NOW())
                ");
                $stmt->execute([$id, $valor, $saldoAnterior, $saldoPosterior, $repasseId, "Repasse Pedido #$orderId"]);
                break;
        }
    }

    private function registrarWalletEstorno(string $tipo, int $id, float $valor, float $saldoAnterior, float $saldoPosterior, int $repasseId, int $orderId, string $motivo): void {
        switch ($tipo) {
            case 'shopper':
                $stmt = $this->db->prepare("
                    INSERT INTO om_shopper_wallet
                    (shopper_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                    VALUES (?, 'estorno', ?, ?, ?, 'om_repasses', ?, ?, 'concluido', NOW())
                ");
                $stmt->execute([$id, -$valor, $saldoAnterior, $saldoPosterior, $repasseId, "ESTORNO Pedido #$orderId: $motivo"]);
                break;

            case 'motorista':
                $stmt = $this->db->prepare("
                    INSERT INTO om_motorista_wallet
                    (motorista_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                    VALUES (?, 'estorno', ?, ?, ?, 'om_repasses', ?, ?, 'concluido', NOW())
                ");
                $stmt->execute([$id, -$valor, $saldoAnterior, $saldoPosterior, $repasseId, "ESTORNO Entrega #$orderId: $motivo"]);
                break;

            case 'mercado':
                $stmt = $this->db->prepare("
                    INSERT INTO om_mercado_wallet
                    (partner_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
                    VALUES (?, 'estorno', ?, ?, ?, 'om_repasses', ?, ?, 'concluido', NOW())
                ");
                $stmt->execute([$id, -$valor, $saldoAnterior, $saldoPosterior, $repasseId, "ESTORNO Pedido #$orderId: $motivo"]);
                break;
        }
    }

    private function registrarLog(int $repasseId, string $statusAnterior, string $statusNovo, string $executadoPorTipo, ?int $executadoPorId, string $observacao): void {
        $stmt = $this->db->prepare("
            INSERT INTO om_repasses_log
            (repasse_id, status_anterior, status_novo, executado_por_tipo, executado_por_id, observacao, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$repasseId, $statusAnterior, $statusNovo, $executadoPorTipo, $executadoPorId, $observacao]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GETTERS
    // ═══════════════════════════════════════════════════════════════════════════

    public function getHoldHoras(): int {
        return $this->holdHoras;
    }

    public function isNotificarLiberacao(): bool {
        return $this->notificarLiberacao;
    }

    public function isNotificarCancelamento(): bool {
        return $this->notificarCancelamento;
    }
}

/**
 * Helper function
 */
function om_repasse(): OmRepasse {
    return OmRepasse::getInstance();
}
