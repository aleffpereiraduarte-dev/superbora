# RELATORIO DE GAPS - ONEMUNDO MARKETPLACE
## Analise Completa: Documento Oficial vs Implementacao Atual

**Data:** 22/01/2026
**Versao:** 1.0

---

## LEGENDA
- ✅ **IMPLEMENTADO** - Funcionalidade completa e operacional
- ⚠️ **PARCIAL** - Existe mas precisa ajustes/melhorias
- ❌ **FALTANDO** - Nao implementado ou critico

---

## 1. MODELO DE VENDEDOR

### 1.1 Tipos de Vendedor (simples vs loja_oficial)

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Campo tipo_vendedor na tabela | ✅ | `om_vendedores.tipo_vendedor` existe |
| Cadastro diferenciado no formulario | ⚠️ | Formulario nao oferece escolha de tipo |
| Funcionalidades diferenciadas por tipo | ⚠️ | Painel /vendedor/ nao diferencia |
| Mini-site apenas para loja_oficial | ✅ | `vendedor-loja.php` verifica `tipo_vendedor` |

**Arquivos Relacionados:**
- `/var/www/html/mercado/seja-vendedor.php` - Cadastro (linhas 59-70)
- `/var/www/html/mercado/vendedor-loja.php` - Mini-site (linha 57)

### 1.2 Integracao OpenCart + Painel Vendedor

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Dual table system (PurpleTree + om_vendedores) | ✅ | Implementado com transacao |
| Sessao compartilhada OCSESSID | ✅ | Funciona corretamente |
| Menu dinamico por status | ✅ | API vendedor-status.php |
| Redirecionamento apos aprovacao | ✅ | JavaScript no account.twig |

**Arquivos Relacionados:**
- `/var/www/html/mercado/api/vendedor-status.php`
- `/var/www/html/catalog/view/theme/journal3/template/account/account.twig`

---

## 2. SISTEMA DE PONTO DE APOIO (LOGISTICA)

### ❌ GAP CRITICO: Regra "Nunca Vendedor -> Cliente Direto"

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Fluxo obrigatorio via Ponto de Apoio | ❌ | **CRITICO: Entrega direta ainda existe!** |
| FLUXO_PONTO_APENAS_MESMA_CIDADE | ✅ | Constante definida |
| Funcao mesmaCidade() | ✅ | Implementada |
| Selecao obrigatoria no checkout | ⚠️ | Selecao existe mas nao e obrigatoria |
| BoraUm API dispatch | ⚠️ | Calcula custo mas NAO despacha |

**PROBLEMA ENCONTRADO em `/var/www/html/mercado/api/frete-ponto-apoio.php`:**
```php
// Linha ~280: Ainda retorna opcao "moto_direto" para entrega direta
// ISSO VIOLA A REGRA DO DOCUMENTO
```

### 2.1 Painel Ponto de Apoio

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Dashboard completo | ✅ | 22 arquivos em /vendedor/ponto-apoio/ |
| Gestao de pacotes | ✅ | Listagem, filtros, acoes |
| QR Code scanning | ✅ | html5-qrcode integrado |
| Workflow 9 estados | ✅ | aguardando -> entregue |
| Financeiro do ponto | ✅ | Comissoes, saques |
| Gerar lotes automaticos | ⚠️ | Existe mas nao automatizado |

**Arquivos Relacionados:**
- `/var/www/html/vendedor/ponto-apoio/dashboard.php`
- `/var/www/html/vendedor/ponto-apoio/pacotes.php`
- `/var/www/html/vendedor/ponto-apoio/scanner.php`

---

## 3. CHECKOUT E COMPRA RAPIDA

### 3.1 One-Click Purchase

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Tabela om_one_quick_buy_settings | ✅ | Criada |
| Cartoes tokenizados | ✅ | Integrado com Mercado Pago |
| Endereco padrao | ✅ | Salvo no perfil |
| Botao "Comprar Agora" | ✅ | Nas paginas de produto |

### 3.2 Janela de Agrupamento Pos-Checkout

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Tabelas om_order_grouping_* | ✅ | 3 tabelas criadas |
| Timer de janela (ex: 15min) | ❌ | **NAO IMPLEMENTADO** |
| UI para adicionar itens | ❌ | **NAO IMPLEMENTADO** |
| Agrupamento automatico por vendedor | ❌ | **NAO IMPLEMENTADO** |
| Geracao de lote unico | ❌ | **NAO IMPLEMENTADO** |

**Arquivos Relacionados:**
- `/var/www/html/mercado/instalacao/001_evolucao_marketplace.sql` - Tabelas existem
- **FALTA:** Logica de agrupamento nao foi implementada

---

## 4. DISPUTAS E GARANTIAS

### 4.1 Sistema de Disputas

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Tabela om_disputes | ✅ | Workflow completo de status |
| Tabela om_dispute_messages | ✅ | Chat entre partes |
| Listar disputas | ✅ | minhas-disputas.php |
| Detalhes com chat | ✅ | disputa.php |
| Abrir nova disputa | ✅ | Modal no formulario |
| Escalacao automatica | ⚠️ | Falta cron para X dias |
| Integracao com reembolso | ❌ | Nao processa estorno automatico |

### 4.2 Sistema de Garantias

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Tabela om_garantias | ✅ | Varios tipos de garantia |
| Listar garantias | ✅ | minhas-garantias.php |
| Acionar garantia | ✅ | acionar-garantia.php -> cria disputa |
| Vigencia automatica | ✅ | Data inicio/fim |
| Compra de garantia extra | ⚠️ | Tabela existe, UI falta |

**Arquivos Relacionados:**
- `/var/www/html/mercado/minhas-disputas.php`
- `/var/www/html/mercado/disputa.php`
- `/var/www/html/mercado/minhas-garantias.php`
- `/var/www/html/mercado/acionar-garantia.php`

