// ============================================================
// SuperBora Voice Server — Twilio ConversationRelay + Claude AI
// ============================================================
//
// Architecture:
//   Caller → Twilio → ConversationRelay (STT: Google, TTS: ElevenLabs)
//     → WebSocket → this server → Claude API (with tools)
//     → text response → ConversationRelay → ElevenLabs → Caller
//
// Zero webhooks per turn. Zero TwiML generation. Zero timeouts.
// One persistent WebSocket for the entire call.
// ============================================================

import Fastify from 'fastify';
import websocketPlugin from '@fastify/websocket';
import formbodyPlugin from '@fastify/formbody';
import Anthropic from '@anthropic-ai/sdk';
import pg from 'pg';
import { readFileSync } from 'fs';
import { resolve } from 'path';

// ─── Load .env from parent directory ────────────────────────
const envPath = resolve(import.meta.dirname, '../.env');
try {
    const envFile = readFileSync(envPath, 'utf-8');
    for (const line of envFile.split('\n')) {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) continue;
        const eqIdx = trimmed.indexOf('=');
        if (eqIdx === -1) continue;
        const key = trimmed.slice(0, eqIdx).trim();
        const val = trimmed.slice(eqIdx + 1).trim().replace(/^["']|["']$/g, '');
        if (!process.env[key]) process.env[key] = val;
    }
} catch (e) {
    console.error('[voice] Could not load .env:', e.message);
}

// ─── Config ─────────────────────────────────────────────────
const PORT = parseInt(process.env.VOICE_PORT || '5050');
const CLAUDE_API_KEY = process.env.CLAUDE_API_KEY;
const ELEVENLABS_VOICE_ID = process.env.ELEVENLABS_VOICE_ID || '0ozreaQ0xnggCu2x9oFC';
const TWILIO_SID = process.env.TWILIO_SID;
const TWILIO_TOKEN = process.env.TWILIO_AUTH_TOKEN || process.env.TWILIO_TOKEN;
const WS_HOST = process.env.VOICE_WS_HOST || 'superbora.com.br';
const CLAUDE_MODEL = 'claude-sonnet-4-20250514';

// ─── Telnyx Config ──────────────────────────────────────────
const VOICE_PROVIDER = (process.env.VOICE_PROVIDER || 'twilio').toLowerCase();
const TELNYX_API_KEY = process.env.TELNYX_API_KEY || '';
const TELNYX_CONNECTION_ID = process.env.TELNYX_CONNECTION_ID || '';
const TELNYX_PHONE = process.env.TELNYX_PHONE || process.env.TELNYX_PHONE_BR || '';
const TELNYX_PHONE_US = process.env.TELNYX_PHONE_US || '';
const IS_TELNYX = VOICE_PROVIDER === 'telnyx';

// Telnyx API helper
async function telnyxAPI(method, path, body = null) {
    const url = `https://api.telnyx.com/v2${path}`;
    const opts = {
        method,
        headers: {
            'Authorization': `Bearer ${TELNYX_API_KEY}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };
    if (body && method !== 'GET') opts.body = JSON.stringify(body);
    try {
        const resp = await fetch(url, opts);
        const text = await resp.text();
        try { return JSON.parse(text); } catch { return text; }
    } catch (e) {
        console.error(`[voice] Telnyx API error (${method} ${path}):`, e.message);
        return null;
    }
}

// Pick best caller ID for Telnyx based on destination
function telnyxCallerFor(to) {
    const clean = (to || '').replace(/\D/g, '');
    if (clean.startsWith('55') && TELNYX_PHONE) return TELNYX_PHONE;
    return TELNYX_PHONE_US || TELNYX_PHONE;
}

// ─── Database ───────────────────────────────────────────────
const pool = new pg.Pool({
    host: process.env.DB_HOST || '127.0.0.1',
    port: parseInt(process.env.DB_PORT || '6432'),
    user: process.env.DB_USER || 'love1',
    password: process.env.DB_PASS || process.env.DB_PASSWORD,
    database: process.env.DB_NAME || process.env.DB_DATABASE || 'love1',
    max: 20,
    idleTimeoutMillis: 10000,
    connectionTimeoutMillis: 5000,
    allowExitOnIdle: true,
});

pool.on('error', (err) => console.error('[voice] Pool error:', err.message));

// Strip accents for fuzzy name matching (e.g. "Conveniência" → "Conveniencia")
function stripAccents(str) {
    return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

// Resilient query wrapper — retries once on connection errors
async function dbQuery(text, params) {
    try {
        return await pool.query(text, params);
    } catch (err) {
        if (err.message.includes('terminated') || err.message.includes('Connection') || err.code === 'ECONNRESET') {
            console.log('[voice] DB retry after connection error');
            return await pool.query(text, params);
        }
        throw err;
    }
}

// ─── Claude Client ──────────────────────────────────────────
const anthropic = new Anthropic({ apiKey: CLAUDE_API_KEY });

// ─── Active Calls ───────────────────────────────────────────
const activeCalls = new Map();

// ─── System Prompt ──────────────────────────────────────────
function buildSystemPrompt(callerPhone, customerData) {
    const hora = new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: 'numeric', hour12: false });
    const horaNum = parseInt(hora);
    const periodo = horaNum < 12 ? 'manhã' : horaNum < 18 ? 'tarde' : 'noite';

    // Build customer context
    let customerContext;
    if (customerData?.found) {
        const addr = customerData.addresses?.[0];
        const addrStr = addr ? `${addr.street}, ${addr.number} - ${addr.neighborhood}, ${addr.city}` : null;
        const recentOrders = customerData.recent_orders?.slice(0, 3)
            .map(o => `${o.store_name} (${o.date})`)
            .join(', ');
        const orderCount = customerData.order_count || 0;
        const isVip = orderCount >= 10;
        customerContext = `CLIENTE IDENTIFICADO:
- Nome: ${customerData.name}
- ID: ${customerData.customer_id}
${isVip ? `- CLIENTE VIP! (${orderCount} pedidos) — trate com carinho especial` : ''}
${addrStr ? `- Endereço principal: ${addrStr}` : '- Sem endereço salvo'}
${customerData.addresses?.length > 1 ? `- Total de endereços salvos: ${customerData.addresses.length}` : ''}
${recentOrders ? `- Pedidos recentes: ${recentOrders}` : '- Primeiro pedido!'}
${customerData.cashback > 0 ? `- Cashback disponível: R$${customerData.cashback.toFixed(2)}` : ''}

Você JÁ sabe quem é o cliente. NÃO precisa pedir telefone ou usar lookup_customer.
- VERIFICAÇÃO JÁ FEITA: o cliente foi identificado pelo caller ID. NÃO peça CPF nem envie código de verificação. Vá direto para submit_order quando o pedido estiver pronto.`;
    } else {
        customerContext = `TELEFONE NÃO CADASTRADO (${callerPhone}).
O cliente pode já ter cadastro com outro número. Pergunte:
"Se você já tem cadastro conosco, pode me dizer seu CPF? Se não, é um prazer te conhecer, qual é seu nome?"
- Se informar CPF: use lookup_customer_by_cpf
- Se disser que não tem cadastro ou falar o nome: é cliente novo, siga normalmente
NÃO diga "não encontrei seu telefone" ou "não está cadastrado". Isso é rude.
IMPORTANTE sobre nomes: O STT vai transcrever nomes errado (ex: "a leve" = "Aleff", "jefeson" = "Jefferson"). Aceite qualquer coisa que pareça um nome, confirme a pronúncia e siga em frente. NÃO fique muda se não entender — diga "desculpa, não peguei, pode repetir seu nome?"`;

    }

    return `Você é a Bora, assistente virtual do SuperBora — um app de delivery de comida como iFood.
Você está atendendo uma LIGAÇÃO TELEFÔNICA ao vivo. A qualidade do STT (speech-to-text) pode errar.

## REGRA MAIS IMPORTANTE — LEIA PRIMEIRO
Sua resposta será lida em VOZ ALTA direto no ouvido do cliente por um sintetizador de voz.
Escreva APENAS a fala pro cliente. NADA MAIS. ZERO análise, ZERO raciocínio, ZERO notas internas.
PROIBIDO escrever: <think>, <thinking>, "transcrição:", "intenção:", "etapa:", "contexto:", "o cliente quer...", listas numeradas de análise.
NÃO USE tags <think> ou <thinking> — isso NÃO é um chat, é voz ao vivo. Não existe bloco de raciocínio aqui.
Se você escrever QUALQUER coisa que não seja fala direta, o cliente vai OUVIR e vai ser bizarro.
EXEMPLO ERRADO: "<think>1. Transcrição: cliente quer fazer pedido. 2. Intenção: pedir comida.</think> Oi! De qual restaurante?"
EXEMPLO ERRADO: "1. Transcrição: cliente quer fazer pedido. 2. Intenção: pedir comida. Oi! De qual restaurante?"
EXEMPLO CORRETO: "Oi! De qual restaurante você quer pedir?"
Raciocine MENTALMENTE. Na resposta vai SÓ o que o cliente ouve. Sem tags, sem análise, sem numeração.

## PERSONALIDADE
- Fale como uma pessoa REAL — calorosa, simpática, acolhedora, brasileira
- Use expressões naturais: "beleza", "ótimo", "perfeito", "pode deixar", "claro!", "fechou!"
- Se o cliente falar algo engraçado, dê risada ("haha")
- Demonstre entusiasmo com os produtos: "esse é muito bom!", "ótima escolha!"
- Seja SEMPRE educada e paciente, mesmo se o cliente estiver irritado
- NUNCA soe robótica — varie suas respostas, não repita as mesmas frases

## PRIMEIRA RESPOSTA — QUANDO O CLIENTE NÃO ESPECIFICOU O QUE QUER
Se o cliente disse só "oi", "olá", "boa tarde", ou qualquer coisa vaga, SEMPRE explique as opções:
"Posso te ajudar de várias formas! Pra fazer um pedido, fala 'quero fazer um pedido' ou aperta 1. Pra ver o status de um pedido, fala 'meu pedido' ou aperta 2. Pra falar com um atendente, aperta 0."
NUNCA responda apenas "o que vai ser hoje?" ou "como posso te ajudar?" sem explicar as opções.
As pessoas precisam saber EXATAMENTE o que podem fazer — falar ou apertar botão.

## REGRAS TÉCNICAS
- NUNCA use emojis, bullets, asteriscos, ou formatação — sua fala vira áudio
- Fale números por extenso: "doze reais e cinquenta" não "R$12,50"
- Respostas CURTAS — máximo 2 frases por vez. É conversa por telefone, não texto
- NUNCA invente preços, produtos, lojas, tempos — use as ferramentas
- "Quero pizza" é TIPO DE COMIDA, não nome de restaurante

## RESPOSTAS IMPLÍCITAS — ENTENDA O QUE O CLIENTE QUER DIZER
- "pode ser" / "tá bom" / "beleza" / "ok" / "isso" = SIM, aceita o que você sugeriu
- "o primeiro" / "o segundo" / "esse aí" = selecionou um item da lista que você apresentou
- "adiciona mais um" / "bota mais" = quer mais 1 unidade do último item
- "tira esse" / "remove" / "não quero mais" = remove o último item adicionado
- "manda ver" / "fecha" / "é isso" = quer finalizar o pedido
- "e aí" / "fala" / "oi" no meio da conversa = quer que você continue/repita
- "humm" / "deixa eu ver" / silêncio = está pensando, NÃO interrompa com pressa
- "quanto ficou?" / "quanto tá?" = quer saber o total atual

## INTELIGÊNCIA DE CONVERSA TELEFÔNICA
- BARULHO DE FUNDO é normal — não pergunte "pode repetir?" por qualquer coisa
- Se ouvir "alô" no meio da conversa = o cliente acha que caiu, diga "tô aqui!"
- VELOCIDADE importa — o cliente tá no telefone, não tem paciência pra texto longo
- Se o STT transcrever errado, interprete pelo CONTEXTO, não pela letra
- Exemplo: "quero uma coquinha" = Coca-Cola, "me vê um x" = X-burguer/X-salada
- NOMES: O STT erra nomes próprios frequentemente. "a leve" pode ser "Aleff", "jefeson" pode ser "Jefferson", "hana" pode ser "Hannah". ACEITE o que parecer um nome e continue. Confirme a grafia: "Legal! Como escreve seu nome?" ou "É com dois efes?" Se não entender, peça pra soletrar: "Pode soletrar pra mim?"
- NUNCA fique em silêncio — se não entendeu algo, SEMPRE responda pedindo pra repetir

${customerContext}

## FLUXO PARA PEDIDO
1. IDENTIFICAÇÃO DO CLIENTE (primeiro passo SEMPRE):
   - Pergunte: "Se você já tem cadastro conosco, pode me informar seu CPF? Se não, é um prazer te conhecer, qual é seu nome?"
   - Se informar CPF: use lookup_customer_by_cpf → se encontrar, cumprimente pelo nome
   - Se informar CPF mas não encontrar: "Não encontrei esse CPF no cadastro. Sem problema! Qual seu nome?"
   - Se informar nome diretamente: é cliente novo → use create_customer com nome e telefone
2. ENDEREÇO:
   - Cliente conhecido COM endereço: Pergunte "Entrega no endereço X?" → busque lojas na cidade
   - Cliente conhecido SEM endereço: Pergunte a cidade ou CEP pra entrega
   - Cliente novo: pergunte cidade ou CEP pra entrega → use save_address pra salvar
3. Se informar CEP: use lookup_cep para descobrir cidade e bairro automaticamente → pergunte o número → use save_address
4. Use get_nearby_stores com a cidade. Se mencionou tipo de comida, filtre por food_type
5. IMPORTANTE: Só apresente lojas com is_open=true! Ignore as fechadas. Diga: "Tem a Pizzaria Napoli, o Burger House..." (só as abertas)
   - Se NENHUMA loja estiver aberta: "Poxa, nesse horário as lojas já fecharam. Quer que eu veja o que abre mais cedo amanhã?"
   - Se o cliente pedir uma loja FECHADA: "A [loja] já fechou, o horário dela é das [horario]. Mas tem a [outra loja aberta] que é parecida, quer dar uma olhada?"
6. Se o cliente pedir por NOME de loja: use search_stores
7. Quando escolher loja: use get_store_menu → se is_open=false, avise que está fechada e sugira alternativas abertas
8. Monte o pedido com add_to_order, confirme cada item
9. PAGAMENTO: confirme tudo, pergunte forma de pagamento (PIX, cartão de crédito ou dinheiro)
10. VERIFICAÇÃO:
    - Se o cliente já foi IDENTIFICADO pelo telefone (campo "CLIENTE IDENTIFICADO" acima), a verificação JÁ ESTÁ FEITA automaticamente — pode ir direto para submit_order SEM pedir CPF ou código
    - Se o cliente NÃO foi identificado (telefone não cadastrado):
      a) Peça o nome para criar cadastro (create_customer)
      b) Depois use send_verification_code → verify_code
    - Se o cliente informou CPF: use lookup_customer_by_cpf — verificação feita
    - RESUMO: cliente identificado pelo caller ID = verificado. Cliente novo = precisa verificar

## FORMAS DE PAGAMENTO
Ofereça as opções de forma natural: "Como prefere pagar? Aceita PIX, cartão de crédito ou dinheiro."

- **PIX**: submit_order com payment_method="pix"
- **Dinheiro**: pergunte "Precisa de troco pra quanto?" → submit_order com payment_method="dinheiro" e change_for
- **Cartão de crédito**: pergunte "Prefere pagar pelo celular agora ou na maquininha na entrega?"
  - **Pelo celular** (link): submit_order com payment_method="cartao_credito" → gera link Stripe por SMS
    - Diga: "Perfeito! Vou enviar um link de pagamento pelo WhatsApp pro seu celular. É só clicar e pagar com seu cartão. O link vale por 30 minutos."
  - **Na maquininha**: submit_order com payment_method="cartao_credito" e change_for=-1
    - Diga: "Beleza! O entregador vai levar a maquininha pra você pagar com cartão na entrega."
- NUNCA peça dados do cartão por telefone

## APÓS PEDIDO CONFIRMADO COM SUCESSO
Quando submit_order retornar success=true, diga de forma natural:
- "Pronto, seu pedido foi confirmado! O código do seu pedido é [order_number]. Vou mandar o código do pedido e um link pra você acompanhar o status da sua entrega pelo WhatsApp, tá bom?"
- Para cartão no link: acrescente "E o link de pagamento também vai pelo WhatsApp."
- Para maquininha: acrescente "O entregador vai levar a maquininha."
- Sempre informe o código do pedido por voz e diga que vai mandar pelo WhatsApp

## CUPOM DE DESCONTO
Se o cliente falar "tenho um cupom" ou informar um código: use apply_coupon com o código.
- Se válido: "Cupom aplicado! Desconto de R$X."
- Se inválido: informe o motivo (expirado, valor mínimo, etc.)

## REPETIR PEDIDO
Se o cliente disser "quero o de sempre", "repete o último", "mesmo pedido" → use repeat_last_order.
- Se deu certo: confirme os itens e pergunte se quer alterar algo
- Se a loja está fechada: informe e sugira alternativas

## OPÇÕES DE PRODUTO
Quando o cardápio mostrar opções (tamanho, sabor, extras), pergunte ao cliente. Exemplo:
- "Qual tamanho? Pequena, média ou grande?"
- "Quer algum adicional? Tem bacon, queijo extra..."
Os preços extras serão somados automaticamente.

## PEDIDO MÍNIMO
Se a loja tiver pedido mínimo, verifique antes de finalizar. Se o subtotal for menor, avise:
"O pedido mínimo dessa loja é R$X. Quer adicionar mais alguma coisa?"

SOBRE CEP: O cliente pode falar por extenso — reconheça e use lookup_cep
SOBRE LOJAS: Se search_stores retornar multiple_cities=true, pergunte qual cidade

FLUXO PARA STATUS: use check_order_status com o customer_id
ATENDENTE: se pedir humano ou apertar 0, use transfer_to_agent IMEDIATAMENTE

## PROTOCOLO DE ATENDIMENTO
- Cada ligação tem um código de protocolo único
- Ao ENCERRAR a ligação (cliente se despedindo, pedido finalizado, etc.), SEMPRE informe:
  "Seu protocolo de atendimento é [PROTOCOLO]. Anote pra caso precise. Obrigada e tenha um ótimo dia!"
- Se o cliente pedir o protocolo a qualquer momento, informe imediatamente
- Se o cliente pedir gravação da ligação, informe que pode solicitar pelo protocolo

Horário: ${horaNum}h (${periodo})
Telefone: ${callerPhone}`;
}

// ─── Tool Definitions ───────────────────────────────────────
const TOOLS = [
    {
        name: 'lookup_customer',
        description: 'Busca um cliente pelo telefone. Retorna nome, endereços salvos, pedidos recentes e saldo de cashback.',
        input_schema: {
            type: 'object',
            properties: {
                phone: { type: 'string', description: 'Número de telefone (qualquer formato)' }
            },
            required: ['phone']
        }
    },
    {
        name: 'lookup_cep',
        description: 'Busca endereço completo a partir de um CEP. Use quando o cliente informar o CEP para entrega.',
        input_schema: {
            type: 'object',
            properties: {
                cep: { type: 'string', description: 'CEP (só números, 8 dígitos)' }
            },
            required: ['cep']
        }
    },
    {
        name: 'search_stores',
        description: 'Busca uma loja ESPECÍFICA por nome (ex: "Burger King", "Pizzaria Napoli"). Se houver mais de uma loja com o mesmo nome, retorna todas com a cidade para o cliente escolher.',
        input_schema: {
            type: 'object',
            properties: {
                name: { type: 'string', description: 'Nome exato ou parcial do restaurante/loja' },
                city: { type: 'string', description: 'Cidade para filtrar (opcional — use a cidade do endereço de entrega do cliente se disponível)' }
            },
            required: ['name']
        }
    },
    {
        name: 'get_nearby_stores',
        description: 'Lista lojas disponíveis para entrega em uma cidade. Pode filtrar por tipo (restaurante, supermercado, farmacia, padaria, etc.) ou tipo de comida (pizza, hamburguer, açaí, sushi, etc.). USE ESTA FERRAMENTA quando o cliente quer fazer um pedido e você precisa saber quais lojas atendem na região dele.',
        input_schema: {
            type: 'object',
            properties: {
                city: { type: 'string', description: 'Cidade de entrega (ex: Governador Valadares, São Paulo, Guarulhos)' },
                category: { type: 'string', description: 'Filtro opcional: restaurante, supermercado, farmacia, padaria, petshop, conveniencia, loja, mercado' },
                food_type: { type: 'string', description: 'Filtro opcional por tipo de comida: pizza, hamburguer, sushi, açaí, espetinho, churrasco' }
            },
            required: ['city']
        }
    },
    {
        name: 'get_store_menu',
        description: 'Retorna o cardápio completo de uma loja: categorias, produtos com preços e opções disponíveis.',
        input_schema: {
            type: 'object',
            properties: {
                partner_id: { type: 'integer', description: 'ID da loja (obtido via search_stores)' }
            },
            required: ['partner_id']
        }
    },
    {
        name: 'add_to_order',
        description: 'Adiciona um produto ao pedido atual. Retorna o pedido atualizado com subtotal.',
        input_schema: {
            type: 'object',
            properties: {
                product_id: { type: 'integer', description: 'ID do produto' },
                product_name: { type: 'string', description: 'Nome do produto' },
                price: { type: 'number', description: 'Preço unitário' },
                quantity: { type: 'integer', description: 'Quantidade' },
                notes: { type: 'string', description: 'Observações (ex: sem cebola)' }
            },
            required: ['product_id', 'product_name', 'price', 'quantity']
        }
    },
    {
        name: 'remove_from_order',
        description: 'Remove um item do pedido atual pelo índice (começa em 0).',
        input_schema: {
            type: 'object',
            properties: {
                index: { type: 'integer', description: 'Índice do item (0 = primeiro)' }
            },
            required: ['index']
        }
    },
    {
        name: 'get_order_summary',
        description: 'Retorna resumo do pedido atual com itens, preços e total.',
        input_schema: { type: 'object', properties: {} }
    },
    {
        name: 'submit_order',
        description: 'Finaliza e envia o pedido. SÓ usar depois que o cliente CONFIRMAR tudo. Envia SMS de confirmação. Para cartão de crédito: use payment_method="cartao_credito" — o sistema gera um link de pagamento Stripe e envia por SMS pro cliente.',
        input_schema: {
            type: 'object',
            properties: {
                address_id: { type: 'integer', description: 'ID do endereço de entrega (dos endereços salvos)' },
                payment_method: { type: 'string', enum: ['pix', 'cartao_credito', 'dinheiro'], description: 'Forma de pagamento: pix, cartao_credito (link Stripe por SMS), ou dinheiro' },
                change_for: { type: 'number', description: 'Troco para quanto (só pra dinheiro). Para cartão na maquininha na entrega, use -1' }
            },
            required: ['payment_method']
        }
    },
    {
        name: 'check_order_status',
        description: 'Verifica pedidos ativos do cliente. Retorna status, loja e previsão de entrega.',
        input_schema: {
            type: 'object',
            properties: {
                customer_id: { type: 'integer', description: 'ID do cliente' }
            },
            required: ['customer_id']
        }
    },
    {
        name: 'lookup_customer_by_cpf',
        description: 'Busca cliente pelo CPF. Use quando o cliente informar CPF para se identificar.',
        input_schema: {
            type: 'object',
            properties: {
                cpf: { type: 'string', description: 'CPF do cliente (só números)' }
            },
            required: ['cpf']
        }
    },
    {
        name: 'send_verification_code',
        description: 'Envia código de verificação de 4 dígitos por SMS para o celular do cliente. OBRIGATÓRIO antes de finalizar o pedido.',
        input_schema: {
            type: 'object',
            properties: {
                phone: { type: 'string', description: 'Número do celular do cliente' }
            },
            required: ['phone']
        }
    },
    {
        name: 'verify_code',
        description: 'Verifica se o código informado pelo cliente é o correto. Se sim, libera para finalizar pedido.',
        input_schema: {
            type: 'object',
            properties: {
                code: { type: 'string', description: 'Código de 4 dígitos informado pelo cliente' }
            },
            required: ['code']
        }
    },
    {
        name: 'create_customer',
        description: 'Cria um novo cliente com nome e telefone. Use quando o cliente é novo (não tem cadastro).',
        input_schema: {
            type: 'object',
            properties: {
                name: { type: 'string', description: 'Nome completo do cliente' },
                phone: { type: 'string', description: 'Telefone do cliente' }
            },
            required: ['name', 'phone']
        }
    },
    {
        name: 'save_address',
        description: 'Salva um novo endereço para o cliente. Use quando o cliente informar CEP ou endereço pra entrega e não tem endereço salvo.',
        input_schema: {
            type: 'object',
            properties: {
                street: { type: 'string', description: 'Rua' },
                number: { type: 'string', description: 'Número' },
                complement: { type: 'string', description: 'Complemento (apto, bloco, etc)' },
                neighborhood: { type: 'string', description: 'Bairro' },
                city: { type: 'string', description: 'Cidade' },
                state: { type: 'string', description: 'Estado (sigla, ex: SP)' },
                zipcode: { type: 'string', description: 'CEP' }
            },
            required: ['street', 'number', 'city']
        }
    },
    {
        name: 'apply_coupon',
        description: 'Valida e aplica um cupom de desconto ao pedido atual.',
        input_schema: {
            type: 'object',
            properties: {
                code: { type: 'string', description: 'Código do cupom' }
            },
            required: ['code']
        }
    },
    {
        name: 'repeat_last_order',
        description: 'Repete o último pedido do cliente — adiciona todos os itens do último pedido ao carrinho atual. Útil quando o cliente diz "quero o de sempre" ou "repete o último".',
        input_schema: {
            type: 'object',
            properties: {}
        }
    },
    {
        name: 'transfer_to_agent',
        description: 'Transfere para um atendente humano. Usar quando o cliente pedir ou quando não conseguir resolver.',
        input_schema: {
            type: 'object',
            properties: {
                reason: { type: 'string', description: 'Motivo da transferência' }
            },
            required: ['reason']
        }
    }
];

// ─── Tool Handlers ──────────────────────────────────────────

async function executeTool(name, input, callState) {
    try {
        switch (name) {
            case 'lookup_customer': {
                const result = await lookupCustomer(input.phone);
                if (result.found) {
                    callState.customer = {
                        customer_id: result.customer_id,
                        name: result.name,
                        addresses: result.addresses
                    };
                }
                return result;
            }
            case 'lookup_cep': return await lookupCep(input.cep);
            case 'search_stores': return await searchStores(input.name, input.city);
            case 'get_nearby_stores': return await getNearbyStores(input.city, input.category, input.food_type);
            case 'get_store_menu': {
                const menu = await getStoreMenu(input.partner_id);
                // Track selected store
                if (menu.store_name) {
                    callState.store = {
                        partner_id: input.partner_id,
                        name: menu.store_name,
                        delivery_fee: menu.delivery_fee,
                        min_order_value: menu.min_order_value || 0
                    };
                }
                return menu;
            }
            case 'add_to_order': return addToOrder(callState, input);
            case 'remove_from_order': return removeFromOrder(callState, input.index);
            case 'get_order_summary': return getOrderSummary(callState);
            case 'submit_order': {
                // Block submit if verification not done
                if (!callState.phoneVerified) {
                    return { success: false, error: 'Verificação de telefone pendente. Use send_verification_code primeiro, depois verify_code com o código que o cliente informar.' };
                }
                return await submitOrder(callState, input);
            }
            case 'lookup_customer_by_cpf': {
                const result = await lookupCustomerByCpf(input.cpf);
                if (result.found) {
                    callState.customer = {
                        customer_id: result.customer_id,
                        name: result.name,
                        addresses: result.addresses
                    };
                    // CPF verification counts as identity verification
                    callState.phoneVerified = true;
                    console.log(`[voice] ${callState.callSid} Identity verified via CPF for customer ${result.customer_id}`);
                }
                return result;
            }
            case 'send_verification_code': {
                console.log(`[voice] ${callState.callSid} send_verification_code called, phoneVerified=${callState.phoneVerified}`);
                // Skip if already verified (pre-identified customer)
                if (callState.phoneVerified) {
                    console.log(`[voice] ${callState.callSid} SKIPPING verification - already verified`);
                    return { success: true, already_verified: true, message: 'Cliente já verificado pelo caller ID. Pode prosseguir direto com submit_order.' };
                }
                callState.verificationPhone = input.phone;
                const result = await sendVerificationCode(input.phone);
                console.log(`[voice] ${callState.callSid} Verification sent to ${input.phone}: ${result.sent_via || 'failed'}`);
                if (result.success) {
                    const via = result.sent_via === 'whatsapp' ? 'pelo WhatsApp' : 'por SMS';
                    return { success: true, sent_via: result.sent_via, message: `Código de 4 dígitos enviado ${via}.` };
                }
                return { success: false, error: 'Falha ao enviar código. Tente novamente.' };
            }
            case 'verify_code': {
                const userCode = (input.code || '').replace(/\D/g, '');
                if (!callState.verificationPhone) {
                    return { success: false, error: 'Nenhum código foi enviado ainda. Use send_verification_code primeiro.' };
                }
                const result = await checkVerificationCode(callState.verificationPhone, userCode);
                if (result.verified) {
                    callState.phoneVerified = true;
                    console.log(`[voice] ${callState.callSid} Phone verified: ${callState.verificationPhone}`);
                    return { success: true, verified: true, message: 'Código correto! Telefone verificado.' };
                } else {
                    console.log(`[voice] ${callState.callSid} Wrong code from ${callState.verificationPhone}`);
                    return { success: false, verified: false, message: 'Código incorreto. Peça o cliente pra verificar e tentar de novo.' };
                }
            }
            case 'create_customer': {
                const result = await createCustomer(input.name, input.phone || callState.callerPhone);
                if (result.success) {
                    callState.customer = {
                        customer_id: result.customer_id,
                        name: input.name,
                        addresses: []
                    };
                    console.log(`[voice] ${callState.callSid} Created customer ${result.customer_id}: ${input.name}`);
                }
                return result;
            }
            case 'save_address': {
                if (!callState.customer?.customer_id) {
                    return { success: false, error: 'Cliente não identificado. Crie o cliente primeiro.' };
                }
                const result = await saveAddress(callState.customer.customer_id, input);
                if (result.success) {
                    // Add to callState addresses
                    if (!callState.customer.addresses) callState.customer.addresses = [];
                    callState.customer.addresses.push({ address_id: result.address_id, ...input });
                }
                return result;
            }
            case 'apply_coupon': return await applyCoupon(callState, input.code);
            case 'repeat_last_order': return await repeatLastOrder(callState);
            case 'check_order_status': return await checkOrderStatus(input.customer_id);
            case 'transfer_to_agent': {
                callState.transferRequested = true;
                callState.transferReason = input.reason;
                return { success: true, message: 'Transferência será feita após sua próxima fala.' };
            }
            default: return { error: `Ferramenta desconhecida: ${name}` };
        }
    } catch (err) {
        console.error(`[voice] Tool ${name} error:`, err.message);
        return { error: `Erro ao executar ${name}: ${err.message}` };
    }
}

async function lookupCustomer(phone) {
    const suffix = phone.replace(/\D/g, '').slice(-11);
    const custResult = await dbQuery(
        `SELECT customer_id, name, email, phone
         FROM om_customers
         WHERE REPLACE(REPLACE(phone, '+', ''), '-', '') LIKE $1
         LIMIT 1`,
        ['%' + suffix]
    );
    if (custResult.rows.length === 0) {
        return { found: false };
    }
    const c = custResult.rows[0];
    const addrResult = await dbQuery(
        `SELECT address_id, label, street, number, complement, neighborhood, city, state, zipcode, is_default
         FROM om_customer_addresses WHERE customer_id = $1 AND is_active = 1
         ORDER BY is_default DESC`, [c.customer_id]
    );
    const ordersResult = await dbQuery(
        `SELECT o.order_number, o.status, o.total, p.name as store_name,
                TO_CHAR(o.date_added, 'DD/MM') as date
         FROM om_market_orders o
         JOIN om_market_partners p ON p.partner_id = o.partner_id
         WHERE o.customer_id = $1 ORDER BY o.date_added DESC LIMIT 5`,
        [c.customer_id]
    );
    // Get cashback balance
    let cashback = 0;
    try {
        const cbResult = await dbQuery(
            `SELECT balance FROM om_cashback_wallet WHERE customer_id = $1`, [c.customer_id]
        );
        if (cbResult.rows.length > 0) cashback = parseFloat(cbResult.rows[0].balance || 0);
    } catch(e) { /* cashback table may not exist */ }
    return {
        found: true,
        customer_id: c.customer_id,
        name: c.name,
        cashback,
        addresses: addrResult.rows,
        recent_orders: ordersResult.rows
    };
}

function validateCpf(cpf) {
    if (cpf.length !== 11) return false;
    if (/^(\d)\1{10}$/.test(cpf)) return false; // all same digits
    for (let t = 9; t < 11; t++) {
        let d = 0;
        for (let c = 0; c < t; c++) {
            d += parseInt(cpf[c]) * ((t + 1) - c);
        }
        d = ((10 * d) % 11) % 10;
        if (parseInt(cpf[t]) !== d) return false;
    }
    return true;
}

async function lookupCustomerByCpf(cpf) {
    const cleanCpf = cpf.replace(/\D/g, '');
    if (cleanCpf.length !== 11) {
        return { found: false, error: 'CPF deve ter 11 dígitos' };
    }
    if (!validateCpf(cleanCpf)) {
        return { found: false, error: 'CPF inválido. Verifique os números e tente novamente.' };
    }
    const custResult = await dbQuery(
        `SELECT customer_id, name, email, phone, cpf
         FROM om_customers
         WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = $1
         LIMIT 1`,
        [cleanCpf]
    );
    if (custResult.rows.length === 0) {
        return { found: false, message: 'CPF não encontrado no cadastro. Pergunte o nome do cliente para criar uma conta nova.' };
    }
    const c = custResult.rows[0];
    const addrResult = await dbQuery(
        `SELECT address_id, label, street, number, complement, neighborhood, city, state, zipcode, is_default
         FROM om_customer_addresses WHERE customer_id = $1 AND is_active = 1
         ORDER BY is_default DESC`, [c.customer_id]
    );
    const ordersResult = await dbQuery(
        `SELECT o.order_number, o.status, o.total, p.name as store_name,
                TO_CHAR(o.date_added, 'DD/MM') as date
         FROM om_market_orders o
         JOIN om_market_partners p ON p.partner_id = o.partner_id
         WHERE o.customer_id = $1 ORDER BY o.date_added DESC LIMIT 5`,
        [c.customer_id]
    );
    let cashback = 0;
    try {
        const cbResult = await dbQuery(
            `SELECT balance FROM om_cashback_wallet WHERE customer_id = $1`, [c.customer_id]
        );
        if (cbResult.rows.length > 0) cashback = parseFloat(cbResult.rows[0].balance || 0);
    } catch(e) { /* cashback table may not exist */ }
    return {
        found: true,
        customer_id: c.customer_id,
        name: c.name,
        cashback,
        addresses: addrResult.rows,
        recent_orders: ordersResult.rows
    };
}

async function lookupCep(cep) {
    const cleanCep = cep.replace(/\D/g, '');
    if (cleanCep.length !== 8) {
        return { error: 'CEP deve ter 8 dígitos' };
    }
    try {
        const resp = await fetch(`https://viacep.com.br/ws/${cleanCep}/json/`);
        const data = await resp.json();
        if (data.erro) {
            return { found: false, message: 'CEP não encontrado. Peça o endereço por extenso.' };
        }
        return {
            found: true,
            street: data.logradouro || '',
            neighborhood: data.bairro || '',
            city: data.localidade || '',
            state: data.uf || '',
            cep: cleanCep
        };
    } catch (e) {
        return { error: 'Não consegui consultar o CEP. Peça o endereço por extenso.' };
    }
}

