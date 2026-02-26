<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * OmDailyBudget - Controle P&L Diario em Tempo Real
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Rastreia lucro/prejuizo do dia em tempo real.
 * Controla subsidios (frete gratis, descontos) baseado na saude financeira.
 *
 * Modos:
 *   agressivo   → lucro > 2x meta  → libera subsidios e beneficios
 *   normal      → lucro > meta     → subsidios moderados
 *   conservador → lucro > 0        → subsidios minimos
 *   protecao    → lucro <= 0       → bloqueia subsidios
 *
 * Uso:
 *   require_once __DIR__ . '/OmDailyBudget.php';
 *   $budget = OmDailyBudget::getInstance()->setDb($db);
 *   if ($budget->podeSubsidiar(11.00)) { ... }
 */

require_once __DIR__ . '/OmPricing.php';

class OmDailyBudget {
    private static $instance = null;
    private $db;

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setDb(PDO $db): self {
        $this->db = $db;
        return $this;
    }

    /**
     * Registra dados financeiros de um pedido no P&L diario
     * Chamado APOS cada pedido ser processado com sucesso
     */
    public function registrarPedido(array $breakdown): void {
        if (!$this->db) return;

        try {
            $gmv = floatval($breakdown['subtotal'] ?? 0);
            $receitaComissao = floatval($breakdown['comissao_valor'] ?? 0);
            $receitaServico = floatval($breakdown['service_fee'] ?? 0);
            $receitaFreteMargem = floatval($breakdown['margem_frete'] ?? 0);
            $receitaExpress = floatval($breakdown['express_fee'] ?? 0);
            $custoBoraum = floatval($breakdown['custo_boraum'] ?? 0);
            $custoSubsidios = floatval($breakdown['subsidio'] ?? 0);
            $custoCashback = floatval($breakdown['cashback_valor'] ?? 0);

            $lucro = round(
                $receitaComissao + $receitaServico + max(0, $receitaFreteMargem) + $receitaExpress
                - $custoSubsidios - $custoCashback,
                2
            );

            $subsidioLiberado = ($custoSubsidios > 0) ? 1 : 0;

            $this->db->prepare("
                INSERT INTO om_daily_pnl (data, total_pedidos, total_gmv, receita_comissao, receita_servico, receita_frete_margem, receita_express, custo_boraum, custo_subsidios, custo_cashback, lucro_acumulado, subsidios_liberados, updated_at)
                VALUES (CURRENT_DATE, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON CONFLICT (data) DO UPDATE SET
                    total_pedidos = om_daily_pnl.total_pedidos + 1,
                    total_gmv = om_daily_pnl.total_gmv + EXCLUDED.total_gmv,
                    receita_comissao = om_daily_pnl.receita_comissao + EXCLUDED.receita_comissao,
                    receita_servico = om_daily_pnl.receita_servico + EXCLUDED.receita_servico,
                    receita_frete_margem = om_daily_pnl.receita_frete_margem + EXCLUDED.receita_frete_margem,
                    receita_express = om_daily_pnl.receita_express + EXCLUDED.receita_express,
                    custo_boraum = om_daily_pnl.custo_boraum + EXCLUDED.custo_boraum,
                    custo_subsidios = om_daily_pnl.custo_subsidios + EXCLUDED.custo_subsidios,
                    custo_cashback = om_daily_pnl.custo_cashback + EXCLUDED.custo_cashback,
                    lucro_acumulado = om_daily_pnl.lucro_acumulado + EXCLUDED.lucro_acumulado,
                    subsidios_liberados = om_daily_pnl.subsidios_liberados + EXCLUDED.subsidios_liberados,
                    updated_at = NOW()
            ")->execute([
                $gmv, $receitaComissao, $receitaServico, $receitaFreteMargem, $receitaExpress,
                $custoBoraum, $custoSubsidios, $custoCashback, $lucro, $subsidioLiberado
            ]);

        } catch (Exception $e) {
            error_log("[OmDailyBudget] Erro registrarPedido: " . $e->getMessage());
        }
    }

    /**
     * Registra dados financeiros da entrega (comissao no confirmar-entrega)
     */
    public function registrarEntrega(array $dados): void {
        if (!$this->db) return;

        try {
            $comissao = floatval($dados['comissao'] ?? 0);
            $custoBoraum = floatval($dados['custo_boraum'] ?? 0);

            // Se custoBoraum > 0, ja foi contabilizado no checkout,
            // mas a comissao real so se confirma na entrega.
            // Aqui apenas atualizamos se houver divergencia.
            // Por ora, nao duplicar — o registrarPedido ja contabiliza tudo.

        } catch (Exception $e) {
            error_log("[OmDailyBudget] Erro registrarEntrega: " . $e->getMessage());
        }
    }

