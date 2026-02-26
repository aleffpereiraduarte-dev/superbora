<?php
/**
 * 🎁 BADGES DE BENEFÍCIOS DO VENDEDOR
 */

function renderBeneficiosBadges($beneficios) {
    if (empty($beneficios)) return '';
    
    $html = '<div class="seller-benefits">';
    
    if (!empty($beneficios['installments_enabled']) && ($beneficios['max_installments'] ?? 1) > 1) {
        $html .= '<span class="benefit-badge installments">💳 ' . $beneficios['max_installments'] . 'x sem juros</span>';
    }
    
    if (!empty($beneficios['free_shipping_enabled'])) {
        $min = $beneficios['free_shipping_min_value'] ?? 0;
        if ($min > 0) {
            $html .= '<span class="benefit-badge shipping">🚚 Grátis acima de R$ ' . number_format($min, 0, ',', '.') . '</span>';
        } else {
            $html .= '<span class="benefit-badge shipping">🚚 Frete grátis</span>';
        }
    }
    
    if (!empty($beneficios['pix_discount']) && $beneficios['pix_discount'] > 0) {
        $html .= '<span class="benefit-badge pix">📱 ' . (int)$beneficios['pix_discount'] . '% PIX</span>';
    }
    
    if (!empty($beneficios['cashback_enabled']) && ($beneficios['cashback_percent'] ?? 0) > 0) {
        $html .= '<span class="benefit-badge cashback">💰 ' . (int)$beneficios['cashback_percent'] . '% cashback</span>';
    }
    
    if (!empty($beneficios['first_purchase_discount']) && $beneficios['first_purchase_discount'] > 0) {
        $html .= '<span class="benefit-badge first">🎉 ' . (int)$beneficios['first_purchase_discount'] . '% 1ª compra</span>';
    }
    
    if (!empty($beneficios['satisfaction_guarantee'])) {
        $html .= '<span class="benefit-badge guarantee">✅ Satisfação garantida</span>';
    }
    
    $html .= '</div>';
    return $html;
}
?>
<style>
.seller-benefits {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 8px 0;
}
.benefit-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.benefit-badge.installments {
    background: #dbeafe;
    color: #1d4ed8;
}
.benefit-badge.shipping {
    background: #d1fae5;
    color: #059669;
}
.benefit-badge.pix {
    background: #ecfdf5;
    color: #047857;
}
.benefit-badge.cashback {
    background: #fef3c7;
    color: #d97706;
}
.benefit-badge.first {
    background: #fce7f3;
    color: #be185d;
}
.benefit-badge.guarantee {
    background: #f3e8ff;
    color: #7c3aed;
}
</style>