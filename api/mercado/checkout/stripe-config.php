<?php
/**
 * GET /api/mercado/checkout/stripe-config.php
 * Retorna configuracao publica do Stripe (publishable key)
 * Usado pelo frontend para inicializar Apple Pay / Google Pay
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

// Carregar chaves Stripe do .env.stripe
$stripeEnv = dirname(__DIR__, 3) . '/.env.stripe';
$STRIPE_PK = '';

if (file_exists($stripeEnv)) {
    $lines = file($stripeEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $k = trim($key);
            $v = trim($value);
            if ($k === 'STRIPE_PUBLIC_KEY') $STRIPE_PK = $v;
        }
    }
}

// Verificar se chave esta configurada
if (empty($STRIPE_PK) || strpos($STRIPE_PK, 'XXX') !== false) {
    response(false, null, "Stripe nao configurado", 503);
}

response(true, [
    "publishable_key" => $STRIPE_PK,
    "country" => "BR",
    "currency" => "brl",
    "apple_pay_enabled" => true,
    "google_pay_enabled" => true,
    "link_enabled" => true
]);
