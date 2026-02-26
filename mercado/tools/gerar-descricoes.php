<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ════════════════════════════════════════════════════════════════════════════════
 * GERADOR DE DESCRIÇÕES INTELIGENTES COM CLAUDE AI
 * ════════════════════════════════════════════════════════════════════════════════
 * Analisa produtos com descrições ruins e gera novas descrições usando IA
 * ════════════════════════════════════════════════════════════════════════════════
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

// Configurações
$BATCH_SIZE = 10; // Produtos por batch
$MAX_PRODUTOS = isset($argv[1]) ? (int)$argv[1] : 50; // Limite total
$DRY_RUN = isset($argv[2]) && $argv[2] === '--dry-run';

echo "════════════════════════════════════════════════════════════════════\n";
echo "  GERADOR DE DESCRIÇÕES INTELIGENTES - ONEMUNDO MERCADO\n";
echo "════════════════════════════════════════════════════════════════════\n\n";

// Carregar API Key
$env_file = dirname(__DIR__) . '/.env';
$claude_api_key = '';

if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, 'CLAUDE_API_KEY=') === 0) {
            $claude_api_key = trim(substr($line, 15));
            break;
        }
    }
}

if (empty($claude_api_key)) {
    die("❌ ERRO: CLAUDE_API_KEY não encontrada no .env\n");
}

echo "✅ API Key carregada\n";

// Conectar ao banco
try {
    $pdo = getPDO();
    echo "✅ Conectado ao banco de dados\n\n";
} catch (PDOException $e) {
    die("❌ Erro de conexão: " . $e->getMessage() . "\n");
}

// Buscar produtos com descrições ruins
echo "🔍 Buscando produtos com descrições ruins...\n";

$sql = "
    SELECT p.product_id, p.name, p.brand, p.barcode, p.unit, p.unit_label,
           p.description, c.name as category_name
    FROM om_market_products_base p
    LEFT JOIN om_market_categories c ON p.category_id = c.category_id
    WHERE
        -- Sem descrição
        (p.description IS NULL OR TRIM(p.description) = '')
        OR
        -- Descrição muito curta
        (LENGTH(TRIM(p.description)) < 30 AND p.description NOT REGEXP '<[^>]+>')
        OR
        -- Descrição é só HTML sem conteúdo útil
        (p.description REGEXP '<[^>]+>' AND p.description NOT LIKE '%</p>%')
        OR
        -- Descrição é só números (EAN)
        (p.description REGEXP '^[0-9]+$')
    ORDER BY RANDOM()
    LIMIT ?
";

$sql = str_replace('?', (int)$MAX_PRODUTOS, $sql);
$stmt = $pdo->query($sql);
$produtos = $stmt->fetchAll();

$total = count($produtos);
echo "📦 Encontrados {$total} produtos para processar\n\n";

if ($total === 0) {
    echo "✅ Nenhum produto precisa de atualização!\n";
    exit(0);
}

if ($DRY_RUN) {
    echo "⚠️  MODO DRY-RUN: Nenhuma alteração será salva no banco\n\n";
}

// Processar em batches
$processados = 0;
$erros = 0;
$batches = array_chunk($produtos, $BATCH_SIZE);

foreach ($batches as $batchNum => $batch) {
    echo "────────────────────────────────────────────────────────────────────\n";
    echo "📦 Processando batch " . ($batchNum + 1) . "/" . count($batches) . "\n";
    echo "────────────────────────────────────────────────────────────────────\n\n";

    foreach ($batch as $produto) {
        $processados++;
        echo "[$processados/$total] {$produto['name']}\n";
        echo "   Marca: " . ($produto['brand'] ?: 'N/A') . "\n";
        echo "   Categoria: " . ($produto['category_name'] ?: 'N/A') . "\n";

        // Gerar descrição com IA
        $descricao = gerarDescricaoIA($produto, $claude_api_key);

        if ($descricao) {
            echo "   ✅ Descrição gerada: " . substr($descricao, 0, 80) . "...\n";

            if (!$DRY_RUN) {
                // Atualizar no banco
                $updateStmt = $pdo->prepare("
                    UPDATE om_market_products_base
                    SET description = ?, ai_validated = 1, updated_at = NOW()
                    WHERE product_id = ?
                ");
                $updateStmt->execute([$descricao, $produto['product_id']]);
                echo "   💾 Salvo no banco!\n";
            }
        } else {
            echo "   ❌ Falha ao gerar descrição\n";
            $erros++;
        }

        echo "\n";

        // Pequena pausa para não sobrecarregar a API
        usleep(200000); // 200ms
    }

    // Pausa entre batches
    if ($batchNum < count($batches) - 1) {
        echo "⏳ Aguardando 2 segundos antes do próximo batch...\n\n";
        sleep(2);
    }
}

echo "════════════════════════════════════════════════════════════════════\n";
echo "  RESUMO\n";
echo "════════════════════════════════════════════════════════════════════\n";
echo "✅ Processados: $processados\n";
echo "❌ Erros: $erros\n";
echo "📊 Taxa de sucesso: " . round(($processados - $erros) / $processados * 100, 1) . "%\n";
echo "════════════════════════════════════════════════════════════════════\n";

/**
 * Gera descrição usando Claude AI
 */
function gerarDescricaoIA($produto, $api_key) {
    $nome = $produto['name'];
    $marca = $produto['brand'] ?: 'Não informada';
    $categoria = $produto['category_name'] ?: 'Geral';
    $unidade = $produto['unit_label'] ?: $produto['unit'] ?: '';

    $prompt = "Você é um especialista em produtos de supermercado. Gere uma descrição comercial atraente e informativa para o seguinte produto:

PRODUTO: {$nome}
MARCA: {$marca}
CATEGORIA: {$categoria}
UNIDADE: {$unidade}

Regras:
1. Escreva em português brasileiro
2. A descrição deve ter entre 100-200 caracteres
3. Destaque qualidades e benefícios do produto
4. Seja objetivo e comercial
5. NÃO inclua preços ou promoções
6. NÃO repita o nome completo do produto
7. Foque em características, usos e diferenciais

Responda APENAS com a descrição, sem aspas ou formatação extra.";

    $data = [
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 200,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("Claude API error: HTTP $http_code - $response");
        return null;
    }

    $result = json_decode($response, true);

    if (isset($result['content'][0]['text'])) {
        $descricao = trim($result['content'][0]['text']);
        // Limpar aspas se houver
        $descricao = trim($descricao, '"\'');
        return $descricao;
    }

    return null;
}
