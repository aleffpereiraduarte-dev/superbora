<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO - CÁLCULO UNIFICADO DE COMISSÕES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * MODELO INSTACART:
 * - Shopper = pago por COMPRAS (% do subtotal + por item)
 * - Motorista = pago por ENTREGA (% da taxa de entrega)
 *
 * Configurações padrão (podem ser sobrescritas pelo banco):
 * - Shopper: 5% do subtotal + R$0.50/item, mínimo R$5.00
 * - Motorista: 80% da taxa de entrega, mínimo R$5.00
 * - Plataforma: 20% da taxa de entrega + markup nos produtos
 */

class OmComissao {
    private static $instance = null;
    private $pdo;
    private $configs = [];

    // Configurações padrão
    private const DEFAULTS = [
        'shopper_mercado' => [
            'percentual' => 5,       // 5% do subtotal
            'valor_por_item' => 0.50,
            'valor_minimo' => 5.00
        ],
        'motorista_mercado' => [
            'percentual' => 80,      // 80% da taxa de entrega
            'valor_minimo' => 5.00
        ],
        'plataforma_mercado' => [
            'percentual' => 20       // 20% da taxa de entrega
        ]
    ];

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setDb(PDO $pdo): void {
        $this->pdo = $pdo;
        $this->loadConfigs();
    }

