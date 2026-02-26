<?php
/**
 * Generate SVG placeholder images for products and partners
 * Run: php /var/www/html/api/mercado/helpers/generate-images.php
 *
 * Creates attractive SVGs with gradients and category icons for:
 * - Products: 400x300 with category gradient + emoji + product name
 * - Partner logos: 200x200 circular with initial + gradient
 * - Partner banners: 800x300 with gradient + name + category
 *
 * Updates the database with the image paths.
 */

// SECURITY: Only allow CLI execution
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }
define('SKIP_RATE_LIMIT', true);

require_once __DIR__ . "/../config/database.php";

$db = getDB();

// Category color gradients and emojis
$categoryStyles = [
    'Leites' =>      ['#4FC3F7', '#0288D1', '🥛'],
    'Queijos' =>     ['#FFD54F', '#FFA000', '🧀'],
    'Iogurtes' =>    ['#CE93D8', '#8E24AA', '🥄'],
    'Manteigas' =>   ['#FFE082', '#FFB300', '🧈'],
    'Ovos' =>        ['#FFCC80', '#EF6C00', '🥚'],
    'Carne Bovina' => ['#EF5350', '#C62828', '🥩'],
    'Carne Suína' => ['#F48FB1', '#AD1457', '🐷'],
    'Frango' =>      ['#FFAB91', '#D84315', '🍗'],
    'Peixes' =>      ['#80DEEA', '#00838F', '🐟'],
    'Embutidos' =>   ['#E57373', '#B71C1C', '🥓'],
    'Frutas' =>      ['#A5D6A7', '#2E7D32', '🍎'],
    'Verduras' =>    ['#81C784', '#1B5E20', '🥬'],
    'Legumes' =>     ['#FFB74D', '#E65100', '🥕'],
    'Pães' =>        ['#D7CCC8', '#795548', '🍞'],
    'Pizzas' =>      ['#FF8A65', '#BF360C', '🍕'],
    'Hambúrgueres' => ['#A1887F', '#4E342E', '🍔'],
    'Empanados' =>   ['#FFAB91', '#BF360C', '🍗'],
    'Batatas' =>     ['#FFF176', '#F9A825', '🍟'],
    'Sorvetes' =>    ['#B3E5FC', '#0277BD', '🍦'],
];
$defaultStyle = ['#90CAF9', '#1565C0', '🛒'];

// Partner category gradients
$partnerCategoryStyles = [
    'supermercado' => ['#43A047', '#1B5E20'],
    'mercado' =>     ['#2E7D32', '#1B5E20'],
    'restaurante' => ['#E65100', '#BF360C'],
    'farmacia' =>    ['#1565C0', '#0D47A1'],
    'loja' =>        ['#6A1B9A', '#4A148C'],
    'padaria' =>     ['#795548', '#3E2723'],
    'acougue' =>     ['#C62828', '#B71C1C'],
    'hortifruti' =>  ['#2E7D32', '#1B5E20'],
    'petshop' =>     ['#00838F', '#006064'],
    'bebidas' =>     ['#F9A825', '#F57F17'],
];
$defaultPartnerStyle = ['#546E7A', '#263238'];

/**
 * Generate product SVG placeholder (400x300)
 */
function generateProductSVG($name, $categoryName, $categoryStyles, $defaultStyle) {
    $style = $categoryStyles[$categoryName] ?? $defaultStyle;
    $color1 = $style[0];
    $color2 = $style[1];
    $emoji = $style[2];

    // Truncate name for display
    $displayName = mb_strlen($name) > 28 ? mb_substr($name, 0, 25) . '...' : $name;
    $displayName = htmlspecialchars($displayName, ENT_XML1, 'UTF-8');
    $catDisplay = htmlspecialchars($categoryName ?: 'Produto', ENT_XML1, 'UTF-8');

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:{$color1};stop-opacity:1"/>
      <stop offset="100%" style="stop-color:{$color2};stop-opacity:1"/>
    </linearGradient>
  </defs>
  <rect width="400" height="300" fill="url(#bg)" rx="12"/>
  <text x="200" y="120" text-anchor="middle" font-size="72">{$emoji}</text>
  <text x="200" y="185" text-anchor="middle" font-family="system-ui,sans-serif" font-size="18" font-weight="700" fill="white">{$displayName}</text>
  <text x="200" y="215" text-anchor="middle" font-family="system-ui,sans-serif" font-size="13" fill="rgba(255,255,255,0.8)">{$catDisplay}</text>
</svg>
SVG;
}

/**
 * Generate partner logo SVG (200x200 circular)
 */
function generateLogoSVG($name, $categoria, $partnerCategoryStyles, $defaultPartnerStyle) {
    $style = $partnerCategoryStyles[$categoria] ?? $defaultPartnerStyle;
    $color1 = $style[0];
    $color2 = $style[1];

    $initial = mb_strtoupper(mb_substr(trim($name), 0, 1));
    $initial = htmlspecialchars($initial, ENT_XML1, 'UTF-8');

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:{$color1};stop-opacity:1"/>
      <stop offset="100%" style="stop-color:{$color2};stop-opacity:1"/>
    </linearGradient>
  </defs>
  <circle cx="100" cy="100" r="96" fill="url(#bg)" stroke="white" stroke-width="4"/>
  <text x="100" y="118" text-anchor="middle" font-family="system-ui,sans-serif" font-size="80" font-weight="700" fill="white">{$initial}</text>
</svg>
SVG;
}

