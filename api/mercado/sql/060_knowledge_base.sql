-- 060: Knowledge Base for support agents
-- Run: psql -h 127.0.0.1 -U love1 -d love1 -f 060_knowledge_base.sql

CREATE TABLE IF NOT EXISTS sb_knowledge_base (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'geral',
    tags TEXT[] DEFAULT '{}',
    search_vector TSVECTOR,
    author_id INT,
    is_published BOOLEAN DEFAULT true,
    view_count INT DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_kb_category ON sb_knowledge_base(category);
CREATE INDEX IF NOT EXISTS idx_kb_published ON sb_knowledge_base(is_published);
CREATE INDEX IF NOT EXISTS idx_kb_search ON sb_knowledge_base USING GIN(search_vector);
CREATE INDEX IF NOT EXISTS idx_kb_tags ON sb_knowledge_base USING GIN(tags);
CREATE INDEX IF NOT EXISTS idx_kb_view_count ON sb_knowledge_base(view_count DESC);
CREATE INDEX IF NOT EXISTS idx_kb_created ON sb_knowledge_base(created_at DESC);

-- Trigger to auto-update search_vector on insert/update
CREATE OR REPLACE FUNCTION kb_search_vector_update() RETURNS trigger AS $$
BEGIN
    NEW.search_vector := setweight(to_tsvector('portuguese', COALESCE(NEW.title, '')), 'A')
                      || setweight(to_tsvector('portuguese', COALESCE(NEW.content, '')), 'B')
                      || setweight(to_tsvector('portuguese', COALESCE(array_to_string(NEW.tags, ' '), '')), 'C');
    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS kb_search_vector_trigger ON sb_knowledge_base;
CREATE TRIGGER kb_search_vector_trigger
    BEFORE INSERT OR UPDATE OF title, content, tags ON sb_knowledge_base
    FOR EACH ROW EXECUTE FUNCTION kb_search_vector_update();

-- Seed 10 default articles
INSERT INTO sb_knowledge_base (title, content, category, tags, author_id, is_published) VALUES
(
    'Política de Reembolso',
    E'## Política de Reembolso SuperBora\n\n### Quando o reembolso é aplicável\n\n- **Pedido cancelado pela loja**: reembolso integral automático\n- **Itens faltando**: reembolso proporcional aos itens não entregues\n- **Produto com defeito/vencido**: reembolso integral do item + crédito de desculpas\n- **Pedido não entregue**: reembolso integral após investigação (até 48h)\n- **Atraso superior a 60 minutos**: crédito de R$5 em cashback\n\n### Prazos\n\n- **PIX**: reembolso em até 5 dias úteis\n- **Cartão de crédito**: estorno em 1-2 faturas (depende da operadora)\n- **Cashback/crédito**: aplicado instantaneamente na carteira\n\n### Como processar\n\n1. Abrir o pedido no painel\n2. Clicar em "Reembolsar"\n3. Selecionar itens ou valor total\n4. Escolher método (estorno original ou cashback)\n5. Adicionar motivo obrigatório\n6. Confirmar — o sistema processa automaticamente\n\n### Limites\n\n- Reembolso máximo = valor total do pedido\n- Reclamações devem ser feitas em até 7 dias\n- Mais de 3 reembolsos/mês por cliente: escalar para supervisor',
    'policies',
    ARRAY['reembolso', 'devolução', 'estorno', 'cancelamento', 'pix'],
    NULL,
    true
),
(
    'Como Cancelar um Pedido',
    E'## Cancelamento de Pedidos\n\n### Regras por status\n\n| Status | Cancelamento | Cobrança |\n|--------|-------------|----------|\n| Pendente | Livre | Sem cobrança |\n| Aceito | Até 2 min | Sem cobrança |\n| Preparando | Com taxa 10% | Taxa de preparação |\n| Pronto | Não recomendado | Taxa de 50% |\n| Em entrega | Não permitido | — |\n\n### Procedimento\n\n1. Verificar status atual do pedido\n2. Se "pendente" ou "aceito": cancelar direto no painel\n3. Se "preparando": confirmar com o cliente sobre a taxa\n4. Se "pronto": tentar redirecionar para outro cliente ou oferecer cashback\n5. Registrar motivo do cancelamento\n\n### Motivos comuns\n\n- Cliente desistiu\n- Endereço incorreto/inacessível\n- Loja sem estoque\n- Tempo de espera excessivo\n- Problema no pagamento\n\n### Cancelamento pelo parceiro\n\nSe a loja cancelar, o cliente recebe reembolso integral + R$5 cashback de desculpas.',
    'procedures',
    ARRAY['cancelar', 'cancelamento', 'pedido', 'status', 'taxa'],
    NULL,
    true
),
(
    'Problemas com Pagamento PIX',
    E'## Troubleshooting PIX\n\n### PIX não confirmado\n\n1. Verificar se o cliente realmente pagou (pedir comprovante)\n2. No painel, checar status do PIX em Financeiro > Pagamentos\n3. Se pago mas não confirmado:\n   - Webhook pode ter falhado\n   - Confirmar manualmente: Pedido > Ações > Confirmar Pagamento PIX\n4. Se PIX expirou (15 min), o pedido é cancelado automaticamente\n\n### PIX duplicado\n\n- O sistema impede pagamento duplicado via idempotency key\n- Se o cliente pagou 2x, verificar no Stripe Dashboard\n- Processar estorno do valor extra via Stripe\n\n### PIX estornado pelo banco\n\n- Verificar em Financeiro se houve chargeback\n- Marcar pedido como "contestado"\n- Abrir disputa se necessário\n\n### Erros comuns\n\n- **"QR Code expirado"**: gerar novo PIX (cliente precisa refazer pedido)\n- **"Valor divergente"**: cliente pagou valor diferente, precisa estorno + novo pedido\n- **"Banco indisponível"**: aguardar ou sugerir cartão de crédito',
    'troubleshooting',
    ARRAY['pix', 'pagamento', 'qrcode', 'webhook', 'estorno'],
    NULL,
    true
),
(
    'Entregador Não Encontrou Endereço',
    E'## Entregador com Problema de Endereço\n\n### Procedimento imediato\n\n1. Verificar endereço no pedido — está completo? (número, complemento, referência)\n2. Tentar contato com o cliente via chat do pedido\n3. Se cliente não responde em 5 minutos:\n   - Ligar para o telefone cadastrado\n   - Se não atender: aguardar mais 5 min no local\n4. Após 10 min sem contato: entregador pode marcar "cliente ausente"\n\n### Endereço incompleto\n\n- Pedir ao cliente para atualizar via app\n- Anotar complemento/referência no chat do pedido\n- Repassar informação ao entregador via app\n\n### Condomínio/prédio\n\n- Entregador deve ir até a portaria\n- Se portaria não permite entrada: entregar na portaria\n- Cliente deve estar ciente das regras do condomínio\n\n### Área de risco\n\n- Entregador NÃO é obrigado a entrar em áreas de risco\n- Oferecer ponto de encontro alternativo\n- Se impossível: cancelar com reembolso integral',
    'troubleshooting',
    ARRAY['entrega', 'endereço', 'entregador', 'ausente', 'condomínio'],
    NULL,
    true
),
(
    'Pedido com Itens Faltando',
    E'## Itens Faltando no Pedido\n\n### Verificação\n\n1. Confirmar quais itens estão faltando com o cliente\n2. Verificar no pedido original quais itens foram listados\n3. Checar se houve substituição aprovada pelo cliente\n4. Verificar fotos do pedido (se shopper tirou foto)\n\n### Ações\n\n- **1-2 itens faltando**: reembolso proporcional + cupom de R$3\n- **Mais de 50% faltando**: reembolso integral + cupom de R$10\n- **Item errado (substituição não autorizada)**: reembolso do item + envio do correto se possível\n\n### Processo de reembolso parcial\n\n1. Pedido > Itens > Selecionar faltantes\n2. Clicar "Reembolsar selecionados"\n3. Sistema calcula valor proporcional automaticamente\n4. Cashback aplicado instantaneamente\n\n### Prevenção\n\n- Shoppers devem confirmar lista antes de sair da loja\n- Foto obrigatória da sacola antes da entrega\n- Lojas com alta taxa de itens faltando: notificar gerente do parceiro',
    'procedures',
    ARRAY['itens', 'faltando', 'reembolso', 'parcial', 'shopper'],
    NULL,
    true
),
(
    'Como Trocar Forma de Pagamento',
    E'## Troca de Forma de Pagamento\n\n### Antes do pagamento ser processado\n\nSe o pedido ainda está em "pendente" e pagamento não confirmado:\n1. Cliente pode cancelar o pedido\n2. Refazer com a nova forma de pagamento\n3. Não há cobrança pelo cancelamento\n\n### Após pagamento processado\n\n**Não é possível trocar a forma de pagamento de um pedido já pago.**\n\nAlternativas:\n1. Cancelar + reembolsar + novo pedido\n2. Se cartão recusado: cliente pode tentar outro cartão ou PIX\n\n### Formas de pagamento aceitas\n\n- **Cartão de crédito**: Visa, Mastercard, Elo, American Express (via Stripe)\n- **PIX**: QR Code com validade de 15 minutos\n- **Cashback**: saldo disponível na carteira (pode combinar com cartão/PIX)\n\n### Problemas comuns\n\n- **Cartão recusado**: verificar se tem limite, data de validade, CVV\n- **PIX expirado**: gerar novo pedido (não é possível reativar PIX)\n- **Cashback insuficiente**: complementar com cartão ou PIX',
    'faq',
    ARRAY['pagamento', 'cartão', 'pix', 'trocar', 'forma'],
    NULL,
    true
),
(
    'Horário de Funcionamento das Lojas',
    E'## Horários de Funcionamento\n\n### Como funciona\n\nCada loja parceira define seu próprio horário de funcionamento no painel do parceiro.\n\n### Verificação\n\n1. No painel admin: Parceiros > Selecionar loja > Horários\n2. Cada dia da semana pode ter horário diferente\n3. Lojas podem ter horário de almoço (pausa)\n4. Feriados: loja decide se abre ou fecha\n\n### Regras\n\n- Loja **aberta**: aceita pedidos normalmente\n- Loja **fechada**: não aparece para o cliente (ou aparece como "fechada")\n- Loja **pausada**: não aceita novos pedidos mas finaliza os em andamento\n\n### Horário de corte\n\n- Pedidos feitos até 30 min antes do fechamento são aceitos\n- A loja pode recusar pedidos próximos do fechamento\n\n### Problemas comuns\n\n- **"Loja aparece fechada mas deveria estar aberta"**: verificar horário configurado + fuso horário\n- **"Loja não aparece no app"**: pode estar desativada, não apenas fechada\n- **Feriados**: loja precisa atualizar manualmente ou usar calendário de exceções',
    'faq',
    ARRAY['horário', 'funcionamento', 'loja', 'aberta', 'fechada'],
    NULL,
    true
),
(
    'Taxa de Entrega - Como Funciona',
    E'## Taxa de Entrega\n\n### Cálculo\n\nA taxa de entrega é calculada com base em:\n1. **Distância** entre a loja e o endereço de entrega (principal fator)\n2. **Demanda** no momento (surge pricing em horários de pico)\n3. **Plano do parceiro** (alguns absorvem parte da taxa)\n\n### Faixas padrão\n\n| Distância | Taxa base |\n|-----------|----------|\n| Até 2 km | R$ 4,99 |\n| 2-5 km | R$ 7,99 |\n| 5-8 km | R$ 10,99 |\n| 8-12 km | R$ 14,99 |\n| > 12 km | R$ 17,99+ |\n\n*Valores podem variar por cidade e horário*\n\n### Entrega grátis\n\n- Pedidos acima de R$ 80 em lojas parceiras selecionadas\n- Assinantes SuperBora Plus: frete grátis em pedidos acima de R$ 40\n- Promoções especiais e cupons de frete grátis\n\n### Surge (taxa dinâmica)\n\n- Ativada quando há muitos pedidos e poucos entregadores\n- Multiplicador máximo: 2x a taxa base\n- Duração média: 15-30 minutos\n- Cliente é informado ANTES de confirmar o pedido\n\n### Reclamações sobre taxa\n\n- Verificar se havia surge no momento do pedido\n- Conferir a distância calculada\n- Se erro no cálculo: reembolsar diferença em cashback',
    'faq',
    ARRAY['taxa', 'entrega', 'frete', 'delivery', 'surge', 'distância'],
    NULL,
    true
),
(
    'Cashback - Regras e Prazos',
    E'## Sistema de Cashback\n\n### Como funciona\n\n- Cliente recebe % do valor do pedido de volta como crédito\n- Cashback fica disponível na "carteira" do app\n- Pode ser usado como forma de pagamento parcial ou total\n\n### Regras\n\n- **Cashback padrão**: 2% do valor do pedido (configurável por parceiro)\n- **Cashback promocional**: até 20% em campanhas especiais\n- **Validade**: 90 dias após crédito\n- **Mínimo para uso**: R$ 1,00\n- **Crédito**: disponível após entrega confirmada (não no ato do pedido)\n\n### Cashback não aparece\n\n1. Verificar se o pedido foi entregue e confirmado\n2. Cashback só é creditado APÓS confirmação de entrega\n3. Pedidos cancelados/reembolsados não geram cashback\n4. Verificar se o cashback não expirou (90 dias)\n\n### Cashback de reembolso\n\n- Quando reembolso é feito em cashback, crédito é instantâneo\n- Validade: 90 dias a partir do crédito\n- Aparece como "Crédito de reembolso" na carteira\n\n### Saldo negativo\n\n- Não existe saldo negativo\n- Se pedido com cashback é cancelado: débito do cashback usado\n- Se saldo insuficiente para débito: saldo vai a zero',
    'policies',
    ARRAY['cashback', 'crédito', 'carteira', 'saldo', 'validade'],
    NULL,
    true
),
(
    'Como Funciona o SuperBora Plus',
    E'## SuperBora Plus — Assinatura Premium\n\n### Benefícios\n\n- **Frete grátis** em pedidos acima de R$ 40\n- **Cashback dobrado** (4% ao invés de 2%)\n- **Prioridade na fila** de preparação\n- **Suporte prioritário** (atendimento em até 2 minutos)\n- **Ofertas exclusivas** para assinantes\n- **Sem taxa de serviço** em pedidos\n\n### Preços\n\n- **Mensal**: R$ 19,90/mês\n- **Anual**: R$ 149,90/ano (R$ 12,49/mês)\n- **Trial**: 14 dias grátis na primeira assinatura\n\n### Cancelamento\n\n- Cliente pode cancelar a qualquer momento pelo app\n- Benefícios continuam até o fim do período pago\n- Sem multa de cancelamento\n- Reembolso proporcional apenas nos primeiros 7 dias\n\n### Problemas comuns\n\n- **"Meu Plus não está funcionando"**: verificar se pagamento está em dia\n- **"Cobrou mas não ativou"**: verificar se pagamento foi confirmado no Stripe\n- **"Frete não ficou grátis"**: verificar se pedido atingiu mínimo de R$ 40\n- **"Cancelei mas ainda cobrou"**: verificar data do cancelamento vs ciclo de cobrança\n\n### Gestão no painel\n\n1. Clientes > Buscar > Aba "Assinatura"\n2. Ver status, data de início, próxima cobrança\n3. Cancelar ou pausar se necessário',
    'faq',
    ARRAY['plus', 'assinatura', 'premium', 'frete grátis', 'benefícios'],
    NULL,
    true
)
ON CONFLICT DO NOTHING;
