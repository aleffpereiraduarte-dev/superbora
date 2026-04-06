<?php
/**
 * SuperBora i18n - Internationalization & Multi-Currency Helper
 *
 * Usage:
 *   require_once __DIR__ . '/i18n.php';
 *   $t = getTranslator(); // auto-detects from Accept-Language or ?lang=
 *   echo $t('order_confirmed'); // "Pedido confirmado!" or "Order confirmed!"
 *   echo formatCurrency(29.90, 'USD'); // "$29.90"
 *   echo convertCurrency(29.90, 'BRL', 'USD'); // converts using cached rates
 */

require_once __DIR__ . '/redis.php';

// ============================================================================
// SUPPORTED LANGUAGES & TRANSLATIONS
// ============================================================================

define('I18N_SUPPORTED_LANGUAGES', ['pt', 'en', 'es']);
define('I18N_DEFAULT_LANGUAGE', 'pt');

/**
 * Translation arrays for API response messages.
 * Keys are organized by domain: order status, errors, notifications, general.
 */
function i18nTranslations(): array {
    static $translations = null;
    if ($translations !== null) return $translations;

    $translations = [
        'pt' => [
            // Order statuses
            'order_confirmed'       => 'Pedido confirmado!',
            'order_preparing'       => 'Pedido em preparo',
            'order_ready'           => 'Pedido pronto!',
            'order_out_for_delivery' => 'Saiu para entrega',
            'order_delivered'       => 'Pedido entregue!',
            'order_cancelled'       => 'Pedido cancelado',
            'order_refused'         => 'Pedido recusado',
            'order_accepted'        => 'Pedido aceito pelo parceiro',
            'order_pending'         => 'Pedido pendente',

            // Payment
            'payment_approved'      => 'Pagamento aprovado',
            'payment_failed'        => 'Falha no pagamento',
            'payment_pending'       => 'Pagamento pendente',
            'pix_confirmed'         => 'PIX confirmado!',
            'pix_expired'           => 'PIX expirado',
            'pix_waiting'           => 'Aguardando pagamento PIX',

            // Errors
            'error_generic'         => 'Erro interno do servidor. Tente novamente.',
            'error_not_found'       => 'Recurso nao encontrado.',
            'error_unauthorized'    => 'Autenticacao obrigatoria.',
            'error_forbidden'       => 'Acesso negado.',
            'error_validation'      => 'Dados invalidos. Verifique e tente novamente.',
            'error_rate_limit'      => 'Muitas requisicoes. Aguarde um momento.',
            'error_stock'           => 'Produto sem estoque disponivel.',
            'error_store_closed'    => 'Loja fechada no momento.',
            'error_min_order'       => 'Pedido abaixo do valor minimo.',
            'error_address'         => 'Endereco de entrega invalido.',
            'error_coupon_invalid'  => 'Cupom invalido ou expirado.',
            'error_coupon_used'     => 'Cupom ja utilizado.',
            'error_cart_empty'      => 'Carrinho vazio.',
            'error_partner_conflict' => 'Carrinho tem itens de outra loja.',

            // Notifications
            'notif_order_accepted'  => 'Seu pedido foi aceito!',
            'notif_order_ready'     => 'Seu pedido esta pronto para retirada!',
            'notif_order_delivering' => 'Seu pedido saiu para entrega!',
            'notif_order_delivered' => 'Pedido entregue! Avalie sua experiencia.',
            'notif_promo'           => 'Oferta especial para voce!',
            'notif_cashback'        => 'Voce recebeu cashback!',

            // General
            'success'               => 'Sucesso',
            'loading'               => 'Carregando...',
            'try_again'             => 'Tente novamente',
            'service_unavailable'   => 'Servico temporariamente indisponivel. Tente novamente em alguns minutos.',
        ],

        'en' => [
            // Order statuses
            'order_confirmed'       => 'Order confirmed!',
            'order_preparing'       => 'Order being prepared',
            'order_ready'           => 'Order ready!',
            'order_out_for_delivery' => 'Out for delivery',
            'order_delivered'       => 'Order delivered!',
            'order_cancelled'       => 'Order cancelled',
            'order_refused'         => 'Order refused',
            'order_accepted'        => 'Order accepted by partner',
            'order_pending'         => 'Order pending',

            // Payment
            'payment_approved'      => 'Payment approved',
            'payment_failed'        => 'Payment failed',
            'payment_pending'       => 'Payment pending',
            'pix_confirmed'         => 'PIX payment confirmed!',
            'pix_expired'           => 'PIX payment expired',
            'pix_waiting'           => 'Waiting for PIX payment',

            // Errors
            'error_generic'         => 'Internal server error. Please try again.',
            'error_not_found'       => 'Resource not found.',
            'error_unauthorized'    => 'Authentication required.',
            'error_forbidden'       => 'Access denied.',
            'error_validation'      => 'Invalid data. Please check and try again.',
            'error_rate_limit'      => 'Too many requests. Please wait a moment.',
            'error_stock'           => 'Product out of stock.',
            'error_store_closed'    => 'Store is currently closed.',
            'error_min_order'       => 'Order below minimum value.',
            'error_address'         => 'Invalid delivery address.',
            'error_coupon_invalid'  => 'Invalid or expired coupon.',
            'error_coupon_used'     => 'Coupon already used.',
            'error_cart_empty'      => 'Cart is empty.',
            'error_partner_conflict' => 'Cart has items from another store.',

            // Notifications
            'notif_order_accepted'  => 'Your order was accepted!',
            'notif_order_ready'     => 'Your order is ready for pickup!',
            'notif_order_delivering' => 'Your order is on its way!',
            'notif_order_delivered' => 'Order delivered! Rate your experience.',
            'notif_promo'           => 'Special offer for you!',
            'notif_cashback'        => 'You received cashback!',

            // General
            'success'               => 'Success',
            'loading'               => 'Loading...',
            'try_again'             => 'Try again',
            'service_unavailable'   => 'Service temporarily unavailable. Please try again in a few minutes.',
        ],

        'es' => [
            // Order statuses
            'order_confirmed'       => 'Pedido confirmado!',
            'order_preparing'       => 'Pedido en preparacion',
            'order_ready'           => 'Pedido listo!',
            'order_out_for_delivery' => 'En camino',
            'order_delivered'       => 'Pedido entregado!',
            'order_cancelled'       => 'Pedido cancelado',
            'order_refused'         => 'Pedido rechazado',
            'order_accepted'        => 'Pedido aceptado por el socio',
            'order_pending'         => 'Pedido pendiente',

            // Payment
            'payment_approved'      => 'Pago aprobado',
            'payment_failed'        => 'Fallo en el pago',
            'payment_pending'       => 'Pago pendiente',
            'pix_confirmed'         => 'Pago PIX confirmado!',
            'pix_expired'           => 'Pago PIX expirado',
            'pix_waiting'           => 'Esperando pago PIX',

            // Errors
            'error_generic'         => 'Error interno del servidor. Intente de nuevo.',
            'error_not_found'       => 'Recurso no encontrado.',
            'error_unauthorized'    => 'Autenticacion requerida.',
            'error_forbidden'       => 'Acceso denegado.',
            'error_validation'      => 'Datos invalidos. Verifique e intente de nuevo.',
            'error_rate_limit'      => 'Demasiadas solicitudes. Espere un momento.',
            'error_stock'           => 'Producto sin stock disponible.',
            'error_store_closed'    => 'Tienda cerrada en este momento.',
            'error_min_order'       => 'Pedido por debajo del valor minimo.',
            'error_address'         => 'Direccion de entrega invalida.',
            'error_coupon_invalid'  => 'Cupon invalido o expirado.',
            'error_coupon_used'     => 'Cupon ya utilizado.',
            'error_cart_empty'      => 'Carrito vacio.',
            'error_partner_conflict' => 'Carrito tiene productos de otra tienda.',

            // Notifications
            'notif_order_accepted'  => 'Tu pedido fue aceptado!',
            'notif_order_ready'     => 'Tu pedido esta listo para recoger!',
            'notif_order_delivering' => 'Tu pedido esta en camino!',
            'notif_order_delivered' => 'Pedido entregado! Evalua tu experiencia.',
            'notif_promo'           => 'Oferta especial para ti!',
            'notif_cashback'        => 'Recibiste cashback!',

            // General
            'success'               => 'Exito',
            'loading'               => 'Cargando...',
            'try_again'             => 'Intente de nuevo',
            'service_unavailable'   => 'Servicio temporalmente no disponible. Intente de nuevo en unos minutos.',
        ],
    ];

    return $translations;
}