/**
 * Generate partner banner SVG (800x300)
 */
function generateBannerSVG($name, $categoria, $partnerCategoryStyles, $defaultPartnerStyle) {
    $style = $partnerCategoryStyles[$categoria] ?? $defaultPartnerStyle;
    $color1 = $style[0];
    $color2 = $style[1];

    $displayName = htmlspecialchars($name, ENT_XML1, 'UTF-8');
    $catDisplay = htmlspecialchars(ucfirst($categoria ?: 'Loja'), ENT_XML1, 'UTF-8');

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="300" viewBox="0 0 800 300">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:{$color1};stop-opacity:1"/>
      <stop offset="100%" style="stop-color:{$color2};stop-opacity:1"/>
    </linearGradient>
  </defs>
  <rect width="800" height="300" fill="url(#bg)" rx="16"/>
  <circle cx="680" cy="150" r="120" fill="rgba(255,255,255,0.08)"/>
  <circle cx="720" cy="80" r="60" fill="rgba(255,255,255,0.05)"/>
  <text x="60" y="140" font-family="system-ui,sans-serif" font-size="36" font-weight="700" fill="white">{$displayName}</text>
  <text x="60" y="180" font-family="system-ui,sans-serif" font-size="18" fill="rgba(255,255,255,0.85)">{$catDisplay}</text>
  <rect x="60" y="210" width="120" height="36" rx="18" fill="rgba(255,255,255,0.2)"/>
  <text x="120" y="234" text-anchor="middle" font-family="system-ui,sans-serif" font-size="14" font-weight="600" fill="white">Ver loja</text>
</svg>
SVG;
}

// ====== MAIN EXECUTION ======

$productsDir = '/var/www/html/uploads/products';
$logosDir = '/var/www/html/uploads/logos';
$bannersDir = '/var/www/html/uploads/banners';

// Ensure directories exist
foreach ([$productsDir, $logosDir, $bannersDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

echo "=== Generating Product Images ===\n";

// Get all products without images
$products = $db->query("
    SELECT p.id, p.name, c.name as category_name
    FROM om_market_products p
    LEFT JOIN om_market_categories c ON p.category_id = c.category_id
    WHERE p.image IS NULL OR p.image = ''
")->fetchAll();

echo "Found " . count($products) . " products without images\n";

$productCount = 0;
foreach ($products as $p) {
    $filename = "prod_{$p['id']}.svg";
    $filepath = "{$productsDir}/{$filename}";
    $dbPath = "/uploads/products/{$filename}";

    $svg = generateProductSVG($p['name'], $p['category_name'], $categoryStyles, $defaultStyle);
    file_put_contents($filepath, $svg);

    $stmt = $db->prepare("UPDATE om_market_products SET image = ? WHERE id = ?");
    $stmt->execute([$dbPath, $p['id']]);
    $productCount++;
}
echo "Generated {$productCount} product images\n";

echo "\n=== Generating Partner Logos ===\n";

$partners = $db->query("
    SELECT partner_id, name, categoria
    FROM om_market_partners
    WHERE logo IS NULL OR logo = ''
")->fetchAll();

echo "Found " . count($partners) . " partners without logos\n";

$logoCount = 0;
foreach ($partners as $p) {
    $filename = "partner_{$p['partner_id']}.svg";
    $filepath = "{$logosDir}/{$filename}";
    $dbPath = "/uploads/logos/{$filename}";

    $svg = generateLogoSVG($p['name'], $p['categoria'], $partnerCategoryStyles, $defaultPartnerStyle);
    file_put_contents($filepath, $svg);

    $stmt = $db->prepare("UPDATE om_market_partners SET logo = ? WHERE partner_id = ?");
    $stmt->execute([$dbPath, $p['partner_id']]);
    $logoCount++;
}
echo "Generated {$logoCount} partner logos\n";

echo "\n=== Generating Partner Banners ===\n";

$partners = $db->query("
    SELECT partner_id, name, categoria
    FROM om_market_partners
    WHERE banner IS NULL OR banner = ''
")->fetchAll();

echo "Found " . count($partners) . " partners without banners\n";

$bannerCount = 0;
foreach ($partners as $p) {
    $filename = "banner_{$p['partner_id']}.svg";
    $filepath = "{$bannersDir}/{$filename}";
    $dbPath = "/uploads/banners/{$filename}";

    $svg = generateBannerSVG($p['name'], $p['categoria'], $partnerCategoryStyles, $defaultPartnerStyle);
    file_put_contents($filepath, $svg);

    $stmt = $db->prepare("UPDATE om_market_partners SET banner = ? WHERE partner_id = ?");
    $stmt->execute([$dbPath, $p['partner_id']]);
    $bannerCount++;
}
echo "Generated {$bannerCount} partner banners\n";

echo "\n=== Summary ===\n";
echo "Products: {$productCount} images generated\n";
echo "Logos: {$logoCount} generated\n";
echo "Banners: {$bannerCount} generated\n";
echo "Done!\n";
