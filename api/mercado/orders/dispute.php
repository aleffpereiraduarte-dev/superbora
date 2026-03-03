<?php
/**
 * /api/mercado/orders/dispute.php
 *
 * Comprehensive dispute resolution system with auto-resolution engine.
 *
 * GET  ?order_id=X           - List disputes for an order
 * GET  ?dispute_id=X         - Get single dispute with timeline
 * POST { order_id, category, subcategory, description, affected_items?, photo_urls? } - Create dispute
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/guards.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once __DIR__ . '/../helpers/notify.php';
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';

setCorsHeaders();

// =================== DISPUTE RULES ===================
// category => subcategory => rules
// 150+ subcategories covering every real-life food delivery marketplace scenario
$DISPUTE_RULES = [
    'food' => [
        // --- Itens errados/faltando ---
        'wrong_items'           => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund+credit', 'credit_amount' => 5.00,  'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Itens errados'],
        'missing_items'         => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Itens faltando'],
        'wrong_order'           => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 5.00,  'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Pedido trocado inteiro'],
        'someone_elses_order'   => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'full_refund+credit',  'credit_amount' => 5.00,  'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Recebi pedido de outra pessoa'],
        'incomplete_order'      => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Pedido incompleto'],
        'missing_drinks'        => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Bebida faltando'],
        'missing_condiments'    => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00,  'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Faltou molho/talheres/guardanapo'],
        'missing_sides'         => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Acompanhamento faltando'],
        'missing_ingredient'    => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'partial_refund',      'credit_amount' => 0,     'refund_pct' => 30,  'sla_hours' => 48, 'label' => 'Ingrediente faltando no prato'],
        // --- Qualidade/estado ---
        'damaged'               => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Danificado/derramado'],
        'cold'                  => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'partial_refund+credit','credit_amount' => 10.00, 'refund_pct' => 50,  'sla_hours' => 48, 'label' => 'Comida fria/gelada'],
        'raw'                   => ['severity' => 'critical', 'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'full_refund+credit',  'credit_amount' => 15.00, 'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Comida crua/mal cozida'],
        'overcooked'            => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'partial_refund',      'credit_amount' => 0,     'refund_pct' => 50,  'sla_hours' => 48, 'label' => 'Comida queimada/passada demais'],
        'expired'               => ['severity' => 'critical', 'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'full_refund+credit',  'credit_amount' => 20.00, 'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Alimento vencido/estragado'],
        'quality'               => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00,  'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Qualidade ruim'],
        'quantity'              => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'partial_refund',      'credit_amount' => 0,     'refund_pct' => 30,  'sla_hours' => 48, 'label' => 'Porcao menor que o anunciado'],
        'taste'                 => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00,  'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Sabor diferente do esperado'],
        'seasoning'             => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00,  'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Tempero excessivo ou insuficiente'],
        'photo_mismatch'        => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'partial_refund+credit','credit_amount' => 5.00, 'refund_pct' => 50,  'sla_hours' => 48, 'label' => 'Produto diferente da foto'],
        'wrong_temperature'     => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'partial_refund',      'credit_amount' => 0,     'refund_pct' => 50,  'sla_hours' => 48, 'label' => 'Temperatura errada (quente/gelado)'],
        'melted'                => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Sorvete/gelado derretido'],
        'spilled_drink'         => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Bebida derramada'],
        // --- Seguranca alimentar ---
        'tampered'              => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'full_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Embalagem violada'],
        'foreign_object'        => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'full_refund+credit',  'credit_amount' => 30.00, 'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Corpo estranho na comida'],
        'allergy'               => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Reacao alergica'],
        'food_poisoning'        => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 30.00, 'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Intoxicacao alimentar'],
        'allergen_not_listed'   => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 20.00, 'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Alergeno nao informado no cardapio'],
        'dietary_not_respected' => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 15.00, 'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Restricao alimentar nao respeitada'],
        // --- Instrucoes ---
        'instructions_ignored'  => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00,  'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Instrucoes especiais ignoradas'],
        // --- Embalagem ---
        'bad_packaging'         => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'credit',              'credit_amount' => 3.00,  'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Embalagem ruim/inadequada'],
        'leaked'                => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Embalagem vazando'],
        'crushed_packaging'     => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Embalagem amassada/destruida'],
        'mixed_items'           => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'partial_refund',      'credit_amount' => 0,     'refund_pct' => 50,  'sla_hours' => 48, 'label' => 'Itens misturados na embalagem'],
        // --- Mercado/Supermercado ---
        'grocery_substitution'  => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund',         'credit_amount' => 0,     'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Substituicao sem consentimento'],
        'grocery_wrong_weight'  => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'difference_refund',   'credit_amount' => 0,     'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Peso/quantidade diferente do cobrado'],
    ],
    'delivery' => [
        // --- Nao chegou / atraso ---
        'never_arrived'          => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Pedido nao chegou'],
        'marked_delivered'       => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 10.00,'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Marcado entregue mas nao recebido'],
        'very_late'              => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Muito atrasado (>30min)'],
        'late'                   => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Atrasado (15-30min)'],
        'weather_delay'          => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Atraso por chuva/clima'],
        'traffic_delay'          => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Atraso por transito'],
        'scheduled_wrong'        => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Agendamento nao respeitado'],
        'wrong_delivery_time'    => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Entregue em horario errado'],
        // --- Endereco / local ---
        'wrong_address'          => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Entregou no endereco errado'],
        'wrong_gate'             => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Deixou na portaria/predio errado'],
        'delivered_to_neighbor'  => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Entregou para vizinho'],
        'left_wrong_spot'        => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Deixou em local errado'],
        'left_on_ground'         => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Pedido deixado no chao'],
        'building_access'        => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Problema de acesso ao predio'],
        'left_no_notice'         => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Deixou sem avisar'],
        'cant_find_address'      => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Entregador nao encontrou endereco'],
        'didnt_wait'             => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Entregador nao esperou no local'],
        'order_abandoned'        => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 5.00, 'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Pedido abandonado pelo entregador'],
        // --- Motorista - comportamento ---
        'rude_driver'            => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Motorista rude/agressivo'],
        'driver_no_communication'=> ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Motorista sem comunicacao'],
        'driver_ate'             => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'full_refund+credit',  'credit_amount' => 10.00,'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Motorista comeu/abriu pedido'],
        'driver_cancelled'       => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Motorista cancelou sem motivo'],
        'driver_detour'          => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Motorista fez desvio longo'],
        'driver_asked_money'     => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 10.00,'refund_pct' => 0,   'sla_hours' => 12, 'label' => 'Motorista pediu dinheiro extra'],
        'driver_unsafe'          => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 12, 'label' => 'Preocupacao com seguranca'],
        'driver_contact'         => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Motorista contactou indevidamente'],
        'no_contact_driver'      => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Nao consigo contatar motorista'],
        'bad_vehicle'            => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Veiculo em mas condicoes'],
        'dirty_bag'              => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Bag/mochila suja do entregador'],
        // --- Dano no transporte ---
        'damaged_transport'      => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Danificado no transporte'],
        'no_thermal_bag'         => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Sem bag termica'],
        // --- Golpes / Fraude do entregador ---
        'driver_fake_delivery'   => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 10.00,'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Confirmou entrega mas nao entregou'],
        'delivered_wrong_person' => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Entregou para pessoa errada'],
        'driver_code_scam'       => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 15.00,'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Pediu codigo por chat/ligacao'],
        'driver_extra_charge'    => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 20.00,'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Golpe da maquininha/cobrou a mais'],
    ],
    'payment' => [
        'overcharged'              => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'difference_refund',   'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Valor diferente do app'],
        'double_charge'            => ['severity' => 'critical', 'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Cobranca em duplicata'],
        'charged_after_cancel'     => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Cobranca apos cancelamento'],
        'change_not_returned'      => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'difference_refund',   'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Troco nao devolvido (dinheiro)'],
        'pix_not_confirmed'        => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'PIX debitado mas nao confirmado'],
        'card_wrongly_declined'    => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Cartao recusado indevidamente'],
        'cashback_missing'         => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Cashback nao creditado'],
        'coupon_not_applied'       => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Cupom nao aplicado'],
        'coupon_expired_during'    => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Cupom expirou durante pedido'],
        'delivery_fee_wrong'       => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'difference_refund',   'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Taxa de entrega diferente'],
        'service_fee_undisclosed'  => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Taxa de servico nao informada'],
        'tip_unauthorized'         => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'difference_refund',   'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Gorjeta cobrada sem autorizar'],
        'refund_not_received'      => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Reembolso nao recebido'],
        'refund_partial_wrong'     => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'difference_refund',   'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Reembolso parcial incorreto'],
        'card_refund_slow'         => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Estorno no cartao demorando'],
        'price_changed'            => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'difference_refund',   'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Preco mudou apos o pedido'],
        'wrong_payment'            => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Cobrou no cartao/metodo errado'],
        'promo_not_honored'        => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'credit',              'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Promocao nao aplicada'],
        'points_missing'           => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'points',              'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Pontos nao computados'],
        'referral_missing'         => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Bonus de indicacao nao veio'],
        // --- Assinatura / Clube ---
        'subscription_charged'     => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Cobranca de assinatura nao autorizada'],
        'subscription_cancel'      => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Nao consigo cancelar assinatura'],
        'subscription_benefits'    => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Beneficios da assinatura nao aplicados'],
        // --- Propaganda ---
        'false_advertising'        => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'credit',              'credit_amount' => 10.00,'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Propaganda enganosa'],
        // --- Gorjeta ---
        'tip_not_delivered'        => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Gorjeta nao repassada ao motorista'],
    ],
    'store' => [
        'closed'                   => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Loja fechada mas aparece aberta'],
        'slow_prep'                => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Tempo de preparo muito longo'],
        'refused'                  => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Loja recusou pedido'],
        'store_cancelled'          => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 5.00, 'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Loja cancelou sem motivo'],
        'partial_items'            => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'item_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 48, 'label' => 'Item indisponivel apos pedido'],
        'substitution_no_consent'  => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Substituicao sem consentimento'],
        'store_different_price'    => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'difference_refund',   'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Preco diferente do cardapio'],
        'misleading_description'   => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'partial_refund+credit','credit_amount' => 5.00,'refund_pct' => 50,  'sla_hours' => 48, 'label' => 'Descricao enganosa do produto'],
        'photo_doesnt_match'       => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'partial_refund+credit','credit_amount' => 5.00,'refund_pct' => 50,  'sla_hours' => 48, 'label' => 'Foto do produto diferente da realidade'],
        'hygiene'                  => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Higiene duvidosa'],
        'rude_staff'               => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Atendimento grosseiro da loja'],
        'wrong_hours'              => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Horario de funcionamento errado'],
        'cancelled_no_notice'      => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 5.00, 'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Cancelaram sem aviso'],
        'long_wait_pickup'         => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Longa espera na retirada'],
        'menu_outdated'            => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Cardapio desatualizado no app'],
        'store_no_reply'           => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Loja nao respondeu mensagem'],
    ],
    'order' => [
        'duplicate_order'          => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Pedido duplicado acidental'],
        'wrong_store'              => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Pedido feito na loja errada'],
        'order_stuck'              => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 12, 'label' => 'Pedido preso em processamento'],
        'cant_cancel'              => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Nao consegui cancelar a tempo'],
        'want_change_address'      => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Quero mudar endereco de entrega'],
        'want_add_items'           => ['severity' => 'low',      'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Quero adicionar itens ao pedido'],
        'want_remove_items'        => ['severity' => 'low',      'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Quero remover itens do pedido'],
        'cant_track'               => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Nao consigo rastrear'],
        'cancelled_charged'        => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Cancelaram mas cobraram'],
        'wrong_status'             => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Status do pedido errado'],
        'no_notification'          => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Nao recebi notificacao'],
        'app_error'                => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'App travou durante checkout'],
        'checkout_error'           => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Erro ao finalizar pedido'],
        'gps_wrong'                => ['severity' => 'medium',   'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'GPS/localizacao errada'],
        'qr_code_broken'           => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'credit',              'credit_amount' => 3.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'QR code da mesa nao funciona'],
        'receipt_missing'          => ['severity' => 'low',      'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 72, 'label' => 'Nao recebi comprovante/nota'],
        'receipt_wrong'            => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Nota fiscal errada'],
        'support_no_response'      => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 12, 'label' => 'Suporte nao responde'],
        'support_closed'           => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 12, 'label' => 'Chamado fechado sem resolver'],
        'refund_denied'            => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Reembolso negado injustamente'],
        'payment_failed_order'     => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 12, 'label' => 'Pagamento falhou mas pedido feito'],
        // --- Grupo / Especial ---
        'group_wrong_item'         => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => true,  'compensation' => 'item_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 24, 'label' => 'Pedido grupo: item de participante errado'],
        'gift_recipient_absent'    => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Pedido presente: destinatario ausente'],
        'scheduled_wrong_time'     => ['severity' => 'high',     'auto_resolve' => true,  'needs_photo' => false, 'compensation' => 'credit',              'credit_amount' => 5.00, 'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Agendado: entregue no horario errado'],
        'corporate_wrong_invoice'  => ['severity' => 'medium',   'auto_resolve' => false, 'needs_photo' => true,  'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 48, 'label' => 'Pedido corporativo: nota fiscal errada'],
    ],
    'safety' => [
        'got_sick'                 => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 30.00,'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Passei mal apos comer'],
        'harassment'               => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Assedio do motorista/loja'],
        'driver_threat'            => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 2,  'label' => 'Ameaca do entregador'],
        'discrimination'           => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund+credit',  'credit_amount' => 20.00,'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Discriminacao'],
        'threat'                   => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 2,  'label' => 'Ameaca ou intimidacao'],
        'privacy_breach'           => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 4,  'label' => 'Dados pessoais expostos'],
        'driver_photographed'      => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 4,  'label' => 'Entregador fotografou endereco'],
        'account_hacked'           => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Conta hackeada/invadida'],
        'unauthorized_order'       => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Pedido nao autorizado'],
        'suspicious_charge'        => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Cobranca suspeita'],
        'data_concern'             => ['severity' => 'high',     'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'none',                'credit_amount' => 0,    'refund_pct' => 0,   'sla_hours' => 24, 'label' => 'Preocupacao com meus dados'],
        'accident'                 => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 2,  'label' => 'Acidente durante entrega'],
        'animal_attack'            => ['severity' => 'critical', 'auto_resolve' => false, 'needs_photo' => false, 'compensation' => 'full_refund',         'credit_amount' => 0,    'refund_pct' => 100, 'sla_hours' => 4,  'label' => 'Animal atacou entregador'],
    ],
];

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // Ensure tables exist
    ensureDisputeTables($db);

    $method = $_SERVER['REQUEST_METHOD'];

    // =================== GET: List/Get disputes ===================
    if ($method === 'GET') {
        $disputeId = (int)($_GET['dispute_id'] ?? 0);

        if ($disputeId) {
            // Single dispute with timeline
            $stmt = $db->prepare("
                SELECT d.*,
                       o.total as order_total_real
                FROM om_order_disputes d
                LEFT JOIN om_market_orders o ON o.order_id = d.order_id
                WHERE d.dispute_id = ? AND d.customer_id = ?
            ");
            $stmt->execute([$disputeId, $customerId]);
            $dispute = $stmt->fetch();
            if (!$dispute) response(false, null, "Disputa nao encontrada", 404);

            // Get timeline
            $stmtTl = $db->prepare("
                SELECT timeline_id, action, actor_type, actor_id, description, metadata, created_at
                FROM om_dispute_timeline
                WHERE dispute_id = ?
                ORDER BY created_at ASC
            ");
            $stmtTl->execute([$disputeId]);
            $timeline = $stmtTl->fetchAll();

            // Get evidence
            $stmtEv = $db->prepare("
                SELECT evidence_id, photo_url, caption, created_at
                FROM om_dispute_evidence
                WHERE dispute_id = ?
                ORDER BY created_at ASC
            ");
            $stmtEv->execute([$disputeId]);
            $evidence = $stmtEv->fetchAll();

            response(true, [
                'dispute' => formatDispute($dispute),
                'timeline' => array_map(function($t) {
                    return [
                        'id' => (int)$t['timeline_id'],
                        'action' => $t['action'],
                        'actor_type' => $t['actor_type'],
                        'description' => $t['description'],
                        'created_at' => $t['created_at'],
                    ];
                }, $timeline),
                'evidence' => array_map(function($e) {
                    return [
                        'id' => (int)$e['evidence_id'],
                        'photo_url' => $e['photo_url'],
                        'caption' => $e['caption'],
                        'created_at' => $e['created_at'],
                    ];
                }, $evidence),
            ]);
        }

        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) response(false, null, "order_id ou dispute_id obrigatorio", 400);

        // Verify order
        $stmtOrder = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmtOrder->execute([$orderId, $customerId]);
        if (!$stmtOrder->fetch()) response(false, null, "Pedido nao encontrado", 404);

        $stmt = $db->prepare("
            SELECT * FROM om_order_disputes
            WHERE order_id = ? AND customer_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$orderId, $customerId]);
        $disputes = $stmt->fetchAll();

        response(true, [
            'disputes' => array_map('formatDispute', $disputes),
            'total' => count($disputes),
        ]);
    }

    // =================== POST: Create dispute ===================
    if ($method === 'POST') {
        $input = getInput();
        $orderId     = (int)($input['order_id'] ?? 0);
        $category    = trim($input['category'] ?? '');
        $subcategory = trim($input['subcategory'] ?? '');
        $description = strip_tags(trim(substr($input['description'] ?? '', 0, 2000)));
        $affectedItems = $input['affected_items'] ?? [];
        $photoUrls   = $input['photo_urls'] ?? [];
        $preferredResolution = trim($input['preferred_resolution'] ?? '');

        // Validate preferred_resolution if provided
        $validResolutions = ['full_refund', 'partial_refund', 'credit', 'redelivery', 'talk_to_support'];
        if ($preferredResolution && !in_array($preferredResolution, $validResolutions)) {
            $preferredResolution = '';
        }

        if (!$orderId) response(false, null, "order_id obrigatorio", 400);
        if (!$category || !$subcategory) response(false, null, "Categoria e subcategoria obrigatorias", 400);

        // Validate category/subcategory
        global $DISPUTE_RULES;
        if (!isset($DISPUTE_RULES[$category][$subcategory])) {
            response(false, null, "Categoria/subcategoria invalida", 400);
        }

        $rule = $DISPUTE_RULES[$category][$subcategory];

        if (empty($description) || strlen($description) < 10) {
            response(false, null, "Descricao obrigatoria (min. 10 caracteres)", 400);
        }

        // Sanitize photo URLs
        $cleanPhotos = [];
        if (is_array($photoUrls)) {
            foreach (array_slice($photoUrls, 0, 5) as $url) {
                $url = filter_var(trim($url), FILTER_SANITIZE_URL);
                if ($url && (strpos($url, '/uploads/') === 0 || strpos($url, 'https://') === 0)) {
                    $cleanPhotos[] = $url;
                }
            }
        }

        // Sanitize affected items - look up REAL prices from order items, ignore client-provided prices
        $cleanItems = [];
        if (is_array($affectedItems)) {
            // Collect item IDs to look up real prices
            $requestedItemIds = [];
            $requestedItemsRaw = [];
            foreach (array_slice($affectedItems, 0, 50) as $item) {
                $itemId = (int)($item['item_id'] ?? $item['product_id'] ?? 0);
                if ($itemId > 0) {
                    $requestedItemIds[] = $itemId;
                    $requestedItemsRaw[$itemId] = $item;
                }
            }

            if (!empty($requestedItemIds)) {
                // Look up real prices from om_market_order_items
                $placeholders = implode(',', array_fill(0, count($requestedItemIds), '?'));
                $stmtRealPrices = $db->prepare("
                    SELECT item_id, product_name, name, price, quantity
                    FROM om_market_order_items
                    WHERE item_id IN ({$placeholders}) AND order_id = ?
                ");
                $params = $requestedItemIds;
                $params[] = $orderId;
                $stmtRealPrices->execute($params);
                $realItems = $stmtRealPrices->fetchAll();

                $realItemLookup = [];
                foreach ($realItems as $ri) {
                    $realItemLookup[(int)$ri['item_id']] = $ri;
                }

                foreach ($requestedItemIds as $itemId) {
                    if (!isset($realItemLookup[$itemId])) continue; // item not in this order
                    $realItem = $realItemLookup[$itemId];
                    $rawItem = $requestedItemsRaw[$itemId];
                    $requestedQty = max(1, (int)($rawItem['quantity'] ?? 1));
                    // Cap quantity at actual order item quantity
                    $maxQty = (int)$realItem['quantity'];
                    $qty = min($requestedQty, $maxQty);
                    if ($qty <= 0) $qty = 1;

                    $cleanItems[] = [
                        'item_id' => $itemId,
                        'name' => htmlspecialchars(substr($realItem['product_name'] ?: $realItem['name'] ?: '', 0, 200), ENT_QUOTES, 'UTF-8'),
                        'price' => (float)$realItem['price'], // REAL price from DB
                        'quantity' => $qty,
                    ];
                }
            }
        }

        // Get order info
        $stmtOrder = $db->prepare("
            SELECT o.order_id, o.status, o.total, o.subtotal, o.delivery_fee,
                   o.delivered_at, o.date_added, o.customer_id, o.partner_id, o.driver_id
            FROM om_market_orders o
            WHERE o.order_id = ? AND o.customer_id = ?
        ");
        $stmtOrder->execute([$orderId, $customerId]);
        $order = $stmtOrder->fetch();
        if (!$order) response(false, null, "Pedido nao encontrado", 404);

        // 7-day window
        $deliveredAt = $order['delivered_at'] ?: $order['date_added'];
        if ($deliveredAt) {
            $daysSinceDelivery = (time() - strtotime($deliveredAt)) / 86400;
            if ($daysSinceDelivery > 7) {
                response(false, null, "O prazo para disputas expirou (7 dias)", 400);
            }
        }

        // Check duplicate (same order + same subcategory within 24 hours)
        $stmtDup = $db->prepare("
            SELECT dispute_id FROM om_order_disputes
            WHERE order_id = ? AND customer_id = ? AND subcategory = ?
            AND created_at > NOW() - INTERVAL '24 hours'
        ");
        $stmtDup->execute([$orderId, $customerId, $subcategory]);
        if ($stmtDup->fetch()) {
            response(false, null, "Voce ja reportou este problema recentemente para este pedido.", 400);
        }

        // ---- Fraud check ----
        $stmtCount = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_order_disputes
            WHERE customer_id = ? AND created_at > NOW() - INTERVAL '30 days'
        ");
        $stmtCount->execute([$customerId]);
        $disputeCount30d = (int)$stmtCount->fetch()['cnt'];

        $stmtAutoCount = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_order_disputes
            WHERE customer_id = ? AND auto_resolved = 1 AND created_at > NOW() - INTERVAL '30 days'
        ");
        $stmtAutoCount->execute([$customerId]);
        $autoResolvedCount30d = (int)$stmtAutoCount->fetch()['cnt'];

        // Check account age
        $stmtAccount = $db->prepare("SELECT created_at FROM om_market_customers WHERE customer_id = ? LIMIT 1");
        $stmtAccount->execute([$customerId]);
        $accountRow = $stmtAccount->fetch();
        $accountAgeDays = $accountRow ? (time() - strtotime($accountRow['created_at'])) / 86400 : 999;

        $isSuspicious = false;
        $suspiciousReason = '';
        if ($autoResolvedCount30d >= 3) {
            $isSuspicious = true;
            $suspiciousReason = 'too_many_auto_resolutions';
        }
        if ($disputeCount30d >= 5) {
            $isSuspicious = true;
            $suspiciousReason = 'too_many_disputes';
        }
        if ($accountAgeDays < 7 && $disputeCount30d >= 2) {
            $isSuspicious = true;
            $suspiciousReason = 'new_account_high_disputes';
        }

        // ---- Calculate compensation ----
        $orderTotal = (float)$order['total'];
        $requestedAmount = 0;
        $compensationType = $rule['compensation'];

        if (strpos($compensationType, 'item_refund') !== false && !empty($cleanItems)) {
            foreach ($cleanItems as $it) {
                $requestedAmount += $it['price'] * $it['quantity'];
            }
        } elseif (strpos($compensationType, 'full_refund') !== false) {
            $requestedAmount = $orderTotal;
        } elseif (strpos($compensationType, 'partial_refund') !== false) {
            if (!empty($cleanItems)) {
                foreach ($cleanItems as $it) {
                    $requestedAmount += $it['price'] * $it['quantity'];
                }
                $requestedAmount = $requestedAmount * ($rule['refund_pct'] / 100);
            } else {
                $requestedAmount = $orderTotal * ($rule['refund_pct'] / 100);
            }
        }
        // Cap at order total
        $requestedAmount = min($requestedAmount, $orderTotal);
        $creditAmount = $rule['credit_amount'];

        // ---- Determine status ----
        // Customer requesting to talk to support forces manual review
        $wantsHumanSupport = ($preferredResolution === 'talk_to_support');
        $canAutoResolve = $rule['auto_resolve'] && !$isSuspicious && !$wantsHumanSupport;
        $needsPhoto = $rule['needs_photo'];

        if ($canAutoResolve && $needsPhoto && empty($cleanPhotos)) {
            $status = 'awaiting_evidence';
            $autoResolved = false;
        } elseif ($canAutoResolve) {
            $status = 'auto_resolved';
            $autoResolved = true;
        } else {
            $status = 'in_review';
            $autoResolved = false;
        }

        $approvedAmount = $autoResolved ? $requestedAmount : 0;

        // Sanitize description
        $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

        $db->beginTransaction();

        $partnerId = (int)($order['partner_id'] ?? 0);
        $driverId = (int)($order['driver_id'] ?? 0);

        $stmt = $db->prepare("
            INSERT INTO om_order_disputes (
                order_id, customer_id, partner_id, driver_id,
                category, subcategory, severity, description,
                photo_urls, affected_items,
                order_total, requested_amount, approved_amount, credit_amount,
                compensation_type, preferred_resolution, status,
                auto_resolved, auto_resolution_rule,
                sla_target_hours,
                customer_dispute_count_30d, is_suspicious, suspicious_reason,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?,
                ?, ?, ?,
                NOW(), NOW()
            )
            RETURNING dispute_id
        ");

        $stmt->execute([
            $orderId, $customerId, $partnerId, $driverId,
            $category, $subcategory, $rule['severity'], $description,
            json_encode($cleanPhotos, JSON_UNESCAPED_UNICODE), json_encode($cleanItems, JSON_UNESCAPED_UNICODE),
            $orderTotal, $requestedAmount, $approvedAmount, $creditAmount,
            $compensationType, $preferredResolution ?: null, $status,
            $autoResolved ? 1 : 0, $subcategory,
            $rule['sla_hours'],
            $disputeCount30d + 1, $isSuspicious ? 1 : 0, $suspiciousReason,
        ]);

        $disputeId = (int)$stmt->fetch()['dispute_id'];

        // Set deadline_at for partner response (48 hours from now)
        // Only for non-auto-resolved disputes (partner needs to respond)
        if (!$autoResolved) {
            $db->prepare("
                UPDATE om_order_disputes SET deadline_at = NOW() + INTERVAL '48 hours' WHERE dispute_id = ?
            ")->execute([$disputeId]);
        }

        // Insert evidence
        foreach ($cleanPhotos as $photoUrl) {
            $db->prepare("
                INSERT INTO om_dispute_evidence (dispute_id, order_id, customer_id, photo_url, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$disputeId, $orderId, $customerId, $photoUrl]);
        }

        // Insert timeline
        $timelineDesc = "Disputa aberta: " . ($rule['label'] ?? $subcategory);
        $db->prepare("
            INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
            VALUES (?, 'opened', 'customer', ?, ?, NOW())
        ")->execute([$disputeId, $customerId, $timelineDesc]);

        // If auto-resolved, add resolution timeline + create refund/credit
        if ($autoResolved) {
            $resolutionParts = [];
            if ($approvedAmount > 0) {
                $resolutionParts[] = "Reembolso de R$ " . number_format($approvedAmount, 2, ',', '.');
            }
            if ($creditAmount > 0) {
                $resolutionParts[] = "R$ " . number_format($creditAmount, 2, ',', '.') . " em creditos";
            }
            $resolutionDesc = "Auto-resolvido: " . implode(' + ', $resolutionParts);
            if (empty($resolutionParts)) {
                $resolutionDesc = "Auto-resolvido: registrado para analise";
            }

            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'auto_resolved', 'system', 0, ?, NOW())
            ")->execute([$disputeId, $resolutionDesc]);

            // Update resolved_at
            $db->prepare("
                UPDATE om_order_disputes
                SET resolved_at = NOW(), resolution_type = 'auto', resolution_note = ?
                WHERE dispute_id = ?
            ")->execute([$resolutionDesc, $disputeId]);

            // Create actual refund if amount > 0, but check for existing refund first
            if ($approvedAmount > 0) {
                try {
                    // Check if a refund already exists for this order + dispute combination
                    $stmtExistingRefund = $db->prepare("
                        SELECT id FROM om_market_refunds
                        WHERE order_id = ? AND customer_id = ? AND reason LIKE ? AND status NOT IN ('failed', 'rejected')
                        LIMIT 1
                    ");
                    $stmtExistingRefund->execute([$orderId, $customerId, "Disputa #{$disputeId}:%"]);
                    if (!$stmtExistingRefund->fetch()) {
                        $db->prepare("
                            INSERT INTO om_market_refunds (order_id, customer_id, amount, reason, items_json, status, admin_note, reviewed_at, created_at)
                            VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW(), NOW())
                        ")->execute([
                            $orderId, $customerId, $approvedAmount,
                            "Disputa #{$disputeId}: " . ($rule['label'] ?? $subcategory),
                            json_encode($cleanItems, JSON_UNESCAPED_UNICODE),
                            "Auto-aprovado via sistema de disputas"
                        ]);
                    } else {
                        error_log("[dispute] Refund already exists for order {$orderId} dispute #{$disputeId}, skipping insert");
                    }
                } catch (Exception $e) {
                    error_log("[dispute] Refund insert failed: " . $e->getMessage());
                }
            }

            // Add credit to wallet if credit > 0
            if ($creditAmount > 0) {
                try {
                    guard_wallet_credit($db, $customerId, $creditAmount, $orderId, "Credito disputa #{$disputeId}", "dispute:{$disputeId}");
                } catch (Exception $e) {
                    error_log("[dispute] Wallet credit failed: " . $e->getMessage());
                }
            }
        } elseif ($status === 'awaiting_evidence') {
            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'awaiting_evidence', 'system', 0, 'Aguardando fotos como evidencia para auto-resolucao', NOW())
            ")->execute([$disputeId]);
        } else {
            // in_review
            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'in_review', 'system', 0, ?, NOW())
            ")->execute([$disputeId, "Encaminhado para analise manual. Prazo: {$rule['sla_hours']}h"]);
        }

        // Notify via chat
        try {
            $statusMsg = match($status) {
                'auto_resolved' => 'resolvido automaticamente',
                'awaiting_evidence' => 'aguardando envio de fotos',
                'in_review' => 'encaminhado para analise',
                default => $status,
            };
            $sysMsg = "Disputa #{$disputeId} aberta: " . ($rule['label'] ?? $subcategory) . ". Status: {$statusMsg}.";
            if ($autoResolved && ($approvedAmount > 0 || $creditAmount > 0)) {
                $parts = [];
                if ($approvedAmount > 0) $parts[] = "reembolso de R$ " . number_format($approvedAmount, 2, ',', '.');
                if ($creditAmount > 0) $parts[] = "R$ " . number_format($creditAmount, 2, ',', '.') . " em creditos";
                $sysMsg .= " Compensacao: " . implode(' + ', $parts) . ".";
            }

            $db->prepare("
                INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, chat_type, is_read, created_at)
                VALUES (?, 'system', 0, 'Sistema', ?, 'text', 'support', 0, NOW())
            ")->execute([$orderId, $sysMsg]);
        } catch (Exception $e) {
            error_log("[dispute] Chat notification failed: " . $e->getMessage());
        }

        // Notify partner via notification system
        if ($partnerId > 0) {
            try {
                $db->prepare("
                    UPDATE om_order_disputes SET partner_notified = 1 WHERE dispute_id = ?
                ")->execute([$disputeId]);

                $db->prepare("
                    INSERT INTO om_market_notifications (recipient_id, recipient_type, title, message, data, is_read, sent_at)
                    VALUES (?, 'partner', ?, ?, ?, 0, NOW())
                ")->execute([
                    $partnerId,
                    "Disputa no pedido #{$orderId}",
                    "Cliente reportou: " . ($rule['label'] ?? $subcategory),
                    json_encode(['type' => 'dispute', 'dispute_id' => $disputeId, 'order_id' => $orderId]),
                ]);
            } catch (Exception $e) {
                error_log("[dispute] Partner notification failed: " . $e->getMessage());
            }
        }

        // Critical severity: notify admin
        if ($rule['severity'] === 'critical') {
            try {
                $db->prepare("
                    INSERT INTO om_market_notifications (recipient_id, recipient_type, title, message, data, is_read, sent_at)
                    VALUES (1, 'admin', ?, ?, ?, 0, NOW())
                ")->execute([
                    "CRITICO: Disputa #{$disputeId}",
                    "Pedido #{$orderId}: " . ($rule['label'] ?? $subcategory) . " - " . substr($description, 0, 100),
                    json_encode(['type' => 'dispute_critical', 'dispute_id' => $disputeId, 'order_id' => $orderId]),
                ]);
            } catch (Exception $e) {
                error_log("[dispute] Admin notification failed: " . $e->getMessage());
            }
        }

        $db->commit();

        // Push notification + WebSocket for auto-resolved disputes
        if ($autoResolved) {
            try {
                $pushParts = [];
                if ($approvedAmount > 0) $pushParts[] = "reembolso de R$ " . number_format($approvedAmount, 2, ',', '.');
                if ($creditAmount > 0) $pushParts[] = "R$ " . number_format($creditAmount, 2, ',', '.') . " em creditos";
                $pushBody = 'Sua disputa sobre o pedido #' . $orderId . ' foi resolvida automaticamente.';
                if (!empty($pushParts)) $pushBody .= ' Compensacao: ' . implode(' + ', $pushParts) . '.';

                notifyCustomer($db, $customerId,
                    'Disputa resolvida automaticamente',
                    $pushBody,
                    '/mercado/',
                    ['type' => 'dispute_resolved', 'dispute_id' => $disputeId, 'order_id' => $orderId]
                );
                wsBroadcastToCustomer($customerId, 'dispute_update', [
                    'dispute_id' => $disputeId,
                    'order_id' => $orderId,
                    'status' => 'auto_resolved',
                    'approved_amount' => round($approvedAmount, 2),
                    'credit_amount' => round($creditAmount, 2),
                ]);
            } catch (\Throwable $e) {
                error_log("[dispute] Push/WS notification failed on auto-resolve: " . $e->getMessage());
            }
        }

        // Build response
        $responseData = [
            'dispute' => [
                'id' => $disputeId,
                'order_id' => $orderId,
                'category' => $category,
                'subcategory' => $subcategory,
                'severity' => $rule['severity'],
                'status' => $status,
                'auto_resolved' => $autoResolved,
                'requested_amount' => round($requestedAmount, 2),
                'approved_amount' => round($approvedAmount, 2),
                'credit_amount' => round($creditAmount, 2),
                'compensation_type' => $compensationType,
                'sla_hours' => $rule['sla_hours'],
                'label' => $rule['label'] ?? $subcategory,
                'preferred_resolution' => $preferredResolution ?: null,
            ],
        ];

        $statusLabel = match($status) {
            'auto_resolved' => 'Disputa resolvida automaticamente',
            'awaiting_evidence' => 'Envie fotos para completar a analise',
            'in_review' => 'Disputa enviada para analise. Prazo: ' . $rule['sla_hours'] . 'h',
            default => 'Disputa registrada',
        };

        response(true, $responseData, $statusLabel);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[dispute] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar disputa", 500);
}

// =================== HELPERS ===================

function formatDispute($d) {
    global $DISPUTE_RULES;
    $cat = $d['category'] ?? '';
    $sub = $d['subcategory'] ?? '';
    $rule = $DISPUTE_RULES[$cat][$sub] ?? null;

    $statusLabels = [
        'open' => 'Aberto',
        'awaiting_evidence' => 'Aguardando fotos',
        'auto_resolved' => 'Resolvido',
        'in_review' => 'Em analise',
        'escalated' => 'Escalado',
        'resolved' => 'Resolvido',
        'closed' => 'Fechado',
    ];

    // Compute time remaining for deadline
    $deadlineAt = $d['deadline_at'] ?? null;
    $timeRemainingHours = null;
    if ($deadlineAt) {
        $deadlineTs = strtotime($deadlineAt);
        $nowTs = time();
        $diffSeconds = $deadlineTs - $nowTs;
        $timeRemainingHours = round($diffSeconds / 3600, 1);
        if ($timeRemainingHours < 0) $timeRemainingHours = 0;
    }

    return [
        'id' => (int)$d['dispute_id'],
        'order_id' => (int)$d['order_id'],
        'category' => $cat,
        'subcategory' => $sub,
        'severity' => $d['severity'] ?? '',
        'label' => $rule['label'] ?? $sub,
        'description' => $d['description'] ?? '',
        'photo_urls' => json_decode($d['photo_urls'] ?? '[]', true) ?: [],
        'affected_items' => json_decode($d['affected_items'] ?? '[]', true) ?: [],
        'order_total' => (float)($d['order_total'] ?? 0),
        'requested_amount' => (float)($d['requested_amount'] ?? 0),
        'approved_amount' => (float)($d['approved_amount'] ?? 0),
        'credit_amount' => (float)($d['credit_amount'] ?? 0),
        'compensation_type' => $d['compensation_type'] ?? '',
        'status' => $d['status'] ?? 'open',
        'status_label' => $statusLabels[$d['status'] ?? ''] ?? ($d['status'] ?? ''),
        'auto_resolved' => (bool)($d['auto_resolved'] ?? false),
        'resolution_note' => $d['resolution_note'] ?? null,
        'sla_target_hours' => (int)($d['sla_target_hours'] ?? 0),
        'preferred_resolution' => $d['preferred_resolution'] ?? null,
        'partner_response' => $d['partner_response'] ?? null,
        'created_at' => $d['created_at'] ?? null,
        'resolved_at' => $d['resolved_at'] ?? null,
        'deadline_at' => $deadlineAt,
        'time_remaining_hours' => $timeRemainingHours,
        'rating_after_dispute' => isset($d['rating_after_dispute']) ? (int)$d['rating_after_dispute'] : null,
        'rating_comment' => $d['rating_comment'] ?? null,
    ];
}

function ensureDisputeTables($db) {
    // Tables om_order_disputes, om_dispute_evidence, om_dispute_timeline created via migration
    // Add preferred_resolution column if not present
    try {
        $db->exec("ALTER TABLE om_order_disputes ADD COLUMN IF NOT EXISTS preferred_resolution VARCHAR(50) DEFAULT NULL");
    } catch (Exception $e) {
        // Column may already exist or table uses different DDL syntax
    }
    return;
}
