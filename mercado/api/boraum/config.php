<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  🚗 BORAUM INTEGRATION - Configuration                                       ║
 * ║  Configure your BoraUm API credentials here                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

// BoraUm API Configuration
// TODO: User will provide these credentials
define('BORAUM_API_URL', getenv('BORAUM_API_URL') ?: 'https://api.boraum.com.br');
define('BORAUM_API_KEY', getenv('BORAUM_API_KEY') ?: '');
define('BORAUM_API_SECRET', getenv('BORAUM_API_SECRET') ?: '');
define('BORAUM_WEBHOOK_SECRET', getenv('BORAUM_WEBHOOK_SECRET') ?: '');

// Delivery settings
define('BORAUM_AUTO_DISPATCH', true);  // Automatically dispatch when shopping is complete
define('BORAUM_VEHICLE_DEFAULT', 'moto'); // moto, carro, van
define('BORAUM_MAX_RETRIES', 3);
define('BORAUM_RETRY_DELAY', 5); // seconds

// Status mapping BoraUm -> OneMundo
$BORAUM_STATUS_MAP = [
    'pending' => 'purchased',
    'accepted' => 'delivering',
    'picked_up' => 'delivering',
    'in_transit' => 'delivering',
    'arrived' => 'delivering',
    'delivered' => 'delivered',
    'cancelled' => 'cancelled',
    'failed' => 'purchased' // Return to purchased if delivery fails
];
