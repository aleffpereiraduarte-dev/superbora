<?php
/**
 * AUTO BACKGROUND REMOVER - VERSÃO HOSPEDAGEM COMPARTILHADA
 * 
 * Usa Remove.bg API (50 grátis/mês) + fallback GD
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIGURAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

// API Key do Remove.bg - Pegue grátis em: https://www.remove.bg/api
define('REMOVEBG_API_KEY', ''); // Deixe vazio para usar só GD

// Cor do fundo (verde claro OneMundo)
define('BG_R', 240);
define('BG_G', 253);
define('BG_B', 244);

// Tamanho final
define('OUTPUT_SIZE', 800);

// Quantas processar por vez
define('BATCH_SIZE', 5);

// ═══════════════════════════════════════════════════════════════════════════════
// CONEXÃO BANCO
// ═══════════════════════════════════════════════════════════════════════════════

$configPath = dirname(__DIR__) . '/config.php';
if (!file_exists($configPath)) {
    die("Config não encontrado");
}
require_once $configPath;

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// Criar tabela de controle
$pdo->exec("
    CREATE TABLE IF NOT EXISTS om_processed_images (
        product_id INT PRIMARY KEY,
        original_hash VARCHAR(32),
        processed_at DATETIME,
        status ENUM('ok','error') DEFAULT 'ok',
        method VARCHAR(20) DEFAULT 'gd'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES DE PROCESSAMENTO
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Remove fundo usando Remove.bg API
 */
function removeBgAPI($imagePath) {
    if (empty(REMOVEBG_API_KEY)) {
        return false;
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.remove.bg/v1.0/removebg',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . REMOVEBG_API_KEY],
        CURLOPT_POSTFIELDS => [
            'image_file' => new CURLFile($imagePath),
            'size' => 'auto',
            'format' => 'png'
        ],
        CURLOPT_TIMEOUT => 60
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        return $response;
    }
    
    return false;
}

/**
 * Remove fundo usando GD (funciona melhor com fundos claros/brancos)
 */
function removeBgGD($imagePath, $tolerance = 40) {
    $info = @getimagesize($imagePath);
    if (!$info) return false;
    
    switch ($info['mime']) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($imagePath); break;
        case 'image/png': $img = @imagecreatefrompng($imagePath); break;
        case 'image/webp': $img = @imagecreatefromwebp($imagePath); break;
        case 'image/gif': $img = @imagecreatefromgif($imagePath); break;
        default: return false;
    }
    
    if (!$img) return false;
    
    $w = imagesx($img);
    $h = imagesy($img);
    
    $out = imagecreatetruecolor($w, $h);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefill($out, 0, 0, $transparent);
    
    // Detectar cor do fundo (cantos)
    $corners = [
        imagecolorat($img, 2, 2),
        imagecolorat($img, $w - 3, 2),
        imagecolorat($img, 2, $h - 3),
        imagecolorat($img, $w - 3, $h - 3)
    ];
    
    $bgColor = array_count_values($corners);
    arsort($bgColor);
    $bg = array_key_first($bgColor);
    
    $bgR = ($bg >> 16) & 0xFF;
    $bgG = ($bg >> 8) & 0xFF;
    $bgB = $bg & 0xFF;
    
    for ($x = 0; $x < $w; $x++) {
        for ($y = 0; $y < $h; $y++) {
            $color = imagecolorat($img, $x, $y);
            $r = ($color >> 16) & 0xFF;
            $g = ($color >> 8) & 0xFF;
            $b = $color & 0xFF;
            
            $diff = abs($r - $bgR) + abs($g - $bgG) + abs($b - $bgB);
            
            if ($diff > $tolerance) {
                $newColor = imagecolorallocate($out, $r, $g, $b);
                imagesetpixel($out, $x, $y, $newColor);
            }
        }
    }
    
    imagedestroy($img);
    
    ob_start();
    imagepng($out);
    $data = ob_get_clean();
    imagedestroy($out);
    
    return $data;
}

/**
 * Aplica fundo colorido e centraliza
 */