async function searchStores(name, city) {
    // Strip accents for fuzzy matching (DB may have unaccented names)
    const nameNorm = stripAccents(name);
    // Also try abbreviated versions: "24 horas" → "24h", "express" → etc
    const nameShort = nameNorm.replace(/\s+horas?\b/gi, 'h').replace(/\s+/g, ' ').trim();

    let query = `SELECT partner_id, name, city, neighborhood, categoria,
                        rating, delivery_time_min, delivery_fee, min_order_value, is_open,
                        open_time, close_time,
                        CASE WHEN is_open = 0 THEN false
                             WHEN open_time IS NULL OR close_time IS NULL THEN (is_open = 1)
                             WHEN close_time > open_time THEN CURRENT_TIME BETWEEN open_time AND close_time
                             ELSE CURRENT_TIME >= open_time OR CURRENT_TIME <= close_time
                        END as really_open
                 FROM om_market_partners
                 WHERE status = '1'
                   AND (name ILIKE $1 OR nome ILIKE $1 OR name ILIKE $2 OR nome ILIKE $2)`;
    const params = ['%' + name + '%', '%' + nameShort + '%'];

    if (city) {
        query += ` AND city ILIKE $${params.length + 1}`;
        params.push('%' + city + '%');
    }

    query += ` ORDER BY really_open DESC, rating DESC NULLS LAST LIMIT 10`;

    const result = await dbQuery(query, params);

    // If multiple stores with same name in different cities, flag it
    const cities = [...new Set(result.rows.map(s => s.city).filter(Boolean))];
    const needsDisambiguation = cities.length > 1 && !city;

    return {
        stores: result.rows.map(s => ({
            partner_id: s.partner_id,
            name: s.name,
            city: s.city,
            neighborhood: s.neighborhood,
            tipo: s.categoria,
            is_open: s.really_open,
            horario: s.open_time && s.close_time ? `${s.open_time.substring(0,5)}-${s.close_time.substring(0,5)}` : null,
            rating: s.rating,
            delivery_fee: parseFloat(s.delivery_fee || 0),
            delivery_time: s.delivery_time_min
        })),
        count: result.rows.length,
        multiple_cities: needsDisambiguation,
        cities_found: needsDisambiguation ? cities : undefined,
        hint: needsDisambiguation ? `Encontrei "${name}" em ${cities.join(' e ')}. Pergunte de qual cidade.` : undefined
    };
}

