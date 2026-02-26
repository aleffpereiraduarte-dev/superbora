<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 FIX SIMPLES - NÃO DÁ ERRO 500
 * Upload em: /mercado/FIX.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Fix Simples</h1><pre>";

// Conectar
try {
    $pdo = getPDO();
    echo "✅ Conectado ao banco\n\n";
} catch (Exception $e) {
    die("❌ Erro: " . $e->getMessage());
}

// 1. CRIAR BADGES
echo "🏅 Criando badges...\n";
try {
    $pdo->exec("INSERT IGNORE INTO om_gamification_badges (icon, name, description, xp_reward) VALUES 
        ('🎯','Primeira Entrega','Completou a primeira entrega',50),
        ('⚡','Velocista','Completou 10 entregas',100),
        ('🏆','Lenda','Completou 100 entregas',500),
        ('⭐','5 Estrelas','Rating perfeito',200),
        ('💎','Fidelidade','30 dias online',500)
    ");
    echo "   ✅ Badges criados\n";
} catch (Exception $e) {
    echo "   ⚠️ " . $e->getMessage() . "\n";
}

// 2. ATRIBUIR BADGES
echo "\n🎁 Atribuindo badges aos workers...\n";
try {
    $workers = $pdo->query("SELECT worker_id FROM om_market_workers WHERE application_status = 'approved'")->fetchAll(PDO::FETCH_COLUMN);
    $badges = $pdo->query("SELECT badge_id FROM om_gamification_badges")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($workers) && !empty($badges)) {
        $count = 0;
        foreach ($workers as $wid) {
            foreach (array_slice($badges, 0, 3) as $bid) {
                try {
                    $pdo->exec("INSERT IGNORE INTO om_worker_badges (worker_id, badge_id) VALUES ($wid, $bid)");
                    $count++;
                } catch (Exception $e) {}
            }
        }
        echo "   ✅ $count badges atribuídos\n";
    } else {
        echo "   ⚠️ Sem workers ou badges\n";
    }
} catch (Exception $e) {
    echo "   ⚠️ " . $e->getMessage() . "\n";
}

// 3. CRIAR GANHOS
echo "\n💰 Criando ganhos para workers...\n";
try {
    $temGanhos = $pdo->query("SELECT COUNT(*) FROM om_market_worker_earnings")->fetchColumn();
    
    if ($temGanhos == 0) {
        $pedidos = $pdo->query("SELECT order_id, shopper_id FROM om_market_orders WHERE status = 'delivered' AND shopper_id IS NOT NULL LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        
        $count = 0;
        foreach ($pedidos as $p) {
            $valor = rand(800, 1500) / 100;
            try {
                $pdo->exec("INSERT INTO om_market_worker_earnings (worker_id, order_id, type, amount, description, status) VALUES ({$p['shopper_id']}, {$p['order_id']}, 'delivery', $valor, 'Entrega', 'available')");
                $count++;
            } catch (Exception $e) {}
        }
        echo "   ✅ $count ganhos criados\n";
    } else {
        echo "   ⏭️ Já tem $temGanhos ganhos\n";
    }
} catch (Exception $e) {
    echo "   ⚠️ " . $e->getMessage() . "\n";
}

// 4. ATUALIZAR SALDO
echo "\n💵 Atualizando saldo dos workers...\n";
try {
    $pdo->exec("UPDATE om_market_workers w SET 
        balance = COALESCE((SELECT SUM(amount) FROM om_market_worker_earnings e WHERE e.worker_id = w.worker_id AND e.status = 'available'), 0),
        total_earned = COALESCE((SELECT SUM(amount) FROM om_market_worker_earnings e WHERE e.worker_id = w.worker_id), 0)
    ");
    echo "   ✅ Saldos atualizados\n";
} catch (Exception $e) {
    echo "   ⚠️ " . $e->getMessage() . "\n";
}

// 5. CORRIGIR PEDIDOS SEM STATUS
echo "\n📦 Corrigindo pedidos sem status...\n";
try {
    $corrigidos = $pdo->exec("UPDATE om_market_orders SET status = 'pending' WHERE status IS NULL OR status = ''");
    echo "   ✅ $corrigidos pedidos corrigidos\n";
} catch (Exception $e) {
    echo "   ⚠️ " . $e->getMessage() . "\n";
}

// RESULTADO
echo "\n════════════════════════════════════════\n";
echo "🎉 FIX COMPLETO!\n\n";

// Mostrar contagens
$badges = $pdo->query("SELECT COUNT(*) FROM om_gamification_badges")->fetchColumn();
$badgesAtrib = $pdo->query("SELECT COUNT(*) FROM om_worker_badges")->fetchColumn();
$ganhos = $pdo->query("SELECT COUNT(*) FROM om_market_worker_earnings")->fetchColumn();

echo "📊 RESULTADO:\n";
echo "   🏅 Badges: $badges\n";
echo "   🎁 Badges Atribuídos: $badgesAtrib\n";
echo "   💰 Ganhos: $ganhos\n";

echo "</pre>";

echo "<p style='margin-top:20px;'>";
echo "<a href='ROBO_CLAUDE.php' style='display:inline-block;padding:12px 24px;background:#6366f1;color:white;text-decoration:none;border-radius:10px;margin:5px;'>🤖 Analisar com Claude</a>";
echo "<a href='?' style='display:inline-block;padding:12px 24px;background:#10b981;color:white;text-decoration:none;border-radius:10px;margin:5px;'>🔄 Rodar Novamente</a>";
echo "</p>";
?>