function applyBackground($pngData, $outputPath) {
    $img = @imagecreatefromstring($pngData);
    if (!$img) return false;
    
    $origW = imagesx($img);
    $origH = imagesy($img);
    
    // Calcular tamanho (85% do espaço)
    $padding = OUTPUT_SIZE * 0.10;
    $available = OUTPUT_SIZE - ($padding * 2);
    $ratio = min($available / $origW, $available / $origH);
    $newW = (int)($origW * $ratio);
    $newH = (int)($origH * $ratio);
    
    // Criar imagem final
    $final = imagecreatetruecolor(OUTPUT_SIZE, OUTPUT_SIZE);
    $bg = imagecolorallocate($final, BG_R, BG_G, BG_B);
    imagefill($final, 0, 0, $bg);
    
    // Redimensionar
    $resized = imagecreatetruecolor($newW, $newH);
    imagesavealpha($resized, true);
    $trans = imagecolorallocatealpha($resized, 0, 0, 0, 127);
    imagefill($resized, 0, 0, $trans);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    
    // Centralizar
    $x = (int)((OUTPUT_SIZE - $newW) / 2);
    $y = (int)((OUTPUT_SIZE - $newH) / 2);
    
    imagecopy($final, $resized, $x, $y, 0, 0, $newW, $newH);
    
    $result = imagepng($final, $outputPath, 6);
    
    imagedestroy($img);
    imagedestroy($resized);
    imagedestroy($final);
    
    return $result;
}

/**
 * Processa uma imagem completa
 */