// ============================================================================
// LANGUAGE DETECTION
// ============================================================================

/**
 * Detect preferred language from request.
 * Priority: ?lang= param > Accept-Language header > default (pt)
 */
function detectLanguage(): string {
    // 1. Explicit ?lang= parameter
    $langParam = $_GET['lang'] ?? $_POST['lang'] ?? '';
    if ($langParam && in_array($langParam, I18N_SUPPORTED_LANGUAGES, true)) {
        return $langParam;
    }

    // 2. Accept-Language header
    $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($acceptLang) {
        // Parse "pt-BR,pt;q=0.9,en;q=0.8,es;q=0.7"
        $langs = [];
        foreach (explode(',', $acceptLang) as $part) {
            $part = trim($part);
            $q = 1.0;
            if (preg_match('/;q=([\d.]+)/', $part, $m)) {
                $q = (float)$m[1];
                $part = preg_replace('/;q=[\d.]+/', '', $part);
            }
            $part = trim($part);
            // Normalize: pt-BR -> pt, en-US -> en, es-AR -> es
            $base = strtolower(explode('-', $part)[0]);
            if (in_array($base, I18N_SUPPORTED_LANGUAGES, true)) {
                $langs[$base] = max($langs[$base] ?? 0, $q);
            }
        }
        if (!empty($langs)) {
            arsort($langs);
            return array_key_first($langs);
        }
    }

    // 3. Default
    return I18N_DEFAULT_LANGUAGE;
}