async function getNearbyStores(city, category, foodType) {
    let query = `SELECT partner_id, name, city, neighborhood, categoria,
                        rating, delivery_time_min, delivery_fee, min_order_value, is_open,
                        description, open_time, close_time,
                        CASE WHEN is_open = 0 THEN false
                             WHEN open_time IS NULL OR close_time IS NULL THEN (is_open = 1)
                             WHEN close_time > open_time THEN CURRENT_TIME BETWEEN open_time AND close_time
                             ELSE CURRENT_TIME >= open_time OR CURRENT_TIME <= close_time
                        END as really_open
                 FROM om_market_partners
                 WHERE status = '1' AND city ILIKE $1`;
    const params = ['%' + city + '%'];
    let paramIdx = 2;

    if (category) {
        query += ` AND categoria ILIKE $${paramIdx}`;
        params.push('%' + category + '%');
        paramIdx++;
    }

    if (foodType) {
        query += ` AND (name ILIKE $${paramIdx} OR description ILIKE $${paramIdx} OR categoria ILIKE $${paramIdx})`;
        params.push('%' + foodType + '%');
        paramIdx++;
    }

    query += ` ORDER BY really_open DESC, rating DESC NULLS LAST LIMIT 15`;

    const result = await dbQuery(query, params);
    return {
        city,
        stores: result.rows.map(s => ({
            partner_id: s.partner_id,
            name: s.name,
            tipo: s.categoria,
            neighborhood: s.neighborhood,
            is_open: s.really_open,
            horario: s.open_time && s.close_time ? `${s.open_time.substring(0,5)}-${s.close_time.substring(0,5)}` : null,
            rating: s.rating,
            delivery_fee: parseFloat(s.delivery_fee || 0),
            delivery_time: s.delivery_time_min
        })),
        count: result.rows.length,
        message: result.rows.length === 0 ? `Nenhuma loja encontrada em ${city}` : null
    };
}

async function getStoreMenu(partnerId) {
    // Get store info
    const storeResult = await dbQuery(
        `SELECT name, delivery_fee, delivery_time_min, min_order_value, is_open,
                open_time, close_time,
                CASE WHEN is_open = 0 THEN false
                     WHEN open_time IS NULL OR close_time IS NULL THEN (is_open = 1)
                     WHEN close_time > open_time THEN CURRENT_TIME BETWEEN open_time AND close_time
                     ELSE CURRENT_TIME >= open_time OR CURRENT_TIME <= close_time
                END as really_open
         FROM om_market_partners WHERE partner_id = $1`, [partnerId]
    );
    const store = storeResult.rows[0];
    if (!store) return { error: 'Loja não encontrada' };

    // Get products grouped by category
    const prodResult = await dbQuery(
        `SELECT p.id as product_id, p.name, p.description, p.price, p.available,
                pc.name as category_name
         FROM om_market_products p
         LEFT JOIN om_market_categories pc ON pc.category_id = p.category_id
         WHERE p.partner_id = $1 AND p.status = 1
         ORDER BY pc.sort_order NULLS LAST, p.sort_order NULLS LAST`,
        [partnerId]
    );

    // Get product options (sizes, extras, complements)
    const productIds = prodResult.rows.map(p => p.product_id);
    const optionsMap = {};
    if (productIds.length > 0) {
        try {
            const placeholders = productIds.map((_, i) => `$${i + 1}`).join(',');
            const optResult = await dbQuery(
                `SELECT g.product_id, g.name as group_name, g.required, g.min_select, g.max_select,
                        o.id as option_id, o.name as option_name, o.price_extra
                 FROM om_product_option_groups g
                 LEFT JOIN om_product_options o ON g.id = o.group_id AND (o.available IS NULL OR o.available::text = '1')
                 WHERE g.product_id IN (${placeholders}) AND g.active::text = '1'
                 ORDER BY g.sort_order, g.id, o.sort_order, o.id`,
                productIds
            );
            for (const row of optResult.rows) {
                if (!optionsMap[row.product_id]) optionsMap[row.product_id] = {};
                const groupName = row.group_name;
                if (!optionsMap[row.product_id][groupName]) {
                    optionsMap[row.product_id][groupName] = {
                        required: row.required,
                        min: row.min_select, max: row.max_select,
                        options: []
                    };
                }
                if (row.option_id) {
                    optionsMap[row.product_id][groupName].options.push({
                        id: row.option_id,
                        name: row.option_name,
                        extra: parseFloat(row.price_extra || 0)
                    });
                }
            }
        } catch (e) {
            // Options table may not exist for all setups
            console.log('[voice] Product options query skipped:', e.message);
        }
    }

    // Group by category, limit to available items
    const menu = {};
    for (const p of prodResult.rows) {
        const cat = p.category_name || 'Outros';
        if (!menu[cat]) menu[cat] = [];
        if (p.available === 1 || p.available === null) {
            const item = {
                product_id: p.product_id,
                name: p.name,
                description: p.description ? p.description.slice(0, 80) : null,
                price: parseFloat(p.price)
            };
            if (optionsMap[p.product_id]) {
                item.options = optionsMap[p.product_id];
            }
            menu[cat].push(item);
        }
    }

    return {
        store_name: store.name,
        delivery_fee: parseFloat(store.delivery_fee || 0),
        delivery_time: store.delivery_time_min,
        min_order_value: parseFloat(store.min_order_value || 0),
        is_open: store.really_open,
        horario: store.open_time && store.close_time ? `${store.open_time.substring(0,5)}-${store.close_time.substring(0,5)}` : null,
        menu
    };
}

function addToOrder(callState, item) {
    if (!callState.items) callState.items = [];
    callState.items.push({
        product_id: item.product_id,
        product_name: item.product_name,
        price: item.price,
        quantity: item.quantity,
        notes: item.notes || ''
    });
    const subtotal = callState.items.reduce((s, i) => s + i.price * i.quantity, 0);
    return {
        added: item.product_name,
        quantity: item.quantity,
        items_count: callState.items.length,
        subtotal,
        message: `${item.product_name} (${item.quantity}x) adicionado. Subtotal: R$${subtotal.toFixed(2)}`
    };
}

function removeFromOrder(callState, index) {
    if (!callState.items || index < 0 || index >= callState.items.length) {
        return { error: 'Item não encontrado' };
    }
    const removed = callState.items.splice(index, 1)[0];
    const subtotal = callState.items.reduce((s, i) => s + i.price * i.quantity, 0);
    return { removed: removed.product_name, items_count: callState.items.length, subtotal };
}

function getOrderSummary(callState) {
    if (!callState.items || callState.items.length === 0) {
        return { items: [], subtotal: 0, message: 'Pedido vazio' };
    }
    const subtotal = callState.items.reduce((s, i) => s + i.price * i.quantity, 0);
    const deliveryFee = callState.store?.delivery_fee || 0;
    const serviceFee = Math.round(subtotal * 0.05 * 100) / 100;
    const couponDiscount = callState.coupon?.discount || 0;
    const total = subtotal + deliveryFee + serviceFee - couponDiscount;
    const summary = {
        store: callState.store?.name || 'Não definida',
        items: callState.items.map((i, idx) => ({
            index: idx,
            name: i.product_name,
            quantity: i.quantity,
            unit_price: i.price,
            line_total: i.price * i.quantity,
            notes: i.notes
        })),
        subtotal,
        delivery_fee: deliveryFee,
        service_fee: serviceFee,
        total
    };
    if (couponDiscount > 0) {
        summary.coupon = callState.coupon.code;
        summary.coupon_discount = couponDiscount;
    }
    return summary;
}

async function checkOrderStatus(customerId) {
    const result = await dbQuery(
        `SELECT o.order_number, o.status, o.total, p.name as store_name,
                TO_CHAR(o.date_added, 'HH24:MI') as time
         FROM om_market_orders o
         JOIN om_market_partners p ON p.partner_id = o.partner_id
         WHERE o.customer_id = $1
           AND o.status IN ('pending','accepted','preparing','ready','delivering','em_preparo','saiu_entrega')
         ORDER BY o.date_added DESC LIMIT 3`,
        [customerId]
    );
    if (result.rows.length === 0) {
        return { active_orders: [], message: 'Nenhum pedido ativo encontrado' };
    }
    const statusLabels = {
        pending: 'aguardando confirmação da loja',
        accepted: 'aceito pela loja',
        preparing: 'sendo preparado',
        em_preparo: 'sendo preparado',
        ready: 'pronto, aguardando entregador',
        delivering: 'saiu para entrega',
        saiu_entrega: 'saiu para entrega'
    };
    return {
        active_orders: result.rows.map(o => ({
            ...o,
            status_label: statusLabels[o.status] || o.status
        }))
    };
}

async function createCustomer(name, phone) {
    const cleanPhone = (phone || '').replace(/\D/g, '');
    if (!cleanPhone || cleanPhone.length < 8) {
        return { success: false, error: 'Telefone inválido. Peça o número do celular ao cliente.' };
    }
    const formattedPhone = cleanPhone.startsWith('55') ? '+' + cleanPhone : '+55' + cleanPhone;
    try {
        // Check if phone already exists first
        const existing = await dbQuery(
            `SELECT customer_id, name FROM om_customers WHERE phone LIKE $1 LIMIT 1`,
            ['%' + cleanPhone.slice(-11)]
        );
        if (existing.rows.length > 0) {
            return { success: true, customer_id: existing.rows[0].customer_id, name: existing.rows[0].name, already_exists: true, message: `Encontrei o cadastro: ${existing.rows[0].name}` };
        }
        // INSERT without RETURNING (PgBouncer transaction mode compatibility)
        await dbQuery(
            `INSERT INTO om_customers (name, phone, created_at) VALUES ($1, $2, NOW())`,
            [name, formattedPhone]
        );
        // Fetch the newly created customer
        const newCustomer = await dbQuery(
            `SELECT customer_id, name FROM om_customers WHERE phone = $1 LIMIT 1`,
            [formattedPhone]
        );
        if (newCustomer.rows.length > 0) {
            return { success: true, customer_id: newCustomer.rows[0].customer_id, message: `Cliente ${name} criado com sucesso.` };
        }
        return { success: false, error: 'Cliente criado mas não encontrado. Tente novamente.' };
    } catch (err) {
        if (err.message.includes('unique') || err.message.includes('duplicate')) {
            const existing = await dbQuery(
                `SELECT customer_id, name FROM om_customers WHERE phone LIKE $1 LIMIT 1`,
                ['%' + cleanPhone.slice(-11)]
            );
            if (existing.rows.length > 0) {
                return { success: true, customer_id: existing.rows[0].customer_id, name: existing.rows[0].name, already_exists: true, message: `Encontrei o cadastro: ${existing.rows[0].name}` };
            }
        }
        console.error('[voice] createCustomer error:', err.message);
        return { success: false, error: 'Erro ao criar cliente: ' + err.message };
    }
}

