<?php
/**
 * SUPERBORA - Claude AI Integration
 * Integração completa com a API Claude para análises inteligentes
 *
 * @author OneMundo Team
 * @version 1.0.0
 */

require_once __DIR__ . '/OmCache.php';

class ClaudeAI {
    // Configuração
    private string $apiKey;
    private string $model = 'claude-3-sonnet-20240229';
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';
    private int $maxTokens = 4096;
    private float $temperature = 0.7;

    // Cache
    private OmCache $cache;
    private int $cacheTTL = 3600; // 1 hora padrão

    // Singleton
    private static ?ClaudeAI $instance = null;

    // Logs
    private string $logDir;

    /**
     * Constructor
     */
    public function __construct(?string $apiKey = null) {
        $this->apiKey = $apiKey ?? $this->getApiKeyFromEnv();
        $this->cache = OmCache::getInstance();
        $this->logDir = __DIR__ . '/../../logs/ai/';

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Singleton instance
     */
    public static function getInstance(): ClaudeAI {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obter API key do ambiente
     */
    private function getApiKeyFromEnv(): string {
        // Tentar várias fontes de configuração
        if (defined('CLAUDE_API_KEY')) {
            return CLAUDE_API_KEY;
        }

        $envKey = getenv('CLAUDE_API_KEY');
        if ($envKey) {
            return $envKey;
        }

        // Tentar arquivo de configuração
        $configFile = __DIR__ . '/../../config/ai.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            if (isset($config['claude_api_key'])) {
                return $config['claude_api_key'];
            }
        }

        throw new Exception('Claude API key não configurada. Defina CLAUDE_API_KEY.');
    }

    /**
     * Configurar modelo
     */
    public function setModel(string $model): self {
        $this->model = $model;
        return $this;
    }

    /**
     * Configurar max tokens
     */
    public function setMaxTokens(int $tokens): self {
        $this->maxTokens = $tokens;
        return $this;
    }

    /**
     * Configurar temperatura
     */
    public function setTemperature(float $temp): self {
        $this->temperature = max(0, min(1, $temp));
        return $this;
    }

    /**
     * Configurar TTL do cache
     */
    public function setCacheTTL(int $ttl): self {
        $this->cacheTTL = $ttl;
        return $this;
    }

    // =========================================================================
    // MÉTODOS PRINCIPAIS
    // =========================================================================

    /**
     * Chat com Claude
     *
     * @param array $messages Array de mensagens [['role' => 'user', 'content' => '...']]
     * @param array $options Opções adicionais
     * @return array Resposta da API
     */
    public function chat(array $messages, array $options = []): array {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'messages' => $messages
        ];

        // System prompt se fornecido
        if (isset($options['system'])) {
            $payload['system'] = $options['system'];
        }

