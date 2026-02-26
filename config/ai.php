<?php
/**
 * Configuração da API Claude AI
 *
 * @author OneMundo Team
 * @version 1.0.0
 */

return [
    // API Key do Claude (Anthropic)
    // Obter em: https://console.anthropic.com/
    'claude_api_key' => getenv('CLAUDE_API_KEY') ?: '',

    // Modelo padrão
    // Opções: claude-3-opus-20240229, claude-3-sonnet-20240229, claude-3-haiku-20240307
    'default_model' => 'claude-3-sonnet-20240229',

    // Configurações de requisição
    'max_tokens' => 4096,
    'temperature' => 0.7,
    'timeout' => 120, // segundos

    // Cache
    'cache_enabled' => true,
    'cache_ttl' => 3600, // 1 hora padrão

    // Limites de uso (por minuto)
    'rate_limit' => [
        'requests_per_minute' => 60,
        'tokens_per_minute' => 100000
    ],

    // Logs
    'logging' => [
        'enabled' => true,
        'log_requests' => true,
        'log_responses' => false, // Atenção: pode conter dados sensíveis
        'log_errors' => true,
        'log_path' => __DIR__ . '/../logs/ai/'
    ],

    // Custos estimados (para monitoramento)
    'pricing' => [
        'claude-3-opus-20240229' => [
            'input' => 0.015,  // por 1K tokens
            'output' => 0.075  // por 1K tokens
        ],
        'claude-3-sonnet-20240229' => [
            'input' => 0.003,
            'output' => 0.015
        ],
        'claude-3-haiku-20240307' => [
            'input' => 0.00025,
            'output' => 0.00125
        ]
    ],

    // Configurações específicas por funcionalidade
    'features' => [
        'fraude' => [
            'model' => 'claude-3-sonnet-20240229',
            'temperature' => 0.2,
            'cache_ttl' => 900 // 15 minutos
        ],
        'atendimento' => [
            'model' => 'claude-3-sonnet-20240229',
            'temperature' => 0.6,
            'cache_ttl' => 0 // Sem cache para chat
        ],
        'sugestoes' => [
            'model' => 'claude-3-haiku-20240307', // Mais rápido e barato
            'temperature' => 0.7,
            'cache_ttl' => 1800 // 30 minutos
        ],
        'performance' => [
            'model' => 'claude-3-sonnet-20240229',
            'temperature' => 0.4,
            'cache_ttl' => 3600 // 1 hora
        ]
    ],

    // Prompts do sistema (templates)
    'system_prompts' => [
        'default' => 'Você é um assistente inteligente do Superbora, especializado em delivery de supermercado.',
        'fraude' => 'Você é um especialista em detecção de fraudes com foco em e-commerce e delivery.',
        'atendimento' => 'Você é um atendente virtual do Superbora, sempre educado, empático e prestativo.',
        'vendas' => 'Você é um especialista em vendas e cross-selling para supermercados.'
    ],

    // Alertas e notificações
    'alerts' => [
        'high_usage_threshold' => 80, // % do limite
        'error_rate_threshold' => 5,  // % de erros
        'notify_email' => 'admin@superbora.com'
    ]
];
