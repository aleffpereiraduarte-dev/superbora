# API BoraUm Food - Documentacao Completa

**Base URL:** `https://superbora.com.br/api/mercado/boraum/`

**Todas as respostas seguem o formato:**
```json
{
  "success": true|false,
  "message": "",
  "data": { ... },
  "timestamp": "2026-02-03T20:00:00-03:00"
}
```

---

## Autenticacao

Enviar o token do passageiro no header de TODAS as requests (exceto `settings.php` e `categorias.php`):

```
Authorization: Bearer <token_do_passageiro>
```

O token e o campo `token` da tabela `boraum_passageiros` (64 caracteres hex).

**Exemplo com curl:**
```bash
curl -H "Authorization: Bearer SEU_TOKEN_AQUI" \
  "https://superbora.com.br/api/mercado/boraum/lojas.php?lat=-23.45&lng=-46.53"
```

**Exemplo com fetch (JavaScript/React Native):**
```javascript
const response = await fetch('https://superbora.com.br/api/mercado/boraum/lojas.php?lat=-23.45&lng=-46.53', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  },
});
const data = await response.json();
```

**Exemplo com Kotlin (Android):**
```kotlin
val request = Request.Builder()
    .url("https://superbora.com.br/api/mercado/boraum/lojas.php?lat=-23.45&lng=-46.53")
    .addHeader("Authorization", "Bearer $token")
    .build()
```

**Exemplo com Swift (iOS):**
```swift
var request = URLRequest(url: URL(string: "https://superbora.com.br/api/mercado/boraum/lojas.php?lat=-23.45&lng=-46.53")!)
request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
```

---

## Endpoints Publicos (sem autenticacao)

### GET /settings.php - Configuracoes do App

Retorna configuracoes de pagamento e valores.

```bash
curl "https://superbora.com.br/api/mercado/boraum/settings.php"
```

**Resposta:**
```json
{
  "service_fee": 2.49,
  "min_order_default": 15.00,
  "delivery_fee_base": 5.99,
  "free_delivery_min": 80.00,
  "max_delivery_km": 15,
  "pix_enabled": true,
  "card_enabled": true,
  "saldo_enabled": true,
  "cash_enabled": true
}
```

### GET /categorias.php - Categorias de Culinaria

```bash
curl "https://superbora.com.br/api/mercado/boraum/categorias.php"
```

**Parametros opcionais:**
| Parametro | Tipo | Descricao |
|-----------|------|-----------|
| lat | float | Latitude do usuario |
| lng | float | Longitude do usuario |
| raio | float | Raio em km (default 10) |

**Resposta:**
```json
{
  "categorias": [
    { "id": 1, "nome": "Acai", "slug": "acai", "icone": "emoji", "imagem": null, "total_lojas": 2 },
    { "id": 2, "nome": "Pizza", "slug": "pizza", "icone": "emoji", "imagem": null, "total_lojas": 2 }
  ]
}
```

---

## Endpoints de Lojas e Produtos

### GET /lojas.php - Listar Lojas Proximas

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://superbora.com.br/api/mercado/boraum/lojas.php?lat=-23.45&lng=-46.53&raio=10"
```

**Parametros:**
| Parametro | Tipo | Obrigatorio | Descricao |
|-----------|------|-------------|-----------|
| lat | float | Sim* | Latitude do usuario |
| lng | float | Sim* | Longitude do usuario |
| raio | float | Nao | Raio em km (default 10, max 100) |
| categoria | string | Nao | mercado, restaurante, farmacia, loja, supermercado |
| tag | string | Nao | Slug da culinaria: acai, pizza, hamburguer, japonesa |
| busca | string | Nao | Busca por nome da loja |
| sort | string | Nao | distance (default), rating, delivery_time, price, name |
| page | int | Nao | Pagina (default 1) |
| limit | int | Nao | Itens por pagina (default 20, max 50) |
| open_now | int | Nao | Apenas abertas (1/0) |
| free_delivery | int | Nao | Apenas entrega gratis (1/0) |
| rating_min | float | Nao | Avaliacao minima (0-5) |

**Resposta:**
```json
{
  "total": 10,
  "pagina": 1,
  "por_pagina": 20,
  "total_paginas": 1,
  "lojas": [
    {
      "id": 141,
      "nome": "Pizzaria do Mario",
      "logo": "/uploads/logos/pizzaria_mario.jpg",
      "banner": "/uploads/banners/pizzaria_mario_banner.jpg",
      "categoria": "restaurante",
      "tags": [{ "nome": "Pizza", "slug": "pizza", "icone": "emoji" }],
      "endereco": "Rua Santa Izabel, 150",
      "cidade": "Guarulhos",
      "estado": "SP",
      "aberto": true,
      "avaliacao": 4.7,
      "taxa_entrega": 6.99,
      "tempo_estimado": 30,
      "pedido_minimo": 30.00,
      "total_produtos": 12,
      "distancia_km": 0.8
    }
  ]
}
```

---

### GET /loja.php - Detalhe da Loja + Cardapio

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://superbora.com.br/api/mercado/boraum/loja.php?id=141&lat=-23.45&lng=-46.53"
```