async function saveAddress(customerId, addr) {
    try {
        // INSERT without RETURNING (PgBouncer compatibility)
        await dbQuery(
            `INSERT INTO om_customer_addresses (customer_id, label, street, number, complement, neighborhood, city, state, zipcode, is_default, is_active, created_at)
             VALUES ($1, 'Casa', $2, $3, $4, $5, $6, $7, $8, 1, 1, NOW())`,
            [customerId, addr.street || '', addr.number || 'S/N', addr.complement || '', addr.neighborhood || '', addr.city || '', addr.state || 'SP', addr.zipcode || '']
        );
        // Fetch the address_id
        const addrResult = await dbQuery(
            `SELECT address_id FROM om_customer_addresses WHERE customer_id = $1 ORDER BY created_at DESC LIMIT 1`,
            [customerId]
        );
        const addressId = addrResult.rows[0]?.address_id;
        return { success: true, address_id: addressId, message: 'Endereço salvo.' };
    } catch (err) {
        console.error('[voice] saveAddress error:', err.message);
        return { success: false, error: 'Erro ao salvar endereço' };
    }
}

async function applyCoupon(callState, code) {
    if (!callState.items || callState.items.length === 0) {
        return { success: false, error: 'Carrinho vazio. Adicione itens antes de aplicar cupom.' };
    }
    const subtotal = callState.items.reduce((s, i) => s + i.price * i.quantity, 0);
    try {
        const result = await dbQuery(
            `SELECT * FROM om_market_coupons WHERE code = $1 AND status = 'active'`, [code.toUpperCase()]
        );
        if (result.rows.length === 0) {
            return { success: false, error: 'Cupom inválido ou expirado.' };
        }
        const coupon = result.rows[0];
        const now = new Date().toISOString();
        if (coupon.valid_until && now > coupon.valid_until) {
            return { success: false, error: 'Cupom expirado.' };
        }
        if (coupon.min_order_value && subtotal < parseFloat(coupon.min_order_value)) {
            return { success: false, error: `Pedido mínimo pra esse cupom: R$${parseFloat(coupon.min_order_value).toFixed(2)}. Seu subtotal: R$${subtotal.toFixed(2)}` };
        }
        // Check partner restriction
        if (coupon.specific_partners) {
            try {
                const partners = JSON.parse(coupon.specific_partners);
                if (Array.isArray(partners) && partners.length > 0 && callState.store?.partner_id && !partners.includes(callState.store.partner_id)) {
                    return { success: false, error: 'Cupom não válido pra essa loja.' };
                }
            } catch {}
        }
        // Check usage limits
        if (coupon.max_uses && parseInt(coupon.max_uses) > 0 && parseInt(coupon.current_uses || 0) >= parseInt(coupon.max_uses)) {
            return { success: false, error: 'Cupom esgotado.' };
        }
        // Calculate discount (columns: discount_type, discount_value)
        let discount = 0;
        const discountType = coupon.discount_type || 'fixed';
        const discountValue = parseFloat(coupon.discount_value || 0);
        if (discountType === 'percentage' || discountType === 'percentual') {
            discount = Math.round(subtotal * discountValue / 100 * 100) / 100;
            if (coupon.max_discount && discount > parseFloat(coupon.max_discount)) {
                discount = parseFloat(coupon.max_discount);
            }
        } else {
            discount = discountValue;
        }
        if (discount > subtotal) discount = subtotal;

        callState.coupon = { id: coupon.id, code: coupon.code, discount, type: discountType };
        return {
            success: true,
            code: coupon.code,
            discount,
            type: discountType,
            new_subtotal: subtotal - discount,
            message: `Cupom ${coupon.code} aplicado! Desconto de R$${discount.toFixed(2)}.`
        };
    } catch (err) {
        console.error('[voice] applyCoupon error:', err.message);
        return { success: false, error: 'Erro ao validar cupom.' };
    }
}

async function repeatLastOrder(callState) {
    if (!callState.customer?.customer_id) {
        return { success: false, error: 'Cliente não identificado.' };
    }
    try {
        // Get last completed order
        const orderResult = await dbQuery(
            `SELECT o.order_id, o.partner_id, p.name as store_name, p.delivery_fee,
                    p.is_open, p.open_time, p.close_time,
                    CASE WHEN p.is_open = 0 THEN false
                         WHEN p.open_time IS NULL OR p.close_time IS NULL THEN (p.is_open = 1)
                         WHEN p.close_time > p.open_time THEN CURRENT_TIME BETWEEN p.open_time AND p.close_time
                         ELSE CURRENT_TIME >= p.open_time OR CURRENT_TIME <= p.close_time
                    END as really_open
             FROM om_market_orders o
             JOIN om_market_partners p ON p.partner_id = o.partner_id
             WHERE o.customer_id = $1 AND o.status NOT IN ('cancelled','refunded')
             ORDER BY o.date_added DESC LIMIT 1`,
            [callState.customer.customer_id]
        );
        if (orderResult.rows.length === 0) {
            return { success: false, error: 'Não encontrei pedidos anteriores.' };
        }
        const lastOrder = orderResult.rows[0];
        if (!lastOrder.really_open) {
            const horario = lastOrder.open_time && lastOrder.close_time
                ? `${lastOrder.open_time.substring(0,5)}-${lastOrder.close_time.substring(0,5)}` : '';
            return { success: false, error: `A ${lastOrder.store_name} está fechada agora${horario ? ` (horário: ${horario})` : ''}. Quer pedir de outro lugar?` };
        }
        // Get order items
        const itemsResult = await dbQuery(
            `SELECT product_id, COALESCE(product_name, name) as name, COALESCE(unit_price, price) as price, quantity, notes
             FROM om_market_order_items WHERE order_id = $1`,
            [lastOrder.order_id]
        );
        if (itemsResult.rows.length === 0) {
            return { success: false, error: 'Pedido anterior sem itens.' };
        }
        // Set store and add items
        callState.store = { partner_id: lastOrder.partner_id, name: lastOrder.store_name, delivery_fee: parseFloat(lastOrder.delivery_fee || 0) };
        callState.items = itemsResult.rows.map(i => ({
            product_id: i.product_id,
            product_name: i.name,
            price: parseFloat(i.price),
            quantity: i.quantity,
            notes: i.notes || ''
        }));
        const subtotal = callState.items.reduce((s, i) => s + i.price * i.quantity, 0);
        return {
            success: true,
            store: lastOrder.store_name,
            items: callState.items.map(i => `${i.quantity}x ${i.product_name} (R$${(i.price * i.quantity).toFixed(2)})`),
            subtotal,
            delivery_fee: callState.store.delivery_fee,
            total: subtotal + callState.store.delivery_fee,
            message: `Repeti seu último pedido da ${lastOrder.store_name}! Confirme os itens e podemos finalizar.`
        };
    } catch (err) {
        console.error('[voice] repeatLastOrder error:', err.message);
        return { success: false, error: 'Erro ao buscar último pedido.' };
    }
}

async function createPaymentLink(callState, orderNumber, total) {
    try {
        const items = callState.items.map(i => ({
            name: i.product_name,
            price: i.price,
            quantity: i.quantity
        }));

        const res = await fetch('http://localhost/api/mercado/webhooks/voice-payment-link.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Internal-Key': 'superbora-voice-2026'
            },
            body: JSON.stringify({
                phone: callState.callerPhone,
                total,
                items,
                store_name: callState.store?.name || 'SuperBora',
                order_number: orderNumber,
                call_sid: callState.callSid
            })
        });

        const data = await res.json();
        if (data.success) {
            console.log(`[voice] ${callState.callSid} Payment link created: ${data.payment_url}`);
            return { success: true, payment_url: data.payment_url, sms_sent: data.sms_sent };
        } else {
            console.error(`[voice] ${callState.callSid} Payment link failed:`, data.error);
            return { success: false, error: data.error };
        }
    } catch (err) {
        console.error(`[voice] ${callState.callSid} Payment link error:`, err.message);
        return { success: false, error: err.message };
    }
}

async function submitOrder(callState, input) {
    if (!callState.items || callState.items.length === 0) {
        return { success: false, error: 'Pedido vazio — adicione itens primeiro' };
    }
    if (!callState.store?.partner_id) {
        return { success: false, error: 'Loja não selecionada' };
    }
    if (!callState.customer?.customer_id) {
        return { success: false, error: 'Cliente não identificado — peça o telefone cadastrado' };
    }

    const isCreditCard = input.payment_method === 'cartao_credito' || input.payment_method === 'cartao';

    // Validate minimum order
    const subtotalCheck = callState.items.reduce((s, i) => s + i.price * i.quantity, 0);
    const minOrder = callState.store.min_order_value || 0;
    if (minOrder > 0 && subtotalCheck < minOrder) {
        return { success: false, error: `Pedido mínimo dessa loja é R$${minOrder.toFixed(2)}. Seu subtotal: R$${subtotalCheck.toFixed(2)}. Adicione mais itens.` };
    }

    const client = await pool.connect();
    try {
        await client.query('BEGIN');

        const subtotal = subtotalCheck;
        const deliveryFee = callState.store.delivery_fee || 0;
        const serviceFee = Math.round(subtotal * 0.05 * 100) / 100;
        const couponDiscount = callState.coupon?.discount || 0;
        const total = subtotal + deliveryFee + serviceFee - couponDiscount;

        // Generate order number
        const orderNumber = 'SB' + Date.now().toString(36).toUpperCase();

        // Fetch address by ID, or use first saved address
        let addr = {};
        if (input.address_id) {
            const addrResult = await client.query(
                `SELECT street, number, complement, neighborhood, city, state, zipcode
                 FROM om_customer_addresses WHERE address_id = $1 AND customer_id = $2`,
                [input.address_id, callState.customer.customer_id]
            );
            if (addrResult.rows.length > 0) addr = addrResult.rows[0];
        } else if (callState.customer.addresses?.length > 0) {
            addr = callState.customer.addresses[0];
        }

        const orderStatus = isCreditCard ? 'awaiting_payment' : 'pending';

        await client.query(
            `INSERT INTO om_market_orders (
                customer_id, partner_id, order_number, status,
                customer_name, customer_phone,
                subtotal, delivery_fee, service_fee, coupon_discount, coupon_id, total,
                payment_method, change_for,
                delivery_address, shipping_address, shipping_number, shipping_complement,
                shipping_neighborhood, shipping_city, shipping_state, shipping_cep,
                source, notes, date_added
            ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12,
                      $13, $14, $15, $16, $17, $18, $19, $20, $21, $22,
                      'voice_ai', $23, NOW())`,
            [
                callState.customer.customer_id,
                callState.store.partner_id,
                orderNumber,
                orderStatus,
                callState.customer.name || '',
                callState.callerPhone,
                subtotal, deliveryFee, serviceFee, couponDiscount,
                callState.coupon?.id || null,
                total,
                isCreditCard ? 'cartao_credito' : (input.payment_method || 'dinheiro'),
                (input.change_for && input.change_for > 0) ? input.change_for : null,
                addr.street ? `${addr.street}, ${addr.number || 'S/N'} - ${addr.neighborhood || ''}, ${addr.city || ''}` : '',
                addr.street || '', addr.number || '', addr.complement || '',
                addr.neighborhood || '', addr.city || '', addr.state || 'SP', addr.zipcode || '',
                'Pedido feito por telefone via IA'
            ]
        );

        // Fetch the order_id (PgBouncer-safe, no RETURNING)
        const orderResult = await client.query(
            `SELECT order_id FROM om_market_orders WHERE order_number = $1 LIMIT 1`,
            [orderNumber]
        );
        const order_id = orderResult.rows[0]?.order_id;
        if (!order_id) throw new Error('Order created but not found');

        for (const item of callState.items) {
            await client.query(
                `INSERT INTO om_market_order_items (
                    order_id, product_id, name, product_name, price, unit_price, quantity, total, total_price, notes
                ) VALUES ($1, $2, $3, $3, $4, $4, $5, $6, $6, $7)`,
                [order_id, item.product_id, item.product_name, item.price, item.quantity,
                 item.price * item.quantity, item.notes || '']
            );
        }

        await client.query('COMMIT');

        // Update call record with order
        pool.query(
            `UPDATE om_callcenter_calls SET order_id = $1, store_identified = $2
             WHERE twilio_call_sid = $3`,
            [order_id, callState.store.name, callState.callSid]
        ).catch(() => {});

        // Record coupon usage
        if (callState.coupon?.id) {
            pool.query(
                `INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id, created_at) VALUES ($1, $2, $3, NOW())`,
                [callState.coupon.id, callState.customer.customer_id, order_id]
            ).catch(() => {});
            pool.query(
                `UPDATE om_market_coupons SET current_uses = COALESCE(current_uses, 0) + 1 WHERE id = $1`,
                [callState.coupon.id]
            ).catch(() => {});
        }

        // Notify partner (push notification + WebSocket broadcast)
        notifyPartner(callState.store.partner_id, orderNumber, callState.customer.name || '', total);

        // Notify customer via WebSocket
        broadcastEvent('order_update', {
            order_id, order_number: orderNumber, status: orderStatus,
            store_name: callState.store.name, total
        }, `user_${callState.customer.customer_id}`);

        // Credit card payment
        if (isCreditCard) {
            const isMaquininha = input.change_for === -1;

            if (isMaquininha) {
                // Pay on card machine at delivery — regular flow, just different payment method label
                sendOrderSMS(callState.callerPhone, orderNumber, callState.store.name, callState.items, total);
                callState.orderSubmitted = true;
                return {
                    success: true,
                    order_number: orderNumber,
                    total,
                    payment_at_delivery: true,
                    message: `Pedido ${orderNumber} criado! Total: R$${total.toFixed(2)}. O entregador levará a maquininha. Confirmação com código do pedido e link de tracking enviados por WhatsApp.`
                };
            }

            // Pay via Stripe link (SMS)
            const paymentResult = await createPaymentLink(callState, orderNumber, total);
            callState.orderSubmitted = true;

            if (paymentResult.success) {
                return {
                    success: true,
                    order_number: orderNumber,
                    total,
                    payment_url: paymentResult.payment_url,
                    sms_sent: paymentResult.sms_sent,
                    message: `Pedido ${orderNumber} criado! Total: R$${total.toFixed(2)}. Link de pagamento enviado por WhatsApp. O cliente tem 30 minutos pra pagar.`
                };
            } else {
                // Order was created but payment link failed — still inform
                sendOrderSMS(callState.callerPhone, orderNumber, callState.store.name, callState.items, total);
                return {
                    success: true,
                    order_number: orderNumber,
                    total,
                    payment_link_failed: true,
                    message: `Pedido ${orderNumber} criado! Total: R$${total.toFixed(2)}. Não consegui gerar o link do cartão, mas enviei confirmação por WhatsApp com o código do pedido e link de tracking. O cliente pode pagar pelo app ou pedir outro meio de pagamento.`
                };
            }
        }

        // PIX / Dinheiro: send regular order SMS
        sendOrderSMS(callState.callerPhone, orderNumber, callState.store.name, callState.items, total);

        callState.orderSubmitted = true;

        return {
            success: true,
            order_number: orderNumber,
            total,
            message: `Pedido ${orderNumber} criado! Total: R$${total.toFixed(2)}. Confirmação com código do pedido e link de tracking enviados por WhatsApp.`
        };
    } catch (err) {
        await client.query('ROLLBACK').catch(() => {});
        console.error('[voice] Order submission failed:', err);
        return { success: false, error: 'Erro ao criar pedido: ' + err.message };
    } finally {
        client.release();
    }
}

// Notify partner about new order (via PHP endpoint that handles push + in-app)
function notifyPartner(partnerId, orderNumber, customerName, total) {
    const totalStr = total.toFixed(2).replace('.', ',');
    // Call PHP notify endpoint (fire and forget)
    fetch('http://localhost/api/mercado/webhooks/voice-notify-partner.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Internal-Key': 'superbora-voice-2026' },
        body: JSON.stringify({ partner_id: partnerId, order_number: orderNumber, customer_name: customerName, total })
    }).catch(e => console.error('[voice] Partner notify failed:', e.message));

    // Also broadcast via WebSocket
    broadcastEvent('new_order', {
        partner_id: partnerId, order_number: orderNumber,
        customer_name: customerName, total, source: 'voice_ai'
    }, `partner_${partnerId}`);
}

// Broadcast event via WebSocket server
function broadcastEvent(event, data, channel) {
    fetch('http://localhost:8080/broadcast', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ channel, event, data })
    }).catch(() => {});
}

