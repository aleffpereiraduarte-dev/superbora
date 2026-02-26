<?php
/**
 * 🔄 WEBHOOK REDIRECTOR
 * Redireciona webhooks antigos para o central
 */

// Captura o payload
$payload = file_get_contents('php://input');

// Envia para webhook central
$ch = curl_init('https://onemundo.com.br/api/pagarme/webhook.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30
]);
$response = curl_exec($ch);
curl_close($ch);

// Retorna resposta
header('Content-Type: application/json');
echo $response ?: json_encode(['success' => true, 'redirected' => true]);