**Parametros:**
| Parametro | Tipo | Obrigatorio | Descricao |
|-----------|------|-------------|-----------|
| id | int | Sim | ID da loja |
| lat | float | Nao | Latitude (calcula distancia) |
| lng | float | Nao | Longitude |
| category_id | int | Nao | Filtrar produtos por categoria |
| q | string | Nao | Busca por nome do produto |
| page | int | Nao | Pagina de produtos (default 1, 30/pagina) |

**Resposta:**
```json
{
  "parceiro": {
    "id": 141,
    "nome": "Pizzaria do Mario",
    "logo": "/uploads/logos/...",
    "banner": "/uploads/banners/...",
    "categoria": "restaurante",
    "descricao": "A melhor pizza artesanal...",
    "aberto": true,
    "avaliacao": 4.7,
    "taxa_entrega": 6.99,
    "tempo_estimado": 30,
    "pedido_minimo": 30.00,
    "entrega_gratis_acima": 80.00,
    "distancia_km": 0.8,
    "horario": { "abertura": "17:00:00", "fechamento": "23:59:00" }
  },
  "categorias": [
    { "id": 100, "nome": "Pizzas", "total": 8 },
    { "id": 101, "nome": "Bebidas", "total": 4 }
  ],
  "promocoes": [
    { "id": 1241, "nome": "Pizza Margherita", "preco": 39.90, "preco_promo": 34.90, "desconto": 13, "imagem": "..." }
  ],
  "produtos": {
    "total": 12,
    "pagina": 1,
    "por_pagina": 30,
    "itens": [
      {
        "id": 1243,
        "nome": "Pizza 4 Queijos",
        "descricao": "Mussarela, provolone, gorgonzola e parmesao",
        "preco": 47.90,
        "preco_promo": null,
        "imagem": "/uploads/products/pizza_4queijos.jpg",
        "categoria": "Pizzas",
        "categoria_id": 100,
        "unidade": "un",
        "estoque": 999,
        "disponivel": true,
        "option_groups": [
          {
            "id": 10,
            "nome": "Tamanho",
            "obrigatorio": true,
            "min": 1,
            "max": 1,
            "opcoes": [
              { "id": 51, "nome": "Broto (4 pedacos)", "imagem": null, "descricao": "Ideal pra 1 pessoa", "preco_extra": 0.00, "disponivel": true },
              { "id": 52, "nome": "Media (8 pedacos)", "imagem": null, "descricao": "Ideal pra 2 pessoas", "preco_extra": 10.00, "disponivel": true },
              { "id": 53, "nome": "Grande (12 pedacos)", "imagem": null, "descricao": "Ideal pra 3-4 pessoas", "preco_extra": 20.00, "disponivel": true }
            ]
          },
          {
            "id": 15,
            "nome": "Borda Recheada",
            "obrigatorio": false,
            "min": 0,
            "max": 1,
            "opcoes": [
              { "id": 69, "nome": "Catupiry", "imagem": null, "descricao": "Borda recheada com catupiry", "preco_extra": 8.00, "disponivel": true }
            ]
          },
          {
            "id": 20,
            "nome": "Ingredientes Extras",
            "obrigatorio": false,
            "min": 0,
            "max": 3,
            "opcoes": [
              { "id": 88, "nome": "Bacon Extra", "imagem": null, "descricao": "Porcao extra de bacon crocante", "preco_extra": 5.00, "disponivel": true }
            ]
          }
        ]
      }
    ]
  }
}
```

