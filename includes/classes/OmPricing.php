<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * OmPricing - Motor de Precificacao Inteligente
 * Fonte unica de verdade para todos os calculos financeiros
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Centraliza TODAS as constantes e calculos de:
 * - Comissao (BoraUm vs entrega propria)
 * - Custo BoraUm por distancia
 * - Frete com subsidio inteligente
 * - Pedido minimo por distancia
 * - Wallet com credito progressivo
 * - SuperBora+ beneficios fixos e variaveis
 * - Validacao completa de pedido
 *
 * Uso:
 *   require_once __DIR__ . '/OmPricing.php';
 *   $comissao = OmPricing::calcularComissao(80.00, 'boraum');
 *   $custo = OmPricing::calcularCustoBoraUm(3.5);
 */

class OmPricing {

    // ═══════════════════════════════════════════════════════════════════════════
    // COMISSAO
    // ═══════════════════════════════════════════════════════════════════════════
    const COMISSAO_BORAUM = 0.18;       // 18% com BoraUm
    const COMISSAO_PROPRIO = 0.10;      // 10% entrega propria/retirada

    // ═══════════════════════════════════════════════════════════════════════════
    // BORAUM
    // ═══════════════════════════════════════════════════════════════════════════
    const BORAUM_BASE = 5.00;           // Custo base BoraUm
    const BORAUM_PER_KM = 1.50;         // Custo por km
    const BORAUM_MINIMO = 8.00;          // Custo minimo BoraUm

    // ═══════════════════════════════════════════════════════════════════════════
    // TAXAS
    // ═══════════════════════════════════════════════════════════════════════════
    const TAXA_SERVICO = 2.49;
    const EXPRESS_FEE_MAX = 50.00;

    // ═══════════════════════════════════════════════════════════════════════════
    // LIMITES PAGAMENTO
    // ═══════════════════════════════════════════════════════════════════════════
    const CASH_LIMITE = 200.00;
    const GORJETA_MAX = 200.00;

    // ═══════════════════════════════════════════════════════════════════════════
    // CASHBACK
    // ═══════════════════════════════════════════════════════════════════════════
    const CASHBACK_MAX_POR_PEDIDO = 15.00;

    // ═══════════════════════════════════════════════════════════════════════════
    // PONTOS DE FIDELIDADE
    // ═══════════════════════════════════════════════════════════════════════════
    const PONTOS_BASE = 100;
    const PONTOS_POR_REAL = 2;
    const PONTO_VALOR = 0.01;            // R$0.01 por ponto
    const PONTOS_MAX_DESCONTO_PCT = 0.50; // Max 50% do subtotal

    // ═══════════════════════════════════════════════════════════════════════════
    // PEDIDO MINIMO POR DISTANCIA (BoraUm)
    // ═══════════════════════════════════════════════════════════════════════════
    const MINIMO_BORAUM = [
        3  => 30.00,   // 0-3km → R$30
        6  => 50.00,   // 3-6km → R$50
        99 => 70.00,   // 6km+  → R$70
    ];

    // ═══════════════════════════════════════════════════════════════════════════
    // WALLET PARCEIRO
    // ═══════════════════════════════════════════════════════════════════════════
    const WALLET_LIMITE_INICIAL = -200.00;
    const WALLET_LIMITE_MAXIMO = -2000.00;  // Teto absoluto
    const WALLET_DIAS_REAVALIACAO = 30;

    // ═══════════════════════════════════════════════════════════════════════════
    // P&L DIARIO
    // ═══════════════════════════════════════════════════════════════════════════
    const META_LUCRO_DIARIO_MIN = 50.00;    // R$50 minimo por dia
    const RESERVA_FINAL_DIA_PCT = 0.30;     // Reservar 30% do lucro

