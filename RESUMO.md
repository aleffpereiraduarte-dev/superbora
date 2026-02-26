# OneMundo - Sistema Marketplace Completo

## Visão Geral do Sistema
Sistema de marketplace nível DoorDash/Instacart com:
- **Mercados** (Partners) - Gerenciam produtos, horários, promoções
- **Shoppers** - Fazem coleta e entrega dos pedidos
- **Clientes** - Compram produtos via app/site
- **Admin** - Gerencia tudo e dá suporte

---

## Estrutura de Arquivos Criados

### Painéis de Gestão

#### Mercado (Partner)
| Arquivo | Descrição | URL |
|---------|-----------|-----|
| `/painel/mercado/produtos.php` | Gestão de produtos com scanner de código de barras, busca no catálogo base, localização na loja | `/painel/mercado/produtos.php` |
| `/painel/mercado/horarios.php` | Horários de funcionamento + fechamentos especiais (feriados, férias) | `/painel/mercado/horarios.php` |
| `/painel/mercado/promocoes.php` | Promoções agendadas com data início/fim | `/painel/mercado/promocoes.php` |
| `/painel/mercado/chat.php` | Chat com suporte admin | `/painel/mercado/chat.php` |

#### Shopper
| Arquivo | Descrição | URL |
|---------|-----------|-----|
| `/painel/shopper/index.php` | Dashboard principal - pedidos, ganhos, status online | `/painel/shopper/` |
| `/painel/shopper/login.php` | Login por telefone + senha | `/painel/shopper/login.php` |
| `/painel/shopper/logout.php` | Logout | `/painel/shopper/logout.php` |

#### Admin
| Arquivo | Descrição | URL |
|---------|-----------|-----|
| `/painel/admin/suporte.php` | Chat unificado - atende clientes, mercados e shoppers | `/painel/admin/suporte.php` |

---

### APIs

#### Mercado/Partner
| Endpoint | Método | Descrição |
|----------|--------|-----------|
| `/api/mercado/parceiro/status.php` | GET | Status do mercado (aberto/fechado), horários |
| `/api/mercado/cobertura.php` | GET | Verifica cobertura de entrega por CEP/coordenadas |
| `/api/mercado/entrega/slots.php` | GET | Slots de entrega disponíveis (considera horário, preparo) |
| `/api/mercado/produtos/buscar-base.php` | GET | Busca no catálogo de 57k+ produtos pré-cadastrados |

#### Parâmetros das APIs

**`/api/mercado/parceiro/status.php`**
```
GET ?partner_id=1
ou
GET ?mercado_id=1

Retorno:
{
  "success": true,
  "mercado": { "id", "nome", "logo", "rating" },
  "status": { "aberto", "motivo", "proximo_abertura", "horario_hoje" },
  "entrega": { "taxa", "pedido_minimo", "tempo_min", "tempo_max" },
  "horarios": [...],
  "fechamentos": [...]
}
```

**`/api/mercado/cobertura.php`**
```
GET ?cep=12345678
ou
GET ?lat=-23.5505&lng=-46.6333

Retorno:
{
  "success": true,
  "atendido": true/false,
  "mercados_disponiveis": [...]
}
```

**`/api/mercado/entrega/slots.php`**
```
GET ?partner_id=1&dias=5

Retorno:
{
  "success": true,
  "mercado": { "id", "nome", "aberto_agora", "tempo_preparo" },
  "permite_entrega_imediata": true/false,
  "slots": [
    { "data", "data_formatada", "dia_semana", "disponivel", "horarios": [...] }
  ]
}
```

---

## Banco de Dados

### Tabelas Criadas/Modificadas

#### Shoppers (`om_market_shoppers`)
Campos adicionados:
- `saldo_pendente` - Saldo aguardando liberação
- `saldo_bloqueado` - Saldo bloqueado
- `nivel_nome` - ENUM('iniciante','bronze','prata','ouro','diamante')
- `aceita_shop_deliver` - Se aceita fazer compras
- `aceita_apenas_entrega` - Se aceita só entregar
- `preferred_stores` - JSON com mercados preferidos
- `badges` - JSON com conquistas

#### Partners/Mercados (`om_market_partners`)
Campos adicionados:
- `saldo_disponivel` - Saldo disponível para saque
- `saldo_pendente` - Saldo aguardando liberação
- `saldo_bloqueado` - Saldo bloqueado
- `total_vendas` - Total de vendas realizadas
- `total_saques` - Total já sacado

### Novas Tabelas