        // Temperatura
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        return $this->makeRequest($payload, $options['use_cache'] ?? false);
    }

    /**
     * Analisar conteúdo com tarefa específica
     *
     * @param string $content Conteúdo a analisar
     * @param string $task Descrição da tarefa
     * @param array $options Opções adicionais
     * @return array Resultado da análise
     */
    public function analyze(string $content, string $task, array $options = []): array {
        $messages = [
            [
                'role' => 'user',
                'content' => "Tarefa: {$task}\n\nConteúdo para análise:\n{$content}"
            ]
        ];

        $systemPrompt = $options['system'] ??
            'Você é um assistente analítico especializado. Forneça análises precisas e estruturadas em JSON quando apropriado.';

        return $this->chat($messages, array_merge($options, ['system' => $systemPrompt]));
    }

    /**
     * Analisar imagem
     *
     * @param string $base64Image Imagem em base64
     * @param string $prompt Instrução para análise
     * @param string $mediaType Tipo de mídia (image/jpeg, image/png, etc)
     * @return array Resultado da análise
     */
    public function analyzeImage(string $base64Image, string $prompt, string $mediaType = 'image/jpeg'): array {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mediaType,
                            'data' => $base64Image
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ]
                ]
            ]
        ];

        return $this->chat($messages, [
            'model' => 'claude-3-sonnet-20240229', // Sonnet tem bom suporte a imagens
            'system' => 'Você é um assistente especializado em análise visual. Forneça descrições detalhadas e precisas.'
        ]);
    }

    // =========================================================================
    // MÉTODOS ESPECÍFICOS DO NEGÓCIO
    // =========================================================================

    /**
     * Analisar pedido completo
     *
     * @param array $order Dados do pedido
     * @return array Análise completa do pedido
     */
    public function analisarPedido(array $order): array {
        $cacheKey = 'ai_pedido_' . md5(json_encode($order));

        // Verificar cache
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $orderJson = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $systemPrompt = <<<PROMPT
Você é um analista de pedidos de supermercado/delivery. Analise o pedido e retorne um JSON com:
{
    "fraude": {
        "risco": "baixo|medio|alto",
        "score": 0-100,
        "motivos": ["lista de motivos se houver"],
        "recomendacao": "aprovar|revisar|bloquear"
    },
    "sugestoes_adicionais": [
        {"produto": "nome", "motivo": "porque sugerir"}
    ],
    "tempo_estimado": {
        "coleta_minutos": 30,
        "entrega_minutos": 20,
        "total_minutos": 50,
        "fatores": ["lista de fatores que afetam o tempo"]
    },
    "problemas_potenciais": [
        {"tipo": "tipo do problema", "descricao": "descrição", "severidade": "baixa|media|alta"}
    ],
    "observacoes": "observações gerais"
}
PROMPT;

        $messages = [
            [
                'role' => 'user',
                'content' => "Analise este pedido:\n\n{$orderJson}"
            ]
        ];

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.3 // Mais determinístico para análises
        ]);

        $result = $this->parseJsonResponse($response);

        // Cachear resultado
        $this->cache->set($cacheKey, $result, 1800); // 30 minutos

        return $result;
    }

    /**
     * Sugerir substituição para produto indisponível
     *
     * @param array $produto Dados do produto original
     * @param array $mercado Dados do mercado com produtos disponíveis
     * @return array Sugestões de substituição
     */
    public function sugerirSubstituicao(array $produto, array $mercado): array {
        $cacheKey = 'ai_subst_' . md5($produto['id'] . '_' . $mercado['id']);

        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $systemPrompt = <<<PROMPT
Você é um especialista em produtos de supermercado. Sugira substituições para produtos indisponíveis.
Retorne JSON:
{
    "substituicoes": [
        {
            "produto_id": "id do produto sugerido",
            "nome": "nome do produto",
            "motivo": "por que é uma boa substituição",
            "similaridade": 0-100,
            "diferenca_preco": "+10% ou -5%",
            "observacao": "detalhes importantes"
        }
    ],
    "sem_substituto": false,
    "mensagem_cliente": "mensagem para o cliente explicando a situação"
}
PROMPT;

        $produtoJson = json_encode($produto, JSON_UNESCAPED_UNICODE);
        $produtosDisponiveis = json_encode($mercado['produtos_disponiveis'] ?? [], JSON_UNESCAPED_UNICODE);

        $messages = [
            [
                'role' => 'user',
                'content' => "Produto indisponível:\n{$produtoJson}\n\nProdutos disponíveis no mercado:\n{$produtosDisponiveis}"
            ]
        ];

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.5
        ]);

        $result = $this->parseJsonResponse($response);
        $this->cache->set($cacheKey, $result, 3600);

        return $result;
    }

    /**
     * Analisar reclamação/ticket de suporte
     *
     * @param array $ticket Dados do ticket
     * @return array Análise e sugestão de resposta
     */
    public function analisarReclamacao(array $ticket): array {
        $systemPrompt = <<<PROMPT
Você é um especialista em atendimento ao cliente de delivery/supermercado. Analise o ticket e retorne JSON:
{
    "classificacao": {
        "categoria": "entrega|produto|pagamento|app|outro",
        "subcategoria": "atraso|danificado|faltante|erro_cobranca|etc",
        "prioridade": "baixa|media|alta|urgente",
        "sentimento": "neutro|frustrado|irritado|satisfeito"
    },
    "analise": {
        "problema_principal": "descrição do problema",
        "causa_provavel": "causa identificada",
        "impacto_cliente": "baixo|medio|alto"
    },
    "solucao": {
        "acao_recomendada": "reembolso|credito|reenvio|desculpas|escalar",
        "valor_sugerido": 0,
        "justificativa": "porque esta solução"
    },
    "resposta_sugerida": "texto da resposta para o cliente",
    "tags": ["lista", "de", "tags"],
    "escalar": false,
    "motivo_escalar": null
}
PROMPT;

        $ticketJson = json_encode($ticket, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $messages = [
            [
                'role' => 'user',
                'content' => "Analise este ticket de suporte:\n\n{$ticketJson}"
            ]
        ];

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.4
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * Gerar resposta para chat com contexto
     *
     * @param array $contexto Contexto da conversa
     * @return array Resposta gerada
     */
    public function gerarRespostaChat(array $contexto): array {
        $systemPrompt = <<<PROMPT
Você é um assistente virtual do Superbora, um aplicativo de delivery de supermercado.
Seja sempre educado, prestativo e direto.
Se não souber a resposta, direcione para o suporte humano.

Informações importantes:
- Horário de funcionamento: conforme cada mercado
- Prazo de entrega: 30 min a 2 horas dependendo da região
- Formas de pagamento: cartão de crédito/débito, Pix, vale-alimentação
- Em caso de problemas, sempre ofereça solução

Retorne JSON:
{
    "resposta": "sua resposta ao cliente",
    "acoes_sugeridas": ["lista de ações que o cliente pode tomar"],
    "precisa_humano": false,
    "motivo_humano": null,
    "intent": "informacao|reclamacao|pedido|outro"
}
PROMPT;

        // Construir histórico de mensagens
        $messages = [];
        if (isset($contexto['historico'])) {
            foreach ($contexto['historico'] as $msg) {
                $messages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
        }

        // Adicionar mensagem atual
        $messages[] = [
            'role' => 'user',
            'content' => $contexto['mensagem']
        ];

        // Adicionar contexto do pedido se existir
        if (isset($contexto['pedido'])) {
            $systemPrompt .= "\n\nContexto do pedido atual:\n" . json_encode($contexto['pedido'], JSON_UNESCAPED_UNICODE);
        }

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.7
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * Detectar possível fraude em pedido
     *
     * @param array $pedido Dados do pedido com histórico do cliente
     * @return array Análise de fraude
     */
    public function detectarFraude(array $pedido): array {
        $cacheKey = 'ai_fraude_' . md5(json_encode($pedido));

        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $systemPrompt = <<<PROMPT
Você é um especialista em detecção de fraudes em e-commerce/delivery. Analise o pedido considerando:
- Padrões de comportamento do cliente
- Valor do pedido vs histórico
- Endereço de entrega
- Horário do pedido
- Método de pagamento
- Itens pedidos (produtos de alto valor, quantidades anormais)

Retorne JSON:
{
    "fraude_detectada": false,
    "score_risco": 0-100,
    "nivel_risco": "muito_baixo|baixo|medio|alto|muito_alto",
    "indicadores": [
        {
            "tipo": "tipo do indicador",
            "descricao": "descrição",
            "peso": 0-10,
            "evidencia": "dados que suportam"
        }
    ],
    "recomendacao": "aprovar|validar_telefone|validar_documento|revisar_manual|bloquear",
    "acoes_sugeridas": ["lista de ações preventivas"],
    "motivo_principal": "principal razão da avaliação",
    "confianca_analise": 0-100
}
PROMPT;

        $pedidoJson = json_encode($pedido, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $messages = [
            [
                'role' => 'user',
                'content' => "Analise este pedido para detecção de fraude:\n\n{$pedidoJson}"
            ]
        ];

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.2 // Muito determinístico para análise de fraude
        ]);

        $result = $this->parseJsonResponse($response);
        $this->cache->set($cacheKey, $result, 900); // 15 minutos

        return $result;
    }

    /**
     * Otimizar rota de entrega
     *
     * @param array $pedidos Lista de pedidos para otimizar
     * @return array Rota otimizada
     */
    public function otimizarRota(array $pedidos): array {
        $systemPrompt = <<<PROMPT
Você é um especialista em logística e otimização de rotas de delivery.
Considere:
- Distância entre pontos
- Tempo estimado de entrega
- Prioridade dos pedidos
- Horários de entrega prometidos
- Condições de tráfego típicas

Retorne JSON:
{
    "rota_otimizada": [
        {
            "ordem": 1,
            "pedido_id": "id do pedido",
            "endereco": "endereço",
            "tempo_chegada_estimado": "HH:MM",
            "tempo_no_local": 5,
            "observacao": "detalhes"
        }
    ],
    "metricas": {
        "distancia_total_km": 0,
        "tempo_total_minutos": 0,
        "economia_vs_ordem_original": "15%",
        "pedidos_dentro_prazo": 10,
        "pedidos_atrasados": 0
    },
    "alertas": [
        {
            "pedido_id": "id",
            "tipo": "atraso_provavel|distancia_grande|etc",
            "mensagem": "descrição"
        }
    ],
    "sugestoes": ["sugestões para melhorar a eficiência"]
}
PROMPT;

        $pedidosJson = json_encode($pedidos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $messages = [
            [
                'role' => 'user',
                'content' => "Otimize a rota para estes pedidos:\n\n{$pedidosJson}"
            ]
        ];

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.3
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * Analisar performance de shopper
     *
     * @param array $shopper Dados do shopper com métricas
     * @return array Análise de performance
     */
    public function analisarPerformanceShopper(array $shopper): array {
        $systemPrompt = <<<PROMPT
Você é um analista de performance de colaboradores de delivery.
Analise as métricas do shopper e forneça feedback construtivo.

Retorne JSON:
{
    "score_geral": 0-100,
    "classificacao": "iniciante|regular|bom|excelente|elite",
    "pontos_fortes": [
        {"area": "área", "descricao": "descrição", "impacto": "alto|medio|baixo"}
    ],
    "areas_melhoria": [
        {"area": "área", "descricao": "descrição", "sugestao": "como melhorar", "prioridade": "alta|media|baixa"}
    ],
    "comparativo_media": {
        "tempo_coleta": "+10% ou -5%",
        "tempo_entrega": "+5%",
        "avaliacao_clientes": "-2%",
        "taxa_problemas": "+20%"
    },
    "tendencia": "melhorando|estavel|piorando",
    "recomendacoes": ["lista de recomendações específicas"],
    "meta_proxima": "próxima meta a atingir",
    "bonus_sugerido": {
        "elegivel": true,
        "valor": 0,
        "motivo": "razão"
    }
}
PROMPT;

        $shopperJson = json_encode($shopper, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $messages = [
            [
                'role' => 'user',
                'content' => "Analise a performance deste shopper:\n\n{$shopperJson}"
            ]
        ];

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.4
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * Analisar performance de mercado
     *
     * @param array $mercado Dados do mercado com métricas
     * @return array Análise e sugestões
     */
    public function analisarPerformanceMercado(array $mercado): array {
        $systemPrompt = <<<PROMPT
Você é um consultor de negócios especializado em supermercados e delivery.
Analise as métricas do mercado e sugira melhorias.

Retorne JSON:
{
    "score_geral": 0-100,
    "saude_negocio": "critico|atencao|saudavel|excelente",
    "metricas_destaque": [
        {"metrica": "nome", "valor": "valor", "status": "bom|neutro|ruim", "comparativo": "+10% vs média"}
    ],
    "oportunidades": [
        {
            "area": "área",
            "descricao": "descrição da oportunidade",
            "impacto_estimado": "R$ X ou X%",
            "esforco": "baixo|medio|alto",
            "prazo_implementacao": "imediato|curto|medio|longo"
        }
    ],
    "problemas_criticos": [
        {
            "problema": "descrição",
            "impacto": "impacto no negócio",
            "solucao": "solução sugerida",
            "urgencia": "imediata|alta|media|baixa"
        }
    ],
    "benchmark": {
        "posicao_ranking": 1,
        "total_mercados": 100,
        "percentil": 95
    },
    "previsoes": {
        "tendencia_vendas": "crescendo|estavel|caindo",
        "projecao_mes": "R$ X",
        "fatores_risco": ["lista de fatores"]
    },
    "plano_acao": [
        {"acao": "ação", "responsavel": "quem", "prazo": "quando", "kpi": "como medir"}
    ]
}
PROMPT;

        $mercadoJson = json_encode($mercado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $messages = [
            [
                'role' => 'user',
                'content' => "Analise a performance deste mercado:\n\n{$mercadoJson}"
            ]
        ];

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.5
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * Sugerir produtos complementares
     *
     * @param array $carrinho Itens no carrinho
     * @param array $catalogoDisponivel Catálogo de produtos disponíveis
     * @return array Sugestões de produtos
     */
    public function sugerirProdutosComplementares(array $carrinho, array $catalogoDisponivel = []): array {
        $cacheKey = 'ai_complementos_' . md5(json_encode($carrinho));

        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $systemPrompt = <<<PROMPT
Você é um especialista em cross-selling e upselling de supermercado.
Sugira produtos complementares baseado no carrinho do cliente.

Considere:
- Combinações naturais de produtos
- Receitas populares
- Ocasiões (churrasco, festa, etc)
- Produtos frequentemente comprados juntos

Retorne JSON:
{
    "sugestoes": [
        {
            "produto": "nome do produto",
            "categoria": "categoria",
            "motivo": "por que sugerir",
            "relevancia": 0-100,
            "tipo": "complemento|upsell|oferta",
            "mensagem_cliente": "mensagem persuasiva"
        }
    ],
    "ocasiao_detectada": "churrasco|cafe_manha|jantar|festa|etc|nenhuma",
    "receitas_sugeridas": [
        {"nome": "nome da receita", "itens_faltando": ["item1", "item2"]}
    ],
    "economia_potencial": {
        "combos_disponiveis": [
            {"nome": "combo", "economia": "R$ X", "itens": ["item1", "item2"]}
        ]
    }
}
PROMPT;

        $carrinhoJson = json_encode($carrinho, JSON_UNESCAPED_UNICODE);

        $messages = [
            [
                'role' => 'user',
                'content' => "Sugira produtos complementares para este carrinho:\n\n{$carrinhoJson}"
            ]
        ];

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.7
        ]);

        $result = $this->parseJsonResponse($response);
        $this->cache->set($cacheKey, $result, 1800);

        return $result;
    }

    /**
     * Gerar promoções personalizadas
     *
     * @param array $cliente Dados e histórico do cliente
     * @return array Promoções personalizadas
     */
    public function gerarPromocoesPersonalizadas(array $cliente): array {
        $systemPrompt = <<<PROMPT
Você é um especialista em marketing e personalização de ofertas.
Crie promoções personalizadas baseadas no perfil e histórico do cliente.

Retorne JSON:
{
    "promocoes": [
        {
            "tipo": "desconto|frete_gratis|cashback|combo|brinde",
            "titulo": "título da promoção",
            "descricao": "descrição",
            "desconto_valor": 0,
            "desconto_percentual": 0,
            "condicoes": ["condições para aplicar"],
            "validade_horas": 24,
            "codigo": "CUPOM123",
            "relevancia": 0-100,
            "motivo_personalizacao": "por que esta oferta para este cliente"
        }
    ],
    "perfil_cliente": {
        "segmento": "novo|casual|regular|vip",
        "categoria_favorita": "categoria",
        "ticket_medio": 0,
        "frequencia": "semanal|quinzenal|mensal|esporadico"
    },
    "estrategia": "qual estratégia de marketing aplicar",
    "proxima_acao": "sugestão para próxima interação"
}
PROMPT;

        $clienteJson = json_encode($cliente, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $messages = [
            [
                'role' => 'user',
                'content' => "Crie promoções personalizadas para este cliente:\n\n{$clienteJson}"
            ]
        ];

        $response = $this->chat($messages, [
            'system' => $systemPrompt,
            'temperature' => 0.6
        ]);

        return $this->parseJsonResponse($response);
    }

    // =========================================================================
    // MÉTODOS AUXILIARES
    // =========================================================================

    /**
     * Fazer requisição à API Claude
     */
    private function makeRequest(array $payload, bool $useCache = false): array {
        // Verificar cache se habilitado
        if ($useCache) {
            $cacheKey = 'claude_' . md5(json_encode($payload));
            $cached = $this->cache->get($cacheKey);
            if ($cached) {
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        $ch = curl_init($this->apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            $this->logError('CURL Error: ' . $error);
            throw new Exception('Erro de conexão com Claude API: ' . $error);
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = $result['error']['message'] ?? 'Erro desconhecido';
            $this->logError("API Error [{$httpCode}]: {$errorMsg}");
            throw new Exception("Erro na API Claude: {$errorMsg}");
        }

        // Log de uso para monitoramento
        $this->logUsage($payload, $result);

        // Formatar resposta
        $formattedResult = [
            'success' => true,
            'content' => $result['content'][0]['text'] ?? '',
            'model' => $result['model'] ?? $this->model,
            'usage' => $result['usage'] ?? [],
            'stop_reason' => $result['stop_reason'] ?? null,
            'from_cache' => false
        ];

        // Salvar no cache se habilitado
        if ($useCache) {
            $this->cache->set($cacheKey, $formattedResult, $this->cacheTTL);
        }

        return $formattedResult;
    }

    /**
     * Parse de resposta JSON do Claude
     */
    private function parseJsonResponse(array $response): array {
        if (!$response['success']) {
            return $response;
        }

        $content = $response['content'];

        // Tentar extrair JSON da resposta
        // Primeiro, tentar parse direto
        $parsed = json_decode($content, true);

        if ($parsed !== null) {
            return [
                'success' => true,
                'data' => $parsed,
                'raw' => $content,
                'usage' => $response['usage'],
                'from_cache' => $response['from_cache'] ?? false
            ];
        }

        // Tentar extrair JSON de blocos de código
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/m', $content, $matches)) {
            $parsed = json_decode(trim($matches[1]), true);
            if ($parsed !== null) {
                return [
                    'success' => true,
                    'data' => $parsed,
                    'raw' => $content,
                    'usage' => $response['usage'],
                    'from_cache' => $response['from_cache'] ?? false
                ];
            }
        }

        // Tentar encontrar JSON no texto
        if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed !== null) {
                return [
                    'success' => true,
                    'data' => $parsed,
                    'raw' => $content,
                    'usage' => $response['usage'],
                    'from_cache' => $response['from_cache'] ?? false
                ];
            }
        }

        // Retornar como texto se não conseguir parse
        return [
            'success' => true,
            'data' => null,
            'text' => $content,
            'raw' => $content,
            'usage' => $response['usage'],
            'from_cache' => $response['from_cache'] ?? false
        ];
    }

    /**
     * Log de uso da API
     */
    private function logUsage(array $request, array $response): void {
        $logFile = $this->logDir . 'usage_' . date('Y-m-d') . '.log';

        $usage = [
            'timestamp' => date('Y-m-d H:i:s'),
            'model' => $request['model'] ?? $this->model,
            'input_tokens' => $response['usage']['input_tokens'] ?? 0,
            'output_tokens' => $response['usage']['output_tokens'] ?? 0,
            'stop_reason' => $response['stop_reason'] ?? null
        ];

        file_put_contents(
            $logFile,
            json_encode($usage) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Log de erros
     */
    private function logError(string $message): void {
        $logFile = $this->logDir . 'errors_' . date('Y-m-d') . '.log';

        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message
        ];

        file_put_contents(
            $logFile,
            json_encode($log) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Obter estatísticas de uso
     */
    public function getUsageStats(?string $date = null): array {
        $date = $date ?? date('Y-m-d');
        $logFile = $this->logDir . 'usage_' . $date . '.log';

        if (!file_exists($logFile)) {
            return [
                'date' => $date,
                'total_requests' => 0,
                'total_input_tokens' => 0,
                'total_output_tokens' => 0,
                'models' => []
            ];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $stats = [
            'date' => $date,
            'total_requests' => 0,
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'models' => []
        ];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                $stats['total_requests']++;
                $stats['total_input_tokens'] += $entry['input_tokens'] ?? 0;
                $stats['total_output_tokens'] += $entry['output_tokens'] ?? 0;

                $model = $entry['model'] ?? 'unknown';
                if (!isset($stats['models'][$model])) {
                    $stats['models'][$model] = 0;
                }
                $stats['models'][$model]++;
            }
        }

        return $stats;
    }

    /**
     * Limpar cache de AI
     */
    public function clearCache(?string $prefix = null): int {
        $count = 0;
        $cacheDir = __DIR__ . '/../../cache/';
        $files = glob($cacheDir . '*.cache');

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content) {
                $data = @unserialize($content);
                // Verificar se é cache de AI (por convenção, começa com ai_ ou claude_)
                if ($prefix === null || strpos(basename($file), md5($prefix)) !== false) {
                    unlink($file);
                    $count++;
                }
            }
        }

        return $count;
    }
}

// =========================================================================
// HELPER FUNCTIONS
// =========================================================================

/**
 * Obter instância do ClaudeAI
 */
function claude_ai(): ClaudeAI {
    return ClaudeAI::getInstance();
}

/**
 * Análise rápida de texto
 */
function claude_analyze(string $content, string $task): array {
    return claude_ai()->analyze($content, $task);
}

/**
 * Chat rápido
 */
function claude_chat(string $message, ?string $system = null): array {
    $options = [];
    if ($system) {
        $options['system'] = $system;
    }

    return claude_ai()->chat([
        ['role' => 'user', 'content' => $message]
    ], $options);
}
