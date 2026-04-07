<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ASSISTENTE DE FRETE COM CLAUDE AI - OneMundo
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Usa Claude AI para recomendar a melhor opção de frete baseado no contexto
 * do cliente, tipo de produto, urgência, etc.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__, 2) . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Buscar chave API Claude
$stmt = $pdo->query("SELECT valor FROM om_entrega_config WHERE chave = 'claude_api_key'");
$claudeApiKey = $stmt->fetchColumn();

// Se não tiver chave, usar lógica local simplificada
if (!$claudeApiKey) {
    $recomendacao = recomendarLocal($input);
    echo json_encode($recomendacao);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// CHAMAR CLAUDE AI
// ══════════════════════════════════════════════════════════════════════════════

$opcoesFrete = $input['opcoes'] ?? [];
$produto = $input['produto'] ?? [];
$cliente = $input['cliente'] ?? [];
$pergunta = $input['pergunta'] ?? '';

// Construir contexto para Claude
$contexto = "Você é um assistente de compras do OneMundo, um marketplace brasileiro.
Ajude o cliente a escolher a melhor opção de frete.

OPÇÕES DE FRETE DISPONÍVEIS:
" . json_encode($opcoesFrete, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "

PRODUTO:
- Nome: " . ($produto['nome'] ?? 'Produto') . "
- Preço: R$ " . number_format($produto['preco'] ?? 0, 2, ',', '.') . "
- Categoria: " . ($produto['categoria'] ?? 'Geral') . "

CLIENTE:
- Cidade: " . ($cliente['cidade'] ?? 'N/A') . "
- Mesma cidade do vendedor: " . ($cliente['mesma_cidade'] ? 'Sim' : 'Não') . "

REGRAS:
1. Se a opção 'Retirar GRÁTIS' estiver disponível, mencione a economia
2. Se 'Recebe Hoje' estiver disponível, destaque a rapidez
3. Para entregas diretas (recebe_hoje_direto), explique que a privacidade é garantida
4. Seja breve e direto (máximo 2-3 frases)
5. Use emojis de forma moderada
6. Se o cliente perguntar algo específico, responda a pergunta

";

if ($pergunta) {
    $contexto .= "\n\nPERGUNTA DO CLIENTE: " . $pergunta;
} else {
    $contexto .= "\n\nDê uma recomendação personalizada da melhor opção de frete.";
}

// Routed via ClaudeClient -> Groq Llama 3.3 70B (auto when AI_PROVIDER=groq)
require_once dirname(__DIR__) . '/mercado/helpers/claude-client.php';
$texto = ClaudeClient::text($contexto, '', 300);
$httpCode = $texto !== null ? 200 : 500;
$response = $texto;

if ($httpCode === 200) {
    echo json_encode([
        'success' => true,
        'recomendacao' => $texto,
        'fonte' => 'claude_ai'
    ], JSON_UNESCAPED_UNICODE);
} else {
    // Fallback para recomendação local
    $recomendacao = recomendarLocal($input);
    echo json_encode($recomendacao);
}

// ══════════════════════════════════════════════════════════════════════════════
// RECOMENDAÇÃO LOCAL (sem Claude)
// ══════════════════════════════════════════════════════════════════════════════

function recomendarLocal($input) {
    $opcoes = $input['opcoes'] ?? [];
    $mesmaCidade = $input['cliente']['mesma_cidade'] ?? false;

    if (empty($opcoes)) {
        return [
            'success' => true,
            'recomendacao' => 'Calcule o frete para ver as opções disponíveis.',
            'fonte' => 'local'
        ];
    }

    // Analisar opções
    $temGratis = false;
    $temRecebeHoje = false;
    $temDireto = false;
    $melhorOpcao = null;

    foreach ($opcoes as $opcao) {
        if ($opcao['gratis'] ?? false) $temGratis = true;
        if ($opcao['tipo'] === 'recebe_hoje') $temRecebeHoje = true;
        if ($opcao['tipo'] === 'recebe_hoje_direto') $temDireto = true;

        if (!$melhorOpcao || ($opcao['gratis'] ?? false)) {
            $melhorOpcao = $opcao;
        }
    }

    // Gerar recomendação
    $texto = '';

    if ($mesmaCidade && $temGratis) {
        $texto = "🎉 Você está na mesma cidade do vendedor! Recomendo a opção **Retirar GRÁTIS** - você economiza no frete e ainda pode pegar seu pedido em poucas horas.";

        if ($temRecebeHoje || $temDireto) {
            $texto .= " Se preferir receber em casa sem sair, o **Recebe Hoje** é uma ótima opção!";
        }
    } elseif ($temRecebeHoje || $temDireto) {
        $texto = "⚡ Boa notícia! O **Recebe HOJE** está disponível - seu pedido pode chegar em até 2-3 horas!";

        if ($temDireto) {
            $texto .= " 🔒 Sua privacidade é garantida: o vendedor não terá acesso ao seu endereço.";
        }
    } else {
        $texto = "📦 A **Entrega Padrão** é sua melhor opção. Seu pedido chegará em alguns dias úteis com toda segurança.";
    }

    return [
        'success' => true,
        'recomendacao' => $texto,
        'fonte' => 'local',
        'opcao_recomendada' => $melhorOpcao['tipo'] ?? null
    ];
}