---

## 5. SISTEMA DE AFILIADOS

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Tabela om_affiliates | ✅ | Dados do afiliado |
| Tabela om_afiliado_produto_links | ✅ | Links por produto |
| Tabela om_afiliado_vendas | ✅ | Registro de vendas |
| Cadastro de afiliado | ✅ | seja-afiliado.php |
| Painel do afiliado | ✅ | /afiliados/painel/index.php |
| Gerar links | ✅ | API generate-link.php |
| Cookie tracking | ✅ | onemundo_aff cookie |
| Comissao na venda | ✅ | OnemundoAffiliate::processOrderAffiliate() |
| Confirmacao na entrega | ✅ | confirmOrderCommission() |
| Cancelamento/estorno | ✅ | cancelOrderCommission() |
| Integracao com wallet | ✅ | Credita na wallet do vendedor |
| Interface de saque | ⚠️ | Falta UI especifica para afiliado sacar |

**Arquivos Relacionados:**
- `/var/www/html/system/library/onemundo_affiliate.php` - 373 linhas completas
- `/var/www/html/afiliados/painel/index.php`
- `/var/www/html/afiliados/api/generate-link.php`
- `/var/www/html/mercado/seja-afiliado.php`

---

## 6. MINI-SITE LOJAS OFICIAIS

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Pagina vendedor-loja.php | ✅ | 706 linhas completas |
| Banner personalizado | ✅ | Exibe se existir |
| Logo da loja | ✅ | Exibe se existir |
| Descricao da loja | ✅ | Truncada em 200 chars |
| Listagem de produtos | ✅ | Com paginacao |
| Filtro por categoria | ✅ | Categorias da loja |
| Busca interna | ✅ | Busca na loja |
| Ordenacao | ✅ | Preco, vendidos, recentes |
| Diferenciacao simples/oficial | ✅ | Simples mostra card simples |
| URL amigavel (/loja/nome) | ❌ | Usa ?slug=nome |
| SEO meta tags | ⚠️ | Basico, falta Open Graph |
| Paginas customizadas | ❌ | Nao implementado |

**Arquivo:** `/var/www/html/mercado/vendedor-loja.php`

---

## 7. INTEGRACAO BORAUM

| Requisito | Status | Observacao |
|-----------|--------|------------|
| Calculo de frete | ⚠️ | Simula mas nao chama API real |
| Despacho automatico | ❌ | **NAO IMPLEMENTADO** |
| Tracking real-time | ❌ | **NAO IMPLEMENTADO** |
| Webhook de status | ❌ | **NAO IMPLEMENTADO** |

---

## RESUMO EXECUTIVO

### Percentual de Implementacao por Modulo

| Modulo | % Completo | Prioridade |
|--------|------------|------------|
| Modelo de Vendedor | 70% | Media |
| Ponto de Apoio | 65% | **ALTA** |
| One-Click Purchase | 90% | Baixa |
| Janela Agrupamento | 20% | Media |
| Disputas | 85% | Baixa |
| Garantias | 80% | Baixa |
| Afiliados | 90% | Baixa |
| Mini-Site Lojas | 80% | Media |
| BoraUm Integration | 25% | **ALTA** |

### GAPS CRITICOS (Prioridade Imediata)

1. **❌ Entrega direta ainda permitida**
   - Arquivo: `/var/www/html/mercado/api/frete-ponto-apoio.php`
   - Acao: Remover opcao `moto_direto`, forcar selecao de Ponto de Apoio

2. **❌ Janela de agrupamento nao funciona**
   - Tabelas existem mas logica nao implementada
   - Acao: Criar `api/order-grouping.php` e timer no frontend

3. **❌ BoraUm nao despacha entregas**
   - Apenas calcula, nao envia pedido real
   - Acao: Implementar `BoraUmApi::dispatchDelivery()`

### MELHORIAS RECOMENDADAS (Proxima Fase)

1. UI para escolher tipo de vendedor no cadastro
2. URL amigavel para mini-sites (/loja/nome)
3. Cron de escalacao automatica de disputas
4. Interface de saque para afiliados
5. Open Graph meta tags nas lojas

---

## ARQUIVOS PRINCIPAIS DO SISTEMA

```
/var/www/html/mercado/
├── seja-vendedor.php          # Cadastro vendedor
├── seja-afiliado.php          # Cadastro afiliado
├── vendedor-loja.php          # Mini-site lojas
├── minhas-disputas.php        # Lista disputas
├── disputa.php                # Detalhe disputa
├── minhas-garantias.php       # Lista garantias
├── acionar-garantia.php       # Acionar garantia
├── api/
│   ├── vendedor-status.php    # Status do vendedor
│   ├── frete-ponto-apoio.php  # Calculo frete (PRECISA CORRECAO)
│   └── disputa.php            # API disputas
└── instalacao/
    ├── instalar_evolucao.php  # Cria 13 tabelas
    └── 001_evolucao_marketplace.sql

/var/www/html/vendedor/ponto-apoio/
├── dashboard.php              # Dashboard ponto
├── pacotes.php                # Gestao pacotes
├── scanner.php                # QR code scanner
├── financeiro.php             # Comissoes
└── ... (22 arquivos total)

/var/www/html/afiliados/
├── index.php                  # Landing page
├── painel/index.php           # Dashboard afiliado
└── api/
    ├── generate-link.php      # Gerar link
    └── register.php           # Registrar afiliado

/var/www/html/system/library/
└── onemundo_affiliate.php     # Biblioteca afiliados (373 linhas)
```

---

**Relatorio gerado automaticamente pela analise do sistema OneMundo**
