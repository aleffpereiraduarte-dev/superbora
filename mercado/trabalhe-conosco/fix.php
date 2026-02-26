<?php
/**
 * 🔧 FIX AUTOMÁTICO - LOGIN.PHP
 */
echo "<pre style='background:#1a1a2e;color:#0f0;padding:20px;font-family:monospace;'>";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║           🔧 FIX AUTOMÁTICO - LOGIN.PHP                      ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n\n";

$loginPath = __DIR__ . '/login.php';

if (!file_exists($loginPath)) {
    die("❌ login.php não encontrado em: $loginPath\n");
}

echo "📁 Arquivo: $loginPath\n\n";

// Ler conteúdo atual
$content = file_get_contents($loginPath);

// Backup
$backupPath = __DIR__ . '/login_backup_' . date('YmdHis') . '.php';
file_put_contents($backupPath, $content);
echo "💾 Backup criado: $backupPath\n\n";

// Corrigir: password -> password_hash
$contentNew = str_replace(
    "\$worker['password']",
    "\$worker['password_hash']",
    $content
);

// Verificar se mudou
if ($content === $contentNew) {
    echo "⚠️ Nenhuma alteração necessária (já estava correto ou padrão diferente)\n";
} else {
    file_put_contents($loginPath, $contentNew);
    echo "✅ CORRIGIDO!\n\n";
    echo "   Alterado: \$worker['password']\n";
    echo "   Para:     \$worker['password_hash']\n";
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║              ✅ PRONTO! TENTA LOGAR AGORA                    ║\n";
echo "╠═══════════════════════════════════════════════════════════════╣\n";
echo "║   📧 E-mail:    shopper@teste.com                            ║\n";
echo "║   🔑 Senha:     123456                                       ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";

echo "</pre>";