// ============================================================================
// TRANSLATOR
// ============================================================================

/**
 * Get a translator closure for the given language.
 *
 * Usage:
 *   $t = getTranslator('en');
 *   echo $t('order_confirmed'); // "Order confirmed!"
 *   echo $t('nonexistent_key'); // "nonexistent_key" (returns key as fallback)
 *
 * @param string|null $lang Language code (pt/en/es). Auto-detects if null.
 * @return Closure
 */
function getTranslator(?string $lang = null): Closure {
    if ($lang === null) {
        $lang = detectLanguage();
    }
    if (!in_array($lang, I18N_SUPPORTED_LANGUAGES, true)) {
        $lang = I18N_DEFAULT_LANGUAGE;
    }

    $translations = i18nTranslations();
    $strings = $translations[$lang] ?? $translations[I18N_DEFAULT_LANGUAGE];
    $fallback = $translations[I18N_DEFAULT_LANGUAGE];

    return function (string $key) use ($strings, $fallback): string {
        return $strings[$key] ?? $fallback[$key] ?? $key;
    };
}

// ============================================================================
// CURRENCY
// ============================================================================

define('I18N_SUPPORTED_CURRENCIES', ['BRL', 'USD', 'EUR', 'GBP', 'ARS', 'CLP', 'MXN', 'COP']);
define('I18N_BASE_CURRENCY', 'BRL');
define('I18N_RATES_CACHE_TTL', 21600); // 6 hours

/**
 * Currency formatting configuration per currency.
 */
function getCurrencyConfig(string $currency): array {
    $configs = [
        'BRL' => ['symbol' => 'R$', 'decimals' => 2, 'dec_sep' => ',', 'thousands' => '.', 'position' => 'prefix_space'],
        'USD' => ['symbol' => '$',  'decimals' => 2, 'dec_sep' => '.', 'thousands' => ',', 'position' => 'prefix'],
        'EUR' => ['symbol' => "\u{20AC}", 'decimals' => 2, 'dec_sep' => ',', 'thousands' => '.', 'position' => 'suffix_space'],
        'GBP' => ['symbol' => "\u{00A3}", 'decimals' => 2, 'dec_sep' => '.', 'thousands' => ',', 'position' => 'prefix'],
        'ARS' => ['symbol' => '$',  'decimals' => 2, 'dec_sep' => ',', 'thousands' => '.', 'position' => 'prefix_space'],
        'CLP' => ['symbol' => '$',  'decimals' => 0, 'dec_sep' => '', 'thousands' => '.', 'position' => 'prefix'],
        'MXN' => ['symbol' => '$',  'decimals' => 2, 'dec_sep' => '.', 'thousands' => ',', 'position' => 'prefix'],
        'COP' => ['symbol' => '$',  'decimals' => 0, 'dec_sep' => '', 'thousands' => '.', 'position' => 'prefix_space'],
    ];
    return $configs[$currency] ?? $configs['USD'];
}

