<?php
require_once __DIR__ . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  📋 CHECKLIST DO SISTEMA DE MATCHING - ONEMUNDO MERCADO                      ║
 * ║  Verifica tudo que precisa estar funcionando                                 ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

$checks = [];
$fixes_needed = [];

echo "<h1>📋 Checklist do Sistema de Matching</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; background: #1a1a2e; color: #eee; }
    .ok { color: #4ade80; }
    .warn { color: #fbbf24; }
    .error { color: #f87171; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #333; padding: 12px; text-align: left; }
    th { background: #333; }
    .fix-btn { background: #4ade80; color: #000; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
    pre { background: #0d0d1a; padding: 15px; border-radius: 8px; overflow-x: auto; }
</style>";

// ═══════════════════════════════════════════════════════════════════════════════
// 1. VERIFICAR TABELAS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>1️⃣ Tabelas do Banco de Dados</h2>";
echo "<table>";
echo "<tr><th>Tabela</th><th>Status</th><th>Registros</th></tr>";

$required_tables = [
    'om_market_orders' => 'Pedidos do mercado',
    'om_market_order_items' => 'Itens dos pedidos',
    'om_market_shoppers' => 'Shoppers cadastrados',
    'om_market_deliveries' => 'Entregadores cadastrados',
    'om_shopper_offers' => 'Ofertas para shoppers',
    'om_delivery_offers' => 'Ofertas para delivery',
    'om_notifications' => 'Notificações',
    'om_payments_pending' => 'Pagamentos pendentes',
    'om_order_timeline' => 'Timeline do pedido',
    'om_market_chat' => 'Chat do pedido'
];

foreach ($required_tables as $table => $desc) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "<tr><td>$table<br><small>$desc</small></td>";
        echo "<td class='ok'>✅ Existe</td>";
        echo "<td>$count</td></tr>";
        $checks[$table] = true;
    } catch (Exception $e) {
        echo "<tr><td>$table<br><small>$desc</small></td>";
        echo "<td class='error'>❌ Não existe</td>";
        echo "<td>-</td></tr>";
        $checks[$table] = false;
        $fixes_needed[] = "Criar tabela $table";
    }
}
echo "</table>";

// ═══════════════════════════════════════════════════════════════════════════════
// 2. VERIFICAR COLUNAS IMPORTANTES
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>2️⃣ Colunas Importantes</h2>";
echo "<table>";
echo "<tr><th>Tabela.Coluna</th><th>Status</th><th>Uso</th></tr>";

$required_columns = [
    'om_market_shoppers.can_deliver' => 'Define se shopper também entrega',
    'om_market_shoppers.is_online' => 'Status online do shopper',
    'om_market_shoppers.current_lat' => 'Latitude atual',
    'om_market_shoppers.current_lng' => 'Longitude atual',
    'om_market_orders.matching_status' => 'Status do matching',
    'om_market_orders.matching_wave' => 'Wave atual',
    'om_market_orders.shopper_id' => 'ID do shopper atribuído',
    'om_market_orders.delivery_id' => 'ID do delivery atribuído',
    'om_market_orders.shopper_earning' => 'Ganho do shopper',
    'om_market_orders.delivery_earning' => 'Ganho do delivery',
];

foreach ($required_columns as $col => $desc) {
    list($table, $column) = explode('.', $col);
    try {
        $result = $pdo->query("SHOW COLUMNS FROM $table LIKE '$column'")->fetch();
        if ($result) {
            echo "<tr><td>$col</td><td class='ok'>✅ Existe</td><td>$desc</td></tr>";
            $checks[$col] = true;
        } else {
            echo "<tr><td>$col</td><td class='error'>❌ Não existe</td><td>$desc</td></tr>";
            $checks[$col] = false;
            $fixes_needed[] = "Adicionar coluna $column em $table";
        }
    } catch (Exception $e) {
        echo "<tr><td>$col</td><td class='warn'>⚠️ Erro</td><td>" . $e->getMessage() . "</td></tr>";
    }
}
echo "</table>";

// ═══════════════════════════════════════════════════════════════════════════════
// 3. VERIFICAR DADOS DE TESTE
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>3️⃣ Dados de Operação</h2>";
echo "<table>";
echo "<tr><th>Item</th><th>Quantidade</th><th>Status</th></tr>";

// Shoppers online
$online_shoppers = $pdo->query("SELECT COUNT(*) FROM om_market_shoppers WHERE is_online = 1 AND status = '1'")->fetchColumn();
echo "<tr><td>Shoppers Online</td><td>$online_shoppers</td>";
echo "<td class='" . ($online_shoppers > 0 ? 'ok' : 'warn') . "'>" . ($online_shoppers > 0 ? '✅ OK' : '⚠️ Nenhum') . "</td></tr>";

// Shoppers que fazem delivery
$can_deliver = $pdo->query("SELECT COUNT(*) FROM om_market_shoppers WHERE can_deliver = 1")->fetchColumn();
echo "<tr><td>Shoppers que também entregam</td><td>$can_deliver</td>";
echo "<td class='" . ($can_deliver > 0 ? 'ok' : 'warn') . "'>" . ($can_deliver > 0 ? '✅ OK' : '⚠️ Nenhum') . "</td></tr>";

// Deliveries online
try {
    $online_deliveries = $pdo->query("SELECT COUNT(*) FROM om_market_deliveries WHERE is_online = 1")->fetchColumn();
    echo "<tr><td>Deliveries Online</td><td>$online_deliveries</td>";
    echo "<td class='" . ($online_deliveries > 0 ? 'ok' : 'warn') . "'>" . ($online_deliveries > 0 ? '✅ OK' : '⚠️ Nenhum') . "</td></tr>";
} catch (Exception $e) {
    echo "<tr><td>Deliveries Online</td><td>-</td><td class='warn'>⚠️ Tabela não existe</td></tr>";
}

// Parceiros ativos
$partners = $pdo->query("SELECT COUNT(*) FROM om_market_partners WHERE status = '1'")->fetchColumn();
echo "<tr><td>Parceiros (Mercados) Ativos</td><td>$partners</td>";
echo "<td class='" . ($partners > 0 ? 'ok' : 'error') . "'>" . ($partners > 0 ? '✅ OK' : '❌ Nenhum') . "</td></tr>";

// Ofertas pendentes
$pending_offers = $pdo->query("SELECT COUNT(*) FROM om_shopper_offers WHERE status = 'pending'")->fetchColumn();
echo "<tr><td>Ofertas Pendentes</td><td>$pending_offers</td>";
echo "<td class='ok'>ℹ️ Info</td></tr>";

// Pedidos aguardando
$pending_orders = $pdo->query("SELECT COUNT(*) FROM om_market_orders WHERE status = 'pending' AND matching_status = 'searching'")->fetchColumn();
echo "<tr><td>Pedidos Aguardando Shopper</td><td>$pending_orders</td>";
echo "<td class='ok'>ℹ️ Info</td></tr>";

echo "</table>";

// ═══════════════════════════════════════════════════════════════════════════════
// 4. VERIFICAR ARQUIVOS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>4️⃣ Arquivos do Sistema</h2>";
echo "<table>";
echo "<tr><th>Arquivo</th><th>Status</th><th>Função</th></tr>";

$base_path = __DIR__;
if (strpos($base_path, 'mercado') === false) {
    $base_path = '/home/cliente/public_html/mercado';
}

$required_files = [
    'webhook/pagarme.php' => 'Webhook que recebe pagamentos',
    'shopper/index.php' => 'Tela principal do shopper',
    'shopper/api/accept.php' => 'API para aceitar pedidos',
    'delivery/index.php' => 'Tela principal do delivery',
    'cron_waves_robust.php' => 'Cron de processamento de waves',
    'api/matching.php' => 'API de matching'
];

foreach ($required_files as $file => $desc) {
    $full_path = $base_path . '/' . $file;
    $exists = file_exists($full_path);
    echo "<tr><td>$file</td>";
    echo "<td class='" . ($exists ? 'ok' : 'error') . "'>" . ($exists ? '✅ Existe' : '❌ Não encontrado') . "</td>";
    echo "<td>$desc</td></tr>";
    if (!$exists) {
        $fixes_needed[] = "Criar arquivo $file";
    }
}
echo "</table>";

// ═══════════════════════════════════════════════════════════════════════════════
// 5. FLUXO DO SISTEMA
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>5️⃣ Fluxo do Sistema</h2>";
echo "<pre style='color: #4ade80;'>
┌─────────────────────────────────────────────────────────────────────────────────┐
│  FLUXO COMPLETO DO PEDIDO                                                       │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                 │
│  1. 💳 PAGAMENTO                                                                │
│     Cliente paga → Pagar.me envia webhook → webhook/pagarme.php                 │
│     ↓                                                                           │
│  2. 📦 PEDIDO CRIADO                                                            │
│     Cria em om_market_orders → status='pending' → matching_status='searching'  │
│     ↓                                                                           │
│  3. 🎯 MATCHING (dispararMatching)                                              │
│     Wave 1: Busca Shopper+Delivery próximos → Cria oferta → Notifica           │
│     ↓                                                                           │
│  4. ⏱️ AGUARDANDO (60 segundos)                                                 │
│     Shopper vê oferta no app → Pode aceitar ou ignorar                         │
│     ↓                                                                           │
│  5a. ✅ SE ACEITAR                                                              │
│      shopper/api/accept.php → Atribui shopper → status='shopping'              │
│      ↓                                                                           │
│  5b. ⏰ SE EXPIRAR (cron_waves.php)                                             │
│      Wave 2: Aumenta oferta +10% → Notifica mais shoppers                      │
│      Wave 3: Aumenta +15% → Só shoppers (sem delivery)                         │
│      ... até Wave 10 ou alguém aceitar                                         │
│     ↓                                                                           │
│  6. 🛒 SHOPPING                                                                 │
│     Shopper faz compras → Escaneia produtos → Chat com cliente                 │
│     ↓                                                                           │
│  7. 📦 PRONTO (se shopper também entrega)                                       │
│     shopper/api/finish.php → status='delivering' → Vai entregar               │
│     ↓                                                                           │
│  7b. 🔄 HANDOFF (se shopper NÃO entrega)                                        │
│     Dispara matching para Delivery → Delivery coleta → Entrega                 │
│     ↓                                                                           │
│  8. 🚚 ENTREGA                                                                  │
│     Delivery vai até cliente → Confirma código → status='delivered'            │
│     ↓                                                                           │
│  9. ⭐ AVALIAÇÃO                                                                │
│     Cliente avalia → Rating atualizado → Ganhos creditados                     │
│                                                                                 │
└─────────────────────────────────────────────────────────────────────────────────┘
</pre>";

// ═══════════════════════════════════════════════════════════════════════════════
// 6. CORREÇÕES NECESSÁRIAS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>6️⃣ Correções Necessárias</h2>";

if (empty($fixes_needed)) {
    echo "<p class='ok'>✅ Nenhuma correção necessária! Sistema pronto.</p>";
} else {
    echo "<ul>";
    foreach ($fixes_needed as $fix) {
        echo "<li class='warn'>⚠️ $fix</li>";
    }
    echo "</ul>";
    
    echo "<form method='post'>";
    echo "<button type='submit' name='apply_fixes' class='fix-btn'>🔧 Aplicar Correções Automáticas</button>";
    echo "</form>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// APLICAR CORREÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['apply_fixes'])) {
    echo "<h2>🔧 Aplicando Correções...</h2>";
    
    // Adicionar colunas faltantes em om_market_orders
    $order_columns = [
        'shopper_earning' => 'DECIMAL(10,2) DEFAULT 0',
        'delivery_earning' => 'DECIMAL(10,2) DEFAULT 0',
        'matching_status' => "VARCHAR(20) DEFAULT 'pending'",
        'matching_wave' => 'INT DEFAULT 0',
        'matching_started_at' => 'DATETIME NULL'
    ];
    
    foreach ($order_columns as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE om_market_orders ADD COLUMN $col $def");
            echo "<p class='ok'>✅ Adicionado: om_market_orders.$col</p>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "<p>ℹ️ Já existe: om_market_orders.$col</p>";
            } else {
                echo "<p class='error'>❌ Erro: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    // Adicionar can_deliver em shoppers
    try {
        $pdo->exec("ALTER TABLE om_market_shoppers ADD COLUMN can_deliver TINYINT(1) DEFAULT 0");
        echo "<p class='ok'>✅ Adicionado: om_market_shoppers.can_deliver</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "<p>ℹ️ Já existe: om_market_shoppers.can_deliver</p>";
        }
    }
    
    echo "<p><a href='?' style='color: #4ade80;'>🔄 Verificar novamente</a></p>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// 7. CONFIGURAÇÕES ATUAIS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>7️⃣ Configurações do Motor de Matching</h2>";
echo "<table>";
echo "<tr><th>Configuração</th><th>Valor</th></tr>";
echo "<tr><td>Shopper Base</td><td>R$ 6,00</td></tr>";
echo "<tr><td>Shopper %</td><td>+ 2% do pedido</td></tr>";
echo "<tr><td>Delivery Base</td><td>R$ 4,00</td></tr>";
echo "<tr><td>Delivery por km</td><td>+ R$ 1,20/km</td></tr>";
echo "<tr><td>Tempo por Wave</td><td>60 segundos</td></tr>";
echo "<tr><td>Bônus Wave 2</td><td>+10%</td></tr>";
echo "<tr><td>Bônus Wave 3</td><td>+15%</td></tr>";
echo "<tr><td>Bônus Wave 4</td><td>+20%</td></tr>";
echo "<tr><td>Bônus Wave 5+</td><td>+25%, +30%...</td></tr>";
echo "<tr><td>TETO Máximo</td><td>20% do pedido</td></tr>";
echo "</table>";

echo "<br><br><p style='color:#666;'>Checklist gerado em: " . date('d/m/Y H:i:s') . "</p>";