function processImage($inputPath, $outputPath) {
    $pngData = removeBgAPI($inputPath);
    $method = 'api';
    
    if (!$pngData) {
        $pngData = removeBgGD($inputPath);
        $method = 'gd';
    }
    
    if (!$pngData) {
        return ['success' => false, 'error' => 'Falha ao remover fundo'];
    }
    
    if (applyBackground($pngData, $outputPath)) {
        return ['success' => true, 'method' => $method];
    }
    
    return ['success' => false, 'error' => 'Falha ao salvar'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// BUSCAR PRODUTOS
// ═══════════════════════════════════════════════════════════════════════════════

$sql = "
    SELECT p.product_id, p.image, pd.name
    FROM oc_product p
    LEFT JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 1
    LEFT JOIN om_processed_images pi ON p.product_id = pi.product_id
    WHERE p.image IS NOT NULL 
    AND p.image != ''
    AND p.image NOT LIKE '%_auto.png'
    AND (pi.product_id IS NULL OR pi.status = 'error')
    ORDER BY p.date_added DESC
    LIMIT " . BATCH_SIZE;

$produtos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════════
// PROCESSAR
// ═══════════════════════════════════════════════════════════════════════════════

$imageBase = dirname(__DIR__) . '/image/';
$processed = 0;
$errors = 0;
$log = [];

foreach ($produtos as $p) {
    $inputPath = $imageBase . $p['image'];
    
    if (!file_exists($inputPath)) {
        $pdo->prepare("INSERT INTO om_processed_images (product_id, status) VALUES (?, 'error') ON DUPLICATE KEY UPDATE status = 'error'")
            ->execute([$p['product_id']]);
        $log[] = ['id' => $p['product_id'], 'status' => 'error', 'msg' => 'Arquivo não existe'];
        $errors++;
        continue;
    }
    
    $dir = dirname($inputPath);
    $filename = pathinfo($p['image'], PATHINFO_FILENAME);
    $outputPath = $dir . '/' . $filename . '_auto.png';
    $newImagePath = dirname($p['image']) . '/' . $filename . '_auto.png';
    
    $result = processImage($inputPath, $outputPath);
    
    if ($result['success']) {
        $pdo->prepare("UPDATE oc_product SET image = ? WHERE product_id = ?")
            ->execute([$newImagePath, $p['product_id']]);
        
        $hash = md5_file($inputPath);
        $pdo->prepare("INSERT INTO om_processed_images (product_id, original_hash, processed_at, status, method) VALUES (?, ?, NOW(), 'ok', ?) ON DUPLICATE KEY UPDATE status = 'ok', method = ?, processed_at = NOW()")
            ->execute([$p['product_id'], $hash, $result['method'], $result['method']]);
        
        $log[] = ['id' => $p['product_id'], 'status' => 'ok', 'method' => $result['method'], 'name' => $p['name']];
        $processed++;
    } else {
        $pdo->prepare("INSERT INTO om_processed_images (product_id, status) VALUES (?, 'error') ON DUPLICATE KEY UPDATE status = 'error'")
            ->execute([$p['product_id']]);
        $log[] = ['id' => $p['product_id'], 'status' => 'error', 'msg' => $result['error']];
        $errors++;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ESTATÍSTICAS
// ═══════════════════════════════════════════════════════════════════════════════

$total = $pdo->query("SELECT COUNT(*) FROM oc_product WHERE image IS NOT NULL AND image != ''")->fetchColumn();
$done = $pdo->query("SELECT COUNT(*) FROM om_processed_images WHERE status = 'ok'")->fetchColumn();
$pending = $total - $done;
$errorCount = $pdo->query("SELECT COUNT(*) FROM om_processed_images WHERE status = 'error'")->fetchColumn();
$percent = $total > 0 ? round($done / $total * 100, 1) : 0;

$recent = $pdo->query("
    SELECT p.product_id, p.image, pd.name, pi.method
    FROM om_processed_images pi
    JOIN oc_product p ON pi.product_id = p.product_id
    LEFT JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 1
    WHERE pi.status = 'ok'
    ORDER BY pi.processed_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30">
    <title>🤖 Auto BG Remover</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:system-ui;background:#0f172a;color:#e2e8f0;padding:20px;min-height:100vh}
        .container{max-width:1200px;margin:0 auto}
        h1{color:#10b981;margin-bottom:10px;font-size:1.8rem}
        .subtitle{color:#64748b;margin-bottom:30px}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin-bottom:25px}
        .stat{background:#1e293b;border-radius:12px;padding:20px;text-align:center}
        .stat-value{font-size:2rem;font-weight:800}
        .blue{color:#3b82f6}.green{color:#10b981}.yellow{color:#f59e0b}.red{color:#ef4444}
        .stat-label{color:#64748b;font-size:12px;margin-top:5px}
        .progress{background:#1e293b;border-radius:12px;padding:20px;margin-bottom:25px}
        .bar{height:16px;background:#334155;border-radius:8px;overflow:hidden}
        .fill{height:100%;background:linear-gradient(90deg,#10b981,#06b6d4);transition:width .5s}
        .progress p{margin-top:10px;color:#94a3b8;font-size:14px}
        .log{background:#1e293b;border-radius:12px;padding:20px;margin-bottom:25px}
        .log h3{color:#10b981;margin-bottom:15px;font-size:1rem}
        .log-item{padding:8px 12px;margin:5px 0;border-radius:6px;font-size:13px}
        .log-item.ok{background:rgba(16,185,129,.1);color:#10b981}
        .log-item.error{background:rgba(239,68,68,.1);color:#ef4444}
        .recent{background:#1e293b;border-radius:12px;padding:20px}
        .recent h3{color:#10b981;margin-bottom:15px;font-size:1rem}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px}
        .item{background:#334155;border-radius:10px;overflow:hidden}
        .item img{width:100%;aspect-ratio:1;object-fit:contain;background:linear-gradient(180deg,#f0fdf4,#dcfce7)}
        .item p{padding:8px;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .item .method{font-size:10px;color:#64748b;padding:0 8px 8px}
        .info{background:#1e293b;border-radius:12px;padding:20px;margin-bottom:25px}
        .info h3{color:#f59e0b;margin-bottom:10px;font-size:1rem}
        .info p{color:#94a3b8;font-size:13px;line-height:1.6}
        .info a{color:#3b82f6}
        .refresh{color:#64748b;font-size:11px;margin-top:20px;text-align:center}
    </style>

<!-- HEADER PREMIUM v3.0 -->
<style>

/* ══════════════════════════════════════════════════════════════════════════════
   🎨 HEADER PREMIUM v3.0 - OneMundo Mercado
   ══════════════════════════════════════════════════════════════════════════════ */

/* Variáveis do Header */
:root {
    --header-bg: rgba(255, 255, 255, 0.92);
    --header-bg-scrolled: rgba(255, 255, 255, 0.98);
    --header-blur: 20px;
    --header-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
    --header-border: rgba(0, 0, 0, 0.04);
    --header-height: 72px;
    --header-height-mobile: 64px;
}

/* Header Principal */
.header, .site-header, [class*="header-main"] {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1000 !important;
    background: var(--header-bg) !important;
    backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    -webkit-backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    border-bottom: 1px solid var(--header-border) !important;
    box-shadow: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    height: auto !important;
    min-height: var(--header-height) !important;
}

.header.scrolled, .site-header.scrolled {
    background: var(--header-bg-scrolled) !important;
    box-shadow: var(--header-shadow) !important;
}

/* Container do Header */
.header-inner, .header-content, .header > div:first-child {
    max-width: 1400px !important;
    margin: 0 auto !important;
    padding: 12px 24px !important;
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOCALIZAÇÃO - Estilo Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.location-btn, .endereco, [class*="location"], [class*="endereco"], [class*="address"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 18px !important;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.04)) !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    min-width: 200px !important;
    max-width: 320px !important;
}

.location-btn:hover, .endereco:hover, [class*="location"]:hover {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.06)) !important;
    border-color: rgba(16, 185, 129, 0.25) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15) !important;
}

/* Ícone de localização */
.location-btn svg, .location-btn i, [class*="location"] svg {
    width: 22px !important;
    height: 22px !important;
    color: #10b981 !important;
    flex-shrink: 0 !important;
}

/* Texto da localização */
.location-text, .endereco-text {
    flex: 1 !important;
    min-width: 0 !important;
}

.location-label, .entregar-em {
    font-size: 11px !important;
    font-weight: 500 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 2px !important;
}

.location-address, .endereco-rua {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Seta da localização */
.location-arrow, .location-btn > svg:last-child {
    width: 16px !important;
    height: 16px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s ease !important;
}

.location-btn:hover .location-arrow {
    transform: translateX(3px) !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TEMPO DE ENTREGA - Badge Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.delivery-time, .tempo-entrega, [class*="delivery-time"], [class*="tempo"] {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 10px 16px !important;
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
    border-radius: 12px !important;
    color: white !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2) !important;
    transition: all 0.3s ease !important;
}

.delivery-time:hover, .tempo-entrega:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.25) !important;
}

.delivery-time svg, .tempo-entrega svg, .delivery-time i {
    width: 18px !important;
    height: 18px !important;
    color: #10b981 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOGO - Design Moderno
   ══════════════════════════════════════════════════════════════════════════════ */

.logo, .site-logo, [class*="logo"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    transition: transform 0.3s ease !important;
}

.logo:hover {
    transform: scale(1.02) !important;
}

.logo-icon, .logo img, .logo svg {
    width: 48px !important;
    height: 48px !important;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border-radius: 14px !important;
    padding: 10px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.logo:hover .logo-icon, .logo:hover img {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
    transform: rotate(-3deg) !important;
}

.logo-text, .logo span, .site-title {
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    letter-spacing: -0.02em !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   BUSCA - Search Bar Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.search-container, .search-box, [class*="search"], .busca {
    flex: 1 !important;
    max-width: 600px !important;
    position: relative !important;
}

.search-input, input[type="search"], input[name*="search"], input[name*="busca"], .busca input {
    width: 100% !important;
    padding: 14px 20px 14px 52px !important;
    background: #f1f5f9 !important;
    border: 2px solid transparent !important;
    border-radius: 16px !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
}

.search-input:hover, input[type="search"]:hover {
    background: #e2e8f0 !important;
}

.search-input:focus, input[type="search"]:focus {
    background: #ffffff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12), inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
    outline: none !important;
}

.search-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 400 !important;
}

/* Ícone da busca */
.search-icon, .search-container svg, .busca svg {
    position: absolute !important;
    left: 18px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 22px !important;
    height: 22px !important;
    color: #94a3b8 !important;
    pointer-events: none !important;
    transition: color 0.3s ease !important;
}

.search-input:focus + .search-icon,
.search-container:focus-within svg {
    color: #10b981 !important;
}

/* Botão de busca por voz (opcional) */
.search-voice-btn {
    position: absolute !important;
    right: 12px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px !important;
    height: 36px !important;
    background: transparent !important;
    border: none !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.search-voice-btn:hover {
    background: rgba(16, 185, 129, 0.1) !important;
}

.search-voice-btn svg {
    width: 20px !important;
    height: 20px !important;
    color: #64748b !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CARRINHO - Cart Button Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.cart-btn, .carrinho-btn, [class*="cart"], [class*="carrinho"], a[href*="cart"], a[href*="carrinho"] {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 52px !important;
    height: 52px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border: none !important;
    border-radius: 16px !important;
    cursor: pointer !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
}

.cart-btn:hover, .carrinho-btn:hover, [class*="cart"]:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
}

.cart-btn:active {
    transform: translateY(-1px) scale(0.98) !important;
}

.cart-btn svg, .carrinho-btn svg, [class*="cart"] svg {
    width: 26px !important;
    height: 26px !important;
    color: white !important;
}

/* Badge do carrinho */
.cart-badge, .carrinho-badge, [class*="cart-count"], [class*="badge"] {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 24px !important;
    height: 24px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 6px !important;
    border: 3px solid white !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: badge-pulse 2s ease-in-out infinite !important;
}

@keyframes badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* ══════════════════════════════════════════════════════════════════════════════
   MENU MOBILE
   ══════════════════════════════════════════════════════════════════════════════ */

.menu-btn, .hamburger, [class*="menu-toggle"] {
    display: none !important;
    width: 44px !important;
    height: 44px !important;
    background: #f1f5f9 !important;
    border: none !important;
    border-radius: 12px !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.menu-btn:hover {
    background: #e2e8f0 !important;
}

.menu-btn svg {
    width: 24px !important;
    height: 24px !important;
    color: #475569 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESPONSIVO
   ══════════════════════════════════════════════════════════════════════════════ */

@media (max-width: 1024px) {
    .search-container, .search-box {
        max-width: 400px !important;
    }
    
    .location-btn, .endereco {
        max-width: 250px !important;
    }
}

@media (max-width: 768px) {
    :root {
        --header-height: var(--header-height-mobile);
    }
    
    .header-inner, .header-content {
        padding: 10px 16px !important;
        gap: 12px !important;
    }
    
    /* Esconder busca no header mobile - mover para baixo */
    .search-container, .search-box, [class*="search"]:not(.search-icon) {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        max-width: 100% !important;
        padding: 12px 16px !important;
        background: white !important;
        border-top: 1px solid #e2e8f0 !important;
        display: none !important;
    }
    
    .search-container.active {
        display: block !important;
    }
    
    /* Logo menor */
    .logo-icon, .logo img {
        width: 42px !important;
        height: 42px !important;
        border-radius: 12px !important;
    }
    
    .logo-text {
        display: none !important;
    }
    
    /* Localização compacta */
    .location-btn, .endereco {
        min-width: auto !important;
        max-width: 180px !important;
        padding: 8px 12px !important;
    }
    
    .location-label, .entregar-em {
        display: none !important;
    }
    
    .location-address {
        font-size: 13px !important;
    }
    
    /* Tempo de entrega menor */
    .delivery-time, .tempo-entrega {
        padding: 8px 12px !important;
        font-size: 12px !important;
    }
    
    /* Carrinho menor */
    .cart-btn, .carrinho-btn {
        width: 46px !important;
        height: 46px !important;
        border-radius: 14px !important;
    }
    
    .cart-btn svg {
        width: 22px !important;
        height: 22px !important;
    }
    
    /* Mostrar menu button */
    .menu-btn, .hamburger {
        display: flex !important;
    }
}

@media (max-width: 480px) {
    .location-btn, .endereco {
        max-width: 140px !important;
    }
    
    .delivery-time, .tempo-entrega {
        display: none !important;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   ANIMAÇÕES DE ENTRADA
   ══════════════════════════════════════════════════════════════════════════════ */

@keyframes headerSlideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.header, .site-header {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *, .header-content > * {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *:nth-child(1) { animation-delay: 0.05s !important; }
.header-inner > *:nth-child(2) { animation-delay: 0.1s !important; }
.header-inner > *:nth-child(3) { animation-delay: 0.15s !important; }
.header-inner > *:nth-child(4) { animation-delay: 0.2s !important; }
.header-inner > *:nth-child(5) { animation-delay: 0.25s !important; }

/* ══════════════════════════════════════════════════════════════════════════════
   AJUSTES DE BODY PARA HEADER FIXED
   ══════════════════════════════════════════════════════════════════════════════ */

body {
    padding-top: calc(var(--header-height) + 10px) !important;
}

@media (max-width: 768px) {
    body {
        padding-top: calc(var(--header-height-mobile) + 10px) !important;
    }
}

</style>
</head>
<body>
<div class="container">
    <h1>🤖 Auto Background Remover</h1>
    <p class="subtitle">Remove fundo e aplica verde padrão automaticamente</p>
    
    <?php if(empty(REMOVEBG_API_KEY)):?>
    <div class="info">
        <h3>💡 Dica: Use a API do Remove.bg</h3>
        <p>Para resultados melhores, pegue uma API key grátis em <a href="https://www.remove.bg/api" target="_blank">remove.bg/api</a> (50/mês grátis).</p>
    </div>
    <?php endif;?>
    
    <div class="stats">
        <div class="stat"><div class="stat-value blue"><?=$total?></div><div class="stat-label">Total</div></div>
        <div class="stat"><div class="stat-value green"><?=$done?></div><div class="stat-label">Processados</div></div>
        <div class="stat"><div class="stat-value yellow"><?=$pending?></div><div class="stat-label">Pendentes</div></div>
        <div class="stat"><div class="stat-value red"><?=$errorCount?></div><div class="stat-label">Erros</div></div>
    </div>
    
    <div class="progress">
        <div class="bar"><div class="fill" style="width:<?=$percent?>%"></div></div>
        <p><?=$percent?>% concluído (<?=$done?>/<?=$total?>)</p>
    </div>
    
    <?php if(!empty($log)):?>
    <div class="log">
        <h3>📋 Esta Execução</h3>
        <?php foreach($log as $l):?>
        <div class="log-item <?=$l['status']?>">
            <?php if($l['status']==='ok'):?>
                ✅ #<?=$l['id']?> - <?=htmlspecialchars($l['name']??'Produto')?> (<?=$l['method']?>)
            <?php else:?>
                ❌ #<?=$l['id']?> - <?=htmlspecialchars($l['msg']??'Erro')?>
            <?php endif;?>
        </div>
        <?php endforeach;?>
    </div>
    <?php endif;?>
    
    <?php if(!empty($recent)):?>
    <div class="recent">
        <h3>✅ Últimos Processados</h3>
        <div class="grid">
            <?php foreach($recent as $r):?>
            <div class="item">
                <img src="/image/<?=htmlspecialchars($r['image'])?>" alt="">
                <p><?=htmlspecialchars($r['name']??'#'.$r['product_id'])?></p>
                <p class="method"><?=$r['method']==='api'?'🌐 API':'🖼️ GD'?></p>
            </div>
            <?php endforeach;?>
        </div>
    </div>
    <?php endif;?>
    
    <p class="refresh">🔄 Atualiza a cada 30s | Processados agora: <?=$processed?></p>
</div>

<script>
// Header scroll effect
(function() {
    const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    if (!header) return;
    
    let lastScroll = 0;
    let ticking = false;
    
    function updateHeader() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        // Hide/show on scroll (opcional)
        /*
        if (currentScroll > lastScroll && currentScroll > 100) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        */
        
        lastScroll = currentScroll;
        ticking = false;
    }
    
    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(updateHeader);
            ticking = true;
        }
    });
    
    // Cart badge animation
    window.animateCartBadge = function() {
        const badge = document.querySelector('.cart-badge, .carrinho-badge, [class*="cart-count"]');
        if (badge) {
            badge.style.transform = 'scale(1.3)';
            setTimeout(() => {
                badge.style.transform = 'scale(1)';
            }, 200);
        }
    };
    
    // Mobile search toggle
    const searchToggle = document.querySelector('.search-toggle, [class*="search-btn"]');
    const searchContainer = document.querySelector('.search-container, .search-box');
    
    if (searchToggle && searchContainer) {
        searchToggle.addEventListener('click', function() {
            searchContainer.classList.toggle('active');
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     🎨 ONEMUNDO HEADER PREMIUM v3.0 - CSS FINAL UNIFICADO
═══════════════════════════════════════════════════════════════════════════════ -->
<style id="om-header-final">
/* RESET */
.mkt-header, .mkt-header-row, .mkt-logo, .mkt-logo-box, .mkt-logo-text,
.mkt-user, .mkt-user-avatar, .mkt-guest, .mkt-cart, .mkt-cart-count, .mkt-search,
.om-topbar, .om-topbar-main, .om-topbar-icon, .om-topbar-content,
.om-topbar-label, .om-topbar-address, .om-topbar-arrow, .om-topbar-time {
    all: revert;
}

/* TOPBAR VERDE */
.om-topbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 20px !important;
    background: linear-gradient(135deg, #047857 0%, #059669 40%, #10b981 100%) !important;
    color: #fff !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    overflow: hidden !important;
}

.om-topbar::before {
    content: '' !important;
    position: absolute !important;
    top: 0 !important;
    left: -100% !important;
    width: 100% !important;
    height: 100% !important;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent) !important;
    transition: left 0.6s ease !important;
}

.om-topbar:hover::before { left: 100% !important; }
.om-topbar:hover { background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important; }

.om-topbar-main {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.om-topbar-icon {
    width: 40px !important;
    height: 40px !important;
    background: rgba(255,255,255,0.18) !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar:hover .om-topbar-icon {
    background: rgba(255,255,255,0.25) !important;
    transform: scale(1.05) !important;
}

.om-topbar-icon svg { width: 20px !important; height: 20px !important; color: #fff !important; }

.om-topbar-content { flex: 1 !important; min-width: 0 !important; }

.om-topbar-label {
    font-size: 11px !important;
    font-weight: 500 !important;
    opacity: 0.85 !important;
    margin-bottom: 2px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: block !important;
}

.om-topbar-address {
    font-size: 14px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 220px !important;
}

.om-topbar-arrow {
    width: 32px !important;
    height: 32px !important;
    background: rgba(255,255,255,0.12) !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    transition: all 0.3s ease !important;
    margin-right: 12px !important;
}

.om-topbar:hover .om-topbar-arrow {
    background: rgba(255,255,255,0.2) !important;
    transform: translateX(3px) !important;
}

.om-topbar-arrow svg { width: 16px !important; height: 16px !important; color: #fff !important; }

.om-topbar-time {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 8px 14px !important;
    background: rgba(0,0,0,0.2) !important;
    border-radius: 50px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar-time:hover { background: rgba(0,0,0,0.3) !important; transform: scale(1.02) !important; }
.om-topbar-time svg { width: 16px !important; height: 16px !important; color: #34d399 !important; }

/* HEADER BRANCO */
.mkt-header {
    background: #ffffff !important;
    padding: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 9999 !important;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08) !important;
    border-bottom: none !important;
}

.mkt-header-row {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 14px 20px !important;
    margin-bottom: 0 !important;
    background: #fff !important;
    border-bottom: 1px solid rgba(0,0,0,0.06) !important;
}

/* LOGO */
.mkt-logo {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
    flex-shrink: 0 !important;
}

.mkt-logo-box {
    width: 44px !important;
    height: 44px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-logo:hover .mkt-logo-box {
    transform: scale(1.05) rotate(-3deg) !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45) !important;
}

.mkt-logo-text {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: #10b981 !important;
    letter-spacing: -0.02em !important;
}

/* USER */
.mkt-user { margin-left: auto !important; text-decoration: none !important; }

.mkt-user-avatar {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 50% !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 16px !important;
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.mkt-user-avatar:hover {
    transform: scale(1.08) !important;
    box-shadow: 0 5px 18px rgba(16, 185, 129, 0.4) !important;
}

.mkt-user.mkt-guest {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: #f1f5f9 !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
}

.mkt-user.mkt-guest:hover { background: #e2e8f0 !important; }
.mkt-user.mkt-guest svg { width: 24px !important; height: 24px !important; color: #64748b !important; }

/* CARRINHO */
.mkt-cart {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 46px !important;
    height: 46px !important;
    background: linear-gradient(135deg, #1e293b, #0f172a) !important;
    border: none !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 15px rgba(15, 23, 42, 0.25) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-cart:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.3) !important;
}

.mkt-cart:active { transform: translateY(-1px) scale(0.98) !important; }
.mkt-cart svg { width: 22px !important; height: 22px !important; color: #fff !important; }

.mkt-cart-count {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 22px !important;
    height: 22px !important;
    padding: 0 6px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border-radius: 11px !important;
    color: #fff !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid #fff !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: cartPulse 2s ease-in-out infinite !important;
}

@keyframes cartPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

/* BUSCA */
.mkt-search {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    background: #f1f5f9 !important;
    border-radius: 14px !important;
    padding: 0 16px !important;
    margin: 0 16px 16px !important;
    border: 2px solid transparent !important;
    transition: all 0.3s ease !important;
}

.mkt-search:focus-within {
    background: #fff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
}

.mkt-search svg {
    width: 20px !important;
    height: 20px !important;
    color: #94a3b8 !important;
    flex-shrink: 0 !important;
    transition: color 0.3s ease !important;
}

.mkt-search:focus-within svg { color: #10b981 !important; }

.mkt-search input {
    flex: 1 !important;
    border: none !important;
    background: transparent !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    outline: none !important;
    padding: 14px 0 !important;
    width: 100% !important;
}

.mkt-search input::placeholder { color: #94a3b8 !important; }

/* RESPONSIVO */
@media (max-width: 480px) {
    .om-topbar { padding: 12px 16px !important; }
    .om-topbar-icon { width: 36px !important; height: 36px !important; }
    .om-topbar-address { max-width: 150px !important; font-size: 13px !important; }
    .om-topbar-arrow { display: none !important; }
    .om-topbar-time { padding: 6px 10px !important; font-size: 11px !important; }
    .mkt-header-row { padding: 12px 16px !important; }
    .mkt-logo-box { width: 40px !important; height: 40px !important; font-size: 18px !important; }
    .mkt-logo-text { font-size: 18px !important; }
    .mkt-cart { width: 42px !important; height: 42px !important; }
    .mkt-search { margin: 0 12px 12px !important; }
    .mkt-search input { font-size: 14px !important; padding: 12px 0 !important; }
}

/* ANIMAÇÕES */
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.mkt-header { animation: slideDown 0.4s ease !important; }

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
::selection { background: rgba(16, 185, 129, 0.2); color: #047857; }
</style>

<script>
(function() {
    var h = document.querySelector('.mkt-header');
    if (h && !document.querySelector('.om-topbar')) {
        var t = document.createElement('div');
        t.className = 'om-topbar';
        t.innerHTML = '<div class="om-topbar-main"><div class="om-topbar-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="om-topbar-content"><div class="om-topbar-label">Entregar em</div><div class="om-topbar-address" id="omAddrFinal">Carregando...</div></div><div class="om-topbar-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div></div><div class="om-topbar-time"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>25-35 min</div>';
        h.insertBefore(t, h.firstChild);
        fetch('/mercado/api/address.php?action=list').then(r=>r.json()).then(d=>{var el=document.getElementById('omAddrFinal');if(el&&d.current)el.textContent=d.current.address_1||'Selecionar';}).catch(()=>{});
    }
    var l = document.querySelector('.mkt-logo');
    if (l && !l.querySelector('.mkt-logo-text')) {
        var s = document.createElement('span');
        s.className = 'mkt-logo-text';
        s.textContent = 'Mercado';
        l.appendChild(s);
    }
})();
</script>
</body>
</html>