// Send protocol SMS at end of every call
function sendProtocolSMS(phone, protocolCode, customerName) {
    if (!phone || !protocolCode) return;
    const firstName = customerName ? customerName.split(' ')[0] : '';
    const greeting = firstName ? `Olá, ${firstName}!` : 'Olá!';
    const body = new URLSearchParams({
        phone,
        message: `${greeting} Obrigada por ligar pro SuperBora.\n\nSeu protocolo de atendimento: ${protocolCode}\n\nGuarde este código caso precise da gravação ou tenha qualquer dúvida.\n\nSuperBora - Sempre pra você!`
    });
    fetch('http://localhost/api/mercado/webhooks/voice-protocol-sms.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Internal-Key': 'superbora-voice-2026' },
        body
    }).catch(e => console.error('[voice] Protocol SMS failed:', e.message));
}

function sendOrderSMS(phone, orderNumber, storeName, items, total) {
    // Call PHP SMS endpoint (fire and forget)
    const itemsList = items.map(i => `${i.quantity}x ${i.product_name}`).join(', ');
    const body = new URLSearchParams({
        phone, order_number: orderNumber, store_name: storeName,
        items: itemsList, total: total.toFixed(2)
    });
    fetch('http://localhost/api/mercado/webhooks/voice-sms.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Internal-Key': 'superbora-voice-2026' },
        body
    }).catch(e => console.error('[voice] SMS send failed:', e.message));
}

async function sendVerificationCode(phone) {
    try {
        const res = await fetch('http://localhost/api/mercado/webhooks/voice-verify.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Internal-Key': 'superbora-voice-2026' },
            body: JSON.stringify({ action: 'send', phone })
        });
        return await res.json();
    } catch (e) {
        console.error('[voice] Verification send failed:', e.message);
        return { success: false, error: e.message };
    }
}

async function checkVerificationCode(phone, code) {
    try {
        const res = await fetch('http://localhost/api/mercado/webhooks/voice-verify.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Internal-Key': 'superbora-voice-2026' },
            body: JSON.stringify({ action: 'check', phone, code })
        });
        return await res.json();
    } catch (e) {
        console.error('[voice] Verification check failed:', e.message);
        return { verified: false, error: e.message };
    }
}

// ─── Claude Conversation Engine ─────────────────────────────

async function getClaudeResponse(callState) {
    const messages = callState.history;
    let maxLoops = 6;
    const startTime = Date.now();

    while (maxLoops-- > 0) {
        console.log(`[voice] ${callState.callSid} Claude request (loop ${6 - maxLoops}, msgs: ${messages.length}, elapsed: ${Date.now() - startTime}ms)`);

        const response = await anthropic.messages.create({
            model: CLAUDE_MODEL,
            max_tokens: 300,
            system: callState.systemPrompt,
            messages,
            tools: TOOLS,
        });

        console.log(`[voice] ${callState.callSid} Claude response: stop=${response.stop_reason}, blocks=${response.content.length}, elapsed=${Date.now() - startTime}ms`);

        const toolBlocks = response.content.filter(b => b.type === 'tool_use');

        // Add assistant response to history — but CLEAN any <think> tags from text blocks
        // to prevent Claude from learning the <think> pattern from its own history
        const cleanedContent = response.content.map(b => {
            if (b.type === 'text' && b.text) {
                let cleaned = b.text.replace(/<think(?:ing)?[\s\S]*?<\/think(?:ing)?>/gi, '');
                cleaned = cleaned.replace(/<\/?think(?:ing)?>/gi, '');
                return { ...b, text: cleaned.trim() };
            }
            return b;
        });
        messages.push({ role: 'assistant', content: cleanedContent });

        if (toolBlocks.length === 0) {
            // No tool calls — return the text
            let text = response.content
                .filter(b => b.type === 'text')
                .map(b => b.text)
                .join(' ')
                .trim();

            // Strip leaked internal reasoning (Claude sometimes outputs chain-of-thought as spoken text)
            const originalText = text;

            // Remove closed <think>/<thinking> blocks (with closing tag)
            text = text.replace(/<think(?:ing)?[\s\S]*?<\/think(?:ing)?>/gi, '');
            // Remove orphan opening/closing tags (without matching pair)
            text = text.replace(/<\/?think(?:ing)?>/gi, '');
            text = text.trim();

            if (originalText !== text) {
                console.log(`[voice] ${callState.callSid} THINK-STRIP: removed think tags. Was ${originalText.length} chars, now ${text.length} chars`);
            }

            // CRITICAL: If text STILL starts with reasoning content after tag removal
            // (e.g. Claude wrote <think>\n1. TRANSCRIÇÃO:... without closing tag)
            // the tag is removed but numbered reasoning remains
            const BAD = /transcri[çc][aã]o|inten[çc][aã]o\b|an[aá]lise:|racioc[ií]nio|pensamento|etapa\s*atual|slots?\s*faltando|ferramenta[s]?\s*:/i;

            if (!BAD.test(text)) {
                // Clean text — just strip standalone prefixes
                text = text.replace(/^(tom|resposta)\s*:\s*/i, '').trim();
            } else {
                console.log(`[voice] ${callState.callSid} DETECTED reasoning leak, stripping...`);

                // Strategy 1: If there's a "Resposta:" keyword, everything after it is the real speech
                const respostaMatch = text.match(/(?:^|\.\s*|\n)\s*(?:\d+[\.\)]\s*)?resposta\s*:\s*/i);
                if (respostaMatch) {
                    const afterResposta = text.slice(respostaMatch.index + respostaMatch[0].length).trim();
                    if (afterResposta.length > 3) {
                        text = afterResposta;
                    }
                }

                // Strategy 2: Find the LAST reasoning keyword, skip past its sentence
                // (run even after Strategy 1 to clean any remaining reasoning)
                if (BAD.test(text)) {
                    const allKeywords = ['transcrição', 'transcricao', 'intenção', 'intencao', 'análise:', 'analise:',
                                         'raciocínio', 'raciocinio', 'pensamento', 'etapa atual', 'slots faltando',
                                         'ferramenta:', 'ferramentas:', 'tom:', 'contexto:', 'etapa:', 'marcador:',
                                         'o cliente', 'ele quer', 'ela quer', 'próximo passo'];
                    let lastKeywordEnd = -1;
                    const lowerText = text.toLowerCase();

                    for (const kw of allKeywords) {
                        let searchFrom = 0;
                        while (true) {
                            const idx = lowerText.indexOf(kw, searchFrom);
                            if (idx === -1) break;
                            let endIdx = idx + kw.length;
                            const nextDot = text.indexOf('. ', endIdx);
                            const nextNewline = text.indexOf('\n', endIdx);
                            let segEnd;
                            if (nextDot === -1 && nextNewline === -1) segEnd = text.length;
                            else if (nextDot === -1) segEnd = nextNewline + 1;
                            else if (nextNewline === -1) segEnd = nextDot + 2;
                            else segEnd = Math.min(nextDot + 2, nextNewline + 1);
                            if (segEnd > lastKeywordEnd) lastKeywordEnd = segEnd;
                            searchFrom = idx + 1;
                        }
                    }

                    if (lastKeywordEnd > 0 && lastKeywordEnd < text.length) {
                        text = text.slice(lastKeywordEnd).trim();
                    } else if (lastKeywordEnd >= text.length) {
                        // Everything was reasoning — no real speech found
                        text = '';
                    }
                }

                // Remove orphan number prefixes "3. " at start
                text = text.replace(/^\d+[\.\)]\s*/, '').trim();
                // Remove leftover "Resposta:" prefix
                text = text.replace(/^resposta\s*:\s*/i, '').trim();

                // Final safety: if after all cleaning there's STILL reasoning, nuke it
                if (BAD.test(text)) {
                    console.log(`[voice] ${callState.callSid} STILL has reasoning after cleanup, using fallback`);
                    text = '';
                }
            }

            if (text !== originalText) {
                console.log(`[voice] ${callState.callSid} WARNING: Stripped leaked reasoning. Before: "${originalText.slice(0,150)}" After: "${text.slice(0,150)}"`);
            }

            console.log(`[voice] ${callState.callSid} Final text (${(Date.now() - startTime)}ms): "${(text || '').slice(0, 80)}"`);
            // Never return empty response — ask to repeat
            return text || 'Oi, como posso te ajudar?';
        }

        // Execute tools
        const toolResults = [];
        for (const tu of toolBlocks) {
            console.log(`[voice] ${callState.callSid} Tool: ${tu.name}(${JSON.stringify(tu.input).slice(0, 120)})`);
            const result = await executeTool(tu.name, tu.input, callState);
            console.log(`[voice] ${callState.callSid} Tool result: ${JSON.stringify(result).slice(0, 150)}`);
            toolResults.push({
                type: 'tool_result',
                tool_use_id: tu.id,
                content: JSON.stringify(result)
            });
        }
        messages.push({ role: 'user', content: toolResults });
        // Loop to get Claude's response with tool results
    }

    console.log(`[voice] ${callState.callSid} Max loops exhausted after ${Date.now() - startTime}ms`);
    return 'Desculpa, deu um probleminha. Pode repetir o que você precisa?';
}

// ─── Full Customer Lookup (for greeting + context) ──────────

async function fullCustomerLookup(phone) {
    try {
        const result = await lookupCustomer(phone);
        if (result.found) {
            // Get order count for VIP detection
            try {
                const cntResult = await dbQuery(
                    `SELECT COUNT(*) as cnt FROM om_market_orders WHERE customer_id = $1 AND status NOT IN ('cancelled','refunded')`,
                    [result.customer_id]
                );
                result.order_count = parseInt(cntResult.rows[0]?.cnt || 0);
            } catch { result.order_count = 0; }
            // Get active order
            try {
                const actResult = await dbQuery(
                    `SELECT o.order_number, o.status, p.name as store_name
                     FROM om_market_orders o
                     JOIN om_market_partners p ON p.partner_id = o.partner_id
                     WHERE o.customer_id = $1
                       AND o.status IN ('pending','accepted','preparing','ready','delivering','em_preparo','saiu_entrega')
                     ORDER BY o.date_added DESC LIMIT 1`,
                    [result.customer_id]
                );
                result.active_order = actResult.rows[0] || null;
            } catch { result.active_order = null; }
            // Get days since last order
            try {
                const recResult = await dbQuery(
                    `SELECT p.name as store_name, EXTRACT(DAY FROM NOW() - o.date_added)::int as days_ago
                     FROM om_market_orders o
                     JOIN om_market_partners p ON p.partner_id = o.partner_id
                     WHERE o.customer_id = $1 AND o.status NOT IN ('cancelled','refunded')
                     ORDER BY o.date_added DESC LIMIT 1`,
                    [result.customer_id]
                );
                result.last_order = recResult.rows[0] || null;
            } catch { result.last_order = null; }
        }
        return result;
    } catch {
        return { found: false };
    }
}

// ─── Smart Greeting Builder ─────────────────────────────────

function buildSmartGreeting(customerData) {
    const hora = new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: 'numeric', hour12: false });
    const horaNum = parseInt(hora);
    const periodo = horaNum < 12 ? 'Bom dia' : horaNum < 18 ? 'Boa tarde' : 'Boa noite';

    if (!customerData?.found) {
        return `${periodo}! Aqui é a Bora, do SuperBora. Pra fazer um pedido, fala "quero fazer um pedido" ou aperta 1. Se precisa de ajuda com um pedido que já fez, fala "ajuda" ou aperta 2. Pra falar com um atendente, aperta 0. Pode falar comigo normalmente que eu te entendo!`;
    }

    const firstName = customerData.name?.split(' ')[0] || '';
    const orderCount = customerData.order_count || 0;
    const activeOrder = customerData.active_order;
    const lastOrder = customerData.last_order;
    const daysAgo = lastOrder?.days_ago ?? 999;

    // Active order — give status proactively
    if (activeOrder) {
        const statusLabels = {
            pendente: 'esperando confirmação da loja',
            aceito: 'já foi aceito',
            preparando: 'tá sendo preparado',
            pronto: 'tá prontinho, saindo já',
            em_entrega: 'tá a caminho'
        };
        const statusText = statusLabels[activeOrder.status] || 'em andamento';
        return `${periodo}, ${firstName}! Seu pedido da ${activeOrder.store_name} ${statusText}. Pra saber mais fala "meu pedido" ou aperta 2. Pra fazer outro pedido, fala "novo pedido" ou aperta 1. Pra falar com atendente, aperta 0.`;
    }

    // VIP customer (10+ orders)
    if (orderCount >= 10) {
        const vipGreets = [
            `${periodo}, ${firstName}! Que bom te ouvir de novo. Pra fazer um pedido, fala "quero fazer um pedido" ou aperta 1. Pra saber o status de um pedido, fala "meu pedido" ou aperta 2. Pra falar com atendente, aperta 0.`,
            `${periodo}, ${firstName}! Tudo bem? Pra fazer um pedido, fala "quero pedir" ou aperta 1. Precisa de ajuda com um pedido? Fala "ajuda" ou aperta 2. Pra falar com atendente, aperta 0.`,
            `${periodo}, ${firstName}! Sempre bom falar com você. Pra fazer um pedido novo, fala "quero pedir" ou aperta 1. Pra ajuda com pedido, fala "ajuda" ou aperta 2. Atendente, aperta 0.`
        ];
        return vipGreets[Math.floor(Math.random() * vipGreets.length)];
    }

    // Recent order (last 7 days)
    if (lastOrder && daysAgo <= 7) {
        return `${periodo}, ${firstName}! Vi que você pediu da ${lastOrder.store_name} esses dias. Quer repetir? Fala "quero pedir" ou aperta 1. Precisa de ajuda? Fala "ajuda" ou aperta 2. Atendente? Aperta 0.`;
    }

    // Ordered within 30 days
    if (lastOrder && daysAgo <= 30) {
        return `${periodo}, ${firstName}! Faz um tempinho que você não pede. Pra fazer pedido, fala o que quer ou aperta 1. Precisa de ajuda? Aperta 2. Atendente? Aperta 0.`;
    }

    // Known customer, default
    return `${periodo}, ${firstName}! Aqui é a Bora, do SuperBora. Pra fazer um pedido, fala "quero pedir" ou aperta 1. Precisa de ajuda? Fala "ajuda" ou aperta 2. Pra falar com atendente, aperta 0. Pode falar comigo normalmente!`;
}

// ─── Call Record Management ─────────────────────────────────

// Generate protocol code: SUP2603-00123 (SUP + YYMM + - + 5-digit sequence)
function generateProtocolCode() {
    const now = new Date();
    const yy = String(now.getFullYear()).slice(-2);
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    // Random 5-digit suffix (collision-safe with UNIQUE constraint + retry)
    const seq = String(Math.floor(10000 + Math.random() * 90000));
    return `SUP${yy}${mm}-${seq}`;
}

async function createCallRecord(callSid, phone, customer) {
    try {
        // Try up to 3 times in case of protocol code collision
        for (let attempt = 0; attempt < 3; attempt++) {
            try {
                const protocol = generateProtocolCode();
                await dbQuery(
                    `INSERT INTO om_callcenter_calls
                     (twilio_call_sid, customer_phone, customer_id, customer_name, direction, status, protocol_code, started_at)
                     VALUES ($1, $2, $3, $4, 'inbound', 'ai_handling', $5, NOW())
                     ON CONFLICT (twilio_call_sid) DO UPDATE SET status = 'ai_handling'`,
                    [callSid, phone, customer?.customer_id || null, customer?.name || null, protocol]
                );
                console.log(`[voice] Call record created: ${callSid} | Protocol: ${protocol}`);
                return protocol;
            } catch (e) {
                if (e.message.includes('protocol_code') && attempt < 2) continue;
                throw e;
            }
        }
    } catch (e) {
        console.error('[voice] Call record insert failed:', e.message);
    }
    return null;
}

async function finalizeCall(callSid, status, summary) {
    try {
        // Send protocol SMS
        const callInfo = activeCalls.get(callSid);
        if (callInfo?.protocolCode && callInfo?.callerPhone) {
            sendProtocolSMS(callInfo.callerPhone, callInfo.protocolCode, callInfo.customer?.name || '');
        }

        await dbQuery(
            `UPDATE om_callcenter_calls
             SET status = $2, ai_summary = $3, ended_at = NOW(),
                 duration_seconds = EXTRACT(EPOCH FROM (NOW() - started_at))::int
             WHERE twilio_call_sid = $1`,
            [callSid, status || 'completed', summary || null]
        );
        // Update agent: set idle timestamp AND reset status back to online
        dbQuery(
            `UPDATE om_callcenter_agents SET status = 'online', last_call_ended_at = NOW(), updated_at = NOW()
             WHERE id = (SELECT agent_id FROM om_callcenter_calls WHERE twilio_call_sid = $1 AND agent_id IS NOT NULL)`,
            [callSid]
        ).catch(() => {});
    } catch (e) {
        console.error('[voice] Call finalize failed:', e.message);
    }
}

