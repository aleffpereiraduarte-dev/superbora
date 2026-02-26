<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO STATUS VALIDATOR
 * Validação de transições de estado para pedidos e saques
 * ══════════════════════════════════════════════════════════════════════════════
 */

class OmStatusValidator {
    private static $instance = null;

    // Transições válidas para pedidos do mercado (PT + EN)
    // Suporta fluxo mercado (shopper) E restaurante/farmacia (parceiro prepara)
    private const ORDER_TRANSITIONS = [
        // Portuguese statuses - fluxo mercado (shopper)
        'pendente' => ['aceito', 'cancelado'],
        'aceito' => ['coletando', 'preparando', 'cancelado'],
        'coletando' => ['coleta_finalizada', 'cancelado'],
        'coleta_finalizada' => ['em_entrega', 'cancelado'],
        // Fluxo restaurante/farmacia/loja (parceiro prepara)
        'preparando' => ['pronto', 'cancelado'],
        'pronto' => ['aguardando_entregador', 'em_entrega', 'cancelado'],
        'aguardando_entregador' => ['em_entrega', 'cancelado'],
        // Comum
        'em_entrega' => ['entregue', 'problema_entrega'],
        'problema_entrega' => ['em_entrega', 'cancelado', 'entregue'],
        'entregue' => [], // Estado final
        'cancelado' => [], // Estado final
        // English statuses (legacy) - map to same PT transitions
        'pending' => ['aceito', 'cancelado', 'confirmed'],
        'confirmed' => ['coletando', 'shopping', 'cancelado', 'aceito', 'preparando'],
        'shopping' => ['coleta_finalizada', 'purchased', 'cancelado', 'coletando'],
        'purchased' => ['em_entrega', 'delivering', 'cancelado', 'coleta_finalizada'],
        'delivering' => ['entregue', 'problema_entrega', 'cancelado'],
        'cancelled' => [], // Estado final
    ];

    // Map English status to Portuguese equivalent
    private const STATUS_EN_TO_PT = [
        'pending' => 'pendente',
        'confirmed' => 'aceito',
        'shopping' => 'coletando',
        'purchased' => 'coleta_finalizada',
        'delivering' => 'em_entrega',
        'delivered' => 'entregue',
        'cancelled' => 'cancelado',
    ];

    // Transições válidas para saques
    private const SAQUE_TRANSITIONS = [
        'solicitado' => ['em_analise', 'aprovado', 'rejeitado'],
        'em_analise' => ['aprovado', 'rejeitado'],
        'aprovado' => ['processando', 'rejeitado'],
        'processando' => ['pago', 'falhou'],
        'pago' => [],      // Estado final
        'rejeitado' => [], // Estado final
        'falhou' => ['processando', 'rejeitado'] // Pode tentar novamente
    ];

    // Transições válidas para cadastro de shopper
    private const SHOPPER_STATUS_TRANSITIONS = [
        0 => [1, 2],     // pendente -> aprovado, rejeitado
        1 => [3],        // aprovado -> suspenso
        2 => [0],        // rejeitado -> pendente (pode reenviar)
        3 => [1, 2]      // suspenso -> aprovado, rejeitado
    ];

    // Transições válidas para items de pedido
    private const ITEM_TRANSITIONS = [
        'pendente' => ['coletado', 'indisponivel', 'substituido'],
        'coletado' => [],
        'indisponivel' => ['substituido'],
        'substituido' => ['coletado']
    ];

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Verifica se uma transição de status de pedido é válida
     */
    public function isValidOrderTransition(string $currentStatus, string $newStatus): bool {
        $currentStatus = $this->normalizeStatus($currentStatus);
        $newStatus = $this->normalizeStatus($newStatus);
        $allowedTransitions = self::ORDER_TRANSITIONS[$currentStatus] ?? [];
        return in_array($newStatus, $allowedTransitions);
    }

    /**
     * Verifica se uma transição de status de saque é válida
     */
    public function isValidSaqueTransition(string $currentStatus, string $newStatus): bool {
        $allowedTransitions = self::SAQUE_TRANSITIONS[$currentStatus] ?? [];
        return in_array($newStatus, $allowedTransitions);
    }

    /**
     * Verifica se uma transição de status de shopper é válida
     */
    public function isValidShopperTransition(int $currentStatus, int $newStatus): bool {
        $allowedTransitions = self::SHOPPER_STATUS_TRANSITIONS[$currentStatus] ?? [];
        return in_array($newStatus, $allowedTransitions);
    }

