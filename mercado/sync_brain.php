<?php
require_once __DIR__ . '/config/database.php';
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * 🔄 SINCRONIZAR BRAIN - GODADDY → VPS (CORRIGIDO)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

set_time_limit(300);
ini_set('memory_limit', '256M');
ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Sync Brain</title>";
echo "<style>body{font-family:Arial;background:#1a1a2e;color:#fff;padding:20px;} .ok{color:#10b981;} .erro{color:#ef4444;}</style></head><body>";
echo "<h1>🔄 Sincronizar Brain</h1>";
flush();

$batch = isset($_GET['batch']) ? (int)$_GET['batch'] : 500;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Conexões
echo "<p>Conectando GoDaddy...</p>"; flush();
try {
    $pdoG = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4", "root", DB_PASSWORD);
    $pdoG->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p class='ok'>✅ GoDaddy OK</p>"; flush();
} catch (Exception $e) {
    die("<p class='erro'>❌ GoDaddy: " . $e->getMessage() . "</p>");
}

echo "<p>Conectando VPS...</p>"; flush();
try {
    $pdoV = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4", "root", DB_PASSWORD);
    $pdoV->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p class='ok'>✅ VPS OK</p>"; flush();
} catch (Exception $e) {
    die("<p class='erro'>❌ VPS: " . $e->getMessage() . "</p>");
}

// Total
$total = $pdoG->query("SELECT COUNT(*) FROM om_one_brain_universal")->fetchColumn();
echo "<p>📊 Total: $total | Batch: $batch | Offset: $offset</p>"; flush();

if ($offset >= $total) {
    echo "<h2 class='ok'>🎉 COMPLETO!</h2>";
    exit;
}

// Busca registros
echo "<p>Buscando registros...</p>"; flush();
$stmt = $pdoG->prepare("SELECT id, pergunta, resposta, categoria, ativo FROM om_one_brain_universal ORDER BY id LIMIT ? OFFSET ?");
$stmt->execute([$batch, $offset]);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<p>Encontrados: " . count($registros) . "</p>"; flush();

// Atualiza VPS
$ok = 0;
$erro = 0;

echo "<p>Atualizando VPS...</p>"; flush();

foreach ($registros as $i => $r) {
    try {
        $stmt2 = $pdoV->prepare("UPDATE om_one_brain_universal SET pergunta = ?, resposta = ?, categoria = ? WHERE id = ?");
        $stmt2->execute([$r['pergunta'], $r['resposta'], $r['categoria'], $r['id']]);
        $ok++;
        
        if ($ok % 100 == 0) {
            echo "<p>Progresso: $ok / " . count($registros) . "</p>"; flush();
        }
    } catch (Exception $e) {
        $erro++;
    }
}

echo "<p class='ok'>✅ Batch concluído! OK: $ok | Erros: $erro</p>"; flush();

// Próximo
$next = $offset + $batch;
$pct = round(($next / $total) * 100, 1);

echo "<h3>Progresso total: $pct%</h3>";
echo "<p><a href='?batch=$batch&offset=$next' style='color:#10b981;font-size:20px;'>▶️ PRÓXIMO ($next / $total)</a></p>";

echo "</body></html>";
