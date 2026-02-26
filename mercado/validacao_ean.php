<?php
require_once __DIR__ . '/config/database.php';
/**
 * PAINEL DE VALIDAÇÃO DE EAN
 * Gerenciar produtos com EAN inválido ou pendente
 */

error_reporting(0);
date_default_timezone_set('America/Sao_Paulo');

$pdo = getPDO();

// ==================== API ====================
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = $_GET['api'];
    
    // Status geral
    if ($api === 'status') {
        $total = $pdo->query("SELECT COUNT(*) FROM om_market_products_base")->fetchColumn();
        $com_ean = $pdo->query("SELECT COUNT(*) FROM om_market_products_base WHERE barcode IS NOT NULL AND LENGTH(barcode) >= 8")->fetchColumn();
        $validados = $pdo->query("SELECT COUNT(*) FROM om_market_products_base WHERE ai_validated = 1")->fetchColumn();
        $invalidados = $pdo->query("SELECT COUNT(*) FROM om_market_products_base WHERE ai_validated = 2")->fetchColumn();
        $pendentes = $pdo->query("SELECT COUNT(*) FROM om_market_products_base WHERE barcode IS NOT NULL AND LENGTH(barcode) >= 8 AND (ai_validated IS NULL OR ai_validated = 0)")->fetchColumn();
        
        echo json_encode([
            'total' => (int)$total,
            'com_ean' => (int)$com_ean,
            'validados' => (int)$validados,
            'invalidados' => (int)$invalidados,
            'pendentes' => (int)$pendentes
        ]);
        exit;
    }
    
    // Listar produtos inválidos
    if ($api === 'invalidos') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $total = $pdo->query("SELECT COUNT(*) FROM om_market_products_base WHERE ai_validated = 2")->fetchColumn();
        
        $produtos = $pdo->query("SELECT product_id, name, brand, barcode, image, description, suggested_price, ai_confidence
            FROM om_market_products_base WHERE ai_validated = 2 
            ORDER BY product_id DESC LIMIT $limit OFFSET $offset")->fetchAll();
        
        echo json_encode([
            'produtos' => $produtos,
            'total' => (int)$total,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]);
        exit;
    }
    
    // Listar pendentes
    if ($api === 'pendentes') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $total = $pdo->query("SELECT COUNT(*) FROM om_market_products_base WHERE barcode IS NOT NULL AND LENGTH(barcode) >= 8 AND (ai_validated IS NULL OR ai_validated = 0)")->fetchColumn();
        
        $produtos = $pdo->query("SELECT product_id, name, brand, barcode, image, description, suggested_price
            FROM om_market_products_base 
            WHERE barcode IS NOT NULL AND LENGTH(barcode) >= 8 AND (ai_validated IS NULL OR ai_validated = 0)
            ORDER BY product_id DESC LIMIT $limit OFFSET $offset")->fetchAll();
        
        echo json_encode([
            'produtos' => $produtos,
            'total' => (int)$total,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]);
        exit;
    }
    
    // Buscar EAN correto
    if ($api === 'buscar_ean') {
        $id = (int)($_GET['id'] ?? 0);
        $prod = $pdo->query("SELECT name, brand FROM om_market_products_base WHERE product_id = $id")->fetch();
        
        if (!$prod) {
            echo json_encode(['success' => false, 'error' => 'Produto não encontrado']);
            exit;
        }
        
        $busca = $prod['name'] . ' ' . ($prod['brand'] ?? '');
        $busca = urlencode($busca);
        
        // Buscar no Serper
        $ch = curl_init('https://google.serper.dev/shopping');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: 4782ed433bf6ca6177a0f74e3bbc1cd1cbb2a731',
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'q' => $prod['name'] . ' ' . ($prod['brand'] ?? ''),
                'gl' => 'br',
                'hl' => 'pt-br',
                'num' => 10
            ])
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        $resultados = [];
        
        if (!empty($data['shopping'])) {
            foreach ($data['shopping'] as $item) {
                $ean = null;
                $texto = ($item['title'] ?? '') . ' ' . ($item['link'] ?? '');
                if (preg_match('/\b(789\d{10})\b/', $texto, $m)) {
                    $ean = $m[1];
                }
                
                $resultados[] = [
                    'titulo' => $item['title'] ?? '',
                    'preco' => $item['price'] ?? '',
                    'fonte' => $item['source'] ?? '',
                    'imagem' => $item['imageUrl'] ?? $item['thumbnail'] ?? '',
                    'link' => $item['link'] ?? '',
                    'ean' => $ean
                ];
            }
        }
        
        echo json_encode(['success' => true, 'resultados' => $resultados]);
        exit;
    }
    
    // Salvar correção
    if ($api === 'salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['product_id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID inválido']);
            exit;
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = $data['name'];
        }
        if (isset($data['brand'])) {
            $updates[] = "brand = ?";
            $params[] = $data['brand'];
        }
        if (isset($data['barcode'])) {
            $updates[] = "barcode = ?";
            $params[] = $data['barcode'];
        }
        if (isset($data['image'])) {
            $updates[] = "image = ?";
            $params[] = $data['image'];
        }
        if (isset($data['ai_validated'])) {
            $updates[] = "ai_validated = ?";
            $params[] = $data['ai_validated'];
        }
        
        if (!empty($updates)) {
            $updates[] = "date_modified = NOW()";
            $params[] = $id;
            $sql = "UPDATE om_market_products_base SET " . implode(", ", $updates) . " WHERE product_id = ?";
            $pdo->prepare($sql)->execute($params);
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Limpar EAN (para rebuscar)
    if ($api === 'limpar_ean' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['product_id'] ?? 0);
        
        if ($id > 0) {
            $pdo->prepare("UPDATE om_market_products_base SET barcode = NULL, ai_validated = 0, date_modified = NOW() WHERE product_id = ?")
                ->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }
    
    // Excluir produto
    if ($api === 'excluir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['product_id'] ?? 0);
        
        if ($id > 0) {
            $pdo->prepare("DELETE FROM om_market_products_base WHERE product_id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }
    
    // Aprovar manualmente
    if ($api === 'aprovar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['product_id'] ?? 0);
        
        if ($id > 0) {
            $pdo->prepare("UPDATE om_market_products_base SET ai_validated = 1, ai_confidence = 100, date_modified = NOW() WHERE product_id = ?")
                ->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🔍 Validação de EAN - OneMundo</title>
<style>
:root{--bg:#0a0f1a;--card:#141d2b;--text:#e2e8f0;--muted:#64748b;--green:#10b981;--yellow:#f59e0b;--red:#ef4444;--blue:#3b82f6;--cyan:#06b6d4;--purple:#8b5cf6;--border:#1e293b}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding:20px}
.container{max-width:1400px;margin:0 auto}
h1{color:var(--cyan);margin-bottom:20px;display:flex;align-items:center;gap:10px}
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:15px;margin-bottom:30px}
.stat{background:var(--card);padding:20px;border-radius:12px;text-align:center;border:1px solid var(--border)}
.stat-value{font-size:28px;font-weight:700;color:var(--cyan)}
.stat-value.green{color:var(--green)}
.stat-value.red{color:var(--red)}
.stat-value.yellow{color:var(--yellow)}
.stat-label{font-size:12px;color:var(--muted);margin-top:5px}
.tabs{display:flex;gap:10px;margin-bottom:20px}
.tab{padding:12px 24px;background:var(--card);border:1px solid var(--border);border-radius:8px;cursor:pointer;font-weight:500}
.tab:hover{border-color:var(--cyan)}
.tab.active{background:var(--cyan);color:#000;border-color:var(--cyan)}
.produtos{display:grid;gap:15px}
.produto{background:var(--card);border-radius:12px;padding:20px;border:1px solid var(--border);display:grid;grid-template-columns:100px 1fr auto;gap:20px;align-items:center}
.produto img{width:100px;height:100px;object-fit:contain;background:#fff;border-radius:8px}
.produto-placeholder{width:100px;height:100px;background:var(--bg);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:30px}
.produto-info h3{font-size:16px;margin-bottom:8px}
.produto-info p{font-size:13px;color:var(--muted);margin-bottom:4px}
.produto-info .ean{font-family:monospace;background:var(--bg);padding:4px 8px;border-radius:4px;font-size:12px}
.produto-info .ean.invalid{color:var(--red);border:1px solid var(--red)}
.produto-actions{display:flex;flex-direction:column;gap:8px}
.btn{padding:10px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:12px;display:flex;align-items:center;gap:6px;justify-content:center;transition:all .2s}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.btn-green{background:var(--green);color:#fff}
.btn-red{background:var(--red);color:#fff}
.btn-yellow{background:var(--yellow);color:#000}
.btn-blue{background:var(--blue);color:#fff}
.btn-cyan{background:var(--cyan);color:#000}
.paginacao{display:flex;gap:8px;justify-content:center;margin-top:20px}
.paginacao button{background:var(--card);border:1px solid var(--border);color:var(--text);padding:8px 16px;border-radius:6px;cursor:pointer}
.paginacao button:hover{border-color:var(--cyan)}
.paginacao button.active{background:var(--cyan);color:#000}
.paginacao button:disabled{opacity:.5;cursor:not-allowed}
.modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.8);z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal.active{display:flex}
.modal-content{background:var(--card);border-radius:16px;max-width:900px;width:100%;max-height:90vh;overflow-y:auto;border:1px solid var(--border)}
.modal-header{padding:20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-header h3{color:var(--cyan)}
.modal-close{background:none;border:none;color:var(--muted);font-size:24px;cursor:pointer}
.modal-body{padding:20px}
.resultado{background:var(--bg);border-radius:8px;padding:15px;margin-bottom:10px;display:grid;grid-template-columns:80px 1fr auto;gap:15px;align-items:center;cursor:pointer;border:2px solid transparent}
.resultado:hover{border-color:var(--cyan)}
.resultado img{width:80px;height:80px;object-fit:contain;background:#fff;border-radius:6px}
.resultado-info h4{font-size:14px;margin-bottom:5px}
.resultado-info p{font-size:12px;color:var(--muted)}
.resultado-info .ean-found{color:var(--green);font-family:monospace}
.form-group{margin-bottom:15px}
.form-group label{display:block;font-size:12px;color:var(--muted);margin-bottom:5px}
.form-group input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:12px;border-radius:8px}
.empty{text-align:center;padding:50px;color:var(--muted)}
.loading{text-align:center;padding:50px}
.loading::after{content:'';display:inline-block;width:30px;height:30px;border:3px solid var(--border);border-top-color:var(--cyan);border-radius:50%;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:768px){
    .stats{grid-template-columns:repeat(2,1fr)}
    .produto{grid-template-columns:1fr}
    .produto img,.produto-placeholder{margin:0 auto}
}
</style>
</head>
<body>

<div class="container">
    <h1>🔍 Validação de EAN <a href="/central.php" style="font-size:14px;color:var(--muted);text-decoration:none;margin-left:20px">← Voltar Central</a></h1>
    
    <!-- Stats -->
    <div class="stats">
        <div class="stat">
            <div class="stat-value" id="s-total">-</div>
            <div class="stat-label">Total Produtos</div>
        </div>
        <div class="stat">
            <div class="stat-value" id="s-com-ean">-</div>
            <div class="stat-label">Com EAN</div>
        </div>
        <div class="stat">
            <div class="stat-value green" id="s-validados">-</div>
            <div class="stat-label">✅ Validados</div>
        </div>
        <div class="stat">
            <div class="stat-value red" id="s-invalidos">-</div>
            <div class="stat-label">❌ Inválidos</div>
        </div>
        <div class="stat">
            <div class="stat-value yellow" id="s-pendentes">-</div>
            <div class="stat-label">⏳ Pendentes</div>
        </div>
    </div>
    
    <!-- Tabs -->
    <div class="tabs">
        <div class="tab active" onclick="showTab('invalidos')">❌ Inválidos</div>
        <div class="tab" onclick="showTab('pendentes')">⏳ Pendentes</div>
    </div>
    
    <!-- Lista -->
    <div class="produtos" id="lista"></div>
    
    <!-- Paginação -->
    <div class="paginacao" id="paginacao"></div>
</div>

<!-- Modal Buscar EAN -->
<div class="modal" id="modal-buscar">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🔍 Buscar EAN Correto</h3>
            <button class="modal-close" onclick="fecharModal('modal-buscar')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="produto-atual" style="background:var(--bg);padding:15px;border-radius:8px;margin-bottom:20px">
                <strong id="pa-nome"></strong>
                <p style="color:var(--muted);font-size:13px" id="pa-marca"></p>
                <p style="color:var(--red);font-family:monospace" id="pa-ean-atual"></p>
            </div>
            
            <h4 style="margin-bottom:15px">Resultados encontrados:</h4>
            <div id="resultados-busca">
                <div class="loading"></div>
            </div>
            
            <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
                <h4 style="margin-bottom:15px">Ou insira manualmente:</h4>
                <div class="form-group">
                    <label>Código de Barras (EAN)</label>
                    <input type="text" id="ean-manual" placeholder="7891234567890">
                </div>
                <div class="form-group">
                    <label>URL da Imagem</label>
                    <input type="text" id="img-manual" placeholder="https://...">
                </div>
                <button class="btn btn-green" onclick="salvarManual()" style="width:100%">💾 Salvar e Validar</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentTab = 'invalidos';
let currentPage = 1;
let currentProductId = null;

async function carregarStats() {
    const r = await fetch('?api=status');
    const d = await r.json();
    
    document.getElementById('s-total').textContent = d.total.toLocaleString();
    document.getElementById('s-com-ean').textContent = d.com_ean.toLocaleString();
    document.getElementById('s-validados').textContent = d.validados.toLocaleString();
    document.getElementById('s-invalidos').textContent = d.invalidados.toLocaleString();
    document.getElementById('s-pendentes').textContent = d.pendentes.toLocaleString();
}

async function carregarLista(page = 1) {
    currentPage = page;
    const lista = document.getElementById('lista');
    lista.innerHTML = '<div class="loading"></div>';
    
    const r = await fetch(`?api=${currentTab}&page=${page}`);
    const d = await r.json();
    
    if (d.produtos.length === 0) {
        lista.innerHTML = '<div class="empty">Nenhum produto encontrado</div>';
        document.getElementById('paginacao').innerHTML = '';
        return;
    }
    
    lista.innerHTML = d.produtos.map(p => `
        <div class="produto">
            ${p.image ? `<img src="${p.image.startsWith('http') ? p.image : '/image/' + p.image}" onerror="this.outerHTML='<div class=produto-placeholder>📦</div>'">` : '<div class="produto-placeholder">📦</div>'}
            <div class="produto-info">
                <h3>${escapeHtml(p.name)}</h3>
                <p>Marca: ${p.brand || 'N/A'} | Preço: ${p.suggested_price ? 'R$ ' + parseFloat(p.suggested_price).toFixed(2) : 'N/A'}</p>
                <p>EAN: <span class="ean ${currentTab === 'invalidos' ? 'invalid' : ''}">${p.barcode || 'N/A'}</span></p>
                ${p.description ? `<p style="margin-top:8px;font-size:12px">${escapeHtml(p.description.substring(0, 100))}...</p>` : ''}
            </div>
            <div class="produto-actions">
                <button class="btn btn-cyan" onclick="buscarEAN(${p.product_id}, '${escapeHtml(p.name)}', '${escapeHtml(p.brand || '')}', '${p.barcode || ''}')">🔍 Buscar EAN</button>
                <button class="btn btn-green" onclick="aprovar(${p.product_id})">✅ Aprovar</button>
                <button class="btn btn-yellow" onclick="limparEAN(${p.product_id})">🔄 Limpar EAN</button>
                <button class="btn btn-red" onclick="excluir(${p.product_id})">🗑️ Excluir</button>
            </div>
        </div>
    `).join('');
    
    // Paginação
    renderPaginacao(d.page, d.pages);
}

function renderPaginacao(current, total) {
    const el = document.getElementById('paginacao');
    if (total <= 1) { el.innerHTML = ''; return; }
    
    let html = `<button onclick="carregarLista(1)" ${current === 1 ? 'disabled' : ''}>«</button>`;
    html += `<button onclick="carregarLista(${current - 1})" ${current === 1 ? 'disabled' : ''}>‹</button>`;
    
    for (let i = Math.max(1, current - 2); i <= Math.min(total, current + 2); i++) {
        html += `<button onclick="carregarLista(${i})" class="${i === current ? 'active' : ''}">${i}</button>`;
    }
    
    html += `<button onclick="carregarLista(${current + 1})" ${current === total ? 'disabled' : ''}>›</button>`;
    html += `<button onclick="carregarLista(${total})" ${current === total ? 'disabled' : ''}>»</button>`;
    
    el.innerHTML = html;
}

function showTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.tab[onclick="showTab('${tab}')"]`).classList.add('active');
    carregarLista(1);
}

async function buscarEAN(id, nome, marca, eanAtual) {
    currentProductId = id;
    
    document.getElementById('pa-nome').textContent = nome;
    document.getElementById('pa-marca').textContent = marca || 'Sem marca';
    document.getElementById('pa-ean-atual').textContent = 'EAN atual: ' + (eanAtual || 'N/A');
    document.getElementById('resultados-busca').innerHTML = '<div class="loading"></div>';
    document.getElementById('ean-manual').value = '';
    document.getElementById('img-manual').value = '';
    
    document.getElementById('modal-buscar').classList.add('active');
    
    const r = await fetch(`?api=buscar_ean&id=${id}`);
    const d = await r.json();
    
    if (d.resultados && d.resultados.length > 0) {
        document.getElementById('resultados-busca').innerHTML = d.resultados.map(res => `
            <div class="resultado" onclick="selecionarResultado('${res.ean || ''}', '${res.imagem || ''}')">
                ${res.imagem ? `<img src="${res.imagem}" onerror="this.style.display='none'">` : '<div style="width:80px"></div>'}
                <div class="resultado-info">
                    <h4>${escapeHtml(res.titulo)}</h4>
                    <p>${res.fonte} - ${res.preco}</p>
                    ${res.ean ? `<p class="ean-found">EAN: ${res.ean}</p>` : '<p style="color:var(--muted)">EAN não encontrado</p>'}
                </div>
                ${res.ean ? '<span style="color:var(--green);font-size:20px">→</span>' : ''}
            </div>
        `).join('');
    } else {
        document.getElementById('resultados-busca').innerHTML = '<div class="empty">Nenhum resultado encontrado</div>';
    }
}

function selecionarResultado(ean, imagem) {
    if (ean) document.getElementById('ean-manual').value = ean;
    if (imagem) document.getElementById('img-manual').value = imagem;
}

async function salvarManual() {
    const ean = document.getElementById('ean-manual').value.trim();
    const img = document.getElementById('img-manual').value.trim();
    
    if (!ean && !img) {
        alert('Informe pelo menos o EAN ou a imagem');
        return;
    }
    
    const data = { product_id: currentProductId, ai_validated: 1 };
    if (ean) data.barcode = ean;
    if (img) data.image = img;
    
    const r = await fetch('?api=salvar', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    });
    
    const d = await r.json();
    if (d.success) {
        alert('✅ Produto salvo e validado!');
        fecharModal('modal-buscar');
        carregarLista(currentPage);
        carregarStats();
    }
}

async function aprovar(id) {
    if (!confirm('Aprovar este produto como está?')) return;
    
    const r = await fetch('?api=aprovar', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ product_id: id })
    });
    
    const d = await r.json();
    if (d.success) {
        carregarLista(currentPage);
        carregarStats();
    }
}

async function limparEAN(id) {
    if (!confirm('Limpar EAN? O sistema irá buscar um novo automaticamente.')) return;
    
    const r = await fetch('?api=limpar_ean', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ product_id: id })
    });
    
    const d = await r.json();
    if (d.success) {
        carregarLista(currentPage);
        carregarStats();
    }
}

async function excluir(id) {
    if (!confirm('⚠️ Excluir este produto permanentemente?')) return;
    
    const r = await fetch('?api=excluir', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ product_id: id })
    });
    
    const d = await r.json();
    if (d.success) {
        carregarLista(currentPage);
        carregarStats();
    }
}

function fecharModal(id) {
    document.getElementById(id).classList.remove('active');
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// Fechar modal ao clicar fora
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) fecharModal(m.id); });
});

// Inicializar
carregarStats();
carregarLista();
setInterval(carregarStats, 30000);
</script>

</body>
</html>