**IMPORTANTE - Campos dos option_groups:**
| Campo | Tipo | Descricao |
|-------|------|-----------|
| nome | string | Nome do grupo (Tamanho, Borda, etc) |
| obrigatorio | bool | Se true, usuario DEVE selecionar |
| min | int | Minimo de opcoes a selecionar |
| max | int | Maximo de opcoes a selecionar |
| opcoes[].nome | string | Nome da opcao |
| opcoes[].preco_extra | float | Valor extra ao preco base |
| opcoes[].disponivel | bool | Se esta disponivel |

**Logica de selecao:**
- Se `max === 1`: radio button (selecao unica)
- Se `max > 1`: checkbox (multipla selecao)
- Se `obrigatorio === true` e `min > 0`: usuario deve selecionar pelo menos `min` opcoes
- Preco final = preco_base + soma(opcoes_selecionadas.preco_extra)

---

### GET /produto.php - Detalhe do Produto

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://superbora.com.br/api/mercado/boraum/produto.php?id=1241"
```

**Resposta:**
```json
{
  "id": 1241,
  "nome": "Pizza Margherita",
  "descricao": "Molho de tomate, mussarela...",
  "preco": 39.90,
  "preco_promo": 34.90,
  "imagem": "/uploads/products/...",
  "unidade": "un",
  "estoque": 993,
  "disponivel": true,
  "loja": { "id": 141, "nome": "Pizzaria do Mario" },
  "option_groups": [ ... ] // Mesmo formato do loja.php
}
```

---

## Enderecos do Passageiro

### GET /enderecos.php - Listar Enderecos

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://superbora.com.br/api/mercado/boraum/enderecos.php"
```

**Resposta:**
```json
{
  "enderecos": [
    {
      "id": 1,
      "label": "Casa",
      "endereco": "Rua das Flores, 123",
      "complemento": "Apto 42",
      "bairro": "Centro",
      "cidade": "Guarulhos",
      "estado": "SP",
      "cep": "07023022",
      "lat": -23.4567,
      "lng": -46.5321,
      "is_default": true,
      "formatted": "Rua das Flores, 123 - Centro, Guarulhos/SP"
    }
  ]
}
```

### GET /enderecos.php?cep=07023022 - Consultar CEP

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://superbora.com.br/api/mercado/boraum/enderecos.php?cep=07023022"
```

**Resposta:**
```json
{
  "cep": "07023022",
  "endereco": "Rua Darcy Vargas",
  "complemento": "",
  "bairro": "Jardim Sao Paulo",
  "cidade": "Guarulhos",
  "estado": "SP"
}
```

### POST /enderecos.php - Criar Endereco

```bash
curl -X POST -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "endereco": "Rua das Flores, 123",
    "complemento": "Apto 42",
    "bairro": "Centro",
    "cidade": "Guarulhos",
    "estado": "SP",
    "cep": "07023022",
    "lat": -23.4567,
    "lng": -46.5321,
    "label": "Casa",
    "is_default": true
  }' \
  "https://superbora.com.br/api/mercado/boraum/enderecos.php"
```

### PUT /enderecos.php - Atualizar Endereco

```bash
curl -X PUT -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "id": 1, "complemento": "Bloco A, Apto 42" }' \
  "https://superbora.com.br/api/mercado/boraum/enderecos.php"
```

### DELETE /enderecos.php?id=1 - Remover Endereco

---

## Cartoes Salvos

### GET /cartoes.php - Listar Cartoes

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://superbora.com.br/api/mercado/boraum/cartoes.php"
```

**Resposta:**
```json
{
  "cartoes": [
    { "id": 1, "bandeira": "visa", "ultimos4": "1234", "holder_name": "JOAO SILVA", "is_default": true, "display": "VISA **** 1234" }
  ]
}
```

### POST /cartoes.php - Salvar Cartao

```bash
curl -X POST -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "bandeira": "visa",
    "ultimos4": "1234",
    "token_cartao": "tok_abc123",
    "holder_name": "JOAO SILVA",
    "is_default": true
  }' \
  "https://superbora.com.br/api/mercado/boraum/cartoes.php"
```

### DELETE /cartoes.php?id=1 - Remover Cartao

---

## Saldo e Carteira

