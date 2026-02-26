<?php
/**
 * APIs de Entregador do Mercado OneMundo
 * Usa mesma base de motoristas do BoraUm (om_boraum_drivers)
 */

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'service' => 'Mercado Entregador API',
    'version' => '1.0.0',
    'endpoints' => [
        'POST /localizacao.php' => 'Atualizar localizacao (driver_id, latitude, longitude, status)',
        'GET/POST /status.php' => 'Consultar/atualizar status online (driver_id)',
        'GET /ofertas.php' => 'Listar ofertas de entrega (driver_id, lat, lng)',
        'POST /aceitar.php' => 'Aceitar entrega (driver_id, order_id)',
        'POST /coletar.php' => 'Confirmar coleta/handoff (driver_id, order_id, qr_code)',
        'POST /foto-coleta.php' => 'Upload foto da coleta (driver_id, order_id, foto)',
        'POST /chegou.php' => 'Registrar chegada no destino (driver_id, order_id)',
        'GET /codigo-entrega.php' => 'Obter codigo de entrega (order_id)',
        'POST /confirmar-entrega.php' => 'Confirmar entrega com codigo (driver_id, order_id, codigo)',
        'GET /ganhos.php' => 'Consultar ganhos (driver_id, periodo=hoje|semana|mes|total)',
        'POST /saque.php' => 'Solicitar saque PIX (driver_id, valor, chave_pix)'
    ],
    'nota' => 'Motoristas do BoraUm sao os mesmos entregadores do Mercado'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
