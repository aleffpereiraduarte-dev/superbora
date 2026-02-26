<?php
require_once dirname(__DIR__) . '/config/database.php';
session_name("OCSESSID");
session_start();

try {
    $pdo = getPDO();
} catch (Exception $e) {
    die("Erro DB: " . $e->getMessage());
}

$categorias = $pdo->query("SELECT category_id, name, icon FROM om_market_categories ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Categorias - OneMundo</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#f5f5f5;padding:20px}
h1{color:#10b981;margin-bottom:20px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px}
.card{background:#fff;border-radius:12px;padding:24px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,0.1);cursor:pointer;transition:all 0.2s}
.card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,0.15)}
.icon{font-size:40px;margin-bottom:8px}
.name{font-weight:600;color:#333}
.empty{text-align:center;padding:60px;color:#666}
</style>
</head>
<body>
<h1>📂 Categorias</h1>
<?php if (empty($categorias)): ?>
<div class="empty">Nenhuma categoria cadastrada</div>
<?php else: ?>
<div class="grid">
<?php foreach ($categorias as $c): ?>
<div class="card" onclick="location.href='/mercado/categorias/?id=<?= $c["category_id"] ?>'">
<div class="icon"><?= $c["icon"] ?: "📦" ?></div>
<div class="name"><?= htmlspecialchars($c["name"]) ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</body>
</html>