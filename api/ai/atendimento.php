<?php
/**
 * API - Atendimento ao Cliente com Claude AI
 *
 * Funcionalidades:
 * - Responder tickets automaticamente (draft)
 * - Classificar prioridade
 * - Sugerir solução
 * - Chatbot inteligente
 *
 * @author OneMundo Team
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Includes
require_once __DIR__ . '/../mercado/config/database.php';
require_once __DIR__ . '/../../includes/classes/ClaudeAI.php';
require_once __DIR__ . '/../../includes/classes/OmCache.php';
require_once __DIR__ . '/../../includes/classes/OmAuth.php';
require_once __DIR__ . '/../../includes/classes/OmAudit.php';

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Obter dados da requisição
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

// Ação a executar
$action = $input['action'] ?? 'analisar';

try {
    $claude = ClaudeAI::getInstance();
    $cache = OmCache::getInstance();

    $result = [];

    switch ($action) {
        case 'analisar':
            // Analisar ticket e classificar
            $result = analisarTicket($claude, $input);
            break;

        case 'responder':
            // Gerar resposta draft para ticket
            $result = gerarRespostaTicket($claude, $input);
            break;

        case 'classificar':
            // Apenas classificar prioridade
            $result = classificarPrioridade($claude, $input);
            break;

        case 'solucao':
            // Sugerir solução
            $result = sugerirSolucao($claude, $input);
            break;

        case 'chat':
            // Resposta para chatbot
            $result = responderChat($claude, $input);
            break;

        case 'resumir':
            // Resumir histórico de atendimento
            $result = resumirHistorico($claude, $input);
            break;

        case 'sentimento':
            // Análise de sentimento
            $result = analisarSentimento($claude, $input);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
            exit;
    }

    // Log do atendimento
    if (class_exists('OmAudit')) {
        OmAudit::getInstance()->log('ai_atendimento', [
            'action' => $action,
            'ticket_id' => $input['ticket_id'] ?? null,
            'success' => true
        ]);
    }

    echo json_encode([
        'success' => true,
        'action' => $action,
        'data' => $result
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao processar atendimento: ' . $e->getMessage()
    ]);
}

// =========================================================================
// FUNÇÕES DE ATENDIMENTO
// =========================================================================

/**
 * Analisar ticket completo
 */
function analisarTicket(ClaudeAI $claude, array $input): array {
    $ticket = $input['ticket'] ?? [];

    if (empty($ticket)) {
        throw new Exception('Dados do ticket não fornecidos');
    }

    // Enriquecer ticket com contexto
    $ticketEnriquecido = enriquecerTicket($ticket, $input);

    // Analisar via Claude
    $resultado = $claude->analisarReclamacao($ticketEnriquecido);

    if (!$resultado['success']) {
        throw new Exception('Falha na análise do ticket');
    }

    $data = $resultado['data'] ?? [];

    // Adicionar ações automáticas sugeridas
    $data['acoes_automaticas'] = determinarAcoesAutomaticas($data);

    // Adicionar templates de resposta
    $data['templates_resposta'] = obterTemplatesResposta($data['classificacao']['categoria'] ?? null);

    return [
        'analise' => $data,
        'metadata' => [
            'timestamp' => date('Y-m-d H:i:s'),
            'from_cache' => $resultado['from_cache'] ?? false
        ]
    ];
}

/**
 * Gerar resposta draft para ticket
 */
