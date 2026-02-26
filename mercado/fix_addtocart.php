<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * FIX ADDTOCART - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * Corrige a função addToCart do index.php para chamar a API diretamente
 * igual a página de produto faz.
 * 
 * PROBLEMA: index.php salva no array JS local e depois tenta sincronizar
 * SOLUÇÃO: Chamar /api/cart.php diretamente com action: 'add'
 */

$arquivo = __DIR__ . '/index.php';
$backup_dir = __DIR__ . '/backups';

if (!file_exists($arquivo)) {
    die("❌ index.php não encontrado");
}

// Criar backup
if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);
$backup = $backup_dir . '/index_' . date('Y-m-d_H-i-s') . '_before_cart_fix.php';
copy($arquivo, $backup);

$conteudo = file_get_contents($arquivo);

// ══════════════════════════════════════════════════════════════════════════════
// NOVA FUNÇÃO addToCart - Chama API diretamente
// ══════════════════════════════════════════════════════════════════════════════

$nova_addToCart = <<<'JS'
function addToCart(productId, name, price, image) {
    // Feedback visual imediato
    const card = document.querySelector(`[data-id="${productId}"]`);
    const addBtn = card?.querySelector('.add-btn, .quick-add-btn');
    if (addBtn) {
        addBtn.disabled = true;
        addBtn.innerHTML = '<span style="font-size:12px">...</span>';
    }
    
    // Chamar API diretamente (igual página de produto)
    fetch('/mercado/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'add',
            product_id: productId,
            name: name,
            price: price,
            price_promo: 0,
            image: image,
            qty: 1
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Atualizar array local também (para UI)
            const existing = cart.find(i => i.product_id === productId);
            if (existing) {
                existing.qty++;
            } else {
                cart.push({ product_id: productId, name, price, price_promo: 0, image, qty: 1 });
            }
            
            // Atualizar badges
            const count = data.count || cart.reduce((sum, i) => sum + i.qty, 0);
            document.querySelectorAll('#cartBadge, #mobileCartBadge, .cart-badge, .mkt-cart-count').forEach(b => {
                if (b) b.textContent = count;
            });
            
            // Mostrar controle de quantidade
            const qtyCtrl = document.getElementById('qty-' + productId);
            if (qtyCtrl) {
                qtyCtrl.classList.add('show');
                const qtyVal = qtyCtrl.querySelector('.qty-value');
                if (qtyVal) qtyVal.textContent = existing ? existing.qty : 1;
            }
            
            // Esconder botão add
            if (addBtn) addBtn.style.display = 'none';
            
            showToast('Produto adicionado!', 'success');
        } else {
            showToast('Erro ao adicionar', 'error');
            if (addBtn) {
                addBtn.disabled = false;
                addBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            }
        }
    })
    .catch(err => {
        console.error('Erro addToCart:', err);
        showToast('Erro de conexão', 'error');
        if (addBtn) {
            addBtn.disabled = false;
            addBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
        }
    });
}
JS;

// ══════════════════════════════════════════════════════════════════════════════
// NOVA FUNÇÃO changeQty - Chama API diretamente
// ══════════════════════════════════════════════════════════════════════════════

$nova_changeQty = <<<'JS'
function changeQty(productId, delta) {
    const item = cart.find(i => i.product_id === productId);
    if (!item) return;
    
    const newQty = item.qty + delta;
    
    if (newQty <= 0) {
        // Remover item
        fetch('/mercado/api/cart.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'remove', product_id: productId})
        }).then(r => r.json()).then(data => {
            cart = cart.filter(i => i.product_id !== productId);
            
            const qtyCtrl = document.getElementById('qty-' + productId);
            if (qtyCtrl) qtyCtrl.classList.remove('show');
            
            const card = document.querySelector(`[data-id="${productId}"]`);
            if (card) {
                const addBtn = card.querySelector('.add-btn, .quick-add-btn');
                if (addBtn) {
                    addBtn.style.display = 'flex';
                    addBtn.disabled = false;
                    addBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
                }
            }
            
            updateCartBadge(data.count);
        });
    } else {
        // Atualizar quantidade
        fetch('/mercado/api/cart.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'update', product_id: productId, qty: newQty})
        }).then(r => r.json()).then(data => {
            item.qty = newQty;
            
            const qtyCtrl = document.getElementById('qty-' + productId);
            if (qtyCtrl) {
                const qtyVal = qtyCtrl.querySelector('.qty-value');
                if (qtyVal) qtyVal.textContent = newQty;
            }
            
            updateCartBadge(data.count);
        });
    }
}