/**
 * Format a monetary amount in the specified currency.
 *
 * Usage:
 *   echo formatCurrency(29.90, 'BRL'); // "R$ 29,90"
 *   echo formatCurrency(29.90, 'USD'); // "$29.90"
 *   echo formatCurrency(1500, 'EUR');  // "1.500,00 €"
 */
function formatCurrency(float $amount, string $currency = 'BRL'): string {
    $currency = strtoupper($currency);
    $config = getCurrencyConfig($currency);

    $formatted = number_format(
        abs($amount),
        $config['decimals'],
        $config['dec_sep'],
        $config['thousands']
    );

    $sign = $amount < 0 ? '-' : '';

    switch ($config['position']) {
        case 'prefix':
            return $sign . $config['symbol'] . $formatted;
        case 'prefix_space':
            return $sign . $config['symbol'] . ' ' . $formatted;
        case 'suffix':
            return $sign . $formatted . $config['symbol'];
        case 'suffix_space':
            return $sign . $formatted . ' ' . $config['symbol'];
        default:
            return $sign . $config['symbol'] . $formatted;
    }
}

/**
 * Fetch and cache exchange rates from a free API.
 * Rates are relative to BRL (base currency).
 * Returns associative array: ['USD' => 0.18, 'EUR' => 0.17, ...]
 */
function fetchExchangeRates(): array {
    $redis = om_redis();
    $cacheKey = 'i18n:exchange_rates';

    // 1. Try Redis cache
    if ($redis->isAvailable()) {
        $cached = $redis->get($cacheKey);
        if ($cached && is_array($cached)) {
            return $cached;
        }
    }

    // 2. Try DB cache (om_currency_rates table)
    $rates = loadRatesFromDB();
    if (!empty($rates)) {
        // Check if rates are fresh (less than 6 hours old)
        $age = time() - ($rates['_updated_at'] ?? 0);
        if ($age < I18N_RATES_CACHE_TTL) {
            unset($rates['_updated_at']);
            // Re-populate Redis
            if ($redis->isAvailable()) {
                $redis->set($cacheKey, $rates, I18N_RATES_CACHE_TTL);
            }
            return $rates;
        }
    }

    // 3. Fetch from API
    $freshRates = fetchRatesFromAPI();
    if (!empty($freshRates)) {
        // Save to Redis
        if ($redis->isAvailable()) {
            $redis->set($cacheKey, $freshRates, I18N_RATES_CACHE_TTL);
        }
        // Save to DB
        saveRatesToDB($freshRates);
        return $freshRates;
    }

    // 4. Return stale DB rates or hardcoded fallback
    if (!empty($rates)) {
        unset($rates['_updated_at']);
        return $rates;
    }

    // Hardcoded fallback rates (approximate, updated rarely)
    return [
        'BRL' => 1.0,
        'USD' => 0.18,
        'EUR' => 0.17,
        'GBP' => 0.14,
        'ARS' => 190.0,
        'CLP' => 170.0,
        'MXN' => 3.1,
        'COP' => 720.0,
    ];
}

/**
 * Fetch rates from free exchange rate API.
 * Uses exchangerate.host (no API key needed for basic usage).
 */