    // ═══════════════════════════════════════════════════════════════════════════
    // SUPERBORA+
    // ═══════════════════════════════════════════════════════════════════════════
    const SUPERBORA_PLUS_PRECO = 4.90;
    const PLUS_DESCONTO_FRETE = 0.10;       // 10% desconto no frete BoraUm
    const PLUS_DESCONTO_RETIRADA = 0.05;    // 5% desconto em retirada
    const PLUS_PONTOS_MULTIPLICADOR = 1.5;  // 1.5x pontos

    // ═══════════════════════════════════════════════════════════════════════════
    // SHOPPER / MOTORISTA
    // ═══════════════════════════════════════════════════════════════════════════
    const SHOPPER_PCT = 0.05;               // 5% do subtotal
    const SHOPPER_POR_ITEM = 0.50;          // R$0.50 por item
    const SHOPPER_MINIMO = 5.00;
    const DRIVER_PCT_FRETE = 0.80;          // 80% do frete
    const DRIVER_MINIMO = 5.00;

    // ═══════════════════════════════════════════════════════════════════════════
    // CALCULOS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Calcula custo real do BoraUm por distancia
     */
    public static function calcularCustoBoraUm(float $distanciaKm): float {
        $custo = self::BORAUM_BASE + ($distanciaKm * self::BORAUM_PER_KM);
        return max(self::BORAUM_MINIMO, round($custo, 2));
    }

    /**
     * Retorna pedido minimo para BoraUm baseado em distancia
     */
    public static function getMinimoBoraUm(float $distanciaKm): float {
        foreach (self::MINIMO_BORAUM as $kmMax => $minimo) {
            if ($distanciaKm <= $kmMax) {
                return $minimo;
            }
        }
        return 70.00; // fallback
    }

    /**
     * Calcula comissao baseada no tipo de entrega
     * @return array ['taxa' => float, 'valor' => float]
     */
    public static function calcularComissao(float $subtotal, string $tipoEntrega): array {
        $taxa = ($tipoEntrega === 'boraum') ? self::COMISSAO_BORAUM : self::COMISSAO_PROPRIO;
        $valor = round($subtotal * $taxa, 2);
        return ['taxa' => $taxa, 'valor' => $valor];
    }

    /**
     * Calcula distancia em km entre dois pontos (Haversine)
     */
    public static function calcularDistancia(float $lat1, float $lng1, float $lat2, float $lng2): float {
        if ($lat1 == 0 || $lng1 == 0 || $lat2 == 0 || $lng2 == 0) return 3.0; // fallback

        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($R * $c, 2);
    }