#### `om_partner_closures` - Fechamentos Especiais
```sql
- id, partner_id
- closure_date, closure_end (período)
- all_day (1=dia todo, 0=horário especial)
- open_time, close_time (se horário especial)
- reason (motivo)
```

#### `om_partner_promotions` - Promoções Agendadas
```sql
- id, partner_id
- titulo, descricao
- tipo_desconto (percent, fixed)
- valor_desconto
- aplica_em (loja, produto, categoria)
- produto_id, categoria_id (se específico)
- data_inicio, data_fim
- ativo
```

#### `om_shopper_boosts` - Peak Pay/Challenges
```sql
- id, partner_id (null=todos), city (null=todas)
- tipo (peak_pay, challenge, streak, bonus)
- titulo, descricao, valor_extra
- meta_pedidos (para challenges)
- horario_inicio, horario_fim
- dias_semana (JSON)
- data_inicio, data_fim
```

#### `om_shopper_boost_progress` - Progresso em Challenges
```sql
- id, shopper_id, boost_id
- pedidos_completados, valor_ganho
- concluido, data_conclusao
```

#### `om_shopper_ratings` - Avaliações Detalhadas
```sql
- id, shopper_id, order_id, customer_id
- rating (1-5)
- comunicacao, pontualidade, qualidade_produtos
- comentario, gorjeta
- respondeu, resposta_shopper
```

#### `om_shopper_wallet_transactions` - Transações Wallet
```sql
- id, shopper_id
- tipo (ganho, gorjeta, bonus, boost, saque, estorno, ajuste)
- valor, saldo_anterior, saldo_posterior
- referencia_tipo, referencia_id
- descricao, status, data_disponivel
```

#### `om_shopper_saques` - Saques do Shopper
```sql
- id, shopper_id
- valor, taxa, valor_liquido
- tipo (semanal, instantaneo)
- metodo (pix, ted), chave_pix
- status, comprovante
- processado_por, processado_em
```

#### `om_shopper_badges` - Badges/Conquistas
```sql
- id, codigo, nome, descricao
- icone, cor
- requisito_tipo (entregas, rating, streak, tempo, especial)
- requisito_valor, pontos
```

#### `om_shopper_achievements` - Conquistas Desbloqueadas
```sql
- id, shopper_id, badge_id
- desbloqueado_em
```

#### `om_market_wallet` - Transações Wallet do Mercado
```sql
- id, partner_id
- tipo (venda, bonus, ajuste, saque, estorno, taxa)
- valor, saldo_anterior, saldo_posterior
- referencia_tipo, referencia_id
- descricao, status, data_liberacao
- created_by
```

#### `om_market_saques` - Saques do Mercado
```sql
- id, partner_id
- valor, taxa, valor_liquido
- metodo (pix, ted, conta_bancaria)
- chave_pix, banco_*, status
- comprovante, motivo_rejeicao
- processado_por, processado_em
```

#### `om_support_tickets` - Tickets de Suporte
```sql
- id, ticket_number
- entidade_tipo (cliente, mercado, shopper, motorista, vendedor)
- entidade_id, entidade_nome
- assunto, categoria, prioridade, status
- atendente_id, atendente_nome
- referencia_tipo, referencia_id
- ultima_mensagem_at, resolvido_em
- avaliacao, avaliacao_comentario
```

#### `om_support_messages` - Mensagens de Suporte
```sql
- id, ticket_id
- remetente_tipo (admin, entidade, sistema)
- remetente_id, remetente_nome
- mensagem, anexos (JSON)
- lida, lida_em
```

#### `om_support_faq` - FAQs por Tipo
```sql
- id, entidade_tipo, categoria
- pergunta, resposta, ordem
- ativo, visualizacoes
```

---

## Fluxo Financeiro

### Cliente faz Pedido
1. Cliente paga **Preço de Venda** (ex: R$ 100,00)
2. Mercado definiu **Preço do Produto** (ex: R$ 80,00)
3. Shopper recebe **Valor Base + Gorjeta** (ex: R$ 8,00 + R$ 5,00)
4. **OneMundo lucra**: R$ 100 - R$ 80 - R$ 8 = R$ 12,00

### Wallet do Mercado
- Após entrega confirmada, valor do produto vai para `saldo_pendente`
- Após 7 dias, move para `saldo_disponivel`
- Mercado pode sacar quando quiser

### Wallet do Shopper
- Ganhos ficam disponíveis em 24h
- Após 5 entregas, libera saque instantâneo (taxa R$ 1,99)
- Saque semanal: grátis
- 100% das gorjetas para o shopper