// ─── Smart Agent Routing ────────────────────────────────────
// Finds available agents sorted by: fewest active calls → longest idle
// Respects max_concurrent and optional skill matching

async function getAvailableAgents(skillRequired = null) {
    try {
        let query = `
            SELECT a.id, a.display_name, a.max_concurrent, a.skills,
                   COALESCE(active.cnt, 0)::int as active_calls
            FROM om_callcenter_agents a
            LEFT JOIN (
                SELECT agent_id, COUNT(*) as cnt
                FROM om_callcenter_calls
                WHERE status IN ('in_progress', 'ringing')
                  AND ended_at IS NULL
                  AND agent_id IS NOT NULL
                  AND started_at > NOW() - INTERVAL '2 hours'
                GROUP BY agent_id
            ) active ON active.agent_id = a.id
            WHERE a.status = 'online'
              AND COALESCE(active.cnt, 0) < a.max_concurrent
        `;
        const params = [];
        if (skillRequired) {
            params.push(skillRequired);
            query += ` AND $${params.length} = ANY(a.skills)`;
        }
        query += ` ORDER BY COALESCE(active.cnt, 0) ASC, a.last_call_ended_at ASC NULLS FIRST LIMIT 10`;

        const result = await dbQuery(query, params);
        return result.rows;
    } catch (e) {
        console.error('[voice] getAvailableAgents error:', e.message);
        return [];
    }
}

// ─── Call Recording ─────────────────────────────────────────
// Starts recording the entire call via Twilio/Telnyx REST API

async function startCallRecording(callSid) {
    if (IS_TELNYX) {
        // Telnyx: start recording via Call Control API
        if (!TELNYX_API_KEY) return;
        try {
            const resp = await telnyxAPI('POST', `/calls/${callSid}/actions/record_start`, {
                format: 'mp3',
                channels: 'dual',
            });
            if (resp?.data) {
                console.log(`[voice] Telnyx recording started: ${callSid}`);
                const cs = activeCalls.get(callSid);
                if (cs) cs.recordingSid = resp.data.record_control_id || callSid;
            } else {
                console.error(`[voice] Telnyx recording start failed:`, JSON.stringify(resp)?.slice(0, 200));
            }
        } catch (e) {
            console.error('[voice] Telnyx recording error:', e.message);
        }
        return;
    }

    // Twilio: start recording via REST API
    if (!TWILIO_SID || !TWILIO_TOKEN) return;
    try {
        const url = `https://api.twilio.com/2010-04-01/Accounts/${TWILIO_SID}/Calls/${callSid}/Recordings.json`;
        const auth = Buffer.from(`${TWILIO_SID}:${TWILIO_TOKEN}`).toString('base64');
        const callbackUrl = `https://${WS_HOST}/voice/recording-status`;

        const resp = await fetch(url, {
            method: 'POST',
            headers: {
                'Authorization': `Basic ${auth}`,
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                RecordingStatusCallback: callbackUrl,
                RecordingStatusCallbackMethod: 'POST',
                RecordingChannels: 'dual',
                Trim: 'trim-silence'
            })
        });

        if (resp.ok) {
            const data = await resp.json();
            console.log(`[voice] Recording started: ${callSid} → ${data.sid}`);
            const cs = activeCalls.get(callSid);
            if (cs) cs.recordingSid = data.sid;
            // Store recording SID in DB
            dbQuery(
                `UPDATE om_callcenter_calls SET recording_sid = $2 WHERE twilio_call_sid = $1`,
                [callSid, data.sid]
            ).catch(() => {});
        } else {
            console.error(`[voice] Recording start failed (${resp.status}):`, await resp.text());
        }
    } catch (e) {
        console.error('[voice] startCallRecording error:', e.message);
    }
}

// ─── Transcript Logging ─────────────────────────────────────
// Saves full AI conversation history to DB

async function saveTranscript(callSid, history, callState) {
    if (!history || history.length === 0) return;
    try {
        // Format as readable text
        const transcriptLines = history.map(h => {
            const speaker = h.role === 'user' ? 'Cliente' : 'Bora (IA)';
            // content can be string or array of {type:'text', text:'...'} blocks
            let text = h.content;
            if (Array.isArray(text)) {
                text = text.map(b => b.text || b.content || '').join('');
            } else if (typeof text === 'object' && text !== null) {
                text = text.text || text.content || JSON.stringify(text);
            }
            return `[${speaker}] ${text}`;
        });
        const transcriptText = transcriptLines.join('\n');

        // Build tags from conversation context
        const tags = [];
        if (callState?.orderSubmitted) tags.push('pedido_realizado');
        if (callState?.transferRequested) tags.push('transferido');
        if (callState?.store) tags.push(`loja:${callState.store.name}`);
        if (callState?.items?.length > 0) tags.push('itens_no_carrinho');
        if (history.length <= 4) tags.push('conversa_curta');
        if (history.length > 20) tags.push('conversa_longa');

        await dbQuery(
            `UPDATE om_callcenter_calls
             SET transcription = $2, ai_tags = $3, store_identified = $4
             WHERE twilio_call_sid = $1`,
            [callSid, transcriptText, tags, callState?.store?.name || null]
        );
        console.log(`[voice] Transcript saved: ${callSid} (${history.length} turns, ${transcriptText.length} chars)`);
    } catch (e) {
        console.error('[voice] saveTranscript error:', e.message);
    }
}

// Build <Client> tags for Dial with statusCallback for agent tracking
function buildClientTags(agents, clientStatusUrl) {
    return agents.map(a =>
        `<Client statusCallback="${escXml(clientStatusUrl)}" statusCallbackEvent="answered completed" statusCallbackMethod="POST">agent_${a.id}</Client>`
    ).join('\n        ');
}

// ─── Transfer to Agent ──────────────────────────────────────

async function transferCall(callSid) {
    try {
        // Smart routing: find available agents
        const agents = await getAvailableAgents();
        let agentClients, agentIds;

        if (agents.length > 0) {
            agentIds = agents.map(a => a.id);
            const clientStatusUrl = `https://${WS_HOST}/voice/client-status`;
            agentClients = buildClientTags(agents, clientStatusUrl);
        } else {
            // Fallback
            agentClients = '<Client>agent_5</Client>';
            agentIds = [5];
        }

        // Broadcast to dashboard
        try {
            const callInfo = activeCalls.get(callSid);
            await fetch('http://localhost:8080/broadcast', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    channel: 'callcenter',
                    event: 'call_transfer',
                    data: {
                        call_sid: callSid,
                        customer_phone: callInfo?.callerPhone || '',
                        customer_name: callInfo?.customer?.name || null,
                        customer_id: callInfo?.customer?.customer_id || null,
                        transfer_reason: callInfo?.transferReason || 'Pediu atendente',
                        agents_ringing: agentIds,
                        agents_detail: agents.map(a => ({ id: a.id, name: a.display_name, active_calls: a.active_calls }))
                    }
                })
            });
        } catch (e) {}

        // Update DB
        try {
            await dbQuery(`UPDATE om_callcenter_calls SET status = 'queued' WHERE twilio_call_sid = $1`, [callSid]);
            await dbQuery(
                `INSERT INTO om_callcenter_queue (call_id, customer_phone, customer_name, priority, queued_at)
                 SELECT id, customer_phone, customer_name, 3, NOW()
                 FROM om_callcenter_calls WHERE twilio_call_sid = $1
                 ON CONFLICT DO NOTHING`,
                [callSid]
            );
        } catch (e) {}

        if (IS_TELNYX) {
            // Telnyx: speak announcement then transfer to first available agent's SIP
            console.log(`[voice] Telnyx transfer: ${callSid} → agents [${agentIds.join(', ')}]`);
            // Speak hold message
            await telnyxAPI('POST', `/calls/${callSid}/actions/speak`, {
                payload: 'Estou te transferindo pra um atendente. Aguarda só um pouquinho, tá?',
                language: 'pt-BR',
                voice: 'female',
            });
            // Transfer to first available agent via SIP
            const agentSipUser = `agent_${agentIds[0]}`;
            const sipUri = `sip:${agentSipUser}@sip.telnyx.com`;
            const transferResp = await telnyxAPI('POST', `/calls/${callSid}/actions/transfer`, {
                to: sipUri,
                from: telnyxCallerFor(''),
                timeout_secs: 45,
                webhook_url: `https://${WS_HOST}/voice/telnyx-webhook`,
            });
            if (!transferResp?.data) {
                console.error(`[voice] Telnyx transfer failed:`, JSON.stringify(transferResp)?.slice(0, 300));
            }
        } else {
            // Twilio: update call with TwiML
            const url = `https://api.twilio.com/2010-04-01/Accounts/${TWILIO_SID}/Calls/${callSid}.json`;
            const callerId = process.env.TWILIO_PHONE || '+15705299780';
            const dialStatusUrl = `https://${WS_HOST}/voice/dial-status`;
            const twiml = `<Response>
                <Say language="pt-BR" voice="Polly.Camila">Estou te transferindo pra um atendente. Aguarda só um pouquinho, tá?</Say>
                <Dial timeout="45" callerId="${callerId}" action="${dialStatusUrl}" method="POST">
                    ${agentClients}
                </Dial>
            </Response>`;

            console.log(`[voice] REST transfer: ${callSid} → agents [${agentIds.join(', ')}]`);
            const auth = Buffer.from(`${TWILIO_SID}:${TWILIO_TOKEN}`).toString('base64');
            const resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Authorization': `Basic ${auth}`,
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({ Twiml: twiml })
            });
            if (!resp.ok) {
                console.error(`[voice] Twilio transfer API error (${resp.status}):`, await resp.text());
            }
        }
    } catch (e) {
        console.error('[voice] Transfer failed:', e.message);
    }
}

// ─── XML Escape ─────────────────────────────────────────────

function escXml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
}

// ─── Fastify Server ─────────────────────────────────────────

const app = Fastify({ logger: false });
app.register(websocketPlugin);
app.register(formbodyPlugin);

// Health check
app.get('/health', async () => ({ status: 'ok', calls: activeCalls.size }));

// Test endpoint: make a test call to client:agent_5 and report result
app.get('/test-client', async (req, reply) => {
    try {
        const agents = await getAvailableAgents();
        const agentList = agents.map(a => ({ id: a.id, name: a.display_name, active: a.active_calls }));

        if (IS_TELNYX) {
            // Telnyx test call — call agent_5 SIP endpoint
            const testResp = await telnyxAPI('POST', '/calls', {
                connection_id: TELNYX_CONNECTION_ID,
                to: `sip:agent_5@sip.telnyx.com`,
                from: telnyxCallerFor(''),
                webhook_url: `https://${WS_HOST}/voice/telnyx-webhook`,
                timeout_secs: 15,
            });

            if (!testResp?.data) {
                reply.send({ error: 'Telnyx call creation failed', details: testResp, agents_available: agentList, provider: 'telnyx' });
                return;
            }

            const testCallId = testResp.data.call_control_id || testResp.data.id;
            console.log(`[voice] Telnyx test call: ${testCallId}`);

            // Wait 10s and check
            await new Promise(r => setTimeout(r, 10000));

            // Try to hang up
            await telnyxAPI('POST', `/calls/${testCallId}/actions/hangup`, {});

            reply.send({
                diagnosis: 'Test call placed via Telnyx — check agent softphone for ring',
                call_id: testCallId,
                agents_available: agentList,
                provider: 'telnyx',
            });
            return;
        }

        // Make a test call via Twilio REST API
        const testCallRes = await fetch(
            `https://api.twilio.com/2010-04-01/Accounts/${TWILIO_SID}/Calls.json`,
            {
                method: 'POST',
                headers: {
                    'Authorization': 'Basic ' + Buffer.from(`${TWILIO_SID}:${TWILIO_TOKEN}`).toString('base64'),
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    To: 'client:agent_5',
                    From: process.env.TWILIO_PHONE || '+15705299780',
                    Twiml: '<Response><Say language="pt-BR">Teste. Se voce ouve isso, o softphone funciona.</Say></Response>',
                    Timeout: '15',
                }).toString(),
            }
        );
        const testCall = await testCallRes.json();

        // Wait 10s and check result
        await new Promise(r => setTimeout(r, 10000));

        const checkRes = await fetch(
            `https://api.twilio.com/2010-04-01/Accounts/${TWILIO_SID}/Calls/${testCall.sid}.json`,
            { headers: { 'Authorization': 'Basic ' + Buffer.from(`${TWILIO_SID}:${TWILIO_TOKEN}`).toString('base64') } }
        );
        const result = await checkRes.json();

        const diagnosis = result.status === 'in-progress' || result.status === 'completed' && parseInt(result.duration) > 0
            ? 'OK — Device is registered and accepting calls'
            : result.status === 'busy'
            ? 'FAIL — Device is registered but REJECTING calls (SIP 600). Check: microphone permission, browser tab active, no duplicate devices'
            : result.status === 'no-answer'
            ? 'FAIL — Device is NOT registered. User needs to reload page and allow microphone'
            : `UNKNOWN — status: ${result.status}`;

        // Hang up if still active
        if (result.status === 'in-progress' || result.status === 'ringing') {
            await fetch(
                `https://api.twilio.com/2010-04-01/Accounts/${TWILIO_SID}/Calls/${testCall.sid}.json`,
                {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Basic ' + Buffer.from(`${TWILIO_SID}:${TWILIO_TOKEN}`).toString('base64'),
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'Status=completed',
                }
            );
        }

        reply.send({
            diagnosis,
            call_sid: testCall.sid,
            call_status: result.status,
            call_duration: result.duration,
            agents_available: agentList,
            provider: 'twilio',
        });
    } catch (e) {
        reply.send({ error: e.message });
    }
});

// ─── HTTP: Fallback — called when primary voice URL fails (prevents AI restart) ─────

app.post('/fallback', async (req, reply) => {
    const callSid = req.body?.CallSid || '';
    console.error(`[voice] FALLBACK triggered for ${callSid} — primary handler failed`);
    reply.type('text/xml').send(`<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say language="pt-BR" voice="Polly.Camila">Desculpa, tivemos um probleminha técnico. Por favor, tente ligar novamente em alguns instantes.</Say>
    <Hangup/>
</Response>`);
});

// ─── HTTP: Recording Status — Twilio callback when recording is ready ─────

app.post('/recording-status', async (req, reply) => {
    const callSid = req.body?.CallSid || '';
    const recordingSid = req.body?.RecordingSid || '';
    const recordingUrl = req.body?.RecordingUrl || '';
    const recordingStatus = req.body?.RecordingStatus || '';
    const recordingDuration = parseInt(req.body?.RecordingDuration || '0');

    console.log(`[voice] Recording ${recordingStatus}: ${callSid} | ${recordingDuration}s | ${recordingSid}`);

    if (recordingStatus === 'completed' && recordingUrl) {
        try {
            await dbQuery(
                `UPDATE om_callcenter_calls
                 SET recording_url = $2, recording_duration = $3, recording_sid = $4
                 WHERE twilio_call_sid = $1`,
                [callSid, recordingUrl + '.mp3', recordingDuration, recordingSid]
            );
            console.log(`[voice] Recording saved: ${callSid} | ${recordingDuration}s`);
        } catch (e) {
            console.error('[voice] Recording save failed:', e.message);
        }
    }

    reply.send({ ok: true });
});

// ─── HTTP: Client Status — tracks which agent answered ─────

