<?php
/**
 * CRON CAÇAR EAN V2 - Com mais logs e melhor busca
 */

set_time_limit(90);
header('Content-Type: text/plain; charset=utf-8');

// Carregar .env
require_once __DIR__ . '/includes/env_loader.php';

$config = [
    'openai_key' => env('OPENAI_API_KEY', ''),
    'serper_key' => env('SERPER_API_KEY', ''),
    'por_execucao' => 5
];

$pdo = getDbConnection();

// STATUS
if (isset($_GET['status'])) {
    header('Content-Type: application/json');
    $total = $pdo->query("SELECT COUNT(*) FROM om_market_products_base")->fetchColumn();
    $com_ean = $pdo->query("SELECT COUNT(*) FROM om_market_products_base WHERE barcode IS NOT NULL AND LENGTH(barcode) >= 8")->fetchColumn();
    echo json_encode(['total'=>(int)$total, 'com_ean'=>(int)$com_ean, 'sem_ean'=>(int)($total-$com_ean)]);
    exit;
}

echo "=== 🎯 CAÇADOR DE EAN V2 ===\n";
echo date('Y-m-d H:i:s') . "\n\n";

// Cache EANs
$eansExistentes = [];
$rows = $pdo->query("SELECT barcode FROM om_market_products_base WHERE barcode IS NOT NULL AND LENGTH(barcode)>=8")->fetchAll(PDO::FETCH_COLUMN);
foreach ($rows as $e) $eansExistentes[$e] = true;

// Produtos sem EAN
$produtos = $pdo->query("SELECT product_id, name, brand, image 
    FROM om_market_products_base 
    WHERE (barcode IS NULL OR barcode = '') 
    AND image LIKE 'http%' 
    AND image NOT LIKE '%clearbit%'
    ORDER BY RANDOM()
    LIMIT {$config['por_execucao']}")->fetchAll(PDO::FETCH_ASSOC);

if (empty($produtos)) { echo "Nenhum!\n"; exit; }

$encontrados = 0;

foreach ($produtos as $prod) {
    $id = $prod['product_id'];
    $nome = $prod['name'];
    $marca = $prod['brand'] ?? '';
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📦 $nome\n";
    echo "   Marca: $marca\n";
    
    // Buscar imagens do produto com "código barras" ou "EAN"
    $buscas = [
        "$nome $marca código barras",
        "$nome $marca barcode EAN",
        "$nome embalagem verso"
    ];
    
    $eanEncontrado = null;
    
    foreach ($buscas as $busca) {
        if ($eanEncontrado) break;
        
        echo "   🔍 Busca: $busca\n";
        
        $imagens = buscarImagens($busca, $config['serper_key']);
        echo "   📷 " . count($imagens) . " imagens encontradas\n";
        
        foreach ($imagens as $idx => $imgUrl) {
            if ($idx >= 3) break; // Max 3 imagens por busca
            
            echo "      [$idx] Analisando... ";
            
            $ean = lerEANdaImagem($imgUrl, $config['openai_key']);
            
            if ($ean) {
                echo "EAN: $ean ";
                
                if (validarEAN($ean) && !isset($eansExistentes[$ean])) {
                    echo "✅ VÁLIDO!\n";
                    $eanEncontrado = $ean;
                    break;
                } else {
                    echo "❌ inválido/duplicado\n";
                }
            } else {
                echo "sem EAN\n";
            }
            
            usleep(200000);
        }
    }
    
    if ($eanEncontrado) {
        $pdo->prepare("UPDATE om_market_products_base SET barcode=?, ai_validated=1, date_modified=NOW() WHERE product_id=?")
            ->execute([$eanEncontrado, $id]);
        $eansExistentes[$eanEncontrado] = true;
        echo "   💾 SALVO: $eanEncontrado\n";
        $encontrados++;
    } else {
        echo "   ❌ Não encontrado\n";
    }
    
    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "=== RESUMO: $encontrados EANs encontrados ===\n";

// ==================== FUNÇÕES ====================

function buscarImagens($termo, $apiKey) {
    $ch = curl_init('https://google.serper.dev/images');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['X-API-KEY: '.$apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'q' => $termo,
            'gl' => 'br',
            'hl' => 'pt-br',
            'num' => 10
        ])
    ]);
    
    $r = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        echo "      ERRO: $err\n";
        return [];
    }
    
    $d = json_decode($r, true);
    
    $imagens = [];
    if (!empty($d['images'])) {
        foreach ($d['images'] as $img) {
            $url = $img['imageUrl'] ?? '';
            if ($url && strlen($url) < 500) {
                $imagens[] = $url;
            }
        }
    }
    return $imagens;
}

function lerEANdaImagem($url, $apiKey) {
    $prompt = "Olhe esta imagem e procure um CÓDIGO DE BARRAS (aquelas barras pretas verticais com números embaixo).

Se encontrar, me diga os 13 NÚMEROS que aparecem embaixo das barras.
Código de barras brasileiro começa com 789.

Responda APENAS:
- Os 13 números se encontrar (ex: 7891234567890)
- A palavra NULL se não encontrar código de barras

Sua resposta:";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => 'high']]
                ]
            ]],
            'max_tokens' => 50,
            'temperature' => 0
        ])
    ]);
    
    $r = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) return null;
    
    $d = json_decode($r, true);
    $texto = trim($d['choices'][0]['message']['content'] ?? '');
    
    // Extrair números
    $numeros = preg_replace('/\D/', '', $texto);
    
    return (strlen($numeros) == 13) ? $numeros : null;
}

function validarEAN($ean) {
    if (!$ean || strlen($ean) != 13) return false;
    
    // Rejeitar padrões falsos
    if (preg_match('/^(0{13}|1{13}|7891234567890|7890{10})$/', $ean)) return false;
    if (preg_match('/(\d)\1{8,}/', $ean)) return false;
    
    return true;
}
