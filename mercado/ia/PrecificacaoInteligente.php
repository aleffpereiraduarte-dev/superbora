<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║  🧠 ONEMUNDO MERCADO - IA DE PRECIFICAÇÃO ULTRA INTELIGENTE v2.0                     ║
 * ║  Sistema que NUNCA dá prejuízo - Considera TODOS os custos                           ║
 * ╠══════════════════════════════════════════════════════════════════════════════════════╣
 * ║  Baseado em: Instacart, iFood, Rappi + Pesquisa de mercado 2024/2025                 ║
 * ║  Custos: Shopper + Delivery + Gateway + Erros + Frete + Fixos + Lucro                ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

class PrecificacaoInteligente {
    
    // ═══════════════════════════════════════════════════════════════════════════════════
    // 1. CONFIGURAÇÕES DE CUSTOS OPERACIONAIS (Pesquisa de Mercado 2024/2025)
    // ═══════════════════════════════════════════════════════════════════════════════════
    
    /**
     * CUSTOS DO SHOPPER (quem faz as compras)
     * Fonte: iFood, Rappi, Glassdoor - Média R$ 1.400-1.800/mês
     * Por pedido: R$ 6-10 fixo + 2% do valor (considerando 30-40 min por pedido)
     */
    private $custoShopper = [
        'fixo_por_pedido' => 6.00,      // R$ 6 fixo por pedido
        'percentual_valor' => 0.02,     // 2% do valor do pedido
        'bonus_pedido_grande' => 3.00,  // Bônus se pedido > R$ 300
        'limite_bonus' => 300.00        // Valor para ativar bônus
    ];
    
    /**
     * CUSTOS DO DELIVERY (quem entrega)
     * Fonte: iFood paga R$ 6,50/rota + R$ 1,50/km
     * Salário médio motoboy: R$ 1.830-2.400/mês
     */
    private $custoDelivery = [
        'fixo_por_entrega' => 5.00,     // R$ 5 fixo por entrega
        'custo_por_km' => 1.50,         // R$ 1,50 por km
        'distancia_media_km' => 3.5,    // Distância média das entregas
        'bonus_urgente' => 3.00         // Bônus para entrega expressa
    ];
    
    /**
     * CUSTOS DO GATEWAY DE PAGAMENTO (Pagar.me)
     * Cartão: 3.5-4.5% | PIX: 0.99-1.5% | Boleto: R$ 2-4
     */
    private $custoGateway = [
        'cartao_credito' => 0.039,      // 3.9%
        'cartao_debito' => 0.025,       // 2.5%
        'pix' => 0.0099,                // 0.99%
        'boleto_fixo' => 3.50,          // R$ 3,50 fixo
        'media_ponderada' => 0.032      // 3.2% (média considerando mix de pagamentos)
    ];
    
    /**
     * CUSTOS DE EMBALAGEM
     */
    private $custoEmbalagem = [
        'sacola_plastica' => 0.30,
        'sacola_papel' => 0.80,
        'caixa_termica' => 2.00,        // Para congelados
        'gelo_gel' => 1.50,             // Pack de gelo
        'etiqueta' => 0.15,
        'media_por_pedido' => 1.50      // Média geral
    ];
    
    /**
     * RESERVA PARA PROBLEMAS (Pesquisa e-commerce Brasil 2024)
     * Devoluções grocery: 2-3% | Produtos danificados: 0.5-1%
     * Erros picking: 1-2% | Cancelamentos: 1-2%
     */
    private $reservaProblemas = [
        'devolucoes' => 0.025,          // 2.5%
        'produtos_danificados' => 0.01, // 1%
        'erros_picking' => 0.015,       // 1.5%
        'cancelamentos' => 0.01,        // 1%
        'total' => 0.05                 // 5% reserva total (conservador)
    ];
    
    /**
     * CUSTOS FIXOS MENSAIS (para diluir nos pedidos)
     */
    private $custosFixosMensais = [
        'servidores' => 1500,
        'sistema_manutencao' => 2000,
        'apis_externas' => 1000,        // Claude, Maps, etc
        'suporte_cliente' => 3000,      // 1-2 pessoas
        'administrativo' => 4000,
        'marketing' => 5000,
        'contabilidade' => 1000,
        'juridico' => 500,
        'seguros' => 500,
        'total' => 18500                // Total mensal
    ];
    
    /**
     * META DE PEDIDOS PARA DILUIÇÃO
     */
    private $metaPedidosMensal = 1000;  // Ajustar conforme crescimento
    
    // ═══════════════════════════════════════════════════════════════════════════════════
    // 2. MARGENS POR CATEGORIA (Pesquisa Supermercados 2024)
    // ═══════════════════════════════════════════════════════════════════════════════════
    