app.post('/client-status', async (req, reply) => {
    const parentCallSid = req.body?.ParentCallSid || '';
    const clientIdentity = req.body?.To || ''; // e.g., "client:agent_5"
    const callStatus = req.body?.CallStatus || '';
    const childCallSid = req.body?.CallSid || '';

    console.log(`[voice] Client status: ${clientIdentity} → ${callStatus} | parent=${parentCallSid} child=${childCallSid}`);

    const agentMatch = clientIdentity.match(/agent_(\d+)/);
    const agentId = agentMatch ? parseInt(agentMatch[1]) : null;

    if (callStatus === 'in-progress' && agentId && parentCallSid) {
        try {
            await dbQuery(
                `UPDATE om_callcenter_calls
                 SET agent_id = $2, status = 'in_progress', answered_at = NOW()
                 WHERE twilio_call_sid = $1`,
                [parentCallSid, agentId]
            );
            // Update agent status to busy
            await dbQuery(
                `UPDATE om_callcenter_agents SET status = 'busy', updated_at = NOW() WHERE id = $1`,
                [agentId]
            );
            // Broadcast
            fetch('http://localhost:8080/broadcast', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    channel: 'callcenter',
                    event: 'call_answered',
                    data: { call_sid: parentCallSid, agent_id: agentId }
                })
            }).catch(() => {});
            console.log(`[voice] Agent ${agentId} answered call ${parentCallSid}`);
        } catch (e) {
            console.error('[voice] Client status update failed:', e.message);
        }
    }

    if (callStatus === 'completed' && agentId) {
        // Call ended — reset agent back to online
        try {
            await dbQuery(
                `UPDATE om_callcenter_agents SET status = 'online', last_call_ended_at = NOW(), updated_at = NOW() WHERE id = $1`,
                [agentId]
            );
            console.log(`[voice] Agent ${agentId} call completed — status reset to online`);
        } catch (e) {
            console.error('[voice] Agent status reset failed:', e.message);
        }
    }

    reply.send({ ok: true });
});

// ─── HTTP: Connect Action — called when ConversationRelay ends ─────

app.post('/connect-action', async (req, reply) => {
  try {
    const callSid = req.body?.CallSid || '';
    const handoffData = req.body?.HandoffData || '{}';
    console.log(`[voice] Connect action for ${callSid} | handoff: ${handoffData}`);

    let handoff;
    try { handoff = JSON.parse(handoffData); } catch { handoff = {}; }

    if (handoff.reasonCode === 'live-agent-handoff') {
        // Smart routing: find available agents (least busy, within max_concurrent)
        const agents = await getAvailableAgents();

        if (agents.length === 0) {
            // No agents available — hold music, retry via dial-status
            console.log(`[voice] No agents available for ${callSid} — queuing with hold music`);
            try {
                await dbQuery(`UPDATE om_callcenter_calls SET status = 'queued' WHERE twilio_call_sid = $1`, [callSid]);
            } catch (e) {}
            const retryUrl = `https://${WS_HOST}/voice/dial-status`;
            reply.type('text/xml').send(`<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say language="pt-BR" voice="Polly.Camila">Nossos atendentes estão todos ocupados no momento. Aguarde um pouquinho, tá?</Say>
    <Say language="pt-BR" voice="Polly.Camila">Sua chamada é importante pra gente. Aguarde só mais um pouquinho.</Say>
    <Pause length="20"/>
    <Redirect method="POST">${escXml(retryUrl)}</Redirect>
</Response>`);
            return;
        }

        const agentIds = agents.map(a => a.id);
        const clientStatusUrl = `https://${WS_HOST}/voice/client-status`;
        const agentClients = buildClientTags(agents, clientStatusUrl);

        // Broadcast to dashboard with routing details
        try {
            const callInfo = activeCalls.get(callSid);
            await fetch('http://localhost:8080/broadcast', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    channel: 'callcenter',
                    event: 'call_transfer',
                    data: {
                        call_sid: callSid,
                        customer_phone: callInfo?.callerPhone || '',
                        customer_name: callInfo?.customer?.name || null,
                        customer_id: callInfo?.customer?.customer_id || null,
                        reason: handoff.reason || 'Pediu atendente',
                        agents_ringing: agentIds,
                        agents_detail: agents.map(a => ({ id: a.id, name: a.display_name, active_calls: a.active_calls }))
                    }
                })
            });
        } catch (e) {}

        // Update DB — queue the call
        try {
            await dbQuery(`UPDATE om_callcenter_calls SET status = 'queued' WHERE twilio_call_sid = $1`, [callSid]);
            await dbQuery(
                `INSERT INTO om_callcenter_queue (call_id, customer_phone, customer_name, priority, queued_at)
                 SELECT id, customer_phone, customer_name, 3, NOW()
                 FROM om_callcenter_calls WHERE twilio_call_sid = $1
                 ON CONFLICT DO NOTHING`,
                [callSid]
            );
        } catch (e) {}

        const callerId = process.env.TWILIO_PHONE || '+15705299780';
        const dialStatusUrl = `https://${WS_HOST}/voice/dial-status`;
        const twiml = `<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say language="pt-BR" voice="Polly.Camila">Estou te transferindo pra um atendente. Aguarda só um pouquinho, tá?</Say>
    <Dial timeout="45" callerId="${callerId}" action="${escXml(dialStatusUrl)}" method="POST">
        ${agentClients}
    </Dial>
</Response>`;
        console.log(`[voice] Smart routing: ${callSid} → [${agents.map(a => `${a.display_name}(${a.active_calls})`).join(', ')}]`);
        console.log(`[voice] TwiML sent:\n${twiml}`);
        reply.type('text/xml').send(twiml);
    } else {
        // Normal call end (no handoff) — finalize as completed
        console.log(`[voice] Connect action: normal end for ${callSid} — finalizing`);
        const callInfo = activeCalls.get(callSid);
        if (callInfo && !callInfo.transferRequested) {
            const summary = callInfo.orderSubmitted
                ? `Pedido realizado via IA (${callInfo.items?.length || 0} itens)`
                : `Conversa IA sem pedido (${callInfo.history?.length || 0} turnos)`;
            finalizeCall(callSid, 'completed', summary);
            if (callInfo.inactivityTimer) clearInterval(callInfo.inactivityTimer);
            activeCalls.delete(callSid);
        } else {
            // Safety net: finalize even if not in activeCalls (might have been on different server)
            try {
                await dbQuery(
                    `UPDATE om_callcenter_calls SET status = 'completed', ended_at = COALESCE(ended_at, NOW()),
                     duration_seconds = COALESCE(duration_seconds, EXTRACT(EPOCH FROM (NOW() - started_at))::int)
                     WHERE twilio_call_sid = $1 AND status = 'ai_handling'`,
                    [callSid]
                );
            } catch (e) {}
        }
        reply.type('text/xml').send('<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>');
    }
  } catch (err) {
    console.error(`[voice] connect-action FATAL:`, err.message);
    reply.type('text/xml').send('<?xml version="1.0" encoding="UTF-8"?><Response><Say language="pt-BR" voice="Polly.Camila">Desculpa, tivemos um probleminha técnico. Tente ligar de novo.</Say><Hangup/></Response>');
  }
});

// ─── HTTP: Dial Status — when agent doesn't answer, play hold music and retry ─────

app.post('/dial-status', async (req, reply) => {
  try {
    const dialStatus = req.body?.DialCallStatus || req.body?.DialStatus || '';
    const callSid = req.body?.CallSid || '';
    const dialCallSid = req.body?.DialCallSid || '';
    const dialDuration = req.body?.DialCallDuration || '0';
    console.log(`[voice] Dial status: ${callSid} | status=${dialStatus || '(retry)'} | child=${dialCallSid} | dur=${dialDuration}s`);

    if (dialStatus === 'completed' || dialStatus === 'answered') {
        // Agent answered and call completed — finalize
        finalizeCall(callSid, 'completed', 'Atendido por agente');
        reply.type('text/xml').send(`<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>`);
        return;
    }

    // No answer / busy / failed / no agents — smart retry
    const agents = await getAvailableAgents();
    const callerId = process.env.TWILIO_PHONE || '+15705299780';
    const dialStatusUrl = `https://${WS_HOST}/voice/dial-status`;

    if (agents.length === 0) {
        // Still no agents — hold music then retry (music ~3min acts as natural delay)
        console.log(`[voice] No agents for retry ${callSid} — holding`);
        reply.type('text/xml').send(`<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say language="pt-BR" voice="Polly.Camila">Só mais um pouquinho, tá? Já já alguém te atende.</Say>
    <Say language="pt-BR" voice="Polly.Camila">Sua chamada é importante pra gente. Aguarde só mais um pouquinho.</Say>
    <Pause length="20"/>
    <Redirect method="POST">${escXml(dialStatusUrl)}</Redirect>
</Response>`);
        return;
    }

    const clientStatusUrl = `https://${WS_HOST}/voice/client-status`;
    const agentClients = buildClientTags(agents, clientStatusUrl);

    console.log(`[voice] Retry routing: ${callSid} → [${agents.map(a => `${a.display_name}(${a.active_calls})`).join(', ')}]`);
    reply.type('text/xml').send(`<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say language="pt-BR" voice="Polly.Camila">Só mais um pouquinho, tá? Já já alguém te atende.</Say>
    <Say language="pt-BR" voice="Polly.Camila">Sua chamada é importante pra gente. Aguarde só mais um pouquinho.</Say>
    <Pause length="20"/>
    <Dial timeout="45" callerId="${callerId}" action="${escXml(dialStatusUrl)}" method="POST">
        ${agentClients}
    </Dial>
</Response>`);
  } catch (err) {
    console.error(`[voice] dial-status FATAL:`, err.message);
    reply.type('text/xml').send('<?xml version="1.0" encoding="UTF-8"?><Response><Say language="pt-BR" voice="Polly.Camila">Desculpa, tivemos um probleminha. Tente ligar de novo.</Say><Hangup/></Response>');
  }
});

// ─── HTTP: Telnyx Webhook — Call Control events ─────────────

app.post('/telnyx-webhook', async (req, reply) => {
    try {
        const event = req.body?.data || req.body;
        const eventType = event?.event_type || event?.record_type || '';
        const payload = event?.payload || event || {};
        const callControlId = payload?.call_control_id || payload?.call_leg_id || '';
        const callerPhone = payload?.from || '';
        const callSid = callControlId; // Telnyx uses call_control_id as the call identifier

        console.log(`[voice] Telnyx webhook: ${eventType} | callControlId=${callControlId} | from=${callerPhone}`);

        switch (eventType) {
            case 'call.initiated': {
                // Inbound call initiated — answer it
                if (payload.direction === 'incoming') {
                    console.log(`[voice] Telnyx incoming call from ${callerPhone} | answering...`);
                    await telnyxAPI('POST', `/calls/${callControlId}/actions/answer`, {
                        webhook_url: `https://${WS_HOST}/voice/telnyx-webhook`,
                    });
                }
                break;
            }
            case 'call.answered': {
                // Call answered — set up AI conversation via gather+speak loop
                console.log(`[voice] Telnyx call answered: ${callControlId} | from=${callerPhone}`);

                // Full customer lookup
                const customerData = await fullCustomerLookup(callerPhone);
                const greeting = buildSmartGreeting(customerData);

                // Create call record
                const customer = customerData?.found ? { customer_id: customerData.customer_id, name: customerData.name } : null;
                const protocolCode = await createCallRecord(callControlId, callerPhone, customer);

                // Initialize call state for Telnyx (similar to WS setup)
                const callState = {
                    callSid: callControlId,
                    streamSid: callControlId,
                    callerPhone,
                    protocolCode,
                    customer: customerData?.found ? {
                        customer_id: customerData.customer_id,
                        name: customerData.name,
                        addresses: customerData.addresses
                    } : null,
                    store: null,
                    items: [],
                    history: [
                        { role: 'assistant', content: [{ type: 'text', text: greeting }] }
                    ],
                    systemPrompt: buildSystemPrompt(callerPhone, customerData) +
                        (protocolCode ? `\n\nPROTOCOLO DESTA LIGAÇÃO: ${protocolCode}` : ''),
                    transferRequested: false,
                    orderSubmitted: false,
                    phoneVerified: !!(customerData?.found),
                    noiseCount: 0,
                    lastAiResponse: greeting,
                    startTime: Date.now(),
                    lastActivityAt: Date.now(),
                    isTelnyx: true,
                };

                activeCalls.set(callControlId, callState);

                // Speak the greeting
                await telnyxAPI('POST', `/calls/${callControlId}/actions/speak`, {
                    payload: greeting,
                    language: 'pt-BR',
                    voice: 'female',
                });

                // Start recording
                startCallRecording(callControlId);

                // Start gathering speech input
                await telnyxAPI('POST', `/calls/${callControlId}/actions/gather`, {
                    input: 'speech dtmf',
                    language: 'pt-BR',
                    minimum_input_length: 1,
                    timeout_millis: 10000,
                    inter_digit_timeout_millis: 3000,
                    webhook_url: `https://${WS_HOST}/voice/telnyx-webhook`,
                });
                break;
            }
            case 'call.gather.ended': {
                // Speech or DTMF gathered — process with Claude
                const callState = activeCalls.get(callControlId);
                if (!callState) {
                    console.log(`[voice] Telnyx gather for unknown call: ${callControlId}`);
                    break;
                }

                callState.lastActivityAt = Date.now();
                const userText = payload?.speech?.text || '';
                const digit = payload?.dtmf?.digit || '';

                if (digit === '0') {
                    // Transfer to agent
                    callState.transferRequested = true;
                    callState.transferReason = 'DTMF 0 — pediu atendente';
                    await telnyxAPI('POST', `/calls/${callControlId}/actions/speak`, {
                        payload: 'Claro! Vou te transferir pra um atendente agora. Só um momentinho, tá?',
                        language: 'pt-BR',
                        voice: 'female',
                    });
                    transferCall(callControlId);
                    finalizeCall(callControlId, 'transferred', 'DTMF 0 — pediu atendente');
                    break;
                }

                const input = digit ? `Digitou ${digit}` : userText;
                if (!input || input.trim().length === 0) {
                    // No input — re-gather
                    await telnyxAPI('POST', `/calls/${callControlId}/actions/gather`, {
                        input: 'speech dtmf',
                        language: 'pt-BR',
                        timeout_millis: 10000,
                        webhook_url: `https://${WS_HOST}/voice/telnyx-webhook`,
                    });
                    break;
                }

                console.log(`[voice] ${callControlId} Telnyx User: "${input}"`);
                callState.history.push({ role: 'user', content: input });

                try {
                    const aiResponse = await Promise.race([
                        getClaudeResponse(callState),
                        new Promise((_, reject) => setTimeout(() => reject(new Error('Claude timeout (20s)')), 20000))
                    ]);

                    console.log(`[voice] ${callControlId} Telnyx AI: "${aiResponse.slice(0, 100)}"`);
                    callState.lastAiResponse = aiResponse.slice(0, 250);

                    // Speak AI response
                    await telnyxAPI('POST', `/calls/${callControlId}/actions/speak`, {
                        payload: aiResponse,
                        language: 'pt-BR',
                        voice: 'female',
                    });

                    if (callState.transferRequested) {
                        setTimeout(() => transferCall(callControlId), 3000);
                        finalizeCall(callControlId, 'transferred', `Transferido: ${callState.transferReason}`);
                        break;
                    }
                } catch (err) {
                    console.error(`[voice] ${callControlId} Telnyx AI error:`, err.message);
                    await telnyxAPI('POST', `/calls/${callControlId}/actions/speak`, {
                        payload: 'Desculpa, deu um probleminha aqui. Pode repetir?',
                        language: 'pt-BR',
                        voice: 'female',
                    });
                }

                // Re-gather for next input
                await telnyxAPI('POST', `/calls/${callControlId}/actions/gather`, {
                    input: 'speech dtmf',
                    language: 'pt-BR',
                    timeout_millis: 10000,
                    webhook_url: `https://${WS_HOST}/voice/telnyx-webhook`,
                });
                break;
            }
            case 'call.speak.ended': {
                // TTS finished — nothing to do, gather is already running
                break;
            }
            case 'call.hangup': {
                // Call ended
                const callState = activeCalls.get(callControlId);
                if (callState) {
                    const duration = Math.round((Date.now() - callState.startTime) / 1000);
                    console.log(`[voice] Telnyx call ended: ${callControlId} | ${duration}s`);
                    if (callState.history?.length > 0) {
                        saveTranscript(callControlId, callState.history, callState);
                    }
                    if (!callState.transferRequested) {
                        const summary = callState.orderSubmitted
                            ? `Pedido realizado via IA (${callState.items.length} itens)`
                            : `Conversa IA sem pedido (${callState.history.length} turnos)`;
                        finalizeCall(callControlId, 'completed', summary);
                    }
                    activeCalls.delete(callControlId);
                }
                break;
            }
            case 'call.recording.saved': {
                // Recording ready
                const recordingUrl = payload?.recording_urls?.mp3 || payload?.public_recording_urls?.mp3 || '';
                const recordingDuration = parseInt(payload?.recording_duration_secs || '0');
                if (recordingUrl) {
                    try {
                        await dbQuery(
                            `UPDATE om_callcenter_calls SET recording_url = $2, recording_duration = $3
                             WHERE twilio_call_sid = $1`,
                            [callControlId, recordingUrl, recordingDuration]
                        );
                        console.log(`[voice] Telnyx recording saved: ${callControlId} | ${recordingDuration}s`);
                    } catch (e) {
                        console.error('[voice] Telnyx recording save failed:', e.message);
                    }
                }
                break;
            }
            default:
                console.log(`[voice] Telnyx unhandled event: ${eventType}`);
        }

        reply.send({ ok: true });
    } catch (err) {
        console.error(`[voice] Telnyx webhook FATAL:`, err.message);
        reply.send({ ok: true }); // Always 200 to prevent retries
    }
});