    /**
     * Carrega configurações do banco de dados
     */
    private function loadConfigs(): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->query("
                SELECT tipo, servico, percentual, valor_minimo, valor_fixo
                FROM om_comissoes_config
                WHERE ativo = 1
            ");

            foreach ($stmt->fetchAll() as $c) {
                $key = $c['tipo'] . '_' . $c['servico'];
                $this->configs[$key] = [
                    'percentual' => floatval($c['percentual']),
                    'valor_minimo' => floatval($c['valor_minimo'] ?? 0),
                    'valor_por_item' => floatval($c['valor_fixo'] ?? 0)
                ];
            }
        } catch (Exception $e) {
            // Usar padrões se tabela não existir
        }
    }

    /**
     * Obtém configuração para um tipo de trabalhador
     */
    private function getConfig(string $tipo, string $servico = 'mercado'): array {
        $key = $tipo . '_' . $servico;
        return $this->configs[$key] ?? self::DEFAULTS[$key] ?? [];
    }

    /**
     * Calcula ganho do SHOPPER
     *
     * Modelo: 5% do subtotal + R$0.50/item, mínimo R$5.00
     *
     * @param float $valorProdutos Valor total dos produtos (subtotal)
     * @param int $qtdItens Quantidade de itens distintos
     * @return array
     */
    public function calcularGanhoShopper(float $valorProdutos, int $qtdItens): array {
        $config = $this->getConfig('shopper');

        $percentual = $config['percentual'] ?? 5;
        $valorPorItem = $config['valor_por_item'] ?? 0.50;
        $valorMinimo = $config['valor_minimo'] ?? 5.00;

        $valorBase = $valorProdutos * ($percentual / 100);
        $valorItens = $qtdItens * $valorPorItem;
        $valorCalculado = $valorBase + $valorItens;
        $valorFinal = max($valorCalculado, $valorMinimo);

        return [
            'valor_bruto' => round($valorProdutos, 2),
            'percentual' => $percentual,
            'valor_base' => round($valorBase, 2),
            'bonus_itens' => round($valorItens, 2),
            'qtd_itens' => $qtdItens,
            'valor_calculado' => round($valorCalculado, 2),
            'valor_minimo' => $valorMinimo,
            'valor_final' => round($valorFinal, 2),
            'descricao' => sprintf(
                "%d%% de R$ %s + R$ %s x %d itens",
                $percentual,
                number_format($valorProdutos, 2, ',', '.'),
                number_format($valorPorItem, 2, ',', '.'),
                $qtdItens
            )
        ];
    }

    /**
     * Calcula ganho do MOTORISTA
     *
     * Modelo: 80% da taxa de entrega, mínimo R$5.00
     * Se entrega gratis pro cliente, usa custo real (distancia)
     *
     * @param float $taxaEntrega Taxa de entrega cobrada do cliente (pode ser 0 se gratis)
     * @param float $custoRealEntrega Custo real baseado na distancia (sempre > 0 se tem motorista)
     * @param float $gorjeta Gorjeta do cliente (100% pro motorista)
     * @return array
     */
    public function calcularGanhoMotorista(float $taxaEntrega, float $custoRealEntrega = 0, float $gorjeta = 0): array {
        $config = $this->getConfig('motorista');

        $percentual = $config['percentual'] ?? 80;
        $valorMinimo = $config['valor_minimo'] ?? 5.00;

        // Usar custo real quando entrega e gratis pro cliente
        $baseCalculo = $taxaEntrega > 0 ? $taxaEntrega : $custoRealEntrega;

        // No platform commission when delivery is free for customer
        $comissaoPlataforma = ($taxaEntrega > 0) ? $baseCalculo * ((100 - $percentual) / 100) : 0;
        $valorLiquido = $baseCalculo * ($percentual / 100);
        $valorFinal = max($valorLiquido, $valorMinimo);

        // Custo absorvido pela plataforma quando entrega gratis
        $custoAbsorvido = ($taxaEntrega == 0 && $baseCalculo > 0) ? $valorFinal : 0;

        return [
            'taxa_entrega' => round($taxaEntrega, 2),
            'custo_real' => round($baseCalculo, 2),
            'entrega_gratis' => $taxaEntrega == 0 && $baseCalculo > 0,
            'custo_absorvido' => round($custoAbsorvido, 2),
            'percentual' => $percentual,
            'comissao_plataforma' => round($comissaoPlataforma, 2),
            'valor_liquido' => round($valorLiquido, 2),
            'gorjeta' => round($gorjeta, 2),
            'valor_minimo' => $valorMinimo,
            'valor_final' => round($valorFinal, 2),
            'valor_total' => round($valorFinal + $gorjeta, 2),
            'descricao' => sprintf(
                "%d%% de R$ %s (entrega%s) + R$ %s gorjeta",
                $percentual,
                number_format($baseCalculo, 2, ',', '.'),
                $taxaEntrega == 0 ? ' gratis - custo absorvido' : '',
                number_format($gorjeta, 2, ',', '.')
            )
        ];
    }

    /**
     * Calcula distribuição completa de um pedido
     *
     * @param array $pedido Array com dados do pedido
     * @return array Distribuição completa
     */
    public function calcularDistribuicao(array $pedido): array {
        // Valores do pedido
        // Derive subtotal: total - delivery_fee - service_fee - gorjeta + discount
        $valorProdutos = floatval($pedido['subtotal'] ?? $pedido['items_total'] ?? (
            $pedido['total']
            - ($pedido['delivery_fee'] ?? 0)
            - ($pedido['service_fee'] ?? 0)
            - ($pedido['tip_amount'] ?? 0)
            + ($pedido['discount'] ?? $pedido['coupon_discount'] ?? 0)
        ));
        $taxaEntrega = floatval($pedido['delivery_fee'] ?? 0);
        $taxaServico = floatval($pedido['service_fee'] ?? 2.49);
        $gorjeta = floatval($pedido['tip_amount'] ?? 0);
        $desconto = floatval($pedido['discount'] ?? $pedido['coupon_discount'] ?? 0);
        $qtdItens = intval($pedido['items_count'] ?? $pedido['total_itens'] ?? 1);
        $custoProdutos = floatval($pedido['custo_produtos'] ?? ($valorProdutos * 0.70));
        $isPickup = intval($pedido['is_pickup'] ?? 0);

        // Custo real de entrega (baseado na distancia, mesmo se gratis pro cliente)
        $custoRealEntrega = floatval($pedido['valor_frete_real'] ?? $pedido['shipping_fee'] ?? 0);
        if ($custoRealEntrega == 0 && !$isPickup) {
            // Fallback: buscar da om_entregas se disponivel
            $custoRealEntrega = floatval($pedido['valor_frete'] ?? 0);
        }
        if ($custoRealEntrega == 0 && $taxaEntrega > 0) {
            $custoRealEntrega = $taxaEntrega;
        }
        // Minimo R$8 se tem motorista e nao e retirada
        if ($custoRealEntrega == 0 && !$isPickup) {
            $custoRealEntrega = 8.00;
        }

        // Calcular ganhos
        $temShopper = intval($pedido['shopper_id'] ?? 0) > 0;
        $temMotorista = !$isPickup; // tem motorista se nao e retirada

        $ganhoShopper = $temShopper
            ? $this->calcularGanhoShopper($valorProdutos, $qtdItens)
            : ['valor_final' => 0, 'descricao' => 'Sem shopper'];

        $ganhoMotorista = $temMotorista
            ? $this->calcularGanhoMotorista($taxaEntrega, $custoRealEntrega, $gorjeta)
            : ['valor_final' => 0, 'valor_total' => 0, 'gorjeta' => 0, 'custo_absorvido' => 0, 'comissao_plataforma' => 0, 'descricao' => 'Retirada - sem motorista'];

        // Lucro da plataforma
        $lucroProdutos = $valorProdutos - $custoProdutos;
        $comissaoEntrega = $temMotorista ? $ganhoMotorista['comissao_plataforma'] : 0;
        $custoAbsorvido = $ganhoMotorista['custo_absorvido'] ?? 0;

        $lucroPlataforma = $lucroProdutos
            + $comissaoEntrega
            + $taxaServico
            - ($temShopper ? $ganhoShopper['valor_final'] : 0)
            - $custoAbsorvido
            - $desconto;

        return [
            'cliente_pagou' => [
                'produtos' => round($valorProdutos, 2),
                'entrega' => round($taxaEntrega, 2),
                'servico' => round($taxaServico, 2),
                'gorjeta' => round($gorjeta, 2),
                'desconto' => round($desconto, 2),
                'total' => round($valorProdutos + $taxaEntrega + $taxaServico + $gorjeta - $desconto, 2)
            ],
            'mercado_recebe' => round($custoProdutos, 2),
            'shopper' => [
                'valor' => $ganhoShopper['valor_final'],
                'tem_shopper' => $temShopper,
                'calculo' => $ganhoShopper
            ],
            'motorista' => [
                'valor' => $ganhoMotorista['valor_final'],
                'gorjeta' => $ganhoMotorista['gorjeta'] ?? 0,
                'valor_total' => $ganhoMotorista['valor_total'] ?? $ganhoMotorista['valor_final'],
                'tem_motorista' => $temMotorista,
                'calculo' => $ganhoMotorista
            ],
            'plataforma' => [
                'lucro_produtos' => round($lucroProdutos, 2),
                'comissao_entrega' => round($comissaoEntrega, 2),
                'taxa_servico' => round($taxaServico, 2),
                'custo_shopper' => round($temShopper ? $ganhoShopper['valor_final'] : 0, 2),
                'custo_entrega_gratis' => round($custoAbsorvido, 2),
                'custo_desconto' => round($desconto, 2),
                'total' => round($lucroPlataforma, 2)
            ]
        ];
    }

    /**
     * Simula ganho para exibir ao trabalhador antes de aceitar
     */
    public function simularGanho(string $tipo, array $pedido): array {
        if ($tipo === 'motorista') {
            $taxaEntrega = floatval($pedido['delivery_fee'] ?? 0);
            $custoReal = floatval($pedido['valor_frete'] ?? $taxaEntrega);
            if ($custoReal == 0) $custoReal = 8.00;
            $gorjeta = floatval($pedido['tip_amount'] ?? 0);
            $ganho = $this->calcularGanhoMotorista($taxaEntrega, $custoReal, $gorjeta);
            return [
                'tipo' => 'motorista',
                'funcao' => 'Entregador',
                'valor' => $ganho['valor_total'],
                'valor_formatado' => 'R$ ' . number_format($ganho['valor_total'], 2, ',', '.'),
                'minimo_garantido' => $ganho['valor_minimo'],
                'como_calculado' => $ganho['descricao'],
                'modelo' => 'Você será pago pela ENTREGA (igual Uber/99)'
            ];
        } else {
            $valorProdutos = floatval($pedido['subtotal'] ?? ($pedido['total'] - ($pedido['delivery_fee'] ?? 0)));
            $qtdItens = intval($pedido['items_count'] ?? $pedido['total_itens'] ?? 1);
            $ganho = $this->calcularGanhoShopper($valorProdutos, $qtdItens);

            return [
                'tipo' => 'shopper',
                'funcao' => 'Comprador (Shopper)',
                'valor' => $ganho['valor_final'],
                'valor_formatado' => 'R$ ' . number_format($ganho['valor_final'], 2, ',', '.'),
                'minimo_garantido' => $ganho['valor_minimo'],
                'como_calculado' => $ganho['descricao'],
                'modelo' => 'Você será pago pelas COMPRAS no mercado (igual Instacart)'
            ];
        }
    }
}

/**
 * Helper global
 */
function om_comissao(): OmComissao {
    return OmComissao::getInstance();
}
