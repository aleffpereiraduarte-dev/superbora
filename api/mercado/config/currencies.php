<?php
/**
 * Currency Rates API Endpoint
 *
 * GET /api/mercado/config/currencies.php
 *   Returns available currencies and exchange rates (BRL base).
 *   Optional: ?currency=USD to get a single rate
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "base": "BRL",
 *     "currencies": ["BRL", "USD", "EUR", ...],
 *     "rates": { "USD": 0.18, "EUR": 0.17, ... },
 *     "updated_at": "2026-04-03T..."
 *   }
 * }
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/i18n.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Method not allowed', 405);
}

// Ensure DB table exists (idempotent, fast if already exists)
ensureCurrencyRatesTable();

// Fetch rates (uses Redis -> DB -> API cascade with caching)
$rates = fetchExchangeRates();

// Optional: filter to a single currency
$currency = strtoupper($_GET['currency'] ?? '');
if ($currency && $currency !== 'BRL') {
    if (!in_array($currency, I18N_SUPPORTED_CURRENCIES, true)) {
        response(false, null, 'Unsupported currency: ' . $currency, 400);
    }
    $rate = $rates[$currency] ?? null;
    if ($rate === null) {
        response(false, null, 'Rate not available for: ' . $currency, 404);
    }
    response(true, [
        'base'       => I18N_BASE_CURRENCY,
        'currency'   => $currency,
        'rate'       => $rate,
        'formatted'  => formatCurrency(1.0, $currency),
        'updated_at' => date('c'),
    ]);
}

// Return all rates
$responseRates = [];
foreach (I18N_SUPPORTED_CURRENCIES as $cur) {
    if (isset($rates[$cur])) {
        $responseRates[$cur] = $rates[$cur];
    }
}

// Get last update time from DB
$updatedAt = date('c');
try {
    $db = getDB();
    $stmt = $db->query("SELECT MAX(updated_at) as last_update FROM om_currency_rates WHERE base_currency = 'BRL'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['last_update']) {
        $updatedAt = date('c', strtotime($row['last_update']));
    }
} catch (Exception $e) {
    // Use current time as fallback
}

// Build formatted examples for each currency (useful for UI display)
$formatted = [];
foreach (I18N_SUPPORTED_CURRENCIES as $cur) {
    $formatted[$cur] = [
        'symbol'  => getCurrencyConfig($cur)['symbol'],
        'example' => formatCurrency(100.00, $cur),
    ];
}

response(true, [
    'base'       => I18N_BASE_CURRENCY,
    'currencies' => I18N_SUPPORTED_CURRENCIES,
    'rates'      => $responseRates,
    'formatted'  => $formatted,
    'updated_at' => $updatedAt,
]);