    /**
     * Calcula frete completo para o pedido
     *
     * @return array [
     *   'frete' => float,         // Valor cobrado do cliente
     *   'custo_boraum' => float,  // Custo real BoraUm
     *   'margem' => float,        // frete - custo
     *   'gratis' => bool,         // Se frete foi zerado
     *   'subsidio' => float,      // Valor absorvido pela plataforma
     *   'desconto_plus' => float  // Desconto SuperBora+
     * ]
     */
    public static function calcularFrete(array $parceiro, float $subtotal, float $distanciaKm, bool $isPickup, bool $usaBoraUm, ?PDO $db = null, int $customerId = 0): array {
        $result = [
            'frete' => 0,
            'custo_boraum' => 0,
            'margem' => 0,
            'gratis' => false,
            'subsidio' => 0,
            'desconto_plus' => 0,
        ];

        // Retirada: sem frete
        if ($isPickup) {
            return $result;
        }

        // Entrega propria (sem BoraUm)
        if (!$usaBoraUm) {
            $frete = floatval($parceiro['delivery_fee'] ?? 0);
            $result['frete'] = $frete;
            $result['margem'] = $frete; // Tudo e receita, nao tem custo BoraUm
            return $result;
        }

        // BoraUm
        $custo = self::calcularCustoBoraUm($distanciaKm);
        $result['custo_boraum'] = $custo;

        $freteParceiro = floatval($parceiro['delivery_fee'] ?? 0);
        $freteMinimo = round($custo + 1.00, 2); // custo + R$1 margem minima

        // Checar se parceiro oferece frete gratis
        $freeDeliveryAbove = floatval($parceiro['free_delivery_above'] ?? 0);

        if ($freeDeliveryAbove > 0 && $subtotal >= $freeDeliveryAbove && $db) {
            $comissao = $subtotal * self::COMISSAO_BORAUM;

            // Consultar OmDailyBudget
            require_once __DIR__ . '/OmDailyBudget.php';
            $budget = OmDailyBudget::getInstance()->setDb($db);

            if ($budget->podeSubsidiar($custo) && $comissao >= $custo) {
                // Frete gratis total: comissao cobre custo E budget permite
                $result['frete'] = 0;
                $result['gratis'] = true;
                $result['subsidio'] = $custo;
                $result['margem'] = -$custo;
                return $result;
            }

            // Frete parcial: cobrar o que a comissao nao cobre, never below minimum
            $freteParcialBase = round($custo - $comissao + 1.00, 2);
            $freteParcial = max($freteMinimo, $freteParceiro, $freteParcialBase);
            $result['frete'] = $freteParcial;
            $result['subsidio'] = max(0, round($custo - $freteParcial, 2));
            $result['margem'] = round($freteParcial - $custo, 2);
            return $result;
        }

        // Sem frete gratis: cobrar max(parceiro, minimo)
        $frete = max($freteParceiro, $freteMinimo);
        $result['frete'] = $frete;
        $result['margem'] = round($frete - $custo, 2);

        // Aplicar desconto SuperBora+ (10% no frete BoraUm)
        if ($db && $customerId > 0 && self::isSuperboraPlus($db, $customerId)) {
            $desconto = round($frete * self::PLUS_DESCONTO_FRETE, 2);
            $result['frete'] = round($frete - $desconto, 2);
            $result['desconto_plus'] = $desconto;
            $result['margem'] = round($result['frete'] - $custo, 2);
        }

        return $result;
    }

    /**
     * Retorna dados da wallet do parceiro com limite calculado
     *
     * @return array ['saldo' => float, 'limite_negativo' => float, 'cash_bloqueado' => bool]
     */
    public static function getWalletParceiro(PDO $db, int $partnerId): array {
        $stmt = $db->prepare("
            SELECT saldo_disponivel, saldo_devedor, limite_negativo, cash_bloqueado, updated_at
            FROM om_mercado_saldo WHERE partner_id = ?
        ");
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'saldo' => 0,
                'limite_negativo' => self::WALLET_LIMITE_INICIAL,
                'cash_bloqueado' => false,
            ];
        }

        $saldoDisponivel = (float)($row['saldo_disponivel'] ?? 0);
        $saldoDevedor = (float)($row['saldo_devedor'] ?? 0);
        $saldo = $saldoDisponivel - $saldoDevedor;
        $cashBloqueado = (bool)($row['cash_bloqueado'] ?? false);

        // Limite atual
        $limiteAtual = (float)($row['limite_negativo'] ?? self::WALLET_LIMITE_INICIAL);

        // Recalcular se necessario (a cada 30 dias desde ultima atualizacao)
        $updatedAt = $row['updated_at'] ?? null;
        if ($updatedAt) {
            $diasAtivo = (int)((time() - strtotime($updatedAt)) / 86400);
            if ($diasAtivo >= self::WALLET_DIAS_REAVALIACAO) {
                $limiteAtual = self::recalcularLimiteWallet($db, $partnerId, $limiteAtual);
            }
        }