    /**
     * Registra subsidio negado no P&L
     */
    public function registrarSubsidioNegado(): void {
        if (!$this->db) return;

        try {
            $this->db->prepare("
                INSERT INTO om_daily_pnl (data, subsidios_negados, updated_at)
                VALUES (CURRENT_DATE, 1, NOW())
                ON CONFLICT (data) DO UPDATE SET
                    subsidios_negados = om_daily_pnl.subsidios_negados + 1,
                    updated_at = NOW()
            ")->execute();
        } catch (Exception $e) {
            error_log("[OmDailyBudget] Erro registrarSubsidioNegado: " . $e->getMessage());
        }
    }

    /**
     * Decide se pode dar subsidio (frete gratis, desconto, etc.) AGORA
     *
     * Logica:
     * 1. Se primeiro pedido do dia → permite (precisa comecar)
     * 2. Calcula reserva necessaria para o resto do dia
     * 3. Verifica se lucro disponivel cobre o subsidio
     * 4. Em horario de pico, e mais agressivo (aceita 50% cobertura)
     *
     * @param float $custoSubsidio Quanto o subsidio custaria
     * @return bool
     */
    public function podeSubsidiar(float $custoSubsidio): bool {
        if (!$this->db) return false;

        try {
            $pnl = $this->getPnlHoje();

            if (!$pnl) {
                return true; // Primeiro pedido do dia
            }

            $lucroAtual = (float)($pnl['lucro_acumulado'] ?? 0);

            // Reserva: precisa ter lucro suficiente para o resto do dia
            $reserva = OmPricing::META_LUCRO_DIARIO_MIN * OmPricing::RESERVA_FINAL_DIA_PCT;
            $lucroDisponivel = $lucroAtual - $reserva;

            if ($lucroDisponivel <= 0) {
                $this->registrarSubsidioNegado();
                return false;
            }

            // Pode subsidiar se lucro disponivel cobre o custo
            if ($lucroDisponivel >= $custoSubsidio) {
                return true;
            }

            // Horario de pico (11-14h, 18-21h): mais agressivo
            $horaAtual = (int)date('H');
            $isPico = ($horaAtual >= 11 && $horaAtual <= 14) || ($horaAtual >= 18 && $horaAtual <= 21);

            if ($isPico && $lucroDisponivel >= $custoSubsidio * 0.5) {
                return true;
            }

            $this->registrarSubsidioNegado();
            return false;

        } catch (Exception $e) {
            error_log("[OmDailyBudget] Erro podeSubsidiar: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retorna o modo atual do dia
     *
     * @return string 'agressivo', 'normal', 'conservador' ou 'protecao'
     */
    public function getModo(): string {
        if (!$this->db) return 'conservador';

        try {
            $pnl = $this->getPnlHoje();

            if (!$pnl) return 'normal'; // Dia sem dados ainda

            $lucro = (float)($pnl['lucro_acumulado'] ?? 0);
            $meta = OmPricing::META_LUCRO_DIARIO_MIN;

            if ($lucro > $meta * 2) return 'agressivo';
            if ($lucro > $meta) return 'normal';
            if ($lucro > 0) return 'conservador';
            return 'protecao';

        } catch (Exception $e) {
            return 'conservador';
        }
    }

    /**
     * Retorna P&L de hoje
     */
    public function getPnlHoje(): ?array {
        if (!$this->db) return null;

        try {
            $stmt = $this->db->prepare("SELECT * FROM om_daily_pnl WHERE data = CURRENT_DATE LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log("[OmDailyBudget] Erro getPnlHoje: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Atualiza coluna modo no P&L
     */
    public function atualizarModo(): void {
        if (!$this->db) return;

        try {
            $modo = $this->getModo();
            $this->db->prepare("
                INSERT INTO om_daily_pnl (data, modo, updated_at)
                VALUES (CURRENT_DATE, ?, NOW())
                ON CONFLICT (data) DO UPDATE SET modo = EXCLUDED.modo, updated_at = NOW()
            ")->execute([$modo]);
        } catch (Exception $e) {
            error_log("[OmDailyBudget] Erro atualizarModo: " . $e->getMessage());
        }
    }
}
