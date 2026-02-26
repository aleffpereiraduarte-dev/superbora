<?php
/**
 * ONEMUNDO - TRANSPORT API INDEX
 * Lista endpoints disponiveis
 */
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => true,
    'api' => 'OneMundo Transport API',
    'version' => '1.0',
    'endpoints' => [
        'corrida' => '/api/transporte/corrida/',
        'motorista' => '/api/transporte/motorista/',
        'passageiro' => '/api/transporte/passageiro/',
        'pagamento' => '/api/transporte/pagamento/',
        'tarifas' => '/api/transporte/tarifas/',
        'cupons' => '/api/transporte/cupons/'
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
