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

// ─── Database ───────────────────────────────────────────────
const pool = new pg.Pool({
    host: process.env.DB_HOST || '127.0.0.1',
    port: parseInt(process.env.DB_PORT || '6432'),
    user: process.env.DB_USER || 'love1',
    password: process.env.DB_PASS || process.env.DB_PASSWORD,
    database: process.env.DB_NAME || process.env.DB_DATABASE || 'love1',
    max: 5,
    idleTimeoutMillis: 10000,
    connectionTimeoutMillis: 5000,
    allowExitOnIdle: true,
});

pool.on('error', (err) => console.error('[voice] Pool error:', err.message));

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
        customerContext = `CLIENTE IDENTIFICADO:
- Nome: ${customerData.name}
- ID: ${customerData.customer_id}
${addrStr ? `- Endereço principal: ${addrStr}` : '- Sem endereço salvo'}
${customerData.addresses?.length > 1 ? `- Total de endereços salvos: ${customerData.addresses.length}` : ''}
${recentOrders ? `- Pedidos recentes: ${recentOrders}` : '- Primeiro pedido!'}
${customerData.cashback > 0 ? `- Cashback disponível: R$${customerData.cashback.toFixed(2)}` : ''}

Você JÁ sabe quem é o cliente. NÃO precisa pedir telefone ou usar lookup_customer.`;
    } else {
        customerContext = `CLIENTE NOVO (telefone ${callerPhone} não encontrado no cadastro).
NÃO use lookup_customer — já foi verificado e o cliente não está cadastrado.
NÃO diga "não encontrei seu telefone" ou "não está cadastrado". Isso é rude.
Seja natural e acolhedora. Pergunte o nome com carinho e depois a cidade ou CEP pra entrega.
Exemplo de primeira resposta: "Claro! Me diz seu nome pra eu te ajudar melhor?"`;
    }

    return `Você é a Bora, assistente virtual do SuperBora — um app de delivery de comida como iFood.
Você está atendendo uma ligação telefônica. Seja simpática, natural e eficiente como uma atendente humana.

PERSONALIDADE:
- Fale como uma pessoa real — calorosa, simpática, brasileira
- Não seja robótica. Varie suas respostas. Use expressões naturais como "beleza", "ótimo", "perfeito", "pode deixar"
- Se o cliente falar algo engraçado, dê risada ("haha")
- Demonstre entusiasmo com os produtos: "esse é muito bom!", "ótima escolha!"

REGRAS TÉCNICAS:
- NUNCA use emojis, bullets, asteriscos, ou formatação — sua fala vira áudio
- Fale números por extenso: "doze reais e cinquenta" não "R$12,50"
- Respostas CURTAS — máximo 2-3 frases por vez. É conversa por telefone, não texto
- NUNCA invente preços, produtos ou lojas — use as ferramentas
- "Quero pizza" é TIPO DE COMIDA, não nome de restaurante

${customerContext}

FLUXO PARA PEDIDO:
1. Se o cliente quer pedir algo:
   - Cliente conhecido COM endereço: Pergunte "Entrega no endereço X?" → busque lojas na cidade
   - Cliente conhecido SEM endereço: Pergunte a cidade ou CEP pra entrega
   - Cliente novo: Pergunte nome → pergunte cidade ou CEP pra entrega
2. Se informar CEP: use lookup_cep para descobrir cidade e bairro automaticamente
3. Use get_nearby_stores com a cidade. Se mencionou tipo de comida, filtre por food_type
4. Apresente 2-3 lojas abertas de forma natural: "Tem a Pizzaria Napoli que é muito boa, tem o Burger House..."
5. Se o cliente pedir por NOME de loja: use search_stores. Se encontrar a mesma loja em cidades diferentes, pergunte qual.
6. Quando escolher loja: use get_store_menu → ofereça itens populares
7. Monte o pedido com add_to_order, confirme cada item
8. No final: confirme tudo, peça pagamento, use submit_order

SOBRE CEP:
- O cliente pode falar o CEP por extenso (ex: "três cinco zero um cinco dois dois zero") — reconheça e use lookup_cep
- Se der erro no CEP, peça o endereço normal: cidade e bairro

SOBRE LOJAS COM MESMO NOME:
- Se search_stores retornar multiple_cities=true, pergunte "Encontrei esse restaurante em duas cidades. Qual seria?"
- Sempre use a cidade do endereço de entrega do cliente no filtro quando possível

FLUXO PARA STATUS: use check_order_status com o customer_id
DÚVIDA/PROBLEMA: responda se souber, senão use transfer_to_agent
ATENDENTE: se pedir humano, use transfer_to_agent IMEDIATAMENTE

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
        description: 'Finaliza e envia o pedido. SÓ usar depois que o cliente CONFIRMAR tudo. Envia SMS de confirmação.',
        input_schema: {
            type: 'object',
            properties: {
                address_id: { type: 'integer', description: 'ID do endereço de entrega (dos endereços salvos)' },
                payment_method: { type: 'string', enum: ['pix', 'cartao', 'dinheiro'], description: 'Forma de pagamento' },
                change_for: { type: 'number', description: 'Troco para quanto (só pra dinheiro)' }
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
                    callState.store = { partner_id: input.partner_id, name: menu.store_name, delivery_fee: menu.delivery_fee };
                }
                return menu;
            }
            case 'add_to_order': return addToOrder(callState, input);
            case 'remove_from_order': return removeFromOrder(callState, input.index);
            case 'get_order_summary': return getOrderSummary(callState);
            case 'submit_order': return await submitOrder(callState, input);
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
    let query = `SELECT partner_id, name, city, neighborhood, categoria,
                        rating, delivery_time_min, delivery_fee, min_order_value, is_open
                 FROM om_market_partners
                 WHERE status = '1'
                   AND (name ILIKE $1 OR nome ILIKE $1)`;
    const params = ['%' + name + '%'];

    if (city) {
        query += ` AND city ILIKE $2`;
        params.push('%' + city + '%');
    }

    query += ` ORDER BY is_open DESC, rating DESC NULLS LAST LIMIT 10`;

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
            is_open: s.is_open === 1,
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
                        description
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

    query += ` ORDER BY is_open DESC, rating DESC NULLS LAST LIMIT 15`;

    const result = await dbQuery(query, params);
    return {
        city,
        stores: result.rows.map(s => ({
            partner_id: s.partner_id,
            name: s.name,
            tipo: s.categoria,
            neighborhood: s.neighborhood,
            is_open: s.is_open === 1,
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
        `SELECT name, delivery_fee, delivery_time_min, min_order_value, is_open
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

    // Group by category, limit to available items
    const menu = {};
    for (const p of prodResult.rows) {
        const cat = p.category_name || 'Outros';
        if (!menu[cat]) menu[cat] = [];
        if (p.available === 1 || p.available === null) {
            menu[cat].push({
                product_id: p.product_id,
                name: p.name,
                description: p.description ? p.description.slice(0, 80) : null,
                price: parseFloat(p.price)
            });
        }
    }

    return {
        store_name: store.name,
        delivery_fee: parseFloat(store.delivery_fee || 0),
        delivery_time: store.delivery_time_min,
        min_order_value: parseFloat(store.min_order_value || 0),
        is_open: store.is_open === '1' || store.is_open === true,
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
    return {
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
        total: subtotal + deliveryFee
    };
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

    const client = await pool.connect();
    try {
        await client.query('BEGIN');

        const subtotal = callState.items.reduce((s, i) => s + i.price * i.quantity, 0);
        const deliveryFee = callState.store.delivery_fee || 0;
        const serviceFee = Math.round(subtotal * 0.05 * 100) / 100;
        const total = subtotal + deliveryFee + serviceFee;

        // Generate order number
        const orderNumber = 'SB' + Date.now().toString(36).toUpperCase();

        // Fetch address by ID, or use first saved address
        let addr = {};
        if (input.address_id) {
            const addrResult = await client.query(
                `SELECT street, number, complement, neighborhood, city, state, zipcode
                 FROM om_customer_addresses WHERE address_id = $1`,
                [input.address_id]
            );
            if (addrResult.rows.length > 0) addr = addrResult.rows[0];
        } else if (callState.customer.addresses?.length > 0) {
            addr = callState.customer.addresses[0];
        }

        const orderResult = await client.query(
            `INSERT INTO om_market_orders (
                customer_id, partner_id, order_number, status,
                customer_name, customer_phone,
                subtotal, delivery_fee, service_fee, total,
                payment_method, change_for,
                delivery_address, shipping_address, shipping_number, shipping_complement,
                shipping_neighborhood, shipping_city, shipping_state, shipping_cep,
                source, notes, date_added
            ) VALUES ($1, $2, $3, 'pending', $4, $5, $6, $7, $8, $9, $10, $11,
                      $12, $13, $14, $15, $16, $17, $18, $19,
                      'voice_ai', $20, NOW())
            RETURNING order_id, order_number`,
            [
                callState.customer.customer_id,
                callState.store.partner_id,
                orderNumber,
                callState.customer.name || '',
                callState.callerPhone,
                subtotal, deliveryFee, serviceFee, total,
                input.payment_method || 'dinheiro',
                input.change_for || null,
                addr.street ? `${addr.street}, ${addr.number || 'S/N'} - ${addr.neighborhood || ''}, ${addr.city || ''}` : '',
                addr.street || '', addr.number || '', addr.complement || '',
                addr.neighborhood || '', addr.city || '', addr.state || 'SP', addr.zipcode || '',
                'Pedido feito por telefone via IA'
            ]
        );

        const { order_id } = orderResult.rows[0];

        for (const item of callState.items) {
            await client.query(
                `INSERT INTO om_market_order_products (
                    order_id, product_id, name, price, quantity, total, notes
                ) VALUES ($1, $2, $3, $4, $5, $6, $7)`,
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

        // Send SMS confirmation (fire and forget via PHP)
        sendOrderSMS(callState.callerPhone, orderNumber, callState.store.name, callState.items, total);

        callState.orderSubmitted = true;

        return {
            success: true,
            order_number: orderNumber,
            total,
            message: `Pedido ${orderNumber} criado! Total: R$${total.toFixed(2)}. SMS de confirmação enviado.`
        };
    } catch (err) {
        await client.query('ROLLBACK').catch(() => {});
        console.error('[voice] Order submission failed:', err);
        return { success: false, error: 'Erro ao criar pedido: ' + err.message };
    } finally {
        client.release();
    }
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

// ─── Claude Conversation Engine ─────────────────────────────

async function getClaudeResponse(callState) {
    const messages = callState.history;
    let maxLoops = 6;

    while (maxLoops-- > 0) {
        const response = await anthropic.messages.create({
            model: CLAUDE_MODEL,
            max_tokens: 300,
            system: callState.systemPrompt,
            messages,
            tools: TOOLS,
        });

        const toolBlocks = response.content.filter(b => b.type === 'tool_use');

        // Add assistant response to history
        messages.push({ role: 'assistant', content: response.content });

        if (toolBlocks.length === 0) {
            // No tool calls — return the text
            const text = response.content
                .filter(b => b.type === 'text')
                .map(b => b.text)
                .join(' ')
                .trim();
            // Never return empty response — ask to repeat
            return text || 'Oi, como posso te ajudar?';
        }

        // Execute tools
        const toolResults = [];
        for (const tu of toolBlocks) {
            console.log(`[voice] Tool call: ${tu.name}(${JSON.stringify(tu.input).slice(0, 100)})`);
            const result = await executeTool(tu.name, tu.input, callState);
            toolResults.push({
                type: 'tool_result',
                tool_use_id: tu.id,
                content: JSON.stringify(result)
            });
        }
        messages.push({ role: 'user', content: toolResults });
        // Loop to get Claude's response with tool results
    }

    return 'Desculpa, deu um probleminha. Pode repetir o que você precisa?';
}

// ─── Full Customer Lookup (for greeting + context) ──────────

async function fullCustomerLookup(phone) {
    try {
        const result = await lookupCustomer(phone);
        return result;
    } catch {
        return { found: false };
    }
}

// ─── Call Record Management ─────────────────────────────────

async function createCallRecord(callSid, phone, customer) {
    try {
        await dbQuery(
            `INSERT INTO om_callcenter_calls
             (twilio_call_sid, customer_phone, customer_id, customer_name, direction, status, started_at)
             VALUES ($1, $2, $3, $4, 'inbound', 'ai_handling', NOW())
             ON CONFLICT (twilio_call_sid) DO UPDATE SET status = 'ai_handling'`,
            [callSid, phone, customer?.customer_id || null, customer?.name || null]
        );
    } catch (e) {
        console.error('[voice] Call record insert failed:', e.message);
    }
}

async function finalizeCall(callSid, status, summary) {
    try {
        await dbQuery(
            `UPDATE om_callcenter_calls
             SET status = $2, ai_summary = $3, ended_at = NOW(),
                 duration_seconds = EXTRACT(EPOCH FROM (NOW() - started_at))::int
             WHERE twilio_call_sid = $1`,
            [callSid, status || 'completed', summary || null]
        );
    } catch (e) {
        console.error('[voice] Call finalize failed:', e.message);
    }
}

// ─── Transfer to Agent ──────────────────────────────────────

async function transferCall(callSid) {
    try {
        const url = `https://api.twilio.com/2010-04-01/Accounts/${TWILIO_SID}/Calls/${callSid}.json`;
        const twiml = `<Response>
            <Say language="pt-BR" voice="Polly.Camila">Transferindo para um atendente. Aguarde um momento.</Say>
            <Dial timeout="60" callerId="${process.env.TWILIO_PHONE || '+17432285380'}">
                <Client>agent</Client>
            </Dial>
            <Say language="pt-BR" voice="Polly.Camila">Desculpe, nenhum atendente disponível. Tente novamente mais tarde.</Say>
            <Hangup/>
        </Response>`;

        const auth = Buffer.from(`${TWILIO_SID}:${TWILIO_TOKEN}`).toString('base64');
        await fetch(url, {
            method: 'POST',
            headers: {
                'Authorization': `Basic ${auth}`,
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({ Twiml: twiml })
        });
        console.log(`[voice] Transfer initiated for ${callSid}`);
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

// ─── HTTP: Incoming Call → TwiML with ConversationRelay ─────

app.post('/incoming-call', async (req, reply) => {
    const callerPhone = req.body?.From || '';
    const callSid = req.body?.CallSid || '';

    console.log(`[voice] Incoming call from ${callerPhone} | CallSid: ${callSid}`);

    // Full customer lookup — name, addresses, recent orders
    const customerData = await fullCustomerLookup(callerPhone);
    const firstName = customerData?.found ? customerData.name?.split(' ')[0] : null;

    const hora = new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: 'numeric', hour12: false });
    const horaNum = parseInt(hora);
    const periodo = horaNum < 12 ? 'Bom dia' : horaNum < 18 ? 'Boa tarde' : 'Boa noite';

    const greeting = firstName
        ? `${periodo}, ${firstName}! Aqui é a Bora, do SuperBora. Posso te ajudar a fazer um pedido, ver o status de uma entrega, tirar dúvidas, fazer uma reclamação ou dar sugestões. O que você precisa?`
        : `${periodo}! Aqui é a Bora, assistente virtual do SuperBora. Posso te ajudar a fazer um pedido, ver o status de uma entrega, tirar dúvidas, fazer uma reclamação ou dar sugestões. Como posso te ajudar?`;

    // Create call record
    const customer = customerData?.found ? { customer_id: customerData.customer_id, name: customerData.name } : null;
    createCallRecord(callSid, callerPhone, customer);

    // Pass full customer data via URL params (JSON-encoded for the WS handler)
    const wsParams = new URLSearchParams({
        phone: callerPhone,
        cd: JSON.stringify(customerData || { found: false })
    });
    const wsUrl = `wss://${WS_HOST}/voice/ws?${wsParams}`;

    const twiml = `<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Connect>
        <ConversationRelay
            url="${escXml(wsUrl)}"
            welcomeGreeting="${escXml(greeting)}"
            language="pt-BR"
            ttsProvider="ElevenLabs"
            voice="${ELEVENLABS_VOICE_ID}"
            transcriptionProvider="google"
            interruptible="true"
            dtmfDetection="true"
            profanityFilter="false"
        />
    </Connect>
</Response>`;

    reply.type('text/xml').send(twiml);
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
                    const callerPhone = params.get('phone') || msg.from || '';

                    // Parse full customer data from URL
                    let customerData = { found: false };
                    try {
                        customerData = JSON.parse(params.get('cd') || '{}');
                    } catch { /* ignore parse errors */ }

                    callState = {
                        callSid: msg.callSid,
                        streamSid: msg.streamSid,
                        callerPhone,
                        customer: customerData?.found ? {
                            customer_id: customerData.customer_id,
                            name: customerData.name,
                            addresses: customerData.addresses
                        } : null,
                        store: null,
                        items: [],
                        history: [],
                        systemPrompt: buildSystemPrompt(callerPhone, customerData),
                        transferRequested: false,
                        orderSubmitted: false,
                        startTime: Date.now()
                    };

                    activeCalls.set(msg.callSid, callState);
                    console.log(`[voice] Call setup: ${msg.callSid} | ${callerPhone} | ${customerData?.name || 'new customer'}`);
                    break;
                }

                // ── Caller spoke (transcribed text) ──
                case 'prompt': {
                    if (!callState) return;

                    const userText = msg.voicePrompt || '';
                    if (!userText.trim()) return;

                    console.log(`[voice] ${callState.callSid} User: "${userText}"`);

                    // Add user message to history
                    callState.history.push({ role: 'user', content: userText });

                    try {
                        const aiResponse = await getClaudeResponse(callState);
                        console.log(`[voice] ${callState.callSid} AI: "${aiResponse.slice(0, 100)}..."`);

                        // Send response to ConversationRelay → ElevenLabs → caller
                        socket.send(JSON.stringify({
                            type: 'text',
                            token: aiResponse,
                            last: true
                        }));

                        // Handle transfer after response
                        if (callState.transferRequested) {
                            setTimeout(() => {
                                transferCall(callState.callSid);
                                finalizeCall(callState.callSid, 'transferred', `Transferido: ${callState.transferReason}`);
                            }, 3000); // Wait for goodbye message to play
                        }
                    } catch (err) {
                        console.error(`[voice] ${callState.callSid} Claude error:`, err.message);
                        socket.send(JSON.stringify({
                            type: 'text',
                            token: 'Desculpa, deu um probleminha. Pode repetir?',
                            last: true
                        }));
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
                        // Transfer to agent
                        socket.send(JSON.stringify({
                            type: 'text',
                            token: 'Vou te transferir para um atendente agora. Um momento.',
                            last: true
                        }));
                        setTimeout(() => {
                            transferCall(callState.callSid);
                            finalizeCall(callState.callSid, 'transferred', 'DTMF 0 — pediu atendente');
                        }, 2000);
                    } else {
                        // Treat digit as text input
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
                const duration = Math.round((Date.now() - callState.startTime) / 1000);
                console.log(`[voice] Call ended: ${callState.callSid} | ${duration}s | items: ${callState.items?.length || 0}`);

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
    console.log(`[voice] ConversationRelay WS: wss://${WS_HOST}/voice/ws`);
    console.log(`[voice] Incoming call webhook: ${address}/incoming-call`);
});