function gerarRespostaTicket(ClaudeAI $claude, array $input): array {
    $ticket = $input['ticket'] ?? [];
    $tom = $input['tom'] ?? 'profissional'; // profissional, amigavel, formal
    $incluirSolucao = $input['incluir_solucao'] ?? true;

    if (empty($ticket)) {
        throw new Exception('Dados do ticket não fornecidos');
    }

    $systemPrompt = <<<PROMPT
Você é um atendente de suporte do Superbora, um app de delivery de supermercado.
Gere uma resposta profissional e empática para o cliente.

Tom da resposta: {$tom}

Diretrizes:
- Sempre cumprimentar o cliente pelo nome
- Demonstrar empatia com o problema
- Ser claro e objetivo
- Oferecer solução quando possível
- Pedir desculpas quando apropriado
- Nunca culpar o cliente
- Finalizar de forma positiva

Retorne JSON:
{
    "resposta": "texto completo da resposta",
    "assunto_sugerido": "assunto para email/ticket",
    "resposta_curta": "versão resumida para SMS/WhatsApp",
    "acoes_prometidas": ["lista de ações prometidas ao cliente"],
    "follow_up": {
        "necessario": true/false,
        "prazo_horas": 24,
        "motivo": "razão do follow-up"
    },
    "tom_detectado_cliente": "frustrado|irritado|neutro|satisfeito",
    "risco_churn": "baixo|medio|alto"
}
PROMPT;

    $ticketJson = json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $messages = [
        [
            'role' => 'user',
            'content' => "Gere uma resposta para este ticket:\n\n{$ticketJson}"
        ]
    ];

    $resultado = $claude->chat($messages, [
        'system' => $systemPrompt,
        'temperature' => 0.6
    ]);

    if (!$resultado['success']) {
        throw new Exception('Falha ao gerar resposta');
    }

    $data = parseJsonFromResponse($resultado['content']);

    // Adicionar variações de resposta
    if ($incluirSolucao) {
        $data['variacoes'] = gerarVariacoesResposta($data['resposta'] ?? '');
    }

    return [
        'resposta' => $data,
        'is_draft' => true,
        'requires_review' => true,
        'metadata' => [
            'timestamp' => date('Y-m-d H:i:s'),
            'tom' => $tom
        ]
    ];
}

/**
 * Classificar prioridade do ticket
 */
