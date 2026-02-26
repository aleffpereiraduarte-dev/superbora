<?php
require_once __DIR__ . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  📱 ANÁLISE DOS APPS DE WORKER - ONEMUNDO MERCADO                            ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

echo "<style>
body { font-family: 'Segoe UI', Arial; background: #0a0a1a; color: #fff; padding: 30px; }
h1, h2, h3 { color: #4ade80; }
.box { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 20px; margin: 20px 0; }
table { width: 100%; border-collapse: collapse; margin: 15px 0; }
th, td { border: 1px solid #333; padding: 12px; text-align: left; }
th { background: #333; }
.ok { color: #4ade80; }
.warn { color: #fbbf24; }
.error { color: #f87171; }
.purple { color: #a78bfa; }
.blue { color: #60a5fa; }
pre { background: #1a1a2e; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
a { color: #4ade80; }
</style>";

echo "<h1>📱 Análise dos Apps de Worker</h1>";

// ═══════════════════════════════════════════════════════════════════════════════
// ESTRUTURA DOS APPS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='box'>";
echo "<h2>🏗️ Estrutura Encontrada</h2>";
echo "<pre>
/mercado/
├── 📁 <span class='purple'>trabalhe-conosco/</span> ← APP PRINCIPAL DOS WORKERS
│   ├── login.php          → Login (tabela: om_workers)
│   ├── cadastro.php       → Cadastro multi-step (shopper/delivery/fullservice)
│   ├── app.php            → Dashboard principal
│   ├── shopping.php       → Tela de compras
│   ├── delivery.php       → Tela de entrega
│   ├── navegacao.php      → GPS/Navegação
│   ├── chat.php           → Chat com cliente
│   ├── ganhos.php         → Histórico de ganhos
│   ├── agenda.php         → Agenda/disponibilidade
│   ├── carteira.php       → Saldo e saques
│   ├── 📁 api/            → APIs do worker
│   │   ├── accept-offer.php
│   │   ├── toggle-online.php
│   │   ├── update-location.php
│   │   └── ...
│   └── ...
│
├── 📁 <span class='blue'>shopper/</span> ← APP ALTERNATIVO (mais simples)
│   ├── login.php          → Login (tabela: om_market_shoppers)
│   ├── index.php          → Dashboard
│   ├── compras.php        → Fazer compras
│   └── ...
│
└── 📁 <span class='blue'>delivery/</span> ← APP SÓ DELIVERY
    ├── login.php          → Login (tabela: om_market_deliveries)
    ├── index.php          → Dashboard
    ├── ofertas.php        → Ver ofertas
    └── ...
</pre>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// DIFERENÇAS ENTRE OS SISTEMAS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='box'>";
echo "<h2>⚖️ Comparação dos Sistemas</h2>";
echo "<table>";
echo "<tr>
        <th>Característica</th>
        <th class='purple'>/trabalhe-conosco/</th>
        <th class='blue'>/shopper/</th>
        <th class='blue'>/delivery/</th>
      </tr>";

$comparisons = [
    ['Tabela de Login', 'om_workers', 'om_market_shoppers', 'om_market_deliveries'],
    ['Session Key', 'worker_id', 'shopper_id', 'delivery_id'],
    ['Cadastro Integrado', '✅ Sim (3 tipos)', '❌ Não', '❌ Não'],
    ['Shopper+Delivery', '✅ fullservice', '❌ Separado', '❌ Separado'],
    ['Design', '⭐ Ultra moderno', '🔹 Funcional', '🔹 Funcional'],
    ['Onboarding', '✅ Multi-step', '❌ Simples', '❌ Simples'],
    ['Verificação Facial', '✅ Sim', '❌ Não', '❌ Não'],
    ['Mapa de Calor', '✅ Sim', '❌ Não', '❌ Não'],
    ['Desafios/Gamificação', '✅ Sim', '❌ Não', '❌ Não'],
];

foreach ($comparisons as $row) {
    echo "<tr><td>{$row[0]}</td><td>{$row[1]}</td><td>{$row[2]}</td><td>{$row[3]}</td></tr>";
}
echo "</table>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// TABELAS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='box'>";
echo "<h2>🗄️ Tabelas de Workers</h2>";

// om_workers
echo "<h3>1️⃣ om_workers (trabalhe-conosco)</h3>";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM om_workers")->fetchColumn();
    $aprovados = $pdo->query("SELECT COUNT(*) FROM om_workers WHERE status = 'aprovado'")->fetchColumn();
    echo "<p class='ok'>✅ Tabela existe - $count registros ($aprovados aprovados)</p>";
    
    $cols = $pdo->query("DESCRIBE om_workers")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p><small>Colunas: " . implode(", ", array_slice($cols, 0, 10)) . "...</small></p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Tabela não existe</p>";
}

// om_market_shoppers
echo "<h3>2️⃣ om_market_shoppers (/shopper/)</h3>";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM om_market_shoppers")->fetchColumn();
    $online = $pdo->query("SELECT COUNT(*) FROM om_market_shoppers WHERE is_online = 1")->fetchColumn();
    echo "<p class='ok'>✅ Tabela existe - $count registros ($online online)</p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Tabela não existe</p>";
}

// om_market_deliveries
echo "<h3>3️⃣ om_market_deliveries (/delivery/)</h3>";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM om_market_deliveries")->fetchColumn();
    $online = $pdo->query("SELECT COUNT(*) FROM om_market_deliveries WHERE is_online = 1")->fetchColumn();
    echo "<p class='ok'>✅ Tabela existe - $count registros ($online online)</p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Tabela não existe</p>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// PROBLEMA IDENTIFICADO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='box' style='border-color: #f59e0b;'>";
echo "<h2 class='warn'>⚠️ Problema Identificado</h2>";
echo "<pre style='color: #fbbf24;'>
EXISTEM 2 SISTEMAS PARALELOS:

1️⃣ /trabalhe-conosco/ → Usa tabela om_workers
   - Mais completo e moderno
   - Cadastro unificado (Shopper/Delivery/FullService)
   - Mas as OFERTAS do webhook vão para om_shopper_offers

2️⃣ /shopper/ + /delivery/ → Usam om_market_shoppers e om_market_deliveries
   - Mais simples
   - É onde o webhook ENVIA as ofertas!
   
O webhook pagarme_v4.php está criando ofertas para om_market_shoppers,
mas o app /trabalhe-conosco/ busca ofertas para om_workers!
</pre>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// SOLUÇÃO PROPOSTA
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='box' style='border-color: #4ade80;'>";
echo "<h2 class='ok'>✅ Soluções Possíveis</h2>";
echo "<table>";
echo "<tr><th>Opção</th><th>Descrição</th><th>Esforço</th></tr>";
echo "<tr>
        <td><strong>A) Unificar para /trabalhe-conosco/</strong></td>
        <td>Migrar tudo para usar om_workers + om_worker_offers</td>
        <td class='warn'>⚠️ Médio - Precisa ajustar webhook</td>
      </tr>";
echo "<tr>
        <td><strong>B) Usar /shopper/ + /delivery/</strong></td>
        <td>Já está funcionando com o webhook! Só testar</td>
        <td class='ok'>✅ Baixo - Já pronto</td>
      </tr>";
echo "<tr>
        <td><strong>C) Sincronizar Tabelas</strong></td>
        <td>Criar trigger para sincronizar om_workers ↔ om_market_shoppers</td>
        <td class='error'>❌ Alto - Complexo</td>
      </tr>";
echo "</table>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// RECOMENDAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='box' style='border-color: #4ade80; background: rgba(74, 222, 128, 0.1);'>";
echo "<h2 class='ok'>💡 Recomendação</h2>";
echo "<pre style='color: #4ade80;'>
OPÇÃO B - Usar /shopper/ + /delivery/ existentes

Por quê?
✅ Webhook já envia ofertas para om_market_shoppers
✅ Tabelas já têm can_deliver, is_online, etc
✅ Menos mudanças = menos riscos
✅ Pode melhorar o design depois

Fluxo atual funciona:
1. Pagamento → Webhook → Cria oferta em om_shopper_offers
2. Shopper acessa /mercado/shopper/ → Vê ofertas → Aceita
3. Faz compras → Entrega (se can_deliver=1)

O /trabalhe-conosco/ pode ser usado DEPOIS para:
- Cadastro bonito de novos workers
- Após aprovação, criar registro em om_market_shoppers
</pre>";
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// LINKS ÚTEIS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='box'>";
echo "<h2>🔗 Links para Testar</h2>";
echo "<table>";
echo "<tr><th>App</th><th>URL</th><th>Status</th></tr>";
echo "<tr><td>Trabalhe Conosco - Cadastro</td><td><a href='/mercado/trabalhe-conosco/cadastro.php'>/trabalhe-conosco/cadastro.php</a></td><td>Para novos workers</td></tr>";
echo "<tr><td>Trabalhe Conosco - Login</td><td><a href='/mercado/trabalhe-conosco/login.php'>/trabalhe-conosco/login.php</a></td><td>Usa om_workers</td></tr>";
echo "<tr><td>Shopper - Login</td><td><a href='/mercado/shopper/login.php'>/shopper/login.php</a></td><td class='ok'>✅ Usa om_market_shoppers</td></tr>";
echo "<tr><td>Delivery - Login</td><td><a href='/mercado/delivery/login.php'>/delivery/login.php</a></td><td>Usa om_market_deliveries</td></tr>";
echo "</table>";
echo "</div>";

echo "<br><p style='color:#666;'>Análise gerada em: " . date('d/m/Y H:i:s') . "</p>";