### GET /saldo.php - Saldo Atual

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://superbora.com.br/api/mercado/boraum/saldo.php"
```

**Resposta:**
```json
{
  "saldo": 50.00,
  "saldo_formatado": "R$ 50,00",
  "transacoes": [
    {
      "id": 1,
      "tipo": "credit",
      "tipo_label": "Credito",
      "valor": 50.00,
      "valor_formatado": "+R$ 50,00",
      "descricao": "Recarga via PIX",
      "saldo_apos": 50.00,
      "created_at": "2026-02-03T19:00:00-03:00"
    }
  ]
}
```

### GET /saldo.php?historico=1&page=1 - Historico Paginado

---

## Cupons

### POST /cupom.php - Validar Cupom

```bash
curl -X POST -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "code": "PROMO10", "partner_id": 141, "subtotal": 50.00 }' \
  "https://superbora.com.br/api/mercado/boraum/cupom.php"
```

**Resposta:**
```json
{
  "valid": true,
  "discount": 10.00,
  "discount_type": "percentage",
  "coupon_name": "PROMO10",
  "message": "Cupom aplicado! Desconto de R$ 10,00"
}
```

---

## Checkout (Criar Pedido)

### POST /checkout.php

```bash
curl -X POST -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "partner_id": 141,
    "items": [
      {
        "id": 1241,
        "quantity": 1,
        "notes": "Sem cebola",
        "addons": [43, 69]
      },
      {
        "id": 1249,
        "quantity": 2,
        "notes": "",
        "addons": []
      }
    ],
    "address_id": 1,
    "payment_method": "pix",
    "coupon_code": "PROMO10",
    "notes": "Portao azul",
    "delivery_instructions": "Deixar na portaria",
    "contactless": false,
    "tip": 5.00
  }' \
  "https://superbora.com.br/api/mercado/boraum/checkout.php"
```

**Campo items[].addons:** Array com IDs das opcoes selecionadas nos option_groups.

**Metodos de pagamento:**
| Metodo | Descricao |
|--------|-----------|
| pix | Gera QR code PIX |
| credito | Usa cartao salvo (precisa `card_id`) |
| saldo | Debita do saldo da carteira |
| misto | Parte saldo + parte PIX/cartao (`use_saldo` + `card_id` ou pix) |

**Resposta:**
```json
{
  "order_id": 123,
  "order_number": "BU-260203-A1B2C",
  "codigo_entrega": "1234",
  "status": "pending",
  "subtotal": 60.70,
  "delivery_fee": 6.99,
  "service_fee": 2.49,
  "tip": 5.00,
  "coupon_discount": 6.07,
  "saldo_usado": 0,
  "total": 69.11,
  "payment_method": "pix",
  "partner": { "id": 141, "name": "Pizzaria do Mario" },
  "pix": {
    "qr_code": "00020126...",
    "expiration": "2026-02-03T20:30:00-03:00"
  }
}
```

---

## Pedidos

### GET /pedidos.php - Listar Pedidos

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://superbora.com.br/api/mercado/boraum/pedidos.php?page=1&limit=15"
```

**Parametros opcionais:**
| Parametro | Descricao |
|-----------|-----------|
| status | "active" (em andamento) ou "completed" (finalizados) |
| page | Pagina (default 1) |
| limit | Itens/pagina (default 15, max 50) |

**Resposta:**
```json
{
  "pedidos": [
    {
      "id": 123,
      "order_number": "BU-260203-A1B2C",
      "status": "entregue",
      "status_label": "Entregue",
      "partner": { "id": 141, "nome": "Pizzaria do Mario", "logo": "..." },
      "total": 69.11,
      "items_count": 3,
      "pagamento": "pix",
      "created_at": "2026-02-03T19:30:00-03:00"
    }
  ],
  "total": 8,
  "pagina": 1,
  "por_pagina": 15,
  "total_paginas": 1
}
```

### GET /pedidos.php?id=123 - Detalhe do Pedido

```bash
curl -H "Authorization: Bearer TOKEN" \
  "https://superbora.com.br/api/mercado/boraum/pedidos.php?id=123"
```

**Resposta completa com:**
- Dados do pedido
- Lista de itens com nome, quantidade, preco, imagem
- Dados do parceiro (nome, telefone, lat/lng)
- Dados do shopper (nome, telefone, foto)
- Dados do motorista (nome, telefone, veiculo)
- Timeline de eventos
- Endereco de entrega