---

## Sistema de Níveis (Shopper)

| Nível | Requisito | Benefícios |
|-------|-----------|------------|
| Iniciante | 0 entregas | Acesso básico |
| Bronze | 10 entregas | - |
| Prata | 50 entregas | Acesso a melhores pedidos |
| Ouro | 200 entregas | Prioridade em Peak Pay |
| Diamante | 500 entregas | Maior prioridade + Bônus |

---

## Badges/Conquistas

| Código | Nome | Requisito |
|--------|------|-----------|
| primeira_entrega | Primeira Entrega | 1 entrega |
| 10_entregas | 10 Entregas | 10 entregas |
| 50_entregas | 50 Entregas | 50 entregas |
| 100_entregas | Centurião | 100 entregas |
| 500_entregas | Veterano | 500 entregas |
| 1000_entregas | Lendário | 1000 entregas |
| rating_perfeito | Avaliação Perfeita | Rating 5.0 com 20+ avaliações |
| streak_5 | Em Chamas | 5 entregas seguidas |
| streak_10 | Imparável | 10 entregas seguidas |
| velocista | Velocista | Média <30min |
| madrugador | Madrugador | 20 entregas antes 8h |
| coruja | Coruja | 20 entregas após 22h |
| fds_warrior | Guerreiro de FDS | 50 entregas em fins de semana |

---

## Testes E2E

### 1. Teste Painel Mercado
```bash
# Login
curl -X POST http://localhost/painel/mercado/login.php \
  -d "email=mercado@test.com&password=123456"

# Verificar produtos
curl http://localhost/painel/mercado/produtos.php

# Testar busca de produtos base
curl "http://localhost/api/mercado/produtos/buscar-base.php?q=coca"
```

### 2. Teste Painel Shopper
```bash
# Login
curl -X POST http://localhost/painel/shopper/login.php \
  -d "phone=11999999999&password=123456"

# Dashboard (autenticado)
curl http://localhost/painel/shopper/

# Toggle online
curl -X POST http://localhost/painel/shopper/ \
  -d "action=toggle_online&online=1"

# Get dashboard
curl -X POST http://localhost/painel/shopper/ \
  -d "action=get_dashboard"
```

### 3. Teste APIs
```bash
# Status do mercado
curl "http://localhost/api/mercado/parceiro/status.php?partner_id=1"

# Cobertura por CEP
curl "http://localhost/api/mercado/cobertura.php?cep=01310100"

# Slots de entrega
curl "http://localhost/api/mercado/entrega/slots.php?partner_id=1&dias=5"
```

### 4. Teste Admin Suporte
```bash
# Login admin
curl -X POST http://localhost/painel/admin/login.php \
  -d "email=admin@onemundo.com&password=admin123"

# Listar tickets
curl -X POST http://localhost/painel/admin/suporte.php \
  -d "action=listar_tickets&status=aberto"

# Estatísticas
curl -X POST http://localhost/painel/admin/suporte.php \
  -d "action=estatisticas"
```

---

## Próximos Passos

### Implementar
1. [ ] Notificações push para shoppers (novos pedidos)
2. [ ] WebSocket para real-time no suporte
3. [ ] Integração com Claude AI para FAQ inteligente
4. [ ] Painel completo do cliente
5. [ ] Sistema de reembolso
6. [ ] Relatórios financeiros para admin
7. [ ] App mobile nativo (React Native/Flutter)

### Testar
1. [ ] Fluxo completo de pedido (cliente -> mercado -> shopper -> entrega)
2. [ ] Sistema de pagamento/wallet
3. [ ] Saques e transferências
4. [ ] Chat de suporte
5. [ ] Promoções e boosts

---

## Credenciais de Teste

### Banco de Dados
```
Host: 147.93.12.236
User: love1
Pass: Aleff2009@
DB: love1
```

### Acessos de Teste
```
Admin: admin@onemundo.com / admin123
Mercado: (criar no painel)
Shopper: (telefone + senha criada no cadastro)
```

---

## Tecnologias Utilizadas

- **Backend**: PHP 8.4 + PDO MySQL
- **Frontend**: HTML5, CSS3 (variáveis, flexbox, grid), JavaScript ES6+
- **UI/UX**: Design mobile-first, animações CSS, PWA-ready
- **Banco**: MySQL 8.x
- **Real-time**: Auto-refresh polling (evoluir para SSE/WebSocket)
- **APIs**: REST JSON
- **Segurança**: Sessions, prepared statements, escape de HTML

---

*Documentação gerada em 28/01/2026*
