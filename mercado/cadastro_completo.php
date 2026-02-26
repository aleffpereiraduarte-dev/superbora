<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🏪 CADASTRO COMPLETO - Mercado Central GV (Partner 100)
 * Preenche TODOS os campos necessários para teste
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<style>
body{font-family:'Segoe UI',sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;padding:30px;min-height:100vh}
h1{color:#22c55e;text-align:center;margin-bottom:10px}
h2{color:#38bdf8;margin:30px 0 15px;border-bottom:1px solid #334155;padding-bottom:10px}
.subtitle{text-align:center;color:#64748b;margin-bottom:30px}
.box{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:16px;padding:25px;margin:20px 0}
.ok{color:#22c55e}.err{color:#ef4444}.warn{color:#f59e0b}
pre{background:#000;padding:15px;border-radius:10px;overflow-x:auto;font-size:12px}
table{width:100%;border-collapse:collapse;margin:15px 0}
th,td{padding:12px;text-align:left;border-bottom:1px solid rgba(255,255,255,0.1)}
th{background:rgba(56,189,248,0.1);color:#38bdf8}
.btn{padding:18px 35px;border:none;border-radius:12px;font-size:16px;font-weight:600;cursor:pointer;margin:10px 5px;transition:all 0.3s}
.btn-success{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff}
.btn-primary{background:linear-gradient(135deg,#38bdf8,#0ea5e9);color:#fff}
.btn:hover{transform:translateY(-2px);box-shadow:0 5px 20px rgba(0,0,0,0.3)}
.credential{background:rgba(34,197,94,0.1);border:2px solid #22c55e;border-radius:12px;padding:25px;margin:20px 0}
.credential h3{color:#22c55e;margin:0 0 15px}
.credential p{margin:10px 0;font-size:16px}
.credential code{background:#000;padding:8px 15px;border-radius:6px;color:#f59e0b;font-size:18px;display:inline-block}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:15px}
.field{background:rgba(0,0,0,0.2);padding:12px;border-radius:8px}
.field label{color:#94a3b8;font-size:12px;display:block;margin-bottom:5px}
.field span{color:#fff;font-size:14px}
</style>";

echo "<h1>🏪 Cadastro Completo</h1>";
echo "<p class='subtitle'>Mercado Central GV - Partner 100</p>";

// ═══════════════════════════════════════════════════════════════════════════════
// 1. VER ESTRUTURA ATUAL
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>1. Estrutura da Tabela om_market_partners</h2>";
echo "<div class='box'>";

$colunas = $pdo->query("SHOW COLUMNS FROM om_market_partners")->fetchAll(PDO::FETCH_ASSOC);
echo "<p>Total de colunas: <strong>" . count($colunas) . "</strong></p>";

// Agrupar colunas por categoria
$grupos = [
    'Identificação' => ['partner_id', 'code', 'name', 'trade_name', 'document', 'cnpj'],
    'Endereço' => ['address', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'cep', 'latitude', 'longitude', 'lat', 'lng', 'endereco_completo'],
    'Contato' => ['phone', 'whatsapp', 'email', 'contact_name'],
    'Login' => ['login_email', 'login_password', 'login_token', 'last_login'],
    'Entrega' => ['delivery_radius_km', 'delivery_radius', 'raio_entrega_km', 'delivery_time_min', 'delivery_time_max', 'delivery_fee', 'min_order_value', 'min_order', 'free_delivery_min', 'free_delivery_above', 'cep_coverage', 'cep_inicio', 'cep_fim'],
    'Horário' => ['opens_at', 'closes_at', 'open_time', 'close_time', 'is_open', 'open_sunday', 'sunday_opens_at', 'sunday_closes_at', 'opening_hours'],
    'Financeiro' => ['commission_rate', 'commission_type', 'partnership_type', 'pagarme_recipient_id', 'partner_discount', 'partner_discount_type', 'accepts_pix', 'accepts_card', 'min_payout', 'payout_frequency'],
    'Status' => ['status', 'verified', 'featured', 'is_featured', 'integration_status'],
    'Mídia' => ['logo', 'banner', 'description', 'slug'],
];

$cols_list = array_column($colunas, 'Field');
echo "<details><summary style='cursor:pointer;color:#38bdf8'>Ver todas as colunas</summary><pre>" . implode(', ', $cols_list) . "</pre></details>";

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 2. DADOS ATUAIS DO PARTNER 100
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>2. Dados Atuais do Partner 100</h2>";
echo "<div class='box'>";

$partner = $pdo->query("SELECT * FROM om_market_partners WHERE partner_id = 100")->fetch(PDO::FETCH_ASSOC);

if ($partner) {
    echo "<p class='ok'>✅ Partner 100 existe</p>";
    
    // Mostrar campos importantes
    $campos_importantes = ['name', 'email', 'login_email', 'login_password', 'address', 'city', 'state', 'cep', 'phone', 'status'];
    echo "<table><tr><th>Campo</th><th>Valor</th></tr>";
    foreach ($campos_importantes as $campo) {
        if (isset($partner[$campo])) {
            $valor = $partner[$campo];
            if (strpos($campo, 'password') !== false && $valor) {
                $valor = '***' . substr($valor, -10);
            }
            $class = $valor ? 'ok' : 'err';
            echo "<tr><td>$campo</td><td class='$class'>" . ($valor ?: '<em>VAZIO</em>') . "</td></tr>";
        }
    }
    echo "</table>";
} else {
    echo "<p class='err'>❌ Partner 100 não existe - será criado!</p>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 3. FORMULÁRIO DE CADASTRO COMPLETO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>3. 🔧 Cadastro Completo</h2>";
echo "<div class='box'>";

// Dados padrão para o Mercado Central GV
$dados = [
    // Identificação
    'partner_id' => 100,
    'code' => 'MCGV100',
    'name' => 'Mercado Central GV',
    'trade_name' => 'Mercado Central Governador Valadares',
    'cnpj' => '12.345.678/0001-90',
    'document' => '12345678000190',
    
    // Endereço (Centro de Governador Valadares)
    'address' => 'Rua Marechal Floriano',
    'street' => 'Rua Marechal Floriano',
    'number' => '1000',
    'complement' => 'Loja 1',
    'neighborhood' => 'Centro',
    'city' => 'Governador Valadares',
    'state' => 'MG',
    'cep' => '35010-140',
    'latitude' => '-18.8512',
    'longitude' => '-41.9455',
    'lat' => '-18.8512',
    'lng' => '-41.9455',
    'endereco_completo' => 'Rua Marechal Floriano, 1000 - Centro, Governador Valadares - MG, 35010-140',
    
    // Contato
    'phone' => '(33) 3271-1000',
    'whatsapp' => '33991001000',
    'email' => 'mercado@onemundo.com.br',
    'contact_name' => 'Gerente OneMundo',
    
    // Login
    'login_email' => 'mercado@onemundo.com.br',
    'login_password' => password_hash('mercado123', PASSWORD_DEFAULT),
    
    // Entrega
    'delivery_radius_km' => 10,
    'delivery_radius' => 10,
    'raio_entrega_km' => 10,
    'delivery_time_min' => 25,
    'delivery_time_max' => 45,
    'delivery_fee' => 5.99,
    'min_order_value' => 30.00,
    'min_order' => 30.00,
    'free_delivery_min' => 100.00,
    'free_delivery_above' => 100.00,
    'cep_inicio' => '35000000',
    'cep_fim' => '35099999',
    
    // Horário
    'opens_at' => '07:00:00',
    'closes_at' => '22:00:00',
    'open_time' => '07:00:00',
    'close_time' => '22:00:00',
    'is_open' => 1,
    'open_sunday' => 1,
    'sunday_opens_at' => '08:00:00',
    'sunday_closes_at' => '20:00:00',
    
    // Financeiro
    'commission_rate' => 10.00,
    'partnership_type' => 'standard',
    'accepts_pix' => 1,
    'accepts_card' => 1,
    
    // Status
    'status' => 1,
    'verified' => 1,
    'featured' => 1,
    'is_featured' => 1,
    'integration_status' => 'online',
    
    // Mídia
    'slug' => 'mercado-central-gv',
    'description' => 'O melhor mercado de Governador Valadares! Oferecemos produtos frescos, preços justos e entrega rápida.',
];

echo "<form method='post'>";
echo "<button type='submit' name='cadastrar' class='btn btn-success' style='font-size:20px;padding:25px 50px'>✅ CADASTRAR/ATUALIZAR PARTNER 100</button>";
echo "</form>";

echo "<p style='color:#64748b;margin-top:15px'>Isso vai criar/atualizar o cadastro com todos os dados necessários para funcionamento completo.</p>";

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// EXECUTAR CADASTRO
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['cadastrar'])) {
    echo "<h2>4. 📝 Executando Cadastro...</h2>";
    echo "<div class='box'>";
    
    try {
        // Verificar quais colunas existem
        $cols_existentes = array_column($colunas, 'Field');
        
        // Filtrar apenas colunas que existem
        $dados_filtrados = [];
        foreach ($dados as $col => $valor) {
            if (in_array($col, $cols_existentes)) {
                $dados_filtrados[$col] = $valor;
            }
        }
        
        // Verificar se partner 100 existe
        $existe = $pdo->query("SELECT partner_id FROM om_market_partners WHERE partner_id = 100")->fetch();
        
        if ($existe) {
            // UPDATE
            $sets = [];
            $params = [];
            foreach ($dados_filtrados as $col => $valor) {
                if ($col != 'partner_id') {
                    $sets[] = "$col = ?";
                    $params[] = $valor;
                }
            }
            $params[] = 100;
            
            $sql = "UPDATE om_market_partners SET " . implode(', ', $sets) . " WHERE partner_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo "<p class='ok'>✅ Partner 100 ATUALIZADO!</p>";
        } else {
            // INSERT
            $cols = array_keys($dados_filtrados);
            $placeholders = array_fill(0, count($cols), '?');
            
            $sql = "INSERT INTO om_market_partners (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($dados_filtrados));
            
            echo "<p class='ok'>✅ Partner 100 CRIADO!</p>";
        }
        
        echo "<p class='ok'>✅ Campos atualizados: <strong>" . count($dados_filtrados) . "</strong></p>";
        
        // Mostrar credenciais
        echo "<div class='credential'>";
        echo "<h3>🔐 CREDENCIAIS DE ACESSO</h3>";
        echo "<p>📧 Email: <code>mercado@onemundo.com.br</code></p>";
        echo "<p>🔑 Senha: <code>mercado123</code></p>";
        echo "<p>🔗 URL: <code>https://onemundo.com.br/mercado/painel/login.php</code></p>";
        echo "</div>";
        
        // Resumo dos dados
        echo "<h3 style='color:#38bdf8;margin-top:20px'>📋 Dados Cadastrados:</h3>";
        echo "<div class='grid'>";
        
        $resumo = [
            'Nome' => $dados['name'],
            'CNPJ' => $dados['cnpj'],
            'Cidade' => $dados['city'] . '/' . $dados['state'],
            'CEP' => $dados['cep'],
            'Telefone' => $dados['phone'],
            'WhatsApp' => $dados['whatsapp'],
            'Horário' => $dados['opens_at'] . ' - ' . $dados['closes_at'],
            'Taxa Entrega' => 'R$ ' . number_format($dados['delivery_fee'], 2, ',', '.'),
            'Pedido Mínimo' => 'R$ ' . number_format($dados['min_order'], 2, ',', '.'),
            'Frete Grátis' => 'Acima de R$ ' . number_format($dados['free_delivery_above'], 2, ',', '.'),
            'Raio Entrega' => $dados['delivery_radius_km'] . ' km',
            'Comissão' => $dados['commission_rate'] . '%',
        ];
        
        foreach ($resumo as $label => $valor) {
            echo "<div class='field'><label>$label</label><span>$valor</span></div>";
        }
        
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<p class='err'>❌ Erro: " . $e->getMessage() . "</p>";
    }
    
    echo "</div>";
}

// ═══════════════════════════════════════════════════════════════════════════════
// 5. TESTAR LOGIN
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>5. 🧪 Testar Login</h2>";
echo "<div class='box'>";

echo "<form method='post' style='display:flex;gap:10px;align-items:center;flex-wrap:wrap'>";
echo "<input type='text' name='test_email' value='mercado@onemundo.com.br' style='padding:12px;width:250px;background:#0f172a;border:1px solid #334155;color:#fff;border-radius:8px'>";
echo "<input type='text' name='test_senha' value='mercado123' style='padding:12px;width:150px;background:#0f172a;border:1px solid #334155;color:#fff;border-radius:8px'>";
echo "<button type='submit' name='testar_login' class='btn btn-primary'>🧪 Testar</button>";
echo "</form>";

if (isset($_POST['testar_login'])) {
    $email = $_POST['test_email'];
    $senha = $_POST['test_senha'];
    
    echo "<div style='margin-top:15px;padding:15px;background:rgba(0,0,0,0.3);border-radius:10px'>";
    
    // Simular login exatamente como o painel faz
    $stmt = $pdo->prepare("SELECT * FROM om_market_partners WHERE (email = ? OR login_email = ?)");
    $stmt->execute([$email, $email]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($p) {
        echo "<p class='ok'>✅ Parceiro encontrado: <strong>{$p['name']}</strong></p>";
        echo "<p>Partner ID: {$p['partner_id']}</p>";
        echo "<p>Status: {$p['status']} " . ($p['status'] == 1 ? '(ativo)' : '(inativo)') . "</p>";
        
        // Testar senha
        $senha_ok = false;
        if (!empty($p['login_password']) && password_verify($senha, $p['login_password'])) {
            $senha_ok = true;
            echo "<p class='ok'>✅ Senha correta (login_password)</p>";
        } else {
            echo "<p class='err'>❌ Senha incorreta</p>";
        }
        
        if ($senha_ok && $p['status'] == 1) {
            echo "<p class='ok' style='font-size:18px;margin-top:10px'>✅ LOGIN OK! Pode acessar o painel.</p>";
        }
    } else {
        echo "<p class='err'>❌ Email não encontrado</p>";
    }
    
    echo "</div>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 6. LINKS
// ═══════════════════════════════════════════════════════════════════════════════

echo "<h2>6. 🔗 Links</h2>";
echo "<div class='box'>";
echo "<a href='/mercado/painel/login.php' class='btn btn-success' style='text-decoration:none'>🔐 Acessar Painel</a> ";
echo "<a href='/mercado/' class='btn btn-primary' style='text-decoration:none'>🛒 Ver Mercado</a>";
echo "</div>";
?>