---

## Tracking em Tempo Real (SSE)

### GET /pedido-status.php - Server-Sent Events

```javascript
// JavaScript/React Native
const eventSource = new EventSource(
  'https://superbora.com.br/api/mercado/boraum/pedido-status.php?order_id=123&token=SEU_TOKEN'
);

eventSource.addEventListener('order_update', (event) => {
  const data = JSON.parse(event.data);
  console.log('Status:', data.status, data.status_label);
});

eventSource.addEventListener('location_update', (event) => {
  const data = JSON.parse(event.data);
  console.log('Motorista em:', data.lat, data.lng);
});

eventSource.addEventListener('order_final', (event) => {
  const data = JSON.parse(event.data);
  console.log('Pedido finalizado:', data.status);
  eventSource.close();
});
```

**Eventos:**
| Evento | Descricao |
|--------|-----------|
| connected | Conexao estabelecida |
| order_update | Mudanca de status |
| location_update | Localizacao do motorista |
| chat_message | Nova mensagem no chat |
| order_final | Pedido entregue/cancelado (fechar conexao) |

---

## Chat do Pedido

### GET /chat.php?order_id=123 - Mensagens

### POST /chat.php - Enviar Mensagem

```bash
curl -X POST -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "order_id": 123, "message": "Quanto tempo falta?", "to": "partner" }' \
  "https://superbora.com.br/api/mercado/boraum/chat.php"
```

### PUT /chat.php - Marcar como Lido

```bash
curl -X PUT -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "order_id": 123 }' \
  "https://superbora.com.br/api/mercado/boraum/chat.php"
```

---

## Avaliar Pedido

### POST /avaliar.php

```bash
curl -X POST -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "order_id": 123, "rating": 5, "comment": "Otima pizza!" }' \
  "https://superbora.com.br/api/mercado/boraum/avaliar.php"
```

**rating:** 1 a 5 (inteiro)

---

## Cancelar Pedido

### POST /cancelar.php

```bash
curl -X POST -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "order_id": 123, "reason": "Pedi errado" }' \
  "https://superbora.com.br/api/mercado/boraum/cancelar.php"
```

**Cancelamento so e permitido nos status:** pending, pendente, aceito, confirmed, confirmado

**Se pagou com saldo:** reembolso automatico na carteira.

---

## Status do Pedido (ciclo de vida)

```
pending → aceito → preparando → pronto → em_entrega → entregue
                                                    → cancelado
```

| Status | Label | Descricao |
|--------|-------|-----------|
| pending | Pendente | Aguardando loja aceitar |
| aceito | Aceito | Loja aceitou o pedido |
| preparando | Em Preparo | Loja esta preparando |
| pronto | Pronto | Pronto para entrega/retirada |
| em_entrega | Em Entrega | Motorista a caminho |
| entregue | Entregue | Pedido entregue |
| cancelado | Cancelado | Pedido cancelado |

---

## Codigos de Erro HTTP

| Codigo | Descricao |
|--------|-----------|
| 200 | Sucesso |
| 400 | Parametro invalido ou faltando |
| 401 | Token invalido ou ausente |
| 403 | Conta bloqueada |
| 404 | Recurso nao encontrado |
| 405 | Metodo HTTP nao permitido |
| 409 | Conflito (ex: cupom ja usado) |
| 429 | Rate limit (max 60 req/min) |
| 500 | Erro interno do servidor |

---

## Token de Teste

Para testes em desenvolvimento:
```
Token: 06cd22961743fec8af9a05262d54f9eb75edfaaf6e71987e170374dd6318810e
Passageiro: Teste Pente Fino (ID: 1)
Telefone: 33999999999
```

## Fluxo Completo de Pedido

1. `GET /lojas.php?lat=X&lng=Y` → Lista lojas proximas
2. `GET /loja.php?id=141` → Cardapio com produtos e opcoes
3. Montar carrinho no app (local)
4. `POST /cupom.php` → Validar cupom (opcional)
5. `GET /enderecos.php` → Listar enderecos salvos
6. `POST /checkout.php` → Criar pedido
7. `GET /pedido-status.php?order_id=X` → Tracking SSE
8. `POST /avaliar.php` → Avaliar apos entrega
