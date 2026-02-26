<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  🏠 APP - DASHBOARD PRINCIPAL DO WORKER                                      ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

require_once 'config.php';
requireWorkerLogin();

$worker = getWorker();
$shopper_id = $_SESSION['shopper_id'] ?? null;

// Se não tem shopper_id, sincronizar
if (!$shopper_id) {
    $shopper_id = syncWorkerToShopper($_SESSION['worker_id']);
}

$pdo = getPDO();

// Toggle online
if (isset($_POST['toggle_online'])) {
    $stmt = $pdo->prepare("SELECT is_online FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $current = $stmt->fetchColumn();
    $new_status = $current ? 0 : 1;
    $pdo->prepare("UPDATE om_market_shoppers SET is_online = ?, last_seen = NOW() WHERE shopper_id = ?")
        ->execute([$new_status, $shopper_id]);
    header('Location: app.php');
    exit;
}

// Buscar dados
$stmt = $pdo->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ?");
$stmt->execute([$shopper_id]);
$shopper = $stmt->fetch();

$ofertas = getAvailableOffers($shopper_id);
$pedido_ativo = getActiveOrder($shopper_id);
$stats = getTodayStats($shopper_id);

// Tipo de worker
$tipo = 'Shopper';
if ($worker['is_delivery'] && $worker['is_shopper']) $tipo = 'Full Service';
elseif ($worker['is_delivery']) $tipo = 'Entregador';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>OneMundo - <?= $tipo ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --bg: #0a0a0a; --card: #141414; --card2: #1a1a1a; --border: #252525;
            --text: #fff; --text2: #888; --green: #00D26A; --green-dark: #00a854;
            --yellow: #FFB800; --red: #ff4757; --blue: #3b82f6; --purple: #8b5cf6;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; padding-bottom: 100px; }
        
        .header { background: var(--card); border-bottom: 1px solid var(--border); padding: 16px 20px; position: sticky; top: 0; z-index: 100; }
        .header-top { display: flex; align-items: center; justify-content: space-between; }
        .user-info { display: flex; align-items: center; gap: 12px; }
        .avatar { width: 48px; height: 48px; background: linear-gradient(135deg, var(--green), var(--green-dark)); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; }
        .user-name { font-weight: 600; font-size: 16px; }
        .user-type { font-size: 12px; color: var(--text2); display: flex; align-items: center; gap: 6px; }
        .user-type .badge { background: var(--purple); color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; }
        .header-actions { display: flex; gap: 8px; }
        .icon-btn { width: 44px; height: 44px; background: var(--card2); border: 1px solid var(--border); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--text); text-decoration: none; font-size: 20px; }
        
        .status-card { background: var(--card); border: 1px solid var(--border); border-radius: 18px; padding: 18px; margin: 16px; display: flex; align-items: center; justify-content: space-between; }
        .status-card.online { border-color: var(--green); background: rgba(0, 210, 106, 0.08); }
        .status-info h3 { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
        .status-card.online .status-info h3 { color: var(--green); }
        .status-info p { font-size: 13px; color: var(--text2); }
        .toggle-form button { width: 60px; height: 34px; background: var(--border); border: none; border-radius: 17px; position: relative; cursor: pointer; }
        .toggle-form button.active { background: var(--green); }
        .toggle-form button::after { content: ''; position: absolute; top: 5px; left: 5px; width: 24px; height: 24px; background: #fff; border-radius: 50%; transition: 0.3s; }
        .toggle-form button.active::after { left: 31px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; padding: 0 16px 16px; }
        .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 16px; text-align: center; }
        .stat-value { font-size: 22px; font-weight: 700; color: var(--green); }
        .stat-label { font-size: 11px; color: var(--text2); margin-top: 4px; }
        
        .active-order { background: linear-gradient(135deg, var(--blue), #2563eb); border-radius: 18px; padding: 20px; margin: 0 16px 16px; color: #fff; }
        .active-order h4 { font-size: 13px; opacity: 0.9; margin-bottom: 4px; }
        .active-order h3 { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .active-order p { font-size: 14px; opacity: 0.9; margin-bottom: 16px; }
        .active-order .btn { width: 100%; padding: 14px; background: #fff; border: none; border-radius: 12px; color: var(--blue); font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none; display: block; text-align: center; }
        
        .section { padding: 0 16px 16px; }
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .section-title { font-size: 18px; font-weight: 700; }
        .section-badge { background: var(--green); color: #000; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 20px; }
        
        .offer-card { background: var(--card); border: 1px solid var(--border); border-radius: 18px; padding: 18px; margin-bottom: 12px; position: relative; overflow: hidden; }
        .offer-timer { position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--border); }
        .offer-timer-bar { height: 100%; background: var(--green); transition: width 1s linear; }
        .offer-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; margin-top: 8px; }
        .offer-earning { font-size: 28px; font-weight: 800; color: var(--green); }
        .offer-time { font-size: 13px; color: var(--text2); background: var(--card2); padding: 6px 12px; border-radius: 8px; }
        .offer-store { font-size: 16px; font-weight: 600; margin-bottom: 8px; }
        .offer-details { display: flex; gap: 16px; font-size: 13px; color: var(--text2); margin-bottom: 14px; }
        .offer-address { font-size: 13px; color: var(--text2); padding: 12px; background: var(--card2); border-radius: 10px; margin-bottom: 16px; }
        .offer-actions { display: grid; grid-template-columns: 1fr 2fr; gap: 10px; }
        .btn-reject { padding: 14px; background: var(--card2); border: 1px solid var(--border); border-radius: 12px; color: var(--text2); font-size: 14px; font-weight: 500; cursor: pointer; }
        .btn-accept { padding: 14px; background: linear-gradient(135deg, var(--green), var(--green-dark)); border: none; border-radius: 12px; color: #000; font-size: 15px; font-weight: 700; cursor: pointer; }
        .btn-accept:active, .btn-reject:active { transform: scale(0.98); }
        
        .empty { text-align: center; padding: 60px 20px; }
        .empty-icon { font-size: 64px; margin-bottom: 16px; }
        .empty h3 { font-size: 18px; margin-bottom: 8px; }
        .empty p { color: var(--text2); font-size: 14px; }
        
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: var(--card); border-top: 1px solid var(--border); display: flex; justify-content: space-around; padding: 10px 0; padding-bottom: max(10px, env(safe-area-inset-bottom)); z-index: 100; }
        .nav-item { display: flex; flex-direction: column; align-items: center; gap: 4px; color: var(--text2); text-decoration: none; font-size: 10px; font-weight: 500; padding: 6px 12px; }
        .nav-item.active { color: var(--green); }
        .nav-item span { font-size: 24px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top">
            <div class="user-info">
                <div class="avatar"><?= strtoupper(substr($worker['name'] ?? 'W', 0, 2)) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($worker['name'] ?? 'Worker') ?></div>
                    <div class="user-type">
                        ⭐ <?= number_format($shopper['rating'] ?? 5, 1) ?>
                        <span class="badge"><?= $tipo ?></span>
                    </div>
                </div>
            </div>
            <div class="header-actions">
                <a href="ganhos.php" class="icon-btn">💰</a>
                <a href="perfil.php" class="icon-btn">⚙️</a>
            </div>
        </div>
    </div>
    
    <div class="status-card <?= ($shopper['is_online'] ?? 0) ? 'online' : '' ?>">
        <div class="status-info">
            <h3><?= ($shopper['is_online'] ?? 0) ? '🟢 Você está Online' : '⚫ Você está Offline' ?></h3>
            <p><?= ($shopper['is_online'] ?? 0) ? 'Recebendo ofertas de pedidos' : 'Ative para receber pedidos' ?></p>
        </div>
        <form method="POST" class="toggle-form">
            <button type="submit" name="toggle_online" class="<?= ($shopper['is_online'] ?? 0) ? 'active' : '' ?>"></button>
        </form>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total_orders'] ?? 0 ?></div>
            <div class="stat-label">Pedidos hoje</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= formatMoney($stats['total_earnings'] ?? 0) ?></div>
            <div class="stat-label">Ganhos hoje</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($shopper['rating'] ?? 5, 1) ?></div>
            <div class="stat-label">Avaliação</div>
        </div>
    </div>
    
    <?php if ($pedido_ativo): ?>
        <div class="active-order">
            <h4>Pedido em andamento</h4>
            <h3>#<?= $pedido_ativo['order_number'] ?></h3>
            <p>📍 <?= htmlspecialchars($pedido_ativo['partner_name']) ?></p>
            <a href="shopping.php?order_id=<?= $pedido_ativo['order_id'] ?>" class="btn">Continuar →</a>
        </div>
    <?php endif; ?>
    
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">Ofertas Disponíveis</h2>
            <?php if (count($ofertas) > 0): ?>
                <span class="section-badge"><?= count($ofertas) ?></span>
            <?php endif; ?>
        </div>
        
        <?php if (empty($ofertas)): ?>
            <div class="empty">
                <div class="empty-icon">📦</div>
                <h3>Nenhuma oferta no momento</h3>
                <p>Fique online para receber novas ofertas</p>
            </div>
        <?php else: ?>
            <?php foreach ($ofertas as $oferta): ?>
                <div class="offer-card" data-offer-id="<?= $oferta['offer_id'] ?>">
                    <div class="offer-timer">
                        <div class="offer-timer-bar" style="width: <?= min(100, ($oferta['seconds_left'] / 60) * 100) ?>%"></div>
                    </div>
                    <div class="offer-header">
                        <div class="offer-earning"><?= formatMoney($oferta['shopper_earning']) ?></div>
                        <div class="offer-time">⏱️ <span class="countdown"><?= $oferta['seconds_left'] ?></span>s</div>
                    </div>
                    <div class="offer-store"><?= htmlspecialchars($oferta['partner_name']) ?></div>
                    <div class="offer-details">
                        <span>🛒 <?= $oferta['items_count'] ?? '?' ?> itens</span>
                        <span>💰 <?= formatMoney($oferta['total']) ?></span>
                        <span>🌊 Wave <?= $oferta['current_wave'] ?? 1 ?></span>
                    </div>
                    <div class="offer-address">📍 <?= htmlspecialchars($oferta['shipping_address'] ?? 'Endereço') ?></div>
                    <div class="offer-actions">
                        <button class="btn-reject" onclick="rejectOffer(<?= $oferta['offer_id'] ?>)">Recusar</button>
                        <button class="btn-accept" onclick="acceptOffer(<?= $oferta['offer_id'] ?>)">✓ Aceitar</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div class="bottom-nav">
        <a href="app.php" class="nav-item active"><span>🏠</span>Home</a>
        <a href="historico.php" class="nav-item"><span>📋</span>Histórico</a>
        <a href="ganhos.php" class="nav-item"><span>💰</span>Ganhos</a>
        <a href="mapa-calor.php" class="nav-item"><span>🗺️</span>Mapa</a>
        <a href="perfil.php" class="nav-item"><span>👤</span>Perfil</a>
    </div>
    
    <script>
        const shopperId = <?= $shopper_id ?>;
        
        // Countdown
        setInterval(() => {
            document.querySelectorAll('.offer-card').forEach(card => {
                const countdown = card.querySelector('.countdown');
                const timerBar = card.querySelector('.offer-timer-bar');
                let seconds = parseInt(countdown.textContent);
                if (seconds > 0) {
                    seconds--;
                    countdown.textContent = seconds;
                    timerBar.style.width = (seconds / 60 * 100) + '%';
                    if (seconds <= 10) countdown.style.color = '#ff4757';
                } else {
                    card.style.opacity = '0.5';
                    card.querySelector('.btn-accept').disabled = true;
                }
            });
        }, 1000);
        
        async function acceptOffer(offerId) {
            const btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Processando...';
            try {
                const response = await fetch('api/accept-offer.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ offer_id: offerId, shopper_id: shopperId })
                });
                const data = await response.json();
                if (data.success) {
                    window.location.href = 'shopping.php?order_id=' + data.order_id;
                } else {
                    alert(data.error || 'Erro ao aceitar');
                    btn.disabled = false;
                    btn.textContent = '✓ Aceitar';
                }
            } catch (e) {
                alert('Erro de conexão');
                btn.disabled = false;
                btn.textContent = '✓ Aceitar';
            }
        }
        
        function rejectOffer(offerId) {
            document.querySelector(`[data-offer-id="${offerId}"]`).style.display = 'none';
        }
        
        // Polling
        setInterval(() => {
            if (document.hidden) return;
            fetch('api/check-offers.php?shopper_id=' + shopperId)
                .then(r => r.json())
                .then(data => { if (data.reload) location.reload(); })
                .catch(() => {});
        }, 15000);
    </script>
</body>
</html>