    /**
     * Margens brutas típicas de supermercados por categoria
     * Fonte: BusinessDojo, Naveo Commerce, pesquisas de mercado
     */
    private $margensCategorias = [
        // Perecíveis - ALTO RISCO (maior margem)
        'padaria' => ['margem' => 0.22, 'risco' => 'muito_alto', 'spoilage' => 0.08],
        'paes' => ['margem' => 0.22, 'risco' => 'muito_alto', 'spoilage' => 0.08],
        'bolos' => ['margem' => 0.25, 'risco' => 'muito_alto', 'spoilage' => 0.10],
        
        // Carnes - ALTO RISCO
        'carnes' => ['margem' => 0.22, 'risco' => 'alto', 'spoilage' => 0.06],
        'acougue' => ['margem' => 0.22, 'risco' => 'alto', 'spoilage' => 0.06],
        'aves' => ['margem' => 0.20, 'risco' => 'alto', 'spoilage' => 0.05],
        'peixes' => ['margem' => 0.25, 'risco' => 'muito_alto', 'spoilage' => 0.10],
        'frutos_mar' => ['margem' => 0.28, 'risco' => 'muito_alto', 'spoilage' => 0.12],
        
        // Frios e Laticínios
        'frios' => ['margem' => 0.20, 'risco' => 'alto', 'spoilage' => 0.05],
        'laticinios' => ['margem' => 0.16, 'risco' => 'medio', 'spoilage' => 0.04],
        'leite' => ['margem' => 0.14, 'risco' => 'medio', 'spoilage' => 0.03],
        'queijos' => ['margem' => 0.20, 'risco' => 'medio', 'spoilage' => 0.04],
        'iogurtes' => ['margem' => 0.18, 'risco' => 'medio', 'spoilage' => 0.05],
        'manteiga' => ['margem' => 0.16, 'risco' => 'baixo', 'spoilage' => 0.02],
        
        // Hortifruti - MUITO ALTO RISCO
        'hortifruti' => ['margem' => 0.20, 'risco' => 'muito_alto', 'spoilage' => 0.10],
        'frutas' => ['margem' => 0.20, 'risco' => 'muito_alto', 'spoilage' => 0.12],
        'verduras' => ['margem' => 0.22, 'risco' => 'muito_alto', 'spoilage' => 0.15],
        'legumes' => ['margem' => 0.18, 'risco' => 'alto', 'spoilage' => 0.08],
        
        // Congelados
        'congelados' => ['margem' => 0.18, 'risco' => 'medio', 'spoilage' => 0.02],
        'sorvetes' => ['margem' => 0.22, 'risco' => 'medio', 'spoilage' => 0.03],
        'pizzas_congeladas' => ['margem' => 0.20, 'risco' => 'baixo', 'spoilage' => 0.01],
        
        // Bebidas
        'bebidas' => ['margem' => 0.20, 'risco' => 'baixo', 'spoilage' => 0.01],
        'refrigerantes' => ['margem' => 0.18, 'risco' => 'muito_baixo', 'spoilage' => 0.005],
        'sucos' => ['margem' => 0.18, 'risco' => 'baixo', 'spoilage' => 0.02],
        'agua' => ['margem' => 0.12, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'energeticos' => ['margem' => 0.22, 'risco' => 'muito_baixo', 'spoilage' => 0],
        
        // Bebidas Alcoólicas - ALTA MARGEM
        'bebidas_alcoolicas' => ['margem' => 0.25, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'cervejas' => ['margem' => 0.22, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'vinhos' => ['margem' => 0.28, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'destilados' => ['margem' => 0.30, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'whisky' => ['margem' => 0.30, 'risco' => 'muito_baixo', 'spoilage' => 0],
        
        // Mercearia Seca - BAIXO RISCO
        'mercearia' => ['margem' => 0.14, 'risco' => 'muito_baixo', 'spoilage' => 0.005],
        'graos' => ['margem' => 0.12, 'risco' => 'muito_baixo', 'spoilage' => 0.005],
        'arroz' => ['margem' => 0.10, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'feijao' => ['margem' => 0.12, 'risco' => 'muito_baixo', 'spoilage' => 0.005],
        'massas' => ['margem' => 0.14, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'cereais' => ['margem' => 0.16, 'risco' => 'muito_baixo', 'spoilage' => 0.01],
        'biscoitos' => ['margem' => 0.18, 'risco' => 'muito_baixo', 'spoilage' => 0.01],
        'doces' => ['margem' => 0.20, 'risco' => 'baixo', 'spoilage' => 0.02],
        'chocolates' => ['margem' => 0.22, 'risco' => 'baixo', 'spoilage' => 0.02],
        'enlatados' => ['margem' => 0.12, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'temperos' => ['margem' => 0.18, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'molhos' => ['margem' => 0.16, 'risco' => 'muito_baixo', 'spoilage' => 0.01],
        'oleos' => ['margem' => 0.12, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'cafe' => ['margem' => 0.16, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'chas' => ['margem' => 0.18, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'acucar' => ['margem' => 0.10, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'farinhas' => ['margem' => 0.12, 'risco' => 'muito_baixo', 'spoilage' => 0.01],
        
        // Não Alimentícios
        'limpeza' => ['margem' => 0.12, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'higiene' => ['margem' => 0.14, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'beleza' => ['margem' => 0.18, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'pet_shop' => ['margem' => 0.18, 'risco' => 'baixo', 'spoilage' => 0.01],
        'bebe' => ['margem' => 0.16, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'fraldas' => ['margem' => 0.14, 'risco' => 'muito_baixo', 'spoilage' => 0],
        'papelaria' => ['margem' => 0.20, 'risco' => 'muito_baixo', 'spoilage' => 0],
        
        // Default
        'default' => ['margem' => 0.16, 'risco' => 'medio', 'spoilage' => 0.02]
    ];
    
    // ═══════════════════════════════════════════════════════════════════════════════════
    // 3. CONFIGURAÇÕES DE MEMBROS/ASSINANTES
    // ═══════════════════════════════════════════════════════════════════════════════════
    
    private $descontosMembros = [
        'diamond' => ['desconto' => 0.05, 'frete_gratis_min' => 100],
        'gold' => ['desconto' => 0.03, 'frete_gratis_min' => 150],
        'silver' => ['desconto' => 0.02, 'frete_gratis_min' => 200],
        'bronze' => ['desconto' => 0.01, 'frete_gratis_min' => 250],
        'normal' => ['desconto' => 0, 'frete_gratis_min' => 300]
    ];
    
    // ═══════════════════════════════════════════════════════════════════════════════════
    // 4. LIMITES DE SEGURANÇA (NUNCA PREJUÍZO)
    // ═══════════════════════════════════════════════════════════════════════════════════
    
    private $limitesSeguranca = [
        'lucro_minimo_reais' => 0.50,       // Mínimo R$ 0,50 por produto
        'margem_minima_percent' => 0.18,    // Mínimo 18% de margem
        'margem_maxima_percent' => 0.45,    // Máximo 45% (não ser abusivo)
        'markup_minimo_absoluto' => 0.20,   // 20% mínimo sempre
        'alerta_margem_baixa' => 0.22       // Alertar se margem < 22%
    ];
    
    // ═══════════════════════════════════════════════════════════════════════════════════
    // 5. CONEXÃO COM BANCO
    // ═══════════════════════════════════════════════════════════════════════════════════
    
    private $conn;
    
    public function __construct($conn = null) {
        if ($conn) {
            $this->conn = $conn;
        } else {
            $this->conn = new mysqli('localhost', 'love1', DB_PASSWORD, 'love1');
            $this->conn->set_charset('utf8mb4');
        }
    }

    /**
     * Define configurações personalizadas para um mercado específico
     * Permite ajustar margens e custos individualmente
     */
    public function setConfigMercado($config) {
        if (!$config) return;

        // Atualizar limites de segurança
        if (isset($config['margem_minima'])) {
            $this->limitesSeguranca['margem_minima_percent'] = floatval($config['margem_minima']) / 100;
        }
        if (isset($config['margem_maxima'])) {
            $this->limitesSeguranca['margem_maxima_percent'] = floatval($config['margem_maxima']) / 100;
        }
        if (isset($config['lucro_minimo_reais'])) {
            $this->limitesSeguranca['lucro_minimo_reais'] = floatval($config['lucro_minimo_reais']);
        }

        // Atualizar custos do shopper
        if (isset($config['custo_shopper_fixo'])) {
            $this->custoShopper['fixo_por_pedido'] = floatval($config['custo_shopper_fixo']);
        }
        if (isset($config['custo_shopper_percent'])) {
            $this->custoShopper['percentual_valor'] = floatval($config['custo_shopper_percent']) / 100;
        }

        // Atualizar custos do delivery
        if (isset($config['custo_delivery_fixo'])) {
            $this->custoDelivery['fixo_por_entrega'] = floatval($config['custo_delivery_fixo']);
        }
        if (isset($config['custo_delivery_km'])) {
            $this->custoDelivery['custo_por_km'] = floatval($config['custo_delivery_km']);
        }

        // Atualizar gateway
        if (isset($config['custo_gateway'])) {
            $this->custoGateway['media_ponderada'] = floatval($config['custo_gateway']) / 100;
        }

        // Atualizar reserva para problemas
        if (isset($config['reserva_problemas'])) {
            $this->reservaProblemas['total'] = floatval($config['reserva_problemas']) / 100;
        }
    }

    /**
     * Carrega configuração do banco de dados para um mercado
     */
    public function carregarConfigMercado($partner_id) {
        $stmt = $this->conn->prepare("SELECT * FROM om_market_pricing_config WHERE partner_id = ? AND ativo = 1");
        $stmt->bind_param("i", $partner_id);
        $stmt->execute();
        $config = $stmt->get_result()->fetch_assoc();

        if ($config) {
            $this->setConfigMercado($config);
        }

        return $config;
    }

    // ═══════════════════════════════════════════════════════════════════════════════════
    // 6. MÉTODO PRINCIPAL: CALCULAR PREÇO
    // ═══════════════════════════════════════════════════════════════════════════════════
    
    /**
     * Calcula o preço final de venda considerando TODOS os custos
     * 
     * @param float $precoCusto - Preço que o mercado parceiro cobra
     * @param string $categoria - Categoria do produto
     * @param string $tipoMembro - Tipo de membro (diamond, gold, silver, bronze, normal)
     * @param float $distanciaKm - Distância da entrega (para diluição do frete)
     * @param float $valorPedidoEstimado - Valor estimado do pedido total
     * @return array
     */
    public function calcularPrecoCompleto(
        float $precoCusto, 
        string $categoria = 'default',
        ?string $tipoMembro = 'normal',
        float $distanciaKm = 3.5,
        float $valorPedidoEstimado = 150.00
    ): array {
        
        // 1. Obter configurações da categoria
        $configCategoria = $this->getConfigCategoria($categoria);
        
        // 2. Calcular cada componente de custo
        $custos = $this->calcularTodosCustos($precoCusto, $configCategoria, $distanciaKm, $valorPedidoEstimado);
        
        // 3. Calcular margem de lucro
        $margemLucro = $this->calcularMargemLucro($precoCusto, $configCategoria);
        
        // 4. Calcular margem total
        $margemTotal = $this->calcularMargemTotal($custos, $margemLucro);
        
        // 5. Aplicar limites de segurança
        $margemTotal = $this->aplicarLimitesSeguranca($margemTotal, $configCategoria);
        
        // 6. Calcular preço bruto
        $precoBruto = $precoCusto * (1 + $margemTotal);
        
        // 7. Aplicar desconto de membro (se aplicável)
        $descontoMembro = $this->calcularDescontoMembro($tipoMembro);
        $precoComDesconto = $precoBruto * (1 - $descontoMembro);
        
        // 8. Arredondamento psicológico
        $precoFinal = $this->arredondamentoPsicologico($precoComDesconto);
        
        // 9. Validação final (garantir lucro mínimo)
        $precoFinal = $this->validarLucroMinimo($precoFinal, $precoCusto);
        
        // 10. Calcular métricas finais
        $lucroEstimado = $precoFinal - $precoCusto;
        $margemReal = ($lucroEstimado / $precoCusto) * 100;
        
        return [
            'preco_custo' => round($precoCusto, 2),
            'preco_final' => round($precoFinal, 2),
            'margem_aplicada' => round($margemReal, 2),
            'lucro_estimado' => round($lucroEstimado, 2),
            'categoria' => $categoria,
            'tipo_membro' => $tipoMembro,
            'desconto_membro' => round($descontoMembro * 100, 1) . '%',
            
            // Detalhamento dos custos
            'custos_detalhados' => [
                'margem_categoria' => round($configCategoria['margem'] * 100, 1) . '%',
                'custo_shopper' => round($custos['shopper'] * 100, 1) . '%',
                'custo_delivery' => round($custos['delivery'] * 100, 1) . '%',
                'custo_gateway' => round($custos['gateway'] * 100, 1) . '%',
                'custo_embalagem' => round($custos['embalagem'] * 100, 1) . '%',
                'reserva_problemas' => round($custos['reserva'] * 100, 1) . '%',
                'reserva_spoilage' => round($configCategoria['spoilage'] * 100, 1) . '%',
                'custos_fixos' => round($custos['fixos'] * 100, 1) . '%',
                'margem_lucro' => round($margemLucro * 100, 1) . '%',
                'margem_total_bruta' => round($margemTotal * 100, 1) . '%'
            ],
            
            // Alertas
            'alertas' => $this->gerarAlertas($margemReal, $lucroEstimado, $configCategoria)
        ];
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════════
    // 7. MÉTODOS AUXILIARES
    // ═══════════════════════════════════════════════════════════════════════════════════
    
    /**
     * Obtém configuração da categoria
     */
    private function getConfigCategoria(string $categoria): array {
        $cat = strtolower(trim($categoria));
        
        // Mapeamento de sinônimos
        $mapa = [
            'pães' => 'paes',
            'açougue' => 'acougue',
            'laticínios' => 'laticinios',
            'grãos' => 'graos',
            'feijão' => 'feijao',
            'café' => 'cafe',
            'chás' => 'chas',
            'açúcar' => 'acucar',
            'bebê' => 'bebe',
            'óleos' => 'oleos'
        ];
        
        if (isset($mapa[$cat])) {
            $cat = $mapa[$cat];
        }
        
        // Buscar correspondência parcial
        foreach ($this->margensCategorias as $key => $config) {
            if (strpos($cat, $key) !== false || strpos($key, $cat) !== false) {
                return $config;
            }
        }
        
        return $this->margensCategorias['default'];
    }
    
    /**
     * Calcula todos os custos operacionais como percentual do preço
     */
    private function calcularTodosCustos(
        float $precoCusto, 
        array $configCategoria,
        float $distanciaKm,
        float $valorPedido
    ): array {
        
        // Número estimado de itens no pedido
        $itensEstimados = max(1, $valorPedido / max(1, $precoCusto));
        
        // Custo do Shopper (diluído por produto)
        $custoShopperPedido = $this->custoShopper['fixo_por_pedido'] + 
                             ($valorPedido * $this->custoShopper['percentual_valor']);
        $custoShopperPorProduto = $custoShopperPedido / $itensEstimados;
        $percentShopper = $custoShopperPorProduto / $precoCusto;
        
        // Custo do Delivery (diluído por produto)
        $custoDeliveryPedido = $this->custoDelivery['fixo_por_entrega'] + 
                              ($distanciaKm * $this->custoDelivery['custo_por_km']);
        $custoDeliveryPorProduto = $custoDeliveryPedido / $itensEstimados;
        $percentDelivery = $custoDeliveryPorProduto / $precoCusto;
        
        // Gateway de pagamento
        $percentGateway = $this->custoGateway['media_ponderada'];
        
        // Embalagem
        $custoEmbalagemPorProduto = $this->custoEmbalagem['media_por_pedido'] / $itensEstimados;
        $percentEmbalagem = $custoEmbalagemPorProduto / $precoCusto;
        
        // Reserva para problemas
        $percentReserva = $this->reservaProblemas['total'];
        
        // Custos fixos mensais (diluídos)
        $custosFixosPorPedido = $this->custosFixosMensais['total'] / $this->metaPedidosMensal;
        $custosFixosPorProduto = $custosFixosPorPedido / $itensEstimados;
        $percentFixos = $custosFixosPorProduto / $precoCusto;
        
        // Limitar percentuais extremos (produtos muito baratos)
        $percentShopper = min(0.08, $percentShopper);
        $percentDelivery = min(0.08, $percentDelivery);
        $percentEmbalagem = min(0.05, $percentEmbalagem);
        $percentFixos = min(0.05, $percentFixos);
        
        return [
            'shopper' => $percentShopper,
            'delivery' => $percentDelivery,
            'gateway' => $percentGateway,
            'embalagem' => $percentEmbalagem,
            'reserva' => $percentReserva,
            'fixos' => $percentFixos
        ];
    }
    
    /**
     * Calcula margem de lucro desejada
     */
    private function calcularMargemLucro(float $precoCusto, array $configCategoria): float {
        // Margem base: 5%
        $margemBase = 0.05;
        
        // Ajuste por faixa de preço (produtos baratos precisam margem maior em %)
        if ($precoCusto < 5) {
            $ajustePreco = 0.04;
        } elseif ($precoCusto < 10) {
            $ajustePreco = 0.03;
        } elseif ($precoCusto < 20) {
            $ajustePreco = 0.02;
        } elseif ($precoCusto < 50) {
            $ajustePreco = 0;
        } else {
            $ajustePreco = -0.01; // Produtos caros: margem menor
        }
        
        // Ajuste por risco da categoria
        $ajusteRisco = 0;
        switch ($configCategoria['risco'] ?? 'medio') {
            case 'muito_alto': $ajusteRisco = 0.02; break;
            case 'alto': $ajusteRisco = 0.01; break;
            case 'medio': $ajusteRisco = 0; break;
            case 'baixo': $ajusteRisco = -0.005; break;
            case 'muito_baixo': $ajusteRisco = -0.01; break;
        }
        
        return $margemBase + $ajustePreco + $ajusteRisco;
    }
    
    /**
     * Calcula margem total somando todos os componentes
     */
    private function calcularMargemTotal(array $custos, float $margemLucro): float {
        return 
            $custos['shopper'] +
            $custos['delivery'] +
            $custos['gateway'] +
            $custos['embalagem'] +
            $custos['reserva'] +
            $custos['fixos'] +
            $margemLucro;
    }
    
    /**
     * Aplica limites de segurança
     */
    private function aplicarLimitesSeguranca(float $margem, array $configCategoria): float {
        // Adicionar margem da categoria
        $margemComCategoria = $margem + $configCategoria['margem'];
        
        // Adicionar reserva de spoilage
        $margemComSpoilage = $margemComCategoria + ($configCategoria['spoilage'] ?? 0);
        
        // Aplicar limites
        $margemFinal = max(
            $this->limitesSeguranca['margem_minima_percent'],
            min($this->limitesSeguranca['margem_maxima_percent'], $margemComSpoilage)
        );
        
        return $margemFinal;
    }
    
    /**
     * Calcula desconto do membro
     */
    private function calcularDescontoMembro(?string $tipoMembro): float {
        $tipo = strtolower($tipoMembro ?? 'normal');
        return $this->descontosMembros[$tipo]['desconto'] ?? 0;
    }
    
    /**
     * Arredondamento psicológico
     */
    private function arredondamentoPsicologico(float $preco): float {
        if ($preco < 3) {
            // Produtos até R$ 3: manter centavos mas arredondar para .X9
            $base = floor($preco);
            $decimal = $preco - $base;
            if ($decimal < 0.20) return $base + 0.19;
            if ($decimal < 0.50) return $base + 0.49;
            if ($decimal < 0.80) return $base + 0.79;
            return $base + 0.99;
        } elseif ($preco < 10) {
            // R$ 3-10: terminar em .49 ou .99
            $base = floor($preco);
            $decimal = $preco - $base;
            if ($decimal < 0.50) return $base + 0.49;
            return $base + 0.99;
        } elseif ($preco < 50) {
            // R$ 10-50: terminar em .90 ou .99
            return floor($preco) + 0.99;
        } elseif ($preco < 100) {
            // R$ 50-100: terminar em .90
            return floor($preco) + 0.90;
        } else {
            // > R$ 100: arredondar para inteiro - 0.10
            return round($preco) - 0.10;
        }
    }
    
    /**
     * Valida e ajusta para garantir lucro mínimo
     */
    private function validarLucroMinimo(float $precoFinal, float $precoCusto): float {
        $lucro = $precoFinal - $precoCusto;
        $margemReal = $lucro / $precoCusto;
        
        // Verificar lucro mínimo em reais
        if ($lucro < $this->limitesSeguranca['lucro_minimo_reais']) {
            $precoAjustado = $precoCusto + $this->limitesSeguranca['lucro_minimo_reais'];
            return $this->arredondamentoPsicologico($precoAjustado);
        }
        
        // Verificar margem mínima percentual
        if ($margemReal < $this->limitesSeguranca['margem_minima_percent']) {
            $precoAjustado = $precoCusto * (1 + $this->limitesSeguranca['margem_minima_percent']);
            return $this->arredondamentoPsicologico($precoAjustado);
        }
        
        return $precoFinal;
    }
    
    /**
     * Gera alertas sobre o preço calculado
     */
    private function gerarAlertas(float $margemReal, float $lucro, array $configCategoria): array {
        $alertas = [];
        
        if ($margemReal < $this->limitesSeguranca['alerta_margem_baixa'] * 100) {
            $alertas[] = "⚠️ Margem baixa ({$margemReal}%) - abaixo do ideal (22%)";
        }
        
        if ($lucro < 1.00) {
            $alertas[] = "⚠️ Lucro baixo (R$ " . number_format($lucro, 2, ',', '.') . ") - considere ajustar";
        }
        
        if ($configCategoria['risco'] === 'muito_alto') {
            $alertas[] = "ℹ️ Categoria de alto risco - margem inclui reserva para perdas";
        }
        
        if (empty($alertas)) {
            $alertas[] = "✅ Preço dentro dos parâmetros ideais";
        }
        
        return $alertas;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════════
    // 8. PROCESSAMENTO EM LOTE
    // ═══════════════════════════════════════════════════════════════════════════════════
    
    /**
     * Processa todos os produtos de um mercado parceiro
     */
    public function processarMercado(int $partner_id): array {
        // Carregar configuração personalizada do mercado (se existir)
        $this->carregarConfigMercado($partner_id);

        $resultado = [
            'partner_id' => $partner_id,
            'inicio' => date('Y-m-d H:i:s'),
            'total_produtos' => 0,
            'processados' => 0,
            'atualizados' => 0,
            'erros' => 0,
            'margem_media' => 0,
            'lucro_total_estimado' => 0,
            'produtos' => []
        ];
        
        // Criar tabela se não existir
        $this->criarTabela();
        
        // Buscar produtos
        $sql = "
            SELECT
                pp.id,
                pp.product_id,
                pp.partner_id,
                pp.price as preco_custo,
                pb.name,
                pb.category_id,
                c.name as categoria_nome
            FROM om_market_products_price pp
            JOIN om_market_products_base pb ON pp.product_id = pb.product_id
            LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
            WHERE pp.partner_id = ?
            AND pp.status = 1
            AND pp.price > 0
            ORDER BY pp.product_id
        ";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $resultado['erros']++;
            $resultado['erro_sql'] = $this->conn->error;
            return $resultado;
        }
        
        $stmt->bind_param("i", $partner_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $somaMargens = 0;
        
        while ($produto = $res->fetch_assoc()) {
            $resultado['total_produtos']++;
            
            try {
                // Usar preço do mercado como custo base
                $precoCusto = floatval($produto['preco_custo']);
                
                // Mapear categoria
                $categoria = $this->mapearCategoria($produto['categoria_nome'] ?? '');
                
                // Calcular preço
                $calculo = $this->calcularPrecoCompleto($precoCusto, $categoria);
                
                // Salvar
                $salvou = $this->salvarPrecoIA(
                    $produto['product_id'],
                    $partner_id,
                    $precoCusto,
                    $calculo['preco_final'],
                    $calculo['margem_aplicada'],
                    $calculo['lucro_estimado'],
                    $categoria
                );
                
                if ($salvou) {
                    $resultado['atualizados']++;
                    $resultado['processados']++;
                    $somaMargens += $calculo['margem_aplicada'];
                    $resultado['lucro_total_estimado'] += $calculo['lucro_estimado'];
                } else {
                    $resultado['erros']++;
                }
                
                // Guardar amostra (primeiros 30)
                if (count($resultado['produtos']) < 30) {
                    $resultado['produtos'][] = [
                        'id' => $produto['product_id'],
                        'nome' => $produto['name'],
                        'categoria' => $categoria,
                        'custo' => $precoCusto,
                        'preco_final' => $calculo['preco_final'],
                        'margem' => $calculo['margem_aplicada'],
                        'lucro' => $calculo['lucro_estimado'],
                        'alertas' => $calculo['alertas']
                    ];
                }
                
            } catch (Exception $e) {
                $resultado['erros']++;
            }
        }
        
        // Calcular média
        if ($resultado['processados'] > 0) {
            $resultado['margem_media'] = round($somaMargens / $resultado['processados'], 2);
        }
        
        $resultado['fim'] = date('Y-m-d H:i:s');
        
        return $resultado;
    }
    
    /**
     * Mapeia nome de categoria para chave
     */
    private function mapearCategoria(string $nome): string {
        $nome = strtolower(trim($nome));
        
        foreach (array_keys($this->margensCategorias) as $key) {
            if (strpos($nome, $key) !== false) {
                return $key;
            }
        }
        
        // Mapeamentos específicos
        $mapa = [
            'pão' => 'padaria',
            'bolo' => 'bolos',
            'carne' => 'carnes',
            'frango' => 'aves',
            'peixe' => 'peixes',
            'fruta' => 'frutas',
            'verdura' => 'verduras',
            'legume' => 'legumes',
            'leite' => 'laticinios',
            'queijo' => 'queijos',
            'iogurt' => 'iogurtes',
            'cervej' => 'cervejas',
            'vinho' => 'vinhos',
            'refrig' => 'refrigerantes',
            'suco' => 'sucos',
            'água' => 'agua',
            'arroz' => 'arroz',
            'feij' => 'feijao',
            'massa' => 'massas',
            'biscoit' => 'biscoitos',
            'chocolate' => 'chocolates',
            'café' => 'cafe',
            'limp' => 'limpeza',
            'higien' => 'higiene',
            'pet' => 'pet_shop',
            'bebê' => 'bebe',
            'frald' => 'fraldas'
        ];
        
        foreach ($mapa as $partial => $key) {
            if (strpos($nome, $partial) !== false) {
                return $key;
            }
        }
        
        return 'default';
    }
    
    /**
     * Salva preço calculado na tabela
     */
    private function salvarPrecoIA(
        int $product_id,
        int $partner_id,
        float $precoCusto,
        float $precoFinal,
        float $margem,
        float $lucro,
        string $categoria
    ): bool {
        
        $now = date('Y-m-d H:i:s');
        
        // Verificar se existe
        $sql = "SELECT id FROM om_market_products_sale WHERE product_id = ? AND partner_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $product_id, $partner_id);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        
        if ($exists) {
            $sql = "
                UPDATE om_market_products_sale SET
                    cost_price = ?,
                    sale_price = ?,
                    margin_percent = ?,
                    profit_estimated = ?,
                    category_key = ?,
                    calculated_at = ?,
                    status = 1
                WHERE product_id = ? AND partner_id = ?
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ddddssii", 
                $precoCusto, $precoFinal, $margem, $lucro, $categoria, $now,
                $product_id, $partner_id
            );
        } else {
            $sql = "
                INSERT INTO om_market_products_sale 
                (product_id, partner_id, cost_price, sale_price, margin_percent, profit_estimated, category_key, calculated_at, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iiddddss", 
                $product_id, $partner_id, $precoCusto, $precoFinal, $margem, $lucro, $categoria, $now
            );
        }
        
        return $stmt->execute();
    }
    
    /**
     * Cria tabela de preços calculados
     */
    public function criarTabela(): bool {
        $sql = "
            CREATE TABLE IF NOT EXISTS om_market_products_sale (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                partner_id INT NOT NULL,
                cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
                sale_price DECIMAL(10,2) NOT NULL,
                margin_percent DECIMAL(5,2) DEFAULT 0,
                profit_estimated DECIMAL(10,2) DEFAULT 0,
                category_key VARCHAR(50) DEFAULT 'default',
                calculated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status TINYINT(1) DEFAULT 1,
                UNIQUE KEY unique_product_partner (product_id, partner_id),
                INDEX idx_partner (partner_id),
                INDEX idx_status (status),
                INDEX idx_category (category_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        return $this->conn->query($sql);
    }
    
    /**
     * Retorna configurações atuais do sistema
     */
    public function getConfiguracoes(): array {
        return [
            'custos_shopper' => $this->custoShopper,
            'custos_delivery' => $this->custoDelivery,
            'custos_gateway' => $this->custoGateway,
            'custos_embalagem' => $this->custoEmbalagem,
            'reserva_problemas' => $this->reservaProblemas,
            'custos_fixos_mensais' => $this->custosFixosMensais,
            'meta_pedidos_mensal' => $this->metaPedidosMensal,
            'descontos_membros' => $this->descontosMembros,
            'limites_seguranca' => $this->limitesSeguranca,
            'total_categorias' => count($this->margensCategorias)
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════════════════════════
// EXECUÇÃO (CLI ou Web)
// ═══════════════════════════════════════════════════════════════════════════════════════

if (php_sapi_name() === 'cli' || isset($_GET['run'])) {
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'>
          <title>OneMundo - IA Precificação</title>
          <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; margin: 20px; background: #f5f5f5; }
            .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
            h2 { color: #34495e; margin-top: 30px; }
            table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
            th { background: #3498db; color: white; }
            tr:nth-child(even) { background: #f9f9f9; }
            .success { color: #27ae60; font-weight: bold; }
            .warning { color: #f39c12; }
            .error { color: #e74c3c; }
            .box { background: #ecf0f1; padding: 15px; border-radius: 5px; margin: 10px 0; }
            .stat { display: inline-block; padding: 10px 20px; margin: 5px; background: #3498db; color: white; border-radius: 5px; }
          </style>
          </head><body><div class='container'>";
    
    echo "<h1>🧠 OneMundo - IA de Precificação Inteligente v2.0</h1>";
    
    $ia = new PrecificacaoInteligente();
    
    // Mostrar configurações
    echo "<h2>⚙️ Configurações do Sistema</h2>";
    $config = $ia->getConfiguracoes();
    
    echo "<div class='box'>";
    echo "<div class='stat'>📦 Shopper: R$ {$config['custos_shopper']['fixo_por_pedido']} + {$config['custos_shopper']['percentual_valor']}%</div>";
    echo "<div class='stat'>🏍️ Delivery: R$ {$config['custos_delivery']['fixo_por_entrega']} + R$ {$config['custos_delivery']['custo_por_km']}/km</div>";
    echo "<div class='stat'>💳 Gateway: " . ($config['custos_gateway']['media_ponderada'] * 100) . "%</div>";
    echo "<div class='stat'>⚠️ Reserva: " . ($config['reserva_problemas']['total'] * 100) . "%</div>";
    echo "<div class='stat'>🏢 Fixos/mês: R$ " . number_format($config['custos_fixos_mensais']['total'], 2, ',', '.') . "</div>";
    echo "<div class='stat'>📊 Categorias: {$config['total_categorias']}</div>";
    echo "</div>";
    
    // Teste com exemplos
    echo "<h2>🧪 Teste de Cálculo (Exemplos)</h2>";
    echo "<table>";
    echo "<tr><th>Produto</th><th>Custo</th><th>Categoria</th><th>Preço Final</th><th>Margem</th><th>Lucro</th><th>Status</th></tr>";
    
    $exemplos = [
        ['Arroz 5kg', 25.00, 'arroz'],
        ['Banana 1kg', 4.00, 'frutas'],
        ['Cerveja 350ml', 3.50, 'cervejas'],
        ['Whisky 1L', 120.00, 'destilados'],
        ['Pão Francês kg', 12.00, 'padaria'],
        ['Picanha kg', 65.00, 'carnes'],
        ['Leite 1L', 5.50, 'leite'],
        ['Detergente 500ml', 2.50, 'limpeza'],
        ['Alface unid', 3.00, 'verduras'],
        ['Chocolate 100g', 8.00, 'chocolates']
    ];
    
    foreach ($exemplos as $ex) {
        $calc = $ia->calcularPrecoCompleto($ex[1], $ex[2]);
        $status = $calc['margem_aplicada'] >= 22 ? 
                  "<span class='success'>✅ OK</span>" : 
                  "<span class='warning'>⚠️ Baixa</span>";
        
        echo "<tr>";
        echo "<td><strong>{$ex[0]}</strong></td>";
        echo "<td>R$ " . number_format($ex[1], 2, ',', '.') . "</td>";
        echo "<td>{$ex[2]}</td>";
        echo "<td><strong>R$ " . number_format($calc['preco_final'], 2, ',', '.') . "</strong></td>";
        echo "<td>{$calc['margem_aplicada']}%</td>";
        echo "<td>R$ " . number_format($calc['lucro_estimado'], 2, ',', '.') . "</td>";
        echo "<td>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Processar mercado se solicitado
    $partner_id = isset($_GET['partner']) ? (int)$_GET['partner'] : 0;
    
    if ($partner_id > 0) {
        echo "<h2>🏪 Processando Mercado #{$partner_id}</h2>";
        
        $resultado = $ia->processarMercado($partner_id);
        
        echo "<div class='box'>";
        echo "<div class='stat'>📦 Total: {$resultado['total_produtos']}</div>";
        echo "<div class='stat success'>✅ Atualizados: {$resultado['atualizados']}</div>";
        echo "<div class='stat error'>❌ Erros: {$resultado['erros']}</div>";
        echo "<div class='stat'>📊 Margem Média: {$resultado['margem_media']}%</div>";
        echo "<div class='stat'>💰 Lucro Estimado: R$ " . number_format($resultado['lucro_total_estimado'], 2, ',', '.') . "</div>";
        echo "</div>";
        
        if (!empty($resultado['produtos'])) {
            echo "<h3>Amostra de Produtos Processados</h3>";
            echo "<table>";
            echo "<tr><th>ID</th><th>Produto</th><th>Categoria</th><th>Custo</th><th>Final</th><th>Margem</th><th>Lucro</th></tr>";
            
            foreach ($resultado['produtos'] as $p) {
                echo "<tr>";
                echo "<td>{$p['id']}</td>";
                echo "<td>" . htmlspecialchars(substr($p['nome'], 0, 35)) . "</td>";
                echo "<td>{$p['categoria']}</td>";
                echo "<td>R$ " . number_format($p['custo'], 2, ',', '.') . "</td>";
                echo "<td><strong>R$ " . number_format($p['preco_final'], 2, ',', '.') . "</strong></td>";
                echo "<td>{$p['margem']}%</td>";
                echo "<td>R$ " . number_format($p['lucro'], 2, ',', '.') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<div class='box'>";
        echo "<p>Para processar um mercado, adicione <code>?partner=ID</code> na URL</p>";
        echo "<p>Exemplo: <a href='?run=1&partner=100'>?run=1&partner=100</a></p>";
        echo "</div>";
    }
    
    echo "</div></body></html>";
}
?>