    /**
     * Verifica se uma transição de status de item é válida
     */
    public function isValidItemTransition(string $currentStatus, string $newStatus): bool {
        $allowedTransitions = self::ITEM_TRANSITIONS[$currentStatus] ?? [];
        return in_array($newStatus, $allowedTransitions);
    }

    /**
     * Retorna as transições permitidas para um status de pedido
     */
    public function getAllowedOrderTransitions(string $currentStatus): array {
        $currentStatus = $this->normalizeStatus($currentStatus);
        return self::ORDER_TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * Retorna as transições permitidas para um status de saque
     */
    public function getAllowedSaqueTransitions(string $currentStatus): array {
        return self::SAQUE_TRANSITIONS[$currentStatus] ?? [];
    }

    /**
     * Valida e lança exceção se transição inválida
     */
    public function validateOrderTransition(string $currentStatus, string $newStatus): void {
        $currentStatus = $this->normalizeStatus($currentStatus);
        $newStatus = $this->normalizeStatus($newStatus);
        if (!$this->isValidOrderTransition($currentStatus, $newStatus)) {
            $allowed = implode(', ', $this->getAllowedOrderTransitions($currentStatus));
            throw new InvalidArgumentException(
                "Transição de status inválida: '$currentStatus' -> '$newStatus'. " .
                "Transições permitidas: $allowed"
            );
        }
    }

    /**
     * Valida e lança exceção se transição de saque inválida
     */
    public function validateSaqueTransition(string $currentStatus, string $newStatus): void {
        if (!$this->isValidSaqueTransition($currentStatus, $newStatus)) {
            $allowed = implode(', ', $this->getAllowedSaqueTransitions($currentStatus));
            throw new InvalidArgumentException(
                "Transição de status de saque inválida: '$currentStatus' -> '$newStatus'. " .
                "Transições permitidas: $allowed"
            );
        }
    }

    /**
     * Verifica se um status é final (não pode mais mudar)
     */
    public function isOrderFinalStatus(string $status): bool {
        return empty(self::ORDER_TRANSITIONS[$status] ?? ['placeholder']);
    }

    /**
     * Verifica se um status de saque é final
     */
    public function isSaqueFinalStatus(string $status): bool {
        return empty(self::SAQUE_TRANSITIONS[$status] ?? ['placeholder']);
    }

    /**
     * Normaliza status EN para PT (se aplicável)
     */
    public function normalizeStatus(string $status): string {
        return self::STATUS_EN_TO_PT[$status] ?? $status;
    }

    /**
     * Retorna descrição legível do status do pedido
     */
    public function getOrderStatusLabel(string $status): string {
        $labels = [
            // Fluxo mercado (shopper)
            'pendente' => 'Aguardando Confirmacao',
            'aceito' => 'Confirmado',
            'coletando' => 'Coletando no Mercado',
            'coleta_finalizada' => 'Coleta Finalizada',
            // Fluxo restaurante/farmacia/loja
            'preparando' => 'Preparando',
            'pronto' => 'Pronto para Retirada',
            'aguardando_entregador' => 'Aguardando Entregador',
            // Comum
            'em_entrega' => 'Em Entrega',
            'problema_entrega' => 'Problema na Entrega',
            'entregue' => 'Entregue',
            'cancelado' => 'Cancelado',
            // English labels (legacy)
            'pending' => 'Aguardando Confirmacao',
            'confirmed' => 'Confirmado',
            'shopping' => 'Comprando',
            'purchased' => 'Comprado',
            'delivering' => 'Entregando',
            'cancelled' => 'Cancelado',
        ];
        return $labels[$status] ?? $status;
    }

    /**
     * Retorna descrição legível do status do saque
     */
    public function getSaqueStatusLabel(string $status): string {
        $labels = [
            'solicitado' => 'Solicitado',
            'em_analise' => 'Em Análise',
            'aprovado' => 'Aprovado',
            'processando' => 'Processando Pagamento',
            'pago' => 'Pago',
            'rejeitado' => 'Rejeitado',
            'falhou' => 'Falhou - Aguardando Retry'
        ];
        return $labels[$status] ?? $status;
    }

    /**
     * Retorna descrição legível do status do shopper
     */
    public function getShopperStatusLabel(int $status): string {
        $labels = [
            0 => 'Pendente de Aprovação',
            1 => 'Aprovado',
            2 => 'Rejeitado',
            3 => 'Suspenso'
        ];
        return $labels[$status] ?? 'Desconhecido';
    }
}

/**
 * Helper global
 */
function om_status(): OmStatusValidator {
    return OmStatusValidator::getInstance();
}