        return [
            'saldo' => $saldo,
            'saldo_disponivel' => $saldoDisponivel,
            'saldo_devedor' => $saldoDevedor,
            'limite_negativo' => $limiteAtual,
            'cash_bloqueado' => $cashBloqueado,
        ];
    }

    /**
     * Recalcula limite de credito baseado nos ultimos 30 dias
     */
    private static function recalcularLimiteWallet(PDO $db, int $partnerId, float $limiteAtual): float {
        try {
            $stmt = $db->prepare("
                SELECT AVG(daily_total) as media_diaria FROM (
                    SELECT DATE(date_added) as dia, SUM(subtotal) as daily_total
                    FROM om_market_orders
                    WHERE partner_id = ?
                    AND status IN ('delivered', 'entregue')
                    AND payment_method NOT IN ('dinheiro', 'cartao_entrega')
                    AND date_added >= NOW() - INTERVAL '30 days'
                    GROUP BY DATE(date_added)
                ) subq
            ");
            $stmt->execute([$partnerId]);
            $mediaDiaria = (float)$stmt->fetchColumn();

            if ($mediaDiaria <= 0) {
                return self::WALLET_LIMITE_INICIAL;
            }

            // Limite = 1 dia de faturamento online (negativo)
            $novoLimite = max(self::WALLET_LIMITE_MAXIMO, -$mediaDiaria);
            // Nunca menos restritivo que o inicial
            $novoLimite = min($novoLimite, self::WALLET_LIMITE_INICIAL);

            // Persistir
            $db->prepare("
                UPDATE om_mercado_saldo
                SET limite_negativo = ?, limite_recalculado_em = CURRENT_DATE
                WHERE partner_id = ?
            ")->execute([$novoLimite, $partnerId]);

            return $novoLimite;

        } catch (Exception $e) {
            error_log("[OmPricing] Erro recalcular limite wallet: " . $e->getMessage());
            return $limiteAtual;
        }
    }

    /**
     * Verifica se cliente e membro SuperBora+
     */
    public static function isSuperboraPlus(PDO $db, int $customerId): bool {
        try {
            $stmt = $db->prepare("
                SELECT 1 FROM om_superbora_plus
                WHERE customer_id = ? AND status = 'active' AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$customerId]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Retorna beneficio variavel para SuperBora+ baseado no budget diario
     *
     * @return array|null ['tipo' => string, 'descricao' => string, 'valor' => float] ou null
     */
    public static function getBeneficioVariavel(PDO $db, int $customerId, bool $isMembro, array $params): ?array {
        require_once __DIR__ . '/OmDailyBudget.php';
        $budget = OmDailyBudget::getInstance()->setDb($db);
        $modo = $budget->getModo();

        $custoBoraum = floatval($params['custo_boraum'] ?? 0);
        $deliveryFee = floatval($params['delivery_fee'] ?? 0);
        $subtotal = floatval($params['subtotal'] ?? 0);
        $tipoEntrega = $params['tipo_entrega'] ?? '';

        // Sem BoraUm ou retirada, beneficio limitado
        if ($tipoEntrega !== 'boraum' || $custoBoraum <= 0) {
            // Membros podem ganhar pontos em dobro em modos bons
            if ($isMembro && in_array($modo, ['agressivo', 'normal'])) {
                return [
                    'tipo' => 'pontos_dobro',
                    'descricao' => 'Pontos em dobro nesta compra!',
                    'valor' => 0,
                    'multiplicador_pontos' => 2.0,
                ];
            }
            return null;
        }

        // Membro SuperBora+: prioridade
        if ($isMembro) {
            if ($modo === 'agressivo') {
                // Frete gratis se budget permite
                if ($budget->podeSubsidiar($custoBoraum)) {
                    return [
                        'tipo' => 'frete_gratis',
                        'descricao' => 'Frete gratis para voce!',
                        'valor' => $deliveryFee,
                        'subsidio' => $custoBoraum,
                    ];
                }
            }
            if (in_array($modo, ['agressivo', 'normal'])) {
                // 50% desconto no frete
                $descontoFrete = round($deliveryFee * 0.5, 2);
                $custoSubsidio = round($custoBoraum * 0.5, 2);
                if ($budget->podeSubsidiar($custoSubsidio)) {
                    return [
                        'tipo' => 'frete_50',
                        'descricao' => '50% de desconto no frete!',
                        'valor' => $descontoFrete,
                        'subsidio' => $custoSubsidio,
                    ];
                }
            }
            return null;
        }

        // NAO membro: so se dia agressivo E sobrar muita margem
        if ($modo === 'agressivo') {
            $pnl = $budget->getPnlHoje();
            $lucroAcumulado = floatval($pnl['lucro_acumulado'] ?? 0);

            // So dar beneficio se lucro > 3x a meta
            if ($lucroAcumulado > self::META_LUCRO_DIARIO_MIN * 3) {
                $descontoFrete = round($deliveryFee * 0.5, 2);
                $custoSubsidio = round($custoBoraum * 0.5, 2);
                if ($budget->podeSubsidiar($custoSubsidio)) {
                    return [
                        'tipo' => 'frete_50',
                        'descricao' => '50% de desconto no frete! Assine SuperBora+ por R$4,90/mes e ganhe isso sempre!',
                        'valor' => $descontoFrete,
                        'subsidio' => $custoSubsidio,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Calcula pontos de fidelidade
     */
    public static function calcularPontos(float $subtotal, bool $isMembro = false): int {
        $pontos = self::PONTOS_BASE + (int)floor($subtotal * self::PONTOS_POR_REAL);
        $multiplicador = $isMembro ? self::PLUS_PONTOS_MULTIPLICADOR : 1.0;
        return (int)round($pontos * $multiplicador);
    }

    /**
     * Calcula pagamento do shopper
     */
    public static function calcularPagShopper(float $subtotal, int $totalItens): float {
        $valor = ($subtotal * self::SHOPPER_PCT) + ($totalItens * self::SHOPPER_POR_ITEM);
        return max(self::SHOPPER_MINIMO, round($valor, 2));
    }

    /**
     * Calcula pagamento do motorista
     */
    public static function calcularPagMotorista(float $deliveryFee): float {
        $valor = $deliveryFee * self::DRIVER_PCT_FRETE;
        return max(self::DRIVER_MINIMO, round($valor, 2));
    }

    /**
     * Validacao completa do pedido antes de criar
     *
     * @return array ['ok' => bool, 'erro' => string|null, 'frete' => array, 'comissao' => array, 'lucro' => float, 'breakdown' => array]
     */
    public static function validarPedido(array $params): array {
        $subtotal = floatval($params['subtotal'] ?? 0);
        $distanciaKm = floatval($params['distancia_km'] ?? 3);
        $tipoEntrega = $params['tipo_entrega'] ?? 'proprio'; // boraum, proprio, retirada
        $pagamento = $params['pagamento'] ?? 'pix';
        $parceiro = $params['parceiro'] ?? [];
        $descontos = $params['descontos'] ?? [];
        $db = $params['db'] ?? null;
        $customerId = (int)($params['customer_id'] ?? 0);
        $partnerId = (int)($parceiro['partner_id'] ?? 0);

        $isBoraUm = ($tipoEntrega === 'boraum');
        $isPickup = ($tipoEntrega === 'retirada');
        $isCash = in_array($pagamento, ['dinheiro', 'cartao_entrega']);

        // 1. Pedido minimo por distancia (BoraUm)
        if ($isBoraUm) {
            $minimo = self::getMinimoBoraUm($distanciaKm);
            if ($subtotal < $minimo) {
                return [
                    'ok' => false,
                    'erro' => "Pedido minimo R$" . number_format($minimo, 2, ',', '.') . " para entregas " . self::getDescricaoDistancia($distanciaKm),
                    'pedido_minimo' => $minimo,
                ];
            }
        }

        // 2. Cash: checar wallet do parceiro
        if ($isCash && $db && $partnerId) {
            $wallet = self::getWalletParceiro($db, $partnerId);

            if ($wallet['cash_bloqueado']) {
                return [
                    'ok' => false,
                    'erro' => 'Pagamento na entrega temporariamente indisponivel para este restaurante. Use PIX ou cartao.',
                ];
            }

            $comissaoCash = $subtotal * self::COMISSAO_PROPRIO;
            $novoSaldo = $wallet['saldo'] - $comissaoCash;

            if ($novoSaldo < $wallet['limite_negativo']) {
                // Bloquear parceiro
                try {
                    $db->prepare("
                        UPDATE om_mercado_saldo SET cash_bloqueado = true WHERE partner_id = ?
                    ")->execute([$partnerId]);
                } catch (Exception $e) {
                    error_log("[OmPricing] Erro ao bloquear cash: " . $e->getMessage());
                }

                return [
                    'ok' => false,
                    'erro' => 'Pagamento na entrega indisponivel neste momento. Use PIX ou cartao.',
                ];
            }
        }

        // 3. Total descontos nao pode exceder 50% do subtotal
        $cupom = floatval($descontos['cupom'] ?? 0);
        $pontosDesc = floatval($descontos['pontos'] ?? 0);
        $cashbackDesc = floatval($descontos['cashback'] ?? 0);
        $totalDescontos = $cupom + $pontosDesc + $cashbackDesc;
        $maxDesconto = $subtotal * 0.50;

        if ($totalDescontos > $maxDesconto && $totalDescontos > 0) {
            $fator = $maxDesconto / $totalDescontos;
            $cupom = round($cupom * $fator, 2);
            $pontosDesc = round($pontosDesc * $fator, 2);
            $cashbackDesc = round($cashbackDesc * $fator, 2);
            $totalDescontos = $cupom + $pontosDesc + $cashbackDesc;
        }

        // 4. Calcular frete
        $frete = self::calcularFrete($parceiro, $subtotal, $distanciaKm, $isPickup, $isBoraUm, $db, $customerId);

        // 5. Calcular comissao
        $comissao = self::calcularComissao($subtotal, $isBoraUm ? 'boraum' : 'proprio');

        // 6. Checar beneficio variavel SuperBora+
        $isMembro = ($db && $customerId) ? self::isSuperboraPlus($db, $customerId) : false;
        $beneficioExtra = null;
        if ($db && $customerId) {
            $beneficioExtra = self::getBeneficioVariavel($db, $customerId, $isMembro, [
                'subtotal' => $subtotal,
                'delivery_fee' => $frete['frete'],
                'custo_boraum' => $frete['custo_boraum'],
                'tipo_entrega' => $tipoEntrega,
            ]);
        }

        // Aplicar beneficio variavel ao frete
        if ($beneficioExtra) {
            if ($beneficioExtra['tipo'] === 'frete_gratis') {
                $frete['subsidio'] += $frete['frete'];
                $frete['frete'] = 0;
                $frete['gratis'] = true;
                $frete['margem'] = -$frete['custo_boraum'];
            } elseif ($beneficioExtra['tipo'] === 'frete_50') {
                $desconto50 = round($frete['frete'] * 0.5, 2);
                $frete['subsidio'] += $desconto50;
                $frete['frete'] = round($frete['frete'] - $desconto50, 2);
                $frete['margem'] = round($frete['frete'] - $frete['custo_boraum'], 2);
            }
        }

        // 7. Calcular P&L do pedido
        $expressFee = floatval($params['express_fee'] ?? 0);
        $serviceFee = self::TAXA_SERVICO;
        $receita = $comissao['valor'] + $serviceFee + max(0, $frete['margem']) + $expressFee;
        $custos = $frete['subsidio'];
        $lucro = round($receita - $custos, 2);

        // 8. Desconto retirada SuperBora+ (5%)
        $plusDescontoRetirada = 0;
        if ($isPickup && $isMembro) {
            $plusDescontoRetirada = round($subtotal * self::PLUS_DESCONTO_RETIRADA, 2);
        }

        return [
            'ok' => true,
            'erro' => null,
            'frete' => $frete,
            'comissao' => $comissao,
            'lucro' => $lucro,
            'is_membro_plus' => $isMembro,
            'beneficio_extra' => $beneficioExtra,
            'plus_desconto_retirada' => $plusDescontoRetirada,
            'descontos_ajustados' => [
                'cupom' => $cupom,
                'pontos' => $pontosDesc,
                'cashback' => $cashbackDesc,
                'total' => $totalDescontos,
            ],
            'breakdown' => [
                'subtotal' => $subtotal,
                'frete_cliente' => $frete['frete'],
                'custo_boraum' => $frete['custo_boraum'],
                'margem_frete' => $frete['margem'],
                'subsidio' => $frete['subsidio'],
                'comissao_pct' => $comissao['taxa'] * 100,
                'comissao_valor' => $comissao['valor'],
                'service_fee' => $serviceFee,
                'express_fee' => $expressFee,
                'receita_total' => $receita,
                'custos_total' => $custos,
                'lucro_pedido' => $lucro,
                'distancia_km' => $distanciaKm,
                'tipo_entrega' => $tipoEntrega,
                'pagamento' => $pagamento,
            ],
        ];
    }

    /**
     * Descricao amigavel da faixa de distancia
     */
    private static function getDescricaoDistancia(float $km): string {
        if ($km <= 3) return 'ate 3km';
        if ($km <= 6) return 'de 3 a 6km';
        return 'acima de 6km';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MULTI-STOP ROUTES
    // ═══════════════════════════════════════════════════════════════════════════

    const MULTISTOP_CUSTO_POR_PARADA = 2.00;
    const MULTISTOP_MAX_DESVIO_KM = 1.0;

    /**
     * Calcula distancia perpendicular de um ponto a um segmento de reta (rota)
     * Usado para encontrar lojas "no caminho" da entrega
     *
     * @param float $latA, $lngA  Loja origem
     * @param float $latB, $lngB  Cliente (destino)
     * @param float $latC, $lngC  Loja candidata
     * @return array ['distancia' => float km, 'progresso' => float 0..1]
     */
    public static function distanciaPerpendicularRota(
        float $latA, float $lngA,
        float $latB, float $lngB,
        float $latC, float $lngC
    ): array {
        $midLat = deg2rad(($latA + $latB) / 2);
        $kmLat = 111.32;
        $kmLng = 111.32 * cos($midLat);

        $bx = ($latB - $latA) * $kmLat;
        $by = ($lngB - $lngA) * $kmLng;
        $cx = ($latC - $latA) * $kmLat;
        $cy = ($lngC - $lngA) * $kmLng;

        $dot = $bx * $bx + $by * $by;
        if ($dot < 0.0001) {
            return ['distancia' => sqrt($cx * $cx + $cy * $cy), 'progresso' => 0];
        }

        $t = max(0, min(1, ($cx * $bx + $cy * $by) / $dot));
        $px = $t * $bx;
        $py = $t * $by;

        return [
            'distancia' => sqrt(($cx - $px) * ($cx - $px) + ($cy - $py) * ($cy - $py)),
            'progresso' => $t,
        ];
    }

    /**
     * Calcula frete multi-stop (cliente paga frete normal, plataforma absorve custo extra)
     */
    public static function calcularFreteMultiStop(float $distanciaTotal, int $numParadas): array {
        $custoBase = self::calcularCustoBoraUm($distanciaTotal);
        $custoExtra = $numParadas * self::MULTISTOP_CUSTO_POR_PARADA;
        $freteCliente = max(self::BORAUM_MINIMO, round($custoBase + 1.00, 2));

        return [
            'frete_cliente' => $freteCliente,
            'custo_boraum' => round($custoBase + $custoExtra, 2),
            'custo_extra_paradas' => round($custoExtra, 2),
            'num_paradas' => $numParadas,
        ];
    }
}
