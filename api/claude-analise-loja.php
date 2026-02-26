<?php
/**
 * OneMundo - API Claude para Análise de Solicitações de Loja
 * Usa IA para avaliar e pontuar solicitações de loja oficial
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../includes/env_loader.php';

// Configuração da API Claude
$ANTHROPIC_API_KEY = env('ANTHROPIC_API_KEY', '');

$input = json_decode(file_get_contents('php://input'), true);
$solicitacao_id = (int)($input['solicitacao_id'] ?? 0);
$action = $input['action'] ?? 'analisar';

if (!$solicitacao_id) {
    echo json_encode(['success' => false, 'error' => 'solicitacao_id obrigatório']);
    exit;
}

try {
    $pdo = getConnection();

    // Buscar dados da solicitação
    $stmt = $pdo->prepare("
        SELECT s.*, c.firstname, c.lastname, c.email, c.telephone,
               c.date_added as cliente_desde,
               (SELECT COUNT(*) FROM oc_order WHERE customer_id = s.customer_id) as total_pedidos,
               (SELECT COUNT(*) FROM oc_review WHERE customer_id = s.customer_id AND status = 1) as total_reviews,
               v.store_name, v.store_status, v.is_ponto_apoio, v.ponto_apoio_status,
               (SELECT COUNT(*) FROM oc_purpletree_vendor_products WHERE seller_id = s.customer_id) as qtd_produtos_real
        FROM om_solicitacao_loja s
        LEFT JOIN oc_customer c ON c.customer_id = s.customer_id
        LEFT JOIN oc_purpletree_vendor_stores v ON v.seller_id = s.customer_id
        WHERE s.id = ?
    ");
    $stmt->execute([$solicitacao_id]);
    $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$solicitacao) {
        echo json_encode(['success' => false, 'error' => 'Solicitação não encontrada']);
        exit;
    }

    // Calcular score e análise
    $analise = analisarSolicitacao($solicitacao);

    // Se tiver API key, usar Claude para análise avançada
    if (!empty($ANTHROPIC_API_KEY)) {
        $analiseIA = chamarClaudeParaAnalise($solicitacao, $ANTHROPIC_API_KEY);
        if ($analiseIA) {
            $analise = array_merge($analise, $analiseIA);
        }
    }

    // Atualizar banco com a análise
    $stmt = $pdo->prepare("
        UPDATE om_solicitacao_loja
        SET score_ia = ?, analise_ia = ?, qtd_produtos = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $analise['score'],
        json_encode($analise['pontos'], JSON_UNESCAPED_UNICODE),
        $solicitacao['qtd_produtos_real'],
        $solicitacao_id
    ]);

    echo json_encode([
        'success' => true,
        'solicitacao_id' => $solicitacao_id,
        'score' => $analise['score'],
        'recomendacao' => $analise['recomendacao'],
        'pontos' => $analise['pontos'],
        'auto_aprovavel' => $analise['auto_aprovavel'],
        'motivos_auto_aprovacao' => $analise['motivos_auto_aprovacao'] ?? []
    ]);

} catch (Exception $e) {
    error_log("[claude-analise-loja] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}

/**
 * Análise baseada em regras de negócio
 */
