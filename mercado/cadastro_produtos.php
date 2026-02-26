<?php
require_once __DIR__ . '/config/database.php';
/**
 * 📦 CADASTRO DE PRODUTOS - MERCADO
 * Ferramenta para adicionar produtos em mercados específicos
 */

session_name('OCSESSID');
session_start();

error_reporting(0);

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';
$msg = '';
$erro = '';

// ═══════════════════════════════════════════════════════════════════════════
// LISTAR MERCADOS
// ═══════════════════════════════════════════════════════════════════════════
$mercados = $pdo->query("SELECT partner_id, name, city FROM om_market_partners WHERE status = '1' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════
// LISTAR PRODUTOS DO MERCADO SELECIONADO
// ═══════════════════════════════════════════════════════════════════════════
$partner_id = $_GET['mercado'] ?? $_POST['partner_id'] ?? '';
$produtos = [];
$mercado_nome = '';

if ($partner_id) {
    $stmt = $pdo->prepare("SELECT name FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partner_id]);
    $mercado_nome = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_products WHERE partner_id = ? ORDER BY category, name");
    $stmt->execute([$partner_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ═══════════════════════════════════════════════════════════════════════════
// CADASTRAR PRODUTO
// ═══════════════════════════════════════════════════════════════════════════
if ($acao === 'cadastrar') {
    $partner_id = $_POST['partner_id'];
    $name = trim($_POST['name']);
    $category = trim($_POST['category']);
    $price = floatval($_POST['price']);
    $price_promo = floatval($_POST['price_promo'] ?? 0);
    $stock = intval($_POST['stock'] ?? 100);
    $description = trim($_POST['description'] ?? '');
    $ean = trim($_POST['ean'] ?? '');
    $image = trim($_POST['image'] ?? '');
    
    if ($name && $price > 0) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO om_market_products (partner_id, name, category, price, price_promo, stock, description, ean, image, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$partner_id, $name, $category, $price, $price_promo, $stock, $description, $ean, $image]);
            $msg = "✅ Produto '$name' cadastrado com sucesso!";
        } catch (Exception $e) {
            $erro = "❌ Erro: " . $e->getMessage();
        }
    } else {
        $erro = "❌ Nome e preço são obrigatórios!";
    }
    
    // Recarregar produtos
    $stmt = $pdo->prepare("SELECT * FROM om_market_products WHERE partner_id = ? ORDER BY category, name");
    $stmt->execute([$partner_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ═══════════════════════════════════════════════════════════════════════════
// CADASTRAR EM LOTE
// ═══════════════════════════════════════════════════════════════════════════
if ($acao === 'lote') {
    $partner_id = $_POST['partner_id'];
    $dados = trim($_POST['dados_lote']);
    
    $linhas = explode("\n", $dados);
    $sucesso = 0;
    $erros = 0;
    
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if (empty($linha)) continue;
        
        // Formato: Nome | Categoria | Preço | Estoque
        $partes = array_map('trim', explode('|', $linha));
        
        if (count($partes) >= 3) {
            $name = $partes[0];
            $category = $partes[1];
            $price = floatval(str_replace(',', '.', $partes[2]));
            $stock = isset($partes[3]) ? intval($partes[3]) : 100;
            
            if ($name && $price > 0) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO om_market_products (partner_id, name, category, price, stock, status, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
                    $stmt->execute([$partner_id, $name, $category, $price, $stock]);
                    $sucesso++;
                } catch (Exception $e) {
                    $erros++;
                }
            }
        }
    }
    
    $msg = "✅ Cadastrados: $sucesso | Erros: $erros";
    
    // Recarregar
    $stmt = $pdo->prepare("SELECT * FROM om_market_products WHERE partner_id = ? ORDER BY category, name");
    $stmt->execute([$partner_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ═══════════════════════════════════════════════════════════════════════════
// EXCLUIR PRODUTO
// ═══════════════════════════════════════════════════════════════════════════
if ($acao === 'excluir') {
    $id = $_POST['id'];
    $pdo->prepare("DELETE FROM om_market_products WHERE id = ?")->execute([$id]);
    $msg = "🗑️ Produto excluído!";
    
    $stmt = $pdo->prepare("SELECT * FROM om_market_products WHERE partner_id = ? ORDER BY category, name");
    $stmt->execute([$partner_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ═══════════════════════════════════════════════════════════════════════════
// PRODUTOS SUGERIDOS (SUPERMERCADO)
// ═══════════════════════════════════════════════════════════════════════════
$produtos_sugeridos = [
    'Laticínios' => [
        ['Leite Integral 1L', 5.49],
        ['Leite Desnatado 1L', 5.89],
        ['Leite Zero Lactose 1L', 7.49],
        ['Queijo Mussarela kg', 45.90],
        ['Queijo Prato kg', 49.90],
        ['Requeijão 200g', 8.90],
        ['Manteiga 200g', 12.90],
        ['Iogurte Natural 170g', 3.49],
        ['Iogurte Grego 100g', 4.99],
        ['Creme de Leite 200g', 4.29],
    ],
    'Padaria' => [
        ['Pão Francês kg', 14.90],
        ['Pão de Forma 500g', 7.90],
        ['Pão Integral 400g', 9.90],
        ['Bisnaguinha 300g', 6.90],
        ['Bolo Chocolate fatia', 5.90],
        ['Croissant unid', 4.50],
    ],
    'Bebidas' => [
        ['Coca-Cola 2L', 10.90],
        ['Coca-Cola Lata 350ml', 4.50],
        ['Guaraná Antarctica 2L', 8.90],
        ['Água Mineral 1.5L', 3.49],
        ['Água Mineral 500ml', 2.49],
        ['Suco Del Valle 1L', 7.90],
        ['Cerveja Skol Lata', 3.99],
        ['Cerveja Heineken Lata', 5.99],
    ],
    'Mercearia' => [
        ['Arroz Tipo 1 5kg', 27.90],
        ['Arroz Integral 1kg', 8.90],
        ['Feijão Carioca 1kg', 8.49],
        ['Feijão Preto 1kg', 7.99],
        ['Açúcar Refinado 1kg', 5.49],
        ['Açúcar Cristal 5kg', 22.90],
        ['Café Pilão 500g', 18.90],
        ['Café 3 Corações 500g', 19.90],
        ['Óleo de Soja 900ml', 7.90],
        ['Azeite Extra Virgem 500ml', 29.90],
        ['Sal Refinado 1kg', 2.99],
        ['Macarrão Espaguete 500g', 4.49],
        ['Macarrão Parafuso 500g', 5.49],
        ['Molho de Tomate 340g', 3.29],
        ['Extrato de Tomate 340g', 5.49],
    ],
    'Hortifruti' => [
        ['Banana Prata kg', 6.90],
        ['Maçã Fuji kg', 12.90],
        ['Laranja Pera kg', 5.90],
        ['Tomate kg', 8.90],
        ['Cebola kg', 5.90],
        ['Batata kg', 6.90],
        ['Alface unid', 3.49],
        ['Cenoura kg', 7.90],
    ],
    'Carnes' => [
        ['Frango Inteiro kg', 14.90],
        ['Peito de Frango kg', 19.90],
        ['Coxa e Sobrecoxa kg', 16.90],
        ['Carne Moída kg', 32.90],
        ['Acém kg', 34.90],
        ['Picanha kg', 69.90],
        ['Linguiça Toscana kg', 24.90],
        ['Bacon 200g', 15.90],
    ],
    'Frios' => [
        ['Presunto kg', 34.90],
        ['Mortadela kg', 19.90],
        ['Peito de Peru kg', 49.90],
        ['Salame Italiano kg', 59.90],
    ],
    'Limpeza' => [
        ['Detergente 500ml', 2.49],
        ['Sabão em Pó 1kg', 14.90],
        ['Amaciante 2L', 12.90],
        ['Água Sanitária 2L', 6.90],
        ['Desinfetante 2L', 8.90],
        ['Esponja de Aço 8un', 3.49],
        ['Papel Higiênico 12 rolos', 19.90],
    ],
    'Higiene' => [
        ['Sabonete 90g', 2.49],
        ['Shampoo 400ml', 14.90],
        ['Condicionador 400ml', 14.90],
        ['Creme Dental 90g', 5.90],
        ['Desodorante Roll-on', 12.90],
    ],
    'Congelados' => [
        ['Pizza Congelada', 18.90],
        ['Lasanha Congelada 600g', 22.90],
        ['Hambúrguer 672g', 24.90],
        ['Nuggets 300g', 16.90],
        ['Sorvete 2L', 24.90],
    ],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📦 Cadastro de Produtos</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#0f0f1a;color:#fff;min-height:100vh}
        .header{background:linear-gradient(135deg,rgba(0,200,150,0.2),rgba(0,150,100,0.1));padding:20px;text-align:center;border-bottom:2px solid #00c896}
        .header h1{color:#00c896;font-size:26px}
        .container{max-width:1200px;margin:0 auto;padding:20px}
        .card{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:20px;margin-bottom:15px}
        .card h2{color:#00c896;margin-bottom:15px;font-size:16px}
        select,input,textarea{padding:10px 12px;border:1px solid #333;border-radius:6px;background:#1a1a2e;color:#fff;font-size:14px;width:100%}
        select{cursor:pointer}
        .btn{padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:bold;cursor:pointer;margin:3px}
        .btn-primary{background:#00c896;color:#000}
        .btn-danger{background:#e74c3c;color:#fff}
        .btn-secondary{background:#555;color:#fff}
        .btn:hover{opacity:0.9}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:15px}
        .grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
        .form-group{margin-bottom:12px}
        .form-group label{display:block;color:#888;font-size:12px;margin-bottom:5px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;text-align:left;border-bottom:1px solid rgba(255,255,255,0.1)}
        th{color:#00c896;font-size:11px;text-transform:uppercase}
        .badge{display:inline-block;padding:3px 8px;border-radius:10px;font-size:11px}
        .badge-ok{background:#00c896;color:#000}
        .badge-off{background:#e74c3c;color:#fff}
        .msg{padding:12px;border-radius:8px;margin-bottom:15px}
        .msg-ok{background:rgba(0,200,150,0.2);border:1px solid #00c896}
        .msg-err{background:rgba(231,76,60,0.2);border:1px solid #e74c3c}
        .sugestoes{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
        .sugestao{background:rgba(0,200,150,0.1);border:1px solid rgba(0,200,150,0.3);padding:6px 12px;border-radius:20px;font-size:12px;cursor:pointer}
        .sugestao:hover{background:rgba(0,200,150,0.3)}
        .tabs{display:flex;gap:5px;margin-bottom:15px;flex-wrap:wrap}
        .tab{padding:8px 16px;background:#222;border-radius:20px;cursor:pointer;font-size:12px}
        .tab.active{background:#00c896;color:#000}
        .stats{display:flex;gap:20px;margin-bottom:15px}
        .stat{text-align:center}
        .stat-val{font-size:24px;font-weight:bold;color:#00c896}
        .stat-lbl{font-size:11px;color:#888}
    </style>
</head>
<body>

<div class="header">
    <h1>📦 Cadastro de Produtos - Mercado</h1>
</div>

<div class="container">
    <?php if ($msg): ?>
        <div class="msg msg-ok"><?= $msg ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="msg msg-err"><?= $erro ?></div>
    <?php endif; ?>
    
    <!-- SELECIONAR MERCADO -->
    <div class="card">
        <h2>🏪 Selecionar Mercado</h2>
        <form method="get">
            <select name="mercado" onchange="this.form.submit()">
                <option value="">-- Selecione o mercado --</option>
                <?php foreach ($mercados as $m): ?>
                    <option value="<?= $m['partner_id'] ?>" <?= $partner_id == $m['partner_id'] ? 'selected' : '' ?>>
                        <?= $m['name'] ?> (<?= $m['city'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    
    <?php if ($partner_id): ?>
    
    <!-- STATS -->
    <div class="stats">
        <div class="stat">
            <div class="stat-val"><?= count($produtos) ?></div>
            <div class="stat-lbl">Produtos</div>
        </div>
        <div class="stat">
            <div class="stat-val"><?= count(array_unique(array_column($produtos, 'category'))) ?></div>
            <div class="stat-lbl">Categorias</div>
        </div>
    </div>
    
    <div class="grid-2">
        <!-- CADASTRO INDIVIDUAL -->
        <div class="card">
            <h2>➕ Cadastrar Produto</h2>
            <form method="post">
                <input type="hidden" name="acao" value="cadastrar">
                <input type="hidden" name="partner_id" value="<?= $partner_id ?>">
                
                <div class="form-group">
                    <label>Nome do Produto *</label>
                    <input type="text" name="name" required placeholder="Ex: Leite Integral 1L">
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label>Categoria *</label>
                        <input type="text" name="category" required placeholder="Ex: Laticínios" id="inputCategoria">
                    </div>
                    <div class="form-group">
                        <label>Preço (R$) *</label>
                        <input type="number" name="price" step="0.01" required placeholder="5.99">
                    </div>
                </div>
                
                <div class="grid-3">
                    <div class="form-group">
                        <label>Preço Promo</label>
                        <input type="number" name="price_promo" step="0.01" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Estoque</label>
                        <input type="number" name="stock" value="100">
                    </div>
                    <div class="form-group">
                        <label>EAN</label>
                        <input type="text" name="ean" placeholder="7891234567890">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Descrição</label>
                    <textarea name="description" rows="2" placeholder="Descrição do produto..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">✅ Cadastrar</button>
            </form>
            
            <p style="color:#888;font-size:11px;margin-top:15px">Categorias sugeridas:</p>
            <div class="sugestoes">
                <?php foreach (array_keys($produtos_sugeridos) as $cat): ?>
                    <span class="sugestao" onclick="document.getElementById('inputCategoria').value='<?= $cat ?>'"><?= $cat ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- CADASTRO EM LOTE -->
        <div class="card">
            <h2>📋 Cadastro em Lote</h2>
            <form method="post">
                <input type="hidden" name="acao" value="lote">
                <input type="hidden" name="partner_id" value="<?= $partner_id ?>">
                
                <div class="form-group">
                    <label>Dados (Nome | Categoria | Preço | Estoque)</label>
                    <textarea name="dados_lote" rows="10" placeholder="Leite Integral 1L | Laticínios | 5.49 | 100
Pão Francês kg | Padaria | 14.90 | 50
Coca-Cola 2L | Bebidas | 10.90 | 80"></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">📥 Importar Lote</button>
            </form>
            
            <p style="color:#888;font-size:11px;margin-top:15px">Produtos sugeridos (clique para adicionar):</p>
            <div class="tabs">
                <?php foreach (array_keys($produtos_sugeridos) as $cat): ?>
                    <span class="tab" onclick="mostrarSugestoes('<?= $cat ?>')"><?= $cat ?></span>
                <?php endforeach; ?>
            </div>
            <div id="listaSugestoes" style="max-height:200px;overflow-y:auto"></div>
        </div>
    </div>
    
    <!-- LISTA DE PRODUTOS -->
    <div class="card">
        <h2>📦 Produtos Cadastrados em <?= $mercado_nome ?> (<?= count($produtos) ?>)</h2>
        
        <?php if (empty($produtos)): ?>
            <p style="color:#888;text-align:center;padding:30px">Nenhum produto cadastrado</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Categoria</th>
                        <th>Preço</th>
                        <th>Estoque</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($produtos as $p): ?>
                    <tr>
                        <td><?= $p['id'] ?></td>
                        <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                        <td><?= htmlspecialchars($p['category'] ?? '-') ?></td>
                        <td>R$ <?= number_format($p['price'], 2, ',', '.') ?></td>
                        <td><?= $p['stock'] ?? 0 ?></td>
                        <td><span class="badge <?= $p['status'] ? 'badge-ok' : 'badge-off' ?>"><?= $p['status'] ? 'Ativo' : 'Inativo' ?></span></td>
                        <td>
                            <form method="post" style="display:inline" onsubmit="return confirm('Excluir?')">
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="partner_id" value="<?= $partner_id ?>">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding:5px 10px;font-size:11px">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <?php endif; ?>
</div>

<script>
const sugestoes = <?= json_encode($produtos_sugeridos) ?>;

function mostrarSugestoes(cat) {
    const lista = sugestoes[cat] || [];
    const textarea = document.querySelector('textarea[name="dados_lote"]');
    
    let html = '<div class="sugestoes">';
    lista.forEach(p => {
        html += `<span class="sugestao" onclick="adicionarProduto('${p[0]}', '${cat}', ${p[1]})">${p[0]} - R$ ${p[1].toFixed(2)}</span>`;
    });
    html += '</div>';
    
    document.getElementById('listaSugestoes').innerHTML = html;
    
    // Marcar tab ativa
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    event.target.classList.add('active');
}

function adicionarProduto(nome, cat, preco) {
    const textarea = document.querySelector('textarea[name="dados_lote"]');
    const linha = `${nome} | ${cat} | ${preco.toFixed(2)} | 100\n`;
    textarea.value += linha;
}
</script>

</body>
</html>