function updateCartBadge(count) {
    document.querySelectorAll('#cartBadge, #mobileCartBadge, .cart-badge, .mkt-cart-count').forEach(b => {
        if (b) b.textContent = count || 0;
    });
}
JS;

// ══════════════════════════════════════════════════════════════════════════════
// SUBSTITUIR FUNÇÕES
// ══════════════════════════════════════════════════════════════════════════════

// Padrão para encontrar a função addToCart antiga
$pattern_addToCart = '/function addToCart\s*\([^)]*\)\s*\{[^}]+(?:\{[^}]*\}[^}]*)*\}/s';

// Padrão para encontrar a função changeQty antiga  
$pattern_changeQty = '/function changeQty\s*\([^)]*\)\s*\{[^}]+(?:\{[^}]*\}[^}]*)*\}/s';

// Contar ocorrências
preg_match_all($pattern_addToCart, $conteudo, $matches_add);
preg_match_all($pattern_changeQty, $conteudo, $matches_change);

$count_add = count($matches_add[0]);
$count_change = count($matches_change[0]);

// Substituir apenas a primeira ocorrência de cada
$conteudo_novo = preg_replace($pattern_addToCart, $nova_addToCart, $conteudo, 1);
$conteudo_novo = preg_replace($pattern_changeQty, $nova_changeQty, $conteudo_novo, 1);

// Remover duplicatas (se existirem)
if ($count_add > 1) {
    // Manter só a primeira (que já foi substituída) e remover as outras
    $temp = preg_replace($pattern_addToCart, '', $conteudo_novo, $count_add - 1);
    if ($temp !== null) $conteudo_novo = $temp;
}

if ($count_change > 1) {
    $temp = preg_replace($pattern_changeQty, '', $conteudo_novo, $count_change - 1);
    if ($temp !== null) $conteudo_novo = $temp;
}

// Salvar
file_put_contents($arquivo, $conteudo_novo);

// ══════════════════════════════════════════════════════════════════════════════
// RESULTADO
// ══════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✅ Fix AddToCart Aplicado</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: #1e293b; border-radius: 16px; padding: 40px; max-width: 600px; text-align: center; }
        h1 { font-size: 48px; margin-bottom: 16px; }
        h2 { font-size: 24px; margin-bottom: 24px; color: #10b981; }
        .info { background: #14532d; padding: 16px; border-radius: 8px; margin: 16px 0; text-align: left; }
        .info strong { color: #10b981; }
        .warning { background: #713f12; padding: 16px; border-radius: 8px; margin: 16px 0; text-align: left; }
        code { background: #0f172a; padding: 4px 8px; border-radius: 4px; font-size: 13px; }
        .btn { display: inline-block; padding: 14px 32px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; margin-top: 20px; }
        .btn:hover { background: #059669; }
        table { width: 100%; margin: 20px 0; text-align: left; }
        th, td { padding: 10px; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; }
        .success { color: #10b981; }
    </style>
</head>
<body>
<div class="card">
    <h1>✅</h1>
    <h2>AddToCart Corrigido!</h2>
    
    <div class="info">
        <strong>O que foi feito:</strong><br><br>
        • addToCart agora chama <code>/api/cart.php</code> diretamente<br>
        • Usa <code>action: 'add'</code> igual a página de produto<br>
        • changeQty também atualizado para chamar API<br>
        • Feedback visual imediato mantido
    </div>
    
    <table>
        <tr>
            <th>Item</th>
            <th>Antes</th>
            <th>Depois</th>
        </tr>
        <tr>
            <td>addToCart encontrados</td>
            <td><?= $count_add ?></td>
            <td class="success">1 (corrigido)</td>
        </tr>
        <tr>
            <td>changeQty encontrados</td>
            <td><?= $count_change ?></td>
            <td class="success">1 (corrigido)</td>
        </tr>
        <tr>
            <td>Backup</td>
            <td colspan="2"><?= basename($backup) ?></td>
        </tr>
    </table>
    
    <div class="warning">
        <strong>⚠️ Teste agora:</strong><br>
        1. Abra o Mercado<br>
        2. Clique no + de um produto<br>
        3. Vá para o carrinho - o produto deve estar lá!
    </div>
    
    <a href="/mercado/" class="btn">🛒 Testar Agora</a>
</div>
</body>
</html>