function analisarSolicitacao($s) {
    $score = 0;
    $pontos = [];
    $autoAprovavel = true;
    $motivosAuto = [];

    // 1. É Ponto de Apoio ativo? (+40 pts)
    if ($s['is_ponto_apoio'] && $s['ponto_apoio_status'] === 'ativo') {
        $score += 40;
        $pontos[] = "✓ Ponto de Apoio ativo (+40 pts)";
        $motivosAuto[] = "É Ponto de Apoio ativo";
    } else {
        $pontos[] = "✗ Não é Ponto de Apoio (0 pts)";
        $autoAprovavel = false;
    }

    // 2. Quantidade de produtos
    $qtdProdutos = (int)$s['qtd_produtos_real'];
    if ($qtdProdutos >= 50) {
        $score += 30;
        $pontos[] = "✓ {$qtdProdutos} produtos cadastrados (+30 pts)";
        $motivosAuto[] = "Possui {$qtdProdutos} produtos";
    } elseif ($qtdProdutos >= 20) {
        $score += 20;
        $pontos[] = "◐ {$qtdProdutos} produtos cadastrados (+20 pts)";
    } elseif ($qtdProdutos >= 5) {
        $score += 10;
        $pontos[] = "◐ {$qtdProdutos} produtos cadastrados (+10 pts)";
    } else {
        $pontos[] = "✗ Poucos produtos: {$qtdProdutos} (0 pts)";
        $autoAprovavel = false;
    }

    // 3. CNPJ válido (+15 pts)
    if (!empty($s['cnpj']) && strlen(preg_replace('/\D/', '', $s['cnpj'])) == 14) {
        $score += 15;
        $pontos[] = "✓ CNPJ informado (+15 pts)";
    } else {
        $pontos[] = "◐ CNPJ não informado (0 pts)";
    }

    // 4. Endereço físico (+15 pts)
    if (!empty($s['endereco']) && !empty($s['cidade_estado'])) {
        $score += 15;
        $pontos[] = "✓ Endereço físico completo (+15 pts)";
    } else {
        $pontos[] = "◐ Endereço incompleto (0 pts)";
    }

    // 5. Histórico do cliente
    $totalPedidos = (int)$s['total_pedidos'];
    if ($totalPedidos >= 10) {
        $score += 10;
        $pontos[] = "✓ Cliente com {$totalPedidos} pedidos (+10 pts)";
    }

    // 6. Tempo como cliente
    if (!empty($s['cliente_desde'])) {
        $diasCliente = (time() - strtotime($s['cliente_desde'])) / 86400;
        if ($diasCliente >= 180) {
            $score += 5;
            $pontos[] = "✓ Cliente há mais de 6 meses (+5 pts)";
        }
    }

    // 7. Descrição da loja
    if (!empty($s['descricao']) && strlen($s['descricao']) >= 50) {
        $score += 5;
        $pontos[] = "✓ Descrição detalhada (+5 pts)";
    }

    // Determinar recomendação
    $recomendacao = 'pendente';
    if ($score >= 70 && $autoAprovavel) {
        $recomendacao = 'aprovar_automatico';
    } elseif ($score >= 50) {
        $recomendacao = 'analisar_manual';
    } elseif ($score < 30) {
        $recomendacao = 'rejeitar';
    }

    return [
        'score' => min(100, $score),
        'pontos' => $pontos,
        'recomendacao' => $recomendacao,
        'auto_aprovavel' => $autoAprovavel && $score >= 70,
        'motivos_auto_aprovacao' => $autoAprovavel && $score >= 70 ? $motivosAuto : []
    ];
}

/**
 * Análise avançada usando Claude AI
 */
function chamarClaudeParaAnalise($solicitacao, $apiKey) {
    $prompt = "Analise esta solicitação de loja oficial para um marketplace:

Nome da Loja: {$solicitacao['nome_loja']}
Tipo: {$solicitacao['tipo_loja']}
Categoria: {$solicitacao['categoria_principal']}
CNPJ: " . ($solicitacao['cnpj'] ?: 'Não informado') . "
Descrição: {$solicitacao['descricao']}
Cidade: {$solicitacao['cidade_estado']}
É Ponto de Apoio: " . ($solicitacao['is_ponto_apoio'] ? 'Sim' : 'Não') . "
Quantidade de Produtos: {$solicitacao['qtd_produtos_real']}
Total de Pedidos do Cliente: {$solicitacao['total_pedidos']}

Responda APENAS com um JSON no formato:
{
  \"analise_resumida\": \"Uma frase sobre a qualidade da solicitação\",
  \"pontos_positivos\": [\"ponto 1\", \"ponto 2\"],
  \"pontos_atencao\": [\"atenção 1\"],
  \"risco\": \"baixo|medio|alto\",
  \"ajuste_score\": número entre -10 e +10
}";

    try {
        $ch = curl_init('https://api.anthropic.com/v1/messages');

        $data = [
            'model' => 'claude-3-haiku-20240307',
            'max_tokens' => 500,
            'system' => 'Você é um analista de risco especializado em marketplaces. Responda APENAS com JSON válido, sem texto adicional.',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $json = json_decode($result, true);
            $text = $json['content'][0]['text'] ?? '';
            $analise = json_decode($text, true);

            if ($analise) {
                return [
                    'analise_ia' => $analise['analise_resumida'] ?? '',
                    'pontos_positivos_ia' => $analise['pontos_positivos'] ?? [],
                    'pontos_atencao_ia' => $analise['pontos_atencao'] ?? [],
                    'risco_ia' => $analise['risco'] ?? 'medio',
                    'ajuste_score' => (int)($analise['ajuste_score'] ?? 0)
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Erro Claude: " . $e->getMessage());
    }

    return null;
}
