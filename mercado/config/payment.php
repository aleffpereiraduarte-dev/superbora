<?php
/**
 * 💳 CONFIGURAÇÃO DE PAGAMENTOS - MERCADO
 * Usa Central Ultra para todas as operações
 */

// Carregar Central
require_once $_SERVER['DOCUMENT_ROOT'] . '/system/library/PagarmeCenterUltra.php';
$pagarme = PagarmeCenterUltra::getInstance();

// Configurações
return [
    'pagarme' => [
        'enabled' => true,
        'public_key' => $pagarme->getPublicKey(),
        'pix_enabled' => true,
        'card_enabled' => true,
        'boleto_enabled' => true,
        'max_installments' => 12,
        '3ds_enabled' => true,
        'antifraud_enabled' => true
    ],
    
    'pix' => [
        'expiration_minutes' => 60
    ],
    
    'boleto' => [
        'expiration_days' => 3
    ]
];