// ─── HTTP: Incoming Call → TwiML with ConversationRelay (Twilio) ─────
// For Telnyx, incoming calls are handled via /telnyx-webhook above.

app.post('/incoming-call', async (req, reply) => {
  try {
    const callerPhone = req.body?.From || '';
    const callSid = req.body?.CallSid || '';

    console.log(`[voice] Incoming call from ${callerPhone} | CallSid: ${callSid}`);

    // Full customer lookup — name, addresses, recent orders, VIP status, active order
    const customerData = await fullCustomerLookup(callerPhone);

    // Smart greeting based on customer history
    const greeting = buildSmartGreeting(customerData);

    // Create call record with protocol code
    const customer = customerData?.found ? { customer_id: customerData.customer_id, name: customerData.name } : null;
    const protocolCode = await createCallRecord(callSid, callerPhone, customer);

    // Pass full customer data + protocol via URL params (JSON-encoded for the WS handler)
    const wsParams = new URLSearchParams({
        phone: callerPhone,
        cd: JSON.stringify(customerData || { found: false }),
        protocol: protocolCode || ''
    });
    const wsUrl = `wss://${WS_HOST}/voice/ws?${wsParams}`;

    const actionUrl = `https://${WS_HOST}/voice/connect-action`;
    const twiml = `<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Connect action="${escXml(actionUrl)}">
        <ConversationRelay
            url="${escXml(wsUrl)}"
            welcomeGreeting="${escXml(greeting)}"
            language="pt-BR"
            ttsProvider="ElevenLabs"
            voice="${ELEVENLABS_VOICE_ID}"
            transcriptionProvider="google"
            speechModel="telephony"
            interruptible="true"
            dtmfDetection="true"
            profanityFilter="false"
        />
    </Connect>
</Response>`;

    reply.type('text/xml').send(twiml);

    // Start recording the entire call (fire-and-forget, covers AI + agent portions)
    startCallRecording(callSid);
  } catch (err) {
    console.error(`[voice] incoming-call FATAL:`, err.message);
    reply.type('text/xml').send('<?xml version="1.0" encoding="UTF-8"?><Response><Say language="pt-BR" voice="Polly.Camila">Desculpa, estamos com um probleminha técnico. Tente ligar de novo em instantes.</Say><Hangup/></Response>');
  }
});

// ─── WebSocket: ConversationRelay Handler ───────────────────

app.register(async (fastify) => {
    fastify.get('/ws', { websocket: true }, (socket, req) => {
        let callState = null;

        socket.on('message', async (rawData) => {
            let msg;
            try {
                msg = JSON.parse(rawData.toString());
            } catch {
                return;
            }

            switch (msg.type) {
                // ── Call connected ──
                case 'setup': {
                    const params = new URL(req.url, 'http://localhost').searchParams;
                    const callerPhone = params.get('phone') || params.get('from') || msg.from || '';

                    // Parse full customer data from URL
                    let customerData = { found: false };
                    try {
                        customerData = JSON.parse(params.get('cd') || '{}');
                    } catch { /* ignore parse errors */ }

                    const protocolCode = params.get('protocol') || '';

                    // Build the greeting text that ConversationRelay already spoke
                    const spokenGreeting = buildSmartGreeting(customerData);

                    callState = {
                        callSid: msg.callSid,
                        streamSid: msg.streamSid,
                        callerPhone,
                        protocolCode,
                        customer: customerData?.found ? {
                            customer_id: customerData.customer_id,
                            name: customerData.name,
                            addresses: customerData.addresses
                        } : null,
                        store: null,
                        items: [],
                        // Seed history with the greeting so Claude knows it already spoke
                        history: [
                            { role: 'assistant', content: [{ type: 'text', text: spokenGreeting }] }
                        ],
                        systemPrompt: buildSystemPrompt(callerPhone, customerData) +
                            (protocolCode ? `\n\nPROTOCOLO DESTA LIGAÇÃO: ${protocolCode}` : ''),
                        transferRequested: false,
                        orderSubmitted: false,
                        phoneVerified: !!(customerData?.found),  // Auto-verify if customer identified by caller ID
                        noiseCount: 0,
                        lastAiResponse: spokenGreeting,
                        startTime: Date.now()
                    };

                    activeCalls.set(msg.callSid, callState);
                    console.log(`[voice] DEBUG phoneVerified=${callState.phoneVerified} cdFound=${customerData?.found}`);
                    console.log(`[voice] Call setup: ${msg.callSid} | ${callerPhone} | ${customerData?.name || 'new customer'} | Protocol: ${protocolCode} | phoneVerified: ${callState.phoneVerified} | cd.found: ${customerData?.found}`);

                    // Inactivity timeout: if no input for 2 minutes, warn; 3 minutes, hang up
                    callState.lastActivityAt = Date.now();
                    callState.inactivityTimer = setInterval(() => {
                        const idleMs = Date.now() - callState.lastActivityAt;
                        if (idleMs > 180000) { // 3 min — hang up
                            console.log(`[voice] ${msg.callSid} Inactivity timeout (3min) — ending call`);
                            try {
                                if (socket.readyState === 1) {
                                    socket.send(JSON.stringify({ type: 'text', token: 'Como não recebi resposta, vou encerrar a ligação. Se precisar, ligue de volta! Tenha um ótimo dia!', last: true }));
                                    setTimeout(() => {
                                        try { socket.send(JSON.stringify({ type: 'end', handoffData: '{}' })); } catch {}
                                    }, 4000);
                                }
                            } catch {}
                            clearInterval(callState.inactivityTimer);
                        } else if (idleMs > 120000 && !callState.inactivityWarned) { // 2 min — warn
                            callState.inactivityWarned = true;
                            try {
                                if (socket.readyState === 1) {
                                    socket.send(JSON.stringify({ type: 'text', token: 'Oi, ainda está aí? Posso te ajudar com mais alguma coisa?', last: true }));
                                }
                            } catch {}
                        }
                    }, 30000); // check every 30s

                    break;
                }

                // ── Caller spoke (transcribed text) ──
                case 'prompt': {
                    if (!callState) {
                        console.log('[voice] Prompt received but no callState — ignoring');
                        return;
                    }

                    const userText = (msg.voicePrompt || '').trim();
                    if (!userText) {
                        console.log(`[voice] ${callState.callSid} Empty prompt — ignoring`);
                        return;
                    }

                    // ── Noise filter: reject garbage transcriptions ──
                    // Single characters, very short noise, or common STT artifacts
                    const cleaned = userText.replace(/[^a-záàâãéèêíïóôõúüçñ\s]/gi, '').trim();
                    const wordCount = cleaned.split(/\s+/).filter(w => w.length > 1).length;
                    const isNoise = (
                        userText.length <= 2 ||                    // "a", "ã", "hm"
                        (userText.length <= 4 && wordCount === 0) || // random short noise
                        /^[hmúãah]+$/i.test(cleaned) ||            // "hm", "ã", "ah", "uh"
                        /^(ok|tá|tô|é|aí|oi|eh|ah|hã|hum)$/i.test(cleaned)  // filler words alone
                    );

                    if (isNoise) {
                        // Track consecutive noise to avoid spamming "didn't catch that"
                        callState.noiseCount = (callState.noiseCount || 0) + 1;
                        console.log(`[voice] ${callState.callSid} Noise filtered: "${userText}" (count: ${callState.noiseCount})`);

                        // After 2+ consecutive noise inputs, OR if the last AI response
                        // ended with a question (Claude asked something and expects an answer),
                        // send a gentle nudge instead of silence
                        const lastResponse = (callState.lastAiResponse || '').trim();
                        const aiAskedQuestion = lastResponse.endsWith('?');

                        if (callState.noiseCount >= 2 || aiAskedQuestion) {
                            const nudge = aiAskedQuestion
                                ? 'Desculpa, não consegui ouvir. Pode repetir, por favor?'
                                : 'Estou aqui! Pode falar.';
                            try {
                                if (socket.readyState === 1) {
                                    socket.send(JSON.stringify({ type: 'text', token: nudge, last: true }));
                                    callState.lastAiResponse = nudge;
                                    console.log(`[voice] ${callState.callSid} Noise nudge sent: "${nudge}"`);
                                }
                            } catch (e) {
                                console.error(`[voice] ${callState.callSid} Nudge send error:`, e.message);
                            }
                            callState.noiseCount = 0; // reset after nudge
                        }
                        return;
                    }

                    // Reset noise counter and inactivity timer on valid input
                    callState.noiseCount = 0;
                    callState.lastActivityAt = Date.now();
                    callState.inactivityWarned = false;

                    console.log(`[voice] ${callState.callSid} User: "${userText}"`);

                    // Inject turn context so Claude knows what happened last
                    // Add user message to history
                    callState.history.push({ role: 'user', content: userText });

                    // Helper to safely send to socket
                    const safeSend = (text) => {
                        try {
                            if (socket.readyState === 1) { // WebSocket.OPEN
                                socket.send(JSON.stringify({ type: 'text', token: text, last: true }));
                                return true;
                            }
                            console.log(`[voice] ${callState.callSid} Socket not open (state=${socket.readyState}), cannot send`);
                            return false;
                        } catch (e) {
                            console.error(`[voice] ${callState.callSid} Send error:`, e.message);
                            return false;
                        }
                    };

                    try {
                        // Timeout protection: 20s max for entire Claude response cycle
                        const timeoutPromise = new Promise((_, reject) =>
                            setTimeout(() => reject(new Error('Claude timeout (20s)')), 20000)
                        );
                        const aiResponse = await Promise.race([
                            getClaudeResponse(callState),
                            timeoutPromise
                        ]);

                        console.log(`[voice] ${callState.callSid} AI: "${aiResponse.slice(0, 100)}"`);

                        // Track last AI response for turn context
                        callState.lastAiResponse = aiResponse.slice(0, 250);

                        safeSend(aiResponse);

                        // Handle transfer after response — use ConversationRelay end-session
                        if (callState.transferRequested) {
                            setTimeout(() => {
                                try {
                                    if (socket.readyState === 1) {
                                        socket.send(JSON.stringify({
                                            type: 'end',
                                            handoffData: JSON.stringify({
                                                reasonCode: 'live-agent-handoff',
                                                reason: callState.transferReason || 'Pediu atendente'
                                            })
                                        }));
                                        console.log(`[voice] ${callState.callSid} Sent end-session for agent handoff`);
                                    } else {
                                        console.log(`[voice] ${callState.callSid} Socket closed, falling back to REST API transfer`);
                                        transferCall(callState.callSid);
                                    }
                                } catch (e) {
                                    console.error(`[voice] ${callState.callSid} End-session failed, falling back:`, e.message);
                                    transferCall(callState.callSid);
                                }
                                finalizeCall(callState.callSid, 'transferred', `Transferido: ${callState.transferReason}`);
                            }, 3000);
                        }
                    } catch (err) {
                        console.error(`[voice] ${callState.callSid} Error:`, err.message, err.stack?.split('\n')[1] || '');
                        safeSend('Desculpa, deu um probleminha aqui. Pode repetir o que você precisa?');
                    }
                    break;
                }

                // ── Caller interrupted ──
                case 'interrupt': {
                    if (!callState) return;
                    console.log(`[voice] ${callState.callSid} Interrupted at ${msg.durationUntilInterruptMs}ms`);
                    // ConversationRelay handles stopping audio; next prompt will come naturally
                    break;
                }

                // ── DTMF digit pressed ──
                case 'dtmf': {
                    if (!callState) return;
                    const digit = msg.digit;
                    console.log(`[voice] ${callState.callSid} DTMF: ${digit}`);

                    if (digit === '0') {
                        // Transfer to agent via ConversationRelay end-session
                        callState.transferRequested = true;
                        callState.transferReason = 'DTMF 0 — pediu atendente';
                        socket.send(JSON.stringify({
                            type: 'text',
                            token: 'Claro! Vou te transferir pra um atendente agora. Só um momentinho, tá?',
                            last: true
                        }));
                        setTimeout(() => {
                            // End ConversationRelay session — Twilio calls the action URL with handoff data
                            socket.send(JSON.stringify({
                                type: 'end',
                                handoffData: JSON.stringify({
                                    reasonCode: 'live-agent-handoff',
                                    reason: 'DTMF 0 — pediu atendente'
                                })
                            }));
                            finalizeCall(callState.callSid, 'transferred', 'DTMF 0 — pediu atendente');
                        }, 3000);
                    } else if (digit === '1') {
                        // Fazer pedido
                        callState.history.push({ role: 'user', content: 'Quero fazer um pedido' });
                        try {
                            const resp = await getClaudeResponse(callState);
                            socket.send(JSON.stringify({ type: 'text', token: resp, last: true }));
                        } catch {
                            socket.send(JSON.stringify({ type: 'text', token: 'Beleza! De qual loja ou restaurante você quer pedir?', last: true }));
                        }
                    } else if (digit === '2') {
                        // Ajuda com pedido
                        callState.history.push({ role: 'user', content: 'Preciso de ajuda com meu pedido' });
                        try {
                            const resp = await getClaudeResponse(callState);
                            socket.send(JSON.stringify({ type: 'text', token: resp, last: true }));
                        } catch {
                            socket.send(JSON.stringify({ type: 'text', token: 'Claro! Me fala o número do pedido ou o que aconteceu que eu te ajudo.', last: true }));
                        }
                    } else {
                        // Other digits — treat as text input
                        callState.history.push({ role: 'user', content: `Digitou ${digit}` });
                        try {
                            const resp = await getClaudeResponse(callState);
                            socket.send(JSON.stringify({ type: 'text', token: resp, last: true }));
                        } catch {
                            socket.send(JSON.stringify({ type: 'text', token: 'Pode falar, estou ouvindo!', last: true }));
                        }
                    }
                    break;
                }

                default:
                    console.log(`[voice] Unknown message type: ${msg.type}`);
            }
        });

        socket.on('close', () => {
            if (callState) {
                // Clear inactivity timer
                if (callState.inactivityTimer) clearInterval(callState.inactivityTimer);

                const duration = Math.round((Date.now() - callState.startTime) / 1000);
                console.log(`[voice] Call ended: ${callState.callSid} | ${duration}s | items: ${callState.items?.length || 0} | turns: ${callState.history?.length || 0}`);

                // Save full conversation transcript
                if (callState.history?.length > 0) {
                    saveTranscript(callState.callSid, callState.history, callState);
                }

                if (!callState.transferRequested) {
                    const summary = callState.orderSubmitted
                        ? `Pedido realizado via IA (${callState.items.length} itens)`
                        : `Conversa IA sem pedido (${callState.history.length} turnos)`;
                    finalizeCall(callState.callSid, 'completed', summary);
                }

                activeCalls.delete(callState.callSid);
            }
        });

        socket.on('error', (err) => {
            console.error('[voice] WebSocket error:', err.message);
        });
    });
});

// ─── Start Server ───────────────────────────────────────────

app.listen({ port: PORT, host: '0.0.0.0' }, (err, address) => {
    if (err) {
        console.error('[voice] Failed to start:', err);
        process.exit(1);
    }
    console.log(`[voice] SuperBora Voice Server running on ${address}`);
    console.log(`[voice] Provider: ${VOICE_PROVIDER.toUpperCase()}`);
    if (IS_TELNYX) {
        console.log(`[voice] Telnyx webhook: ${address}/telnyx-webhook`);
        console.log(`[voice] Telnyx phone BR: ${TELNYX_PHONE} | US: ${TELNYX_PHONE_US}`);
    } else {
        console.log(`[voice] ConversationRelay WS: wss://${WS_HOST}/voice/ws`);
        console.log(`[voice] Incoming call webhook: ${address}/incoming-call`);
    }
});