function classificarPrioridade(ClaudeAI $claude, array $input): array {
    $mensagem = $input['mensagem'] ?? $input['ticket']['mensagem'] ?? '';
    $assunto = $input['assunto'] ?? $input['ticket']['assunto'] ?? '';

    if (empty($mensagem) && empty($assunto)) {
        throw new Exception('Mensagem ou assunto deve ser fornecido');
    }

    $systemPrompt = <<<PROMPT
Classifique a prioridade deste ticket de suporte.

Critérios de prioridade:
- URGENTE: Cliente sem produto, problema de saúde/segurança, fraude, valores altos
- ALTA: Pedido errado, produto danificado, atraso significativo, cliente VIP
- MÉDIA: Dúvidas sobre pedido, pequenos problemas, reclamações gerais
- BAIXA: Sugestões, elogios, dúvidas gerais, informações

Retorne JSON:
{
    "prioridade": "urgente|alta|media|baixa",
    "score": 0-100,
    "categoria": "entrega|produto|pagamento|app|atendimento|outro",
    "subcategoria": "subcategoria específica",
    "palavras_chave": ["palavras", "importantes"],
    "justificativa": "razão da classificação",
    "sla_horas": 24,
    "departamento_sugerido": "suporte|logistica|financeiro|comercial"
}
PROMPT;

    $conteudo = "Assunto: {$assunto}\nMensagem: {$mensagem}";

    $resultado = $claude->analyze($conteudo, 'Classifique a prioridade deste ticket', [
        'system' => $systemPrompt,
        'temperature' => 0.2
    ]);

    if (!$resultado['success']) {
        throw new Exception('Falha na classificação');
    }

    return [
        'classificacao' => $resultado['data'] ?? [],
        'metadata' => [
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

/**
 * Sugerir solução para o problema
 */
function sugerirSolucao(ClaudeAI $claude, array $input): array {
    $problema = $input['problema'] ?? $input['ticket']['mensagem'] ?? '';
    $categoria = $input['categoria'] ?? null;
    $historicoPedido = $input['pedido'] ?? [];

    $systemPrompt = <<<PROMPT
Você é um especialista em resolução de problemas de delivery/supermercado.
Sugira a melhor solução para o problema do cliente.

Considere:
- Histórico do pedido (se disponível)
- Políticas típicas de reembolso/crédito
- Custo-benefício para a empresa
- Satisfação do cliente
- Prevenção de churn

Soluções possíveis:
- Reembolso total ou parcial
- Crédito na conta
- Reenvio de produto
- Cupom de desconto
- Brinde
- Apenas desculpas (para casos menores)

Retorne JSON:
{
    "solucao_principal": {
        "tipo": "reembolso|credito|reenvio|cupom|brinde|desculpas",
        "descricao": "descrição da solução",
        "valor": 0,
        "justificativa": "por que esta solução"
    },
    "solucoes_alternativas": [
        {
            "tipo": "tipo",
            "descricao": "descrição",
            "valor": 0,
            "quando_usar": "quando aplicar esta alternativa"
        }
    ],
    "script_atendente": "texto para o atendente usar",
    "acoes_internas": ["ações que a empresa deve tomar"],
    "prevencao": "como evitar este problema no futuro",
    "custo_estimado": 0,
    "roi_satisfacao": "alto|medio|baixo"
}
PROMPT;

    $dados = [
        'problema' => $problema,
        'categoria' => $categoria,
        'pedido' => $historicoPedido
    ];

    $resultado = $claude->analyze(
        json_encode($dados, JSON_UNESCAPED_UNICODE),
        'Sugira a melhor solução para este problema',
        ['system' => $systemPrompt]
    );

    if (!$resultado['success']) {
        throw new Exception('Falha ao sugerir solução');
    }

    return [
        'solucao' => $resultado['data'] ?? [],
        'metadata' => [
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

/**
 * Responder chat em tempo real
 */
function responderChat(ClaudeAI $claude, array $input): array {
    $mensagem = $input['mensagem'] ?? '';
    $historico = $input['historico'] ?? [];
    $contexto = $input['contexto'] ?? [];

    if (empty($mensagem)) {
        throw new Exception('Mensagem não fornecida');
    }

    $contextoCompleto = [
        'mensagem' => $mensagem,
        'historico' => $historico,
        'pedido' => $contexto['pedido'] ?? null,
        'cliente' => $contexto['cliente'] ?? null
    ];

    $resultado = $claude->gerarRespostaChat($contextoCompleto);

    if (!$resultado['success']) {
        throw new Exception('Falha ao gerar resposta do chat');
    }

    $data = $resultado['data'] ?? [];

    // Adicionar quick replies sugeridos
    $data['quick_replies'] = gerarQuickReplies($data['intent'] ?? 'outro');

    // Verificar se precisa escalar para humano
    if ($data['precisa_humano'] ?? false) {
        $data['mensagem_escalar'] = 'Um momento, vou transferir você para um de nossos atendentes.';
    }

    return [
        'resposta' => $data['resposta'] ?? 'Desculpe, não entendi. Pode reformular?',
        'acoes' => $data['acoes_sugeridas'] ?? [],
        'precisa_humano' => $data['precisa_humano'] ?? false,
        'intent' => $data['intent'] ?? 'outro',
        'quick_replies' => $data['quick_replies'] ?? [],
        'metadata' => [
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

/**
 * Resumir histórico de atendimento
 */
function resumirHistorico(ClaudeAI $claude, array $input): array {
    $tickets = $input['tickets'] ?? [];
    $clienteId = $input['cliente_id'] ?? null;

    if (empty($tickets)) {
        throw new Exception('Histórico de tickets não fornecido');
    }

    $systemPrompt = <<<PROMPT
Resuma o histórico de atendimento deste cliente de forma concisa.
Identifique padrões, problemas recorrentes e nível de satisfação.

Retorne JSON:
{
    "resumo": "resumo geral do histórico",
    "total_tickets": 10,
    "problemas_recorrentes": [
        {"problema": "descrição", "ocorrencias": 3, "status": "resolvido|pendente"}
    ],
    "sentimento_geral": "positivo|neutro|negativo",
    "nivel_satisfacao": 0-100,
    "risco_churn": "baixo|medio|alto",
    "valor_total_compensacoes": 0,
    "tickets_abertos": 0,
    "tempo_medio_resolucao_horas": 24,
    "recomendacoes": ["recomendações para o atendimento"],
    "perfil_cliente": "detalhista|impaciente|compreensivo|exigente|etc",
    "pontos_atencao": ["pontos importantes para próximos atendimentos"]
}
PROMPT;

    $resultado = $claude->analyze(
        json_encode($tickets, JSON_UNESCAPED_UNICODE),
        'Resuma o histórico de atendimento deste cliente',
        ['system' => $systemPrompt]
    );

    if (!$resultado['success']) {
        throw new Exception('Falha ao resumir histórico');
    }

    return [
        'resumo' => $resultado['data'] ?? [],
        'metadata' => [
            'timestamp' => date('Y-m-d H:i:s'),
            'cliente_id' => $clienteId
        ]
    ];
}

/**
 * Análise de sentimento
 */
function analisarSentimento(ClaudeAI $claude, array $input): array {
    $texto = $input['texto'] ?? $input['mensagem'] ?? '';

    if (empty($texto)) {
        throw new Exception('Texto não fornecido');
    }

    $systemPrompt = <<<PROMPT
Analise o sentimento do texto do cliente.

Retorne JSON:
{
    "sentimento": "muito_negativo|negativo|neutro|positivo|muito_positivo",
    "score": -100 a 100,
    "emocoes": ["frustração", "raiva", "satisfação", etc],
    "intensidade": "baixa|media|alta",
    "urgencia_percebida": "baixa|media|alta",
    "palavras_indicativas": ["palavras", "que", "indicam", "sentimento"],
    "tom": "formal|informal|agressivo|educado|sarcástico",
    "requer_atencao_especial": true/false,
    "sugestao_abordagem": "como abordar este cliente"
}
PROMPT;

    $resultado = $claude->analyze(
        $texto,
        'Analise o sentimento deste texto',
        ['system' => $systemPrompt, 'temperature' => 0.3]
    );

    if (!$resultado['success']) {
        throw new Exception('Falha na análise de sentimento');
    }

    return [
        'sentimento' => $resultado['data'] ?? [],
        'metadata' => [
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
}

// =========================================================================
// FUNÇÕES AUXILIARES
// =========================================================================

/**
 * Enriquecer ticket com contexto
 */
function enriquecerTicket(array $ticket, array $input): array {
    // Adicionar dados do pedido se disponíveis
    if (isset($input['pedido'])) {
        $ticket['pedido'] = $input['pedido'];
    }

    // Adicionar dados do cliente
    if (isset($input['cliente'])) {
        $ticket['cliente'] = $input['cliente'];
    }

    // Adicionar histórico de tickets
    if (isset($input['historico_tickets'])) {
        $ticket['historico_tickets'] = $input['historico_tickets'];
    }

    // Adicionar contexto temporal
    $ticket['contexto'] = [
        'data_analise' => date('Y-m-d H:i:s'),
        'dia_semana' => date('l'),
        'horario_comercial' => isHorarioComercial()
    ];

    return $ticket;
}

/**
 * Determinar ações automáticas baseadas na análise
 */
function determinarAcoesAutomaticas(array $analise): array {
    $acoes = [];

    $prioridade = $analise['classificacao']['prioridade'] ?? 'media';
    $categoria = $analise['classificacao']['categoria'] ?? 'outro';
    $solucao = $analise['solucao']['acao_recomendada'] ?? null;

    // Notificações automáticas por prioridade
    if ($prioridade === 'urgente') {
        $acoes[] = [
            'tipo' => 'notificar',
            'destinatario' => 'supervisor',
            'mensagem' => 'Ticket urgente requer atenção imediata'
        ];
    }

    // Ações por categoria
    switch ($categoria) {
        case 'pagamento':
            $acoes[] = [
                'tipo' => 'atribuir',
                'departamento' => 'financeiro',
                'motivo' => 'Ticket relacionado a pagamento'
            ];
            break;

        case 'entrega':
            $acoes[] = [
                'tipo' => 'atribuir',
                'departamento' => 'logistica',
                'motivo' => 'Ticket relacionado a entrega'
            ];
            break;
    }

    // Ações por solução recomendada
    if ($solucao === 'reembolso' && ($analise['solucao']['valor_sugerido'] ?? 0) <= 50) {
        $acoes[] = [
            'tipo' => 'aprovar_automatico',
            'valor' => $analise['solucao']['valor_sugerido'],
            'motivo' => 'Reembolso dentro do limite automático'
        ];
    }

    return $acoes;
}

/**
 * Obter templates de resposta por categoria
 */
function obterTemplatesResposta(?string $categoria): array {
    $templates = [
        'entrega' => [
            'atraso' => 'Olá {nome}, pedimos desculpas pelo atraso na entrega do seu pedido #{pedido}. Estamos trabalhando para que ele chegue o mais rápido possível.',
            'nao_entregue' => 'Olá {nome}, lamentamos que sua entrega não tenha sido realizada. Vamos verificar o ocorrido e entrar em contato em breve.'
        ],
        'produto' => [
            'danificado' => 'Olá {nome}, sentimos muito que o produto tenha chegado danificado. Vamos providenciar a substituição imediatamente.',
            'faltante' => 'Olá {nome}, pedimos desculpas pelo item faltante no seu pedido. Já estamos providenciando o reembolso/reenvio.'
        ],
        'pagamento' => [
            'cobranca_indevida' => 'Olá {nome}, identificamos sua solicitação sobre a cobrança. Vamos analisar e retornar em até 24h.',
            'reembolso' => 'Olá {nome}, seu reembolso foi solicitado e será processado em até X dias úteis.'
        ]
    ];

    if ($categoria && isset($templates[$categoria])) {
        return $templates[$categoria];
    }

    return $templates;
}

/**
 * Gerar variações de resposta
 */
function gerarVariacoesResposta(string $resposta): array {
    // Versões simplificadas da resposta
    return [
        'formal' => $resposta,
        'curta' => substr($resposta, 0, 200) . '...',
        'whatsapp' => preg_replace('/\n{2,}/', "\n", substr($resposta, 0, 500))
    ];
}

/**
 * Gerar quick replies baseados no intent
 */
function gerarQuickReplies(string $intent): array {
    $replies = [
        'informacao' => [
            'Ver meu pedido',
            'Falar com atendente',
            'Cancelar pedido'
        ],
        'reclamacao' => [
            'Solicitar reembolso',
            'Falar com supervisor',
            'Reportar problema'
        ],
        'pedido' => [
            'Acompanhar entrega',
            'Alterar endereço',
            'Adicionar item'
        ],
        'outro' => [
            'Falar com atendente',
            'Ver FAQ',
            'Fazer pedido'
        ]
    ];

    return $replies[$intent] ?? $replies['outro'];
}

/**
 * Verificar se é horário comercial
 */
function isHorarioComercial(): bool {
    $hora = (int) date('H');
    $diaSemana = date('N'); // 1 = segunda, 7 = domingo

    // Segunda a sexta, 8h às 20h
    if ($diaSemana <= 5 && $hora >= 8 && $hora < 20) {
        return true;
    }

    // Sábado, 8h às 14h
    if ($diaSemana == 6 && $hora >= 8 && $hora < 14) {
        return true;
    }

    return false;
}

/**
 * Parse JSON da resposta do Claude
 */
function parseJsonFromResponse(string $content): array {
    // Tentar parse direto
    $parsed = json_decode($content, true);
    if ($parsed !== null) {
        return $parsed;
    }

    // Tentar extrair JSON de blocos de código
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/m', $content, $matches)) {
        $parsed = json_decode(trim($matches[1]), true);
        if ($parsed !== null) {
            return $parsed;
        }
    }

    // Tentar encontrar JSON no texto
    if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
        $parsed = json_decode($matches[0], true);
        if ($parsed !== null) {
            return $parsed;
        }
    }

    return ['resposta' => $content];
}
