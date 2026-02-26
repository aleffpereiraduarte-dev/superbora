<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * FIX ADDTOCART SOBRESCRITO - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 * 
 * PROBLEMA ENCONTRADO:
 * - addToCart original: (productId, name, price, image)
 * - addToCart sobrescrito 3x: (productId, price, pricePromo, name, image, btn)
 * 
 * Os parâmetros estão em ORDEM DIFERENTE, causando:
 * - name recebe a URL da imagem
 * - price recebe o nome
 * - image recebe undefined
 */

$arquivo = __DIR__ . '/index.php';

if (!file_exists($arquivo)) {
    die("❌ index.php não encontrado");
}

// Backup
$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);
$backup = $backup_dir . '/index_' . date('Y-m-d_H-i-s') . '.php';
copy($arquivo, $backup);

$conteudo = file_get_contents($arquivo);
$original_size = strlen($conteudo);
$fixes = [];

// ══════════════════════════════════════════════════════════════════════════════
// CONTAR PROBLEMAS
// ══════════════════════════════════════════════════════════════════════════════

$count_sobrescritas = preg_match_all('/window\.addToCart\s*=\s*function\s*\(productId,\s*price,\s*pricePromo/', $conteudo);
$count_original = preg_match_all('/function addToCart\(productId, name, price, image\)/', $conteudo);

// ══════════════════════════════════════════════════════════════════════════════
// REMOVER SOBRESCRITAS COM ASSINATURA ERRADA
// ══════════════════════════════════════════════════════════════════════════════

// Padrão para encontrar o bloco completo da sobrescrita
$pattern = '/\/\/ Melhorar função addToCart existente\s*\nconst originalAddToCart = window\.addToCart;\s*\nwindow\.addToCart = function\(productId, price, pricePromo, name, image, btn\) \{[^}]+(?:\{[^}]*\}[^}]*)*\};\s*/s';

$conteudo = preg_replace($pattern, '', $conteudo, -1, $c1);
if ($c1 > 0) {
    $fixes[] = "Removido $c1 sobrescrita(s) de addToCart com assinatura errada";
}

// ══════════════════════════════════════════════════════════════════════════════
// LIMPAR CÓDIGO LIXO
// ══════════════════════════════════════════════════════════════════════════════

// Remover }"]`);
$conteudo = preg_replace('/\}"\]\`\);\s*/', '', $conteudo, -1, $c2);
if ($c2 > 0) {
    $fixes[] = "Removido $c2 lixo(s) }\"]\`);";
}

// Limpar múltiplas linhas em branco
$conteudo = preg_replace("/\n{4,}/", "\n\n\n", $conteudo);

// ══════════════════════════════════════════════════════════════════════════════
// VERIFICAR SE addToCart ESTÁ CORRETO
// ══════════════════════════════════════════════════════════════════════════════

// Se não existe addToCart, adicionar
if (strpos($conteudo, 'function addToCart(productId, name, price, image)') === false) {
    $fixes[] = "⚠️ addToCart original não encontrado - precisa adicionar manualmente";
}

// Salvar
file_put_contents($arquivo, $conteudo);
$new_size = strlen($conteudo);

// Verificar resultado
$remaining_sobrescritas = preg_match_all('/window\.addToCart\s*=\s*function/', $conteudo);
$remaining_original = preg_match_all('/function addToCart\(productId, name, price, image\)/', $conteudo);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔧 Fix AddToCart Sobrescrito</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { font-size: 28px; margin-bottom: 24px; text-align: center; }
        .card { background: #1e293b; border-radius: 16px; padding: 24px; margin-bottom: 20px; }
        .card h2 { margin-bottom: 16px; font-size: 18px; color: #94a3b8; }
        .fix { background: #14532d; border-left: 4px solid #10b981; padding: 12px 16px; margin: 8px 0; border-radius: 0 8px 8px 0; }
        .error { background: #7f1d1d; border-left: 4px solid #ef4444; padding: 12px 16px; margin: 8px 0; border-radius: 0 8px 8px 0; }
        .info { background: #1e3a5f; border-left: 4px solid #3b82f6; padding: 12px 16px; margin: 8px 0; border-radius: 0 8px 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #0f172a; }
        .green { color: #10b981; }
        .red { color: #ef4444; }
        .btn { display: inline-block; padding: 16px 32px; background: #10b981; color: white; text-decoration: none; border-radius: 12px; font-weight: 600; margin: 8px; }
        .btn:hover { background: #059669; }
        code { background: #0f172a; padding: 4px 8px; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Fix: AddToCart Sobrescrito</h1>
    
    <div class="card">
        <h2>🔴 Problema Encontrado</h2>
        <div class="error">
            <strong>A função addToCart foi sobrescrita com assinatura DIFERENTE!</strong><br><br>
            <code>Original: addToCart(productId, name, price, image)</code><br>
            <code>Sobrescrita: addToCart(productId, price, pricePromo, name, image, btn)</code><br><br>
            Resultado: O nome do produto recebe a URL da imagem! 🐛
        </div>
    </div>
    
    <div class="card">
        <h2>📊 Estatísticas</h2>
        <table>
            <tr><th>Métrica</th><th>Antes</th><th>Depois</th></tr>
            <tr>
                <td>Tamanho</td>
                <td><?= number_format($original_size / 1024, 1) ?> KB</td>
                <td class="green"><?= number_format($new_size / 1024, 1) ?> KB</td>
            </tr>
            <tr>
                <td>Sobrescritas erradas</td>
                <td class="red"><?= $count_sobrescritas ?></td>
                <td class="green"><?= $remaining_sobrescritas ?></td>
            </tr>
            <tr>
                <td>addToCart original</td>
                <td><?= $count_original ?></td>
                <td class="green"><?= $remaining_original ?></td>
            </tr>
        </table>
    </div>
    
    <div class="card">
        <h2>✅ Correções Aplicadas</h2>
        <?php if (empty($fixes)): ?>
        <div class="info">Nenhuma correção necessária.</div>
        <?php else: ?>
        <?php foreach ($fixes as $fix): ?>
        <div class="fix">✓ <?= htmlspecialchars($fix) ?></div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h2>💾 Backup</h2>
        <div class="info">
            <code><?= basename($backup) ?></code>
        </div>
    </div>
    
    <div class="card">
        <h2>🧪 Teste Agora</h2>
        <div class="info">
            1. Vá para o Mercado<br>
            2. Clique no + de um produto<br>
            3. O produto deve ser adicionado com nome correto!
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 24px;">
        <a href="/mercado/" class="btn">🛒 Testar Mercado</a>
        <a href="/mercado/carrinho/" class="btn" style="background: #3b82f6;">🛍️ Ver Carrinho</a>
        <a href="/mercado/debug_botoes.php" class="btn" style="background: #8b5cf6;">🔧 Debug</a>
    </div>
</div>
</body>
</html>