function fetchRatesFromAPI(): array {
    $apiUrl = 'https://api.exchangerate.host/latest?base=BRL&symbols=' . implode(',', I18N_SUPPORTED_CURRENCIES);

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: SuperBora/1.0\r\n",
        ],
    ]);

    try {
        $response = @file_get_contents($apiUrl, false, $ctx);
        if ($response === false) {
            error_log('[i18n] Failed to fetch exchange rates from API');
            return [];
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['rates'])) {
            // Try alternative API format (some APIs return differently)
            // Also try exchangerate-api.com free tier
            return fetchRatesFromAlternativeAPI();
        }

        $rates = ['BRL' => 1.0];
        foreach (I18N_SUPPORTED_CURRENCIES as $currency) {
            if ($currency === 'BRL') continue;
            if (isset($data['rates'][$currency])) {
                $rates[$currency] = round((float)$data['rates'][$currency], 6);
            }
        }

        return $rates;
    } catch (Exception $e) {
        error_log('[i18n] Exchange rate API error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Alternative API fallback.
 */
function fetchRatesFromAlternativeAPI(): array {
    $apiUrl = 'https://open.er-api.com/v6/latest/BRL';

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: SuperBora/1.0\r\n",
        ],
    ]);

    try {
        $response = @file_get_contents($apiUrl, false, $ctx);
        if ($response === false) return [];

        $data = json_decode($response, true);
        if (!$data || empty($data['rates'])) return [];

        $rates = ['BRL' => 1.0];
        foreach (I18N_SUPPORTED_CURRENCIES as $currency) {
            if ($currency === 'BRL') continue;
            if (isset($data['rates'][$currency])) {
                $rates[$currency] = round((float)$data['rates'][$currency], 6);
            }
        }

        return $rates;
    } catch (Exception $e) {
        error_log('[i18n] Alternative exchange rate API error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Load rates from PostgreSQL.
 */
function loadRatesFromDB(): array {
    try {
        $db = getDB();
        $stmt = $db->query("
            SELECT currency, rate, updated_at
            FROM om_currency_rates
            WHERE base_currency = 'BRL'
            ORDER BY updated_at DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return [];

        $rates = ['BRL' => 1.0];
        $latestUpdate = 0;
        foreach ($rows as $row) {
            $rates[$row['currency']] = (float)$row['rate'];
            $ts = strtotime($row['updated_at']);
            if ($ts > $latestUpdate) $latestUpdate = $ts;
        }
        $rates['_updated_at'] = $latestUpdate;
        return $rates;
    } catch (Exception $e) {
        error_log('[i18n] DB rate load error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Save rates to PostgreSQL (upsert).
 */
function saveRatesToDB(array $rates): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO om_currency_rates (base_currency, currency, rate, updated_at)
            VALUES ('BRL', :currency, :rate, NOW())
            ON CONFLICT (base_currency, currency)
            DO UPDATE SET rate = :rate2, updated_at = NOW()
        ");

        foreach ($rates as $currency => $rate) {
            if ($currency === 'BRL' || str_starts_with($currency, '_')) continue;
            $stmt->execute([
                ':currency' => $currency,
                ':rate'     => $rate,
                ':rate2'    => $rate,
            ]);
        }
    } catch (Exception $e) {
        error_log('[i18n] DB rate save error: ' . $e->getMessage());
    }
}

/**
 * Convert amount from one currency to another.
 *
 * Usage:
 *   echo convertCurrency(29.90, 'BRL', 'USD'); // ~5.38
 *   echo convertCurrency(10.00, 'USD', 'EUR'); // ~0.94
 */
function convertCurrency(float $amount, string $from, string $to): float {
    $from = strtoupper($from);
    $to = strtoupper($to);

    if ($from === $to) return $amount;

    $rates = fetchExchangeRates();

    // Convert to BRL first (base), then to target
    $fromRate = $rates[$from] ?? null;
    $toRate = $rates[$to] ?? null;

    if ($fromRate === null || $toRate === null || $fromRate == 0) {
        error_log("[i18n] Missing rate for conversion: {$from} -> {$to}");
        return $amount; // Return unconverted as safety fallback
    }

    // amount_in_BRL = amount / fromRate (since rates are BRL-based, e.g. USD=0.18 means 1 BRL = 0.18 USD)
    $amountInBRL = $amount / $fromRate;
    // amount_in_target = amountInBRL * toRate
    return round($amountInBRL * $toRate, 2);
}

/**
 * Create the om_currency_rates table if it doesn't exist.
 * Safe to call multiple times (idempotent).
 */
function ensureCurrencyRatesTable(): void {
    try {
        $db = getDB();
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_currency_rates (
                id SERIAL PRIMARY KEY,
                base_currency VARCHAR(3) NOT NULL DEFAULT 'BRL',
                currency VARCHAR(3) NOT NULL,
                rate NUMERIC(16, 6) NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(base_currency, currency)
            )
        ");
    } catch (Exception $e) {
        error_log('[i18n] Table creation error: ' . $e->getMessage());
    }
}
