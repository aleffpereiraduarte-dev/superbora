# RESUMO - OneMundo Marketplace

**Última atualização:** 28/01/2026

---

## STATUS GERAL

| Componente | Status | Observações |
|------------|--------|-------------|
| Checkout | OK | PIX + Cartão + Apple/Google Pay |
| Stripe | OK | Webhook configurado |
| Pagar.me | OK | PIX funcionando (precisa chaves v5) |
| Shopper App | OK | Modernizado v6.0 |
| Admin Panel | PENDENTE | A fazer |
| Painel Mercado | PENDENTE | A fazer |

---

## SHOPPER APP - MELHORIAS v6.0

### Visual Modernizado
- Login com glassmorphism e animações
- Dashboard com cards premium
- Bottom nav com blur effect
- Cores atualizadas (--green: #00E676)
- Background ambient light effects
- Transições suaves com cubic-bezier

### PWA Completo
- manifest.json configurado
- Service Worker com cache offline
- Push notifications suporte
- Geolocation tracking
- Ícones e shortcuts

### APIs Corrigidas (TESTADAS E FUNCIONANDO)
- notify.php - SQL Injection CORRIGIDO
- shopper.php - Credenciais hardcoded REMOVIDAS
- delivery.php - Usando config centralizado
- status.php - Tipo de campo corrigido (is_online)

### Testes Realizados (28/01/2026)
```
1. notify.php (check_new_orders): OK
2. status.php (online): OK
3. status.php (offline): OK
4. get-offers.php: OK
5. notify.php (heartbeat): OK
```

---

## ESTRUTURA DE ARQUIVOS

```
/var/www/html/
├── .env                    # Variáveis de ambiente (NÃO COMMITAR)
├── api_pagarme.php        # API Pagar.me (PIX, Cartão)
├── api_stripe.php         # API Stripe
├── webhook_stripe.php     # Webhook Stripe
├── checkout_novo.php      # Checkout principal
├── om_pagamentos_v29.js   # JS Pagamentos
│
├── includes/
│   ├── env_loader.php     # Carrega .env
│   ├── om_bootstrap.php   # Bootstrap sistema
│   └── classes/
│       ├── OmConfig.php   # Configurações
│       ├── OmCart.php     # Carrinho
│       └── OmCustomer.php # Cliente
│
└── mercado/shopper/       # App Shopper
    ├── index.php          # Dashboard v6.0
    ├── login.php          # Login premium
    ├── compras.php        # Interface compras
    ├── manifest.json      # PWA manifest
    ├── sw.js              # Service Worker
    └── api/               # APIs backend
        ├── accept-offer.php
        ├── get-offers.php
        ├── scan.php
        ├── chat.php
        ├── status.php     # CORRIGIDO
        ├── notify.php     # CORRIGIDO
        ├── shopper.php    # CORRIGIDO
        ├── delivery.php   # CORRIGIDO
        └── ...
```

---

## CONFIGURAÇÃO .ENV

O arquivo `.env` deve conter:

```env
# Database
DB_HOSTNAME=localhost
DB_DATABASE=love1
DB_USERNAME=love1
DB_PASSWORD=***

# Pagar.me
PAGARME_SECRET_KEY=sk_xxx
PAGARME_PUBLIC_KEY=pk_xxx

# Stripe
STRIPE_SECRET_KEY=sk_xxx
STRIPE_PUBLIC_KEY=pk_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# JWT
JWT_SECRET=xxx
```

---

## PRÓXIMOS PASSOS

### 1. Admin Panel (/admin)
- Dashboard com métricas
- Gestão de pedidos
- Gestão de shoppers
- Relatórios financeiros

### 2. Painel Mercado (/painel)
- Login parceiros
- Gestão produtos
- Pedidos do mercado
- Configurações

### 3. Integrações
- Conectar todos os sistemas
- API unificada
- WebSocket para real-time
- Notificações push

---

## COMANDOS ÚTEIS

```bash
# Reiniciar PHP (após mudanças)
sudo systemctl restart php8.4-fpm

# Ver logs
tail -f /var/log/apache2/error.log

# Testar API Pagar.me
curl -X POST https://onemundo.com.br/api_pagarme.php \
  -H "Content-Type: application/json" \
  -d '{"action":"test"}'

# Testar Shopper API
curl https://onemundo.com.br/mercado/shopper/api/get-offers.php?shopper_id=1
```

---

## SEGURANÇA

### Corrigido
- SQL Injection em notify.php
- Credenciais hardcoded removidas
- Usando config centralizado

### Pendente
- Implementar rate limiting
- Adicionar CSRF tokens
- Validar tokens JWT em APIs
- Logs de auditoria

---

## BANCO DE DADOS

### Tabelas principais
- `om_market_orders` - Pedidos
- `om_market_order_items` - Itens do pedido
- `om_market_shoppers` - Shoppers
- `om_market_partners` - Mercados parceiros
- `om_market_cart` - Carrinho
- `om_market_products` - Produtos
- `om_market_chat` - Chat pedido

---

*Documentação gerada automaticamente pelo Claude Code*
