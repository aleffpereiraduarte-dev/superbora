<?php
/**
 * PAINEL DO MERCADO - Avaliacoes de Clientes
 * Gerenciamento de reviews com resposta do parceiro
 */
session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
$db = getDB();

$mercado_id = (int)$_SESSION['mercado_id'];
$mercado_nome = $_SESSION['mercado_nome'] ?? 'Mercado';

// ── AJAX: Salvar resposta do parceiro ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_reply'])) {
    header('Content-Type: application/json; charset=utf-8');

    $review_id = (int)($_POST['review_id'] ?? 0);
    $reply = trim($_POST['reply'] ?? '');

    if (!$review_id || !$reply) {
        echo json_encode(['success' => false, 'message' => 'Dados invalidos']);
        exit;
    }

    // Verificar que o review pertence a este parceiro
    $stmt = $db->prepare("SELECT id FROM om_market_reviews WHERE id = ? AND partner_id = ?");
    $stmt->execute([$review_id, $mercado_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Avaliacao nao encontrada']);
        exit;
    }

    $stmt = $db->prepare("
        UPDATE om_market_reviews
        SET partner_reply = ?, partner_reply_at = NOW()
        WHERE id = ? AND partner_id = ?
    ");
    $stmt->execute([$reply, $review_id, $mercado_id]);

    echo json_encode(['success' => true, 'message' => 'Resposta salva com sucesso']);
    exit;
}

// ── Filtros ──
$filter_rating = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$filter_replied = $_GET['replied'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_sort = $_GET['sort'] ?? 'newest';

// ── Estatisticas gerais ──
$stmtStats = $db->prepare("
    SELECT
        COUNT(*) as total_reviews,
        COALESCE(AVG(rating), 0) as avg_rating,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as r5,
        SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as r4,
        SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as r3,
        SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as r2,
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as r1,
        SUM(CASE WHEN partner_reply IS NOT NULL AND partner_reply != '' THEN 1 ELSE 0 END) as replied_count
    FROM om_market_reviews
    WHERE partner_id = ?
");
$stmtStats->execute([$mercado_id]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

$total_reviews = (int)$stats['total_reviews'];
$avg_rating = $total_reviews > 0 ? round((float)$stats['avg_rating'], 1) : 0;
$replied_count = (int)$stats['replied_count'];
$response_rate = $total_reviews > 0 ? round(($replied_count / $total_reviews) * 100) : 0;

$distribution = [
    5 => (int)$stats['r5'],
    4 => (int)$stats['r4'],
    3 => (int)$stats['r3'],
    2 => (int)$stats['r2'],
    1 => (int)$stats['r1'],
];

// ── Tendencia: este mes vs mes passado ──
$stmtThisMonth = $db->prepare("
    SELECT COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as cnt
    FROM om_market_reviews
    WHERE partner_id = ? AND created_at >= date_trunc('month', CURRENT_DATE)
");
$stmtThisMonth->execute([$mercado_id]);
$thisMonth = $stmtThisMonth->fetch(PDO::FETCH_ASSOC);

$stmtLastMonth = $db->prepare("
    SELECT COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as cnt
    FROM om_market_reviews
    WHERE partner_id = ?
      AND created_at >= date_trunc('month', CURRENT_DATE) - INTERVAL '1 month'
      AND created_at < date_trunc('month', CURRENT_DATE)
");
$stmtLastMonth->execute([$mercado_id]);
$lastMonth = $stmtLastMonth->fetch(PDO::FETCH_ASSOC);

$thisMonthAvg = (int)$thisMonth['cnt'] > 0 ? round((float)$thisMonth['avg_rating'], 1) : null;
$lastMonthAvg = (int)$lastMonth['cnt'] > 0 ? round((float)$lastMonth['avg_rating'], 1) : null;
$trend = null;
if ($thisMonthAvg !== null && $lastMonthAvg !== null) {
    $trend = round($thisMonthAvg - $lastMonthAvg, 1);
}

// ── Buscar avaliacoes com filtros ──
$where = ["r.partner_id = ?"];
$params = [$mercado_id];

if ($filter_rating >= 1 && $filter_rating <= 5) {
    $where[] = "r.rating = ?";
    $params[] = $filter_rating;
}

if ($filter_replied === 'yes') {
    $where[] = "r.partner_reply IS NOT NULL AND r.partner_reply != ''";
} elseif ($filter_replied === 'no') {
    $where[] = "(r.partner_reply IS NULL OR r.partner_reply = '')";
}

if ($filter_date_from) {
    $where[] = "r.created_at >= ?";
    $params[] = $filter_date_from . ' 00:00:00';
}

if ($filter_date_to) {
    $where[] = "r.created_at <= ?";
    $params[] = $filter_date_to . ' 23:59:59';
}

$whereSQL = implode(' AND ', $where);
$orderSQL = $filter_sort === 'oldest' ? 'r.created_at ASC' :
            ($filter_sort === 'highest' ? 'r.rating DESC, r.created_at DESC' :
            ($filter_sort === 'lowest' ? 'r.rating ASC, r.created_at DESC' :
            'r.created_at DESC'));

$stmtReviews = $db->prepare("
    SELECT r.*
    FROM om_market_reviews r
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT 100
");
$stmtReviews->execute($params);
$reviews = $stmtReviews->fetchAll(PDO::FETCH_ASSOC);

// ── Contagem filtrada ──
$filtered_count = count($reviews);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avaliacoes - <?= htmlspecialchars($mercado_nome) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
</head>
<body class="om-app-layout">
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>SuperBora</span>'">
        </div>
        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link"><i class="lucide-layout-dashboard"></i><span>Dashboard</span></a>
            <a href="pedidos.php" class="om-sidebar-link"><i class="lucide-shopping-bag"></i><span>Pedidos</span></a>
            <a href="produtos.php" class="om-sidebar-link"><i class="lucide-package"></i><span>Produtos</span></a>
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
            <a href="categorias.php" class="om-sidebar-link"><i class="lucide-tags"></i><span>Categorias</span></a>
            <a href="faturamento.php" class="om-sidebar-link"><i class="lucide-bar-chart-3"></i><span>Faturamento</span></a>
            <a href="repasses.php" class="om-sidebar-link"><i class="lucide-wallet"></i><span>Repasses</span></a>
            <a href="avaliacoes.php" class="om-sidebar-link active"><i class="lucide-star"></i><span>Avaliacoes</span></a>
            <a href="horarios.php" class="om-sidebar-link"><i class="lucide-clock"></i><span>Horarios</span></a>
            <a href="perfil.php" class="om-sidebar-link"><i class="lucide-settings"></i><span>Configuracoes</span></a>
        </nav>
        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link"><i class="lucide-log-out"></i><span>Sair</span></a>
        </div>
    </aside>

    <main class="om-main-content">
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>
            <h1 class="om-topbar-title">Avaliacoes de Clientes</h1>
            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <div class="om-page-content">

            <!-- ===== SUMMARY CARDS ===== -->
            <div class="rv-summary-grid">
                <!-- Big Star Rating -->
                <div class="rv-card rv-card-rating">
                    <div class="rv-big-star">
                        <i class="lucide-star"></i>
                    </div>
                    <div class="rv-rating-number"><?= $avg_rating ?></div>
                    <div class="rv-rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="lucide-star rv-star <?= $i <= round($avg_rating) ? 'rv-star-filled' : '' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <div class="rv-rating-count"><?= $total_reviews ?> avaliacao<?= $total_reviews !== 1 ? 'es' : '' ?></div>
                    <?php if ($trend !== null): ?>
                    <div class="rv-trend <?= $trend >= 0 ? 'rv-trend-up' : 'rv-trend-down' ?>">
                        <i class="<?= $trend >= 0 ? 'lucide-trending-up' : 'lucide-trending-down' ?>"></i>
                        <?= ($trend >= 0 ? '+' : '') . $trend ?> vs mes anterior
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Distribution -->
                <div class="rv-card rv-card-dist">
                    <div class="rv-card-title">Distribuicao</div>
                    <?php for ($s = 5; $s >= 1; $s--): ?>
                    <?php $pct = $total_reviews > 0 ? round(($distribution[$s] / $total_reviews) * 100) : 0; ?>
                    <div class="rv-dist-row">
                        <span class="rv-dist-label"><?= $s ?><i class="lucide-star rv-star-mini"></i></span>
                        <div class="rv-dist-bar-bg">
                            <div class="rv-dist-bar" style="width:<?= $pct ?>%"></div>
                        </div>
                        <span class="rv-dist-count"><?= $distribution[$s] ?></span>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Response Rate -->
                <div class="rv-card rv-card-response">
                    <div class="rv-card-title">Taxa de Resposta</div>
                    <div class="rv-response-ring">
                        <svg viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="52" fill="none" stroke="#e5e7eb" stroke-width="10"/>
                            <circle cx="60" cy="60" r="52" fill="none" stroke="#10b981" stroke-width="10"
                                    stroke-dasharray="<?= round($response_rate * 3.267) ?> 326.7"
                                    stroke-linecap="round" transform="rotate(-90 60 60)"/>
                        </svg>
                        <div class="rv-response-pct"><?= $response_rate ?>%</div>
                    </div>
                    <div class="rv-response-detail">
                        <span><?= $replied_count ?> respondida<?= $replied_count !== 1 ? 's' : '' ?></span>
                        <span><?= $total_reviews - $replied_count ?> pendente<?= ($total_reviews - $replied_count) !== 1 ? 's' : '' ?></span>
                    </div>
                </div>

                <!-- Month Trend -->
                <div class="rv-card rv-card-trend">
                    <div class="rv-card-title">Este Mes</div>
                    <div class="rv-trend-number"><?= $thisMonthAvg ?? '—' ?></div>
                    <div class="rv-trend-label"><?= (int)$thisMonth['cnt'] ?> avaliacao<?= (int)$thisMonth['cnt'] !== 1 ? 'es' : '' ?></div>
                    <div class="rv-trend-divider"></div>
                    <div class="rv-card-title">Mes Anterior</div>
                    <div class="rv-trend-number rv-trend-number-sm"><?= $lastMonthAvg ?? '—' ?></div>
                    <div class="rv-trend-label"><?= (int)$lastMonth['cnt'] ?> avaliacao<?= (int)$lastMonth['cnt'] !== 1 ? 'es' : '' ?></div>
                </div>
            </div>

            <!-- ===== FILTERS ===== -->
            <div class="rv-filters om-card">
                <form method="GET" class="rv-filter-form">
                    <div class="rv-filter-group">
                        <label>Nota</label>
                        <select name="rating" class="rv-select">
                            <option value="0" <?= $filter_rating === 0 ? 'selected' : '' ?>>Todas</option>
                            <?php for ($s = 5; $s >= 1; $s--): ?>
                            <option value="<?= $s ?>" <?= $filter_rating === $s ? 'selected' : '' ?>><?= $s ?> estrela<?= $s > 1 ? 's' : '' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="rv-filter-group">
                        <label>Resposta</label>
                        <select name="replied" class="rv-select">
                            <option value="" <?= $filter_replied === '' ? 'selected' : '' ?>>Todas</option>
                            <option value="yes" <?= $filter_replied === 'yes' ? 'selected' : '' ?>>Respondidas</option>
                            <option value="no" <?= $filter_replied === 'no' ? 'selected' : '' ?>>Nao respondidas</option>
                        </select>
                    </div>

                    <div class="rv-filter-group">
                        <label>De</label>
                        <input type="date" name="date_from" class="rv-input" value="<?= htmlspecialchars($filter_date_from) ?>">
                    </div>

                    <div class="rv-filter-group">
                        <label>Ate</label>
                        <input type="date" name="date_to" class="rv-input" value="<?= htmlspecialchars($filter_date_to) ?>">
                    </div>

                    <div class="rv-filter-group">
                        <label>Ordenar</label>
                        <select name="sort" class="rv-select">
                            <option value="newest" <?= $filter_sort === 'newest' ? 'selected' : '' ?>>Mais recentes</option>
                            <option value="oldest" <?= $filter_sort === 'oldest' ? 'selected' : '' ?>>Mais antigas</option>
                            <option value="highest" <?= $filter_sort === 'highest' ? 'selected' : '' ?>>Maior nota</option>
                            <option value="lowest" <?= $filter_sort === 'lowest' ? 'selected' : '' ?>>Menor nota</option>
                        </select>
                    </div>

                    <div class="rv-filter-actions">
                        <button type="submit" class="rv-btn rv-btn-primary"><i class="lucide-search"></i> Filtrar</button>
                        <a href="avaliacoes.php" class="rv-btn rv-btn-ghost"><i class="lucide-x"></i> Limpar</a>
                    </div>
                </form>
            </div>

            <!-- ===== REVIEWS LIST ===== -->
            <div class="rv-list-header">
                <span><?= $filtered_count ?> avaliacao<?= $filtered_count !== 1 ? 'es' : '' ?> encontrada<?= $filtered_count !== 1 ? 's' : '' ?></span>
            </div>

            <?php if (empty($reviews)): ?>
            <div class="om-card rv-empty">
                <i class="lucide-star"></i>
                <p>Nenhuma avaliacao encontrada</p>
                <small>As avaliacoes dos clientes aparecerao aqui</small>
            </div>
            <?php else: ?>
            <?php foreach ($reviews as $review): ?>
            <div class="rv-review-card om-card" id="review-<?= $review['id'] ?>">
                <div class="rv-review-header">
                    <div class="rv-review-avatar">
                        <?= strtoupper(substr($review['customer_name'] ?? 'C', 0, 2)) ?>
                    </div>
                    <div class="rv-review-meta">
                        <div class="rv-review-name"><?= htmlspecialchars($review['customer_name'] ?? 'Cliente') ?></div>
                        <div class="rv-review-info">
                            <span class="rv-review-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="lucide-star rv-star <?= $i <= (int)$review['rating'] ? 'rv-star-filled' : '' ?>"></i>
                                <?php endfor; ?>
                            </span>
                            <span class="rv-review-date">
                                <i class="lucide-calendar"></i>
                                <?= $review['created_at'] ? date('d/m/Y H:i', strtotime($review['created_at'])) : '—' ?>
                            </span>
                            <?php if ($review['order_id']): ?>
                            <span class="rv-review-order">
                                <i class="lucide-shopping-bag"></i>
                                Pedido #<?= (int)$review['order_id'] ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="rv-review-badge rv-badge-<?= (int)$review['rating'] >= 4 ? 'good' : ((int)$review['rating'] >= 3 ? 'ok' : 'bad') ?>">
                        <?= (int)$review['rating'] ?>.0
                    </div>
                </div>

                <?php if (!empty($review['comment'])): ?>
                <div class="rv-review-comment">
                    <?= nl2br(htmlspecialchars($review['comment'])) ?>
                </div>
                <?php endif; ?>

                <!-- Existing reply -->
                <?php if (!empty($review['partner_reply'])): ?>
                <div class="rv-reply-box">
                    <div class="rv-reply-header">
                        <i class="lucide-corner-down-right"></i>
                        <strong>Sua resposta</strong>
                        <span class="rv-reply-date"><?= $review['partner_reply_at'] ? date('d/m/Y H:i', strtotime($review['partner_reply_at'])) : '' ?></span>
                    </div>
                    <div class="rv-reply-text" id="reply-text-<?= $review['id'] ?>"><?= nl2br(htmlspecialchars($review['partner_reply'])) ?></div>
                    <button class="rv-btn rv-btn-sm rv-btn-ghost" onclick="openReply(<?= $review['id'] ?>, true)">
                        <i class="lucide-edit-3"></i> Editar
                    </button>
                </div>
                <?php endif; ?>

                <!-- Reply form (hidden by default) -->
                <div class="rv-reply-form" id="reply-form-<?= $review['id'] ?>" style="display:none">
                    <textarea id="reply-textarea-<?= $review['id'] ?>" class="rv-textarea"
                              placeholder="Escreva sua resposta ao cliente..."
                              rows="3"><?= htmlspecialchars($review['partner_reply'] ?? '') ?></textarea>
                    <div class="rv-reply-actions">
                        <button class="rv-btn rv-btn-sm rv-btn-ghost" onclick="closeReply(<?= $review['id'] ?>)">Cancelar</button>
                        <button class="rv-btn rv-btn-sm rv-btn-primary" onclick="saveReply(<?= $review['id'] ?>)">
                            <i class="lucide-send"></i> Salvar Resposta
                        </button>
                    </div>
                </div>

                <!-- Reply button (if no reply yet) -->
                <?php if (empty($review['partner_reply'])): ?>
                <div class="rv-review-actions" id="reply-trigger-<?= $review['id'] ?>">
                    <button class="rv-btn rv-btn-sm rv-btn-outline" onclick="openReply(<?= $review['id'] ?>)">
                        <i class="lucide-message-square"></i> Responder
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </main>

    <style>
    *{box-sizing:border-box}

    /* ===== Summary Grid ===== */
    .rv-summary-grid{display:grid;grid-template-columns:1fr 1.5fr 1fr 1fr;gap:16px;margin-bottom:24px}
    .rv-card{background:#fff;border-radius:16px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
    .rv-card-title{font-size:13px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}

    /* Big Star Card */
    .rv-card-rating{text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px}
    .rv-big-star{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#f59e0b,#f97316);display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;margin-bottom:4px}
    .rv-rating-number{font-size:42px;font-weight:800;color:#1f2937;line-height:1}
    .rv-rating-stars{display:flex;gap:3px;margin:6px 0}
    .rv-star{font-size:16px;color:#d1d5db}
    .rv-star-filled{color:#f59e0b}
    .rv-star-mini{font-size:12px;color:#f59e0b;margin-left:2px}
    .rv-rating-count{font-size:14px;color:#6b7280}
    .rv-trend{font-size:12px;font-weight:600;display:flex;align-items:center;gap:4px;margin-top:4px;padding:4px 10px;border-radius:20px}
    .rv-trend-up{background:#d1fae5;color:#065f46}
    .rv-trend-down{background:#fee2e2;color:#991b1b}

    /* Distribution Card */
    .rv-card-dist{display:flex;flex-direction:column;justify-content:center}
    .rv-dist-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
    .rv-dist-row:last-child{margin-bottom:0}
    .rv-dist-label{font-size:13px;font-weight:600;color:#374151;min-width:32px;display:flex;align-items:center}
    .rv-dist-bar-bg{flex:1;height:10px;background:#f3f4f6;border-radius:10px;overflow:hidden}
    .rv-dist-bar{height:100%;background:linear-gradient(90deg,#f59e0b,#f97316);border-radius:10px;transition:width .5s ease}
    .rv-dist-count{font-size:13px;font-weight:500;color:#6b7280;min-width:28px;text-align:right}

    /* Response Rate Card */
    .rv-card-response{text-align:center;display:flex;flex-direction:column;align-items:center}
    .rv-response-ring{position:relative;width:110px;height:110px;margin:4px 0 12px}
    .rv-response-ring svg{width:100%;height:100%}
    .rv-response-pct{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:#1f2937}
    .rv-response-detail{display:flex;flex-direction:column;gap:2px;font-size:12px;color:#6b7280}

    /* Trend Card */
    .rv-card-trend{text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center}
    .rv-trend-number{font-size:36px;font-weight:800;color:#1f2937;line-height:1}
    .rv-trend-number-sm{font-size:28px;color:#6b7280}
    .rv-trend-label{font-size:12px;color:#6b7280;margin-bottom:4px}
    .rv-trend-divider{width:40px;height:2px;background:#e5e7eb;border-radius:2px;margin:12px 0}

    /* ===== Filters ===== */
    .rv-filters{padding:16px 20px;margin-bottom:20px}
    .rv-filter-form{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}
    .rv-filter-group{display:flex;flex-direction:column;gap:4px}
    .rv-filter-group label{font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.3px}
    .rv-select,.rv-input{padding:8px 12px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:13px;font-family:inherit;background:#fff;color:#1f2937;min-width:140px;transition:.2s}
    .rv-select:focus,.rv-input:focus{outline:none;border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.12)}
    .rv-filter-actions{display:flex;gap:8px;align-items:flex-end}

    /* ===== Buttons ===== */
    .rv-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;border:none;transition:.2s;text-decoration:none}
    .rv-btn-primary{background:#10b981;color:#fff}.rv-btn-primary:hover{background:#059669}
    .rv-btn-outline{background:#fff;color:#10b981;border:1.5px solid #10b981}.rv-btn-outline:hover{background:#f0fdf4}
    .rv-btn-ghost{background:#f3f4f6;color:#374151}.rv-btn-ghost:hover{background:#e5e7eb}
    .rv-btn-sm{padding:6px 12px;font-size:12px}

    /* ===== List Header ===== */
    .rv-list-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;font-size:14px;font-weight:500;color:#6b7280}

    /* ===== Empty State ===== */
    .rv-empty{text-align:center;padding:64px 24px;color:#9ca3af}
    .rv-empty i{font-size:48px;display:block;margin-bottom:16px;color:#d1d5db}
    .rv-empty p{font-size:16px;margin:8px 0 4px;color:#6b7280}
    .rv-empty small{font-size:13px}

    /* ===== Review Card ===== */
    .rv-review-card{padding:20px;margin-bottom:12px}
    .rv-review-header{display:flex;align-items:flex-start;gap:14px}
    .rv-review-avatar{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0}
    .rv-review-meta{flex:1;min-width:0}
    .rv-review-name{font-size:15px;font-weight:600;color:#1f2937}
    .rv-review-info{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:4px}
    .rv-review-stars{display:flex;gap:2px}
    .rv-review-stars .rv-star{font-size:14px}
    .rv-review-date,.rv-review-order{font-size:12px;color:#9ca3af;display:flex;align-items:center;gap:4px}
    .rv-review-date i,.rv-review-order i{font-size:12px}
    .rv-review-badge{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;flex-shrink:0}
    .rv-badge-good{background:#d1fae5;color:#065f46}
    .rv-badge-ok{background:#fef3c7;color:#92400e}
    .rv-badge-bad{background:#fee2e2;color:#991b1b}

    .rv-review-comment{margin:14px 0 0 58px;font-size:14px;color:#374151;line-height:1.6;white-space:pre-wrap}

    /* ===== Reply Box ===== */
    .rv-reply-box{margin:16px 0 0 58px;background:#f0fdf4;border-radius:12px;padding:14px 16px;border-left:3px solid #10b981}
    .rv-reply-header{display:flex;align-items:center;gap:8px;font-size:13px;color:#065f46;margin-bottom:8px}
    .rv-reply-header i{font-size:14px}
    .rv-reply-date{font-size:11px;color:#6b7280;margin-left:auto}
    .rv-reply-text{font-size:14px;color:#374151;line-height:1.5;white-space:pre-wrap}

    /* ===== Reply Form ===== */
    .rv-reply-form{margin:14px 0 0 58px}
    .rv-textarea{width:100%;padding:12px 14px;border:1.5px solid #e5e7eb;border-radius:12px;font-size:14px;font-family:inherit;color:#1f2937;resize:vertical;min-height:80px;transition:.2s}
    .rv-textarea:focus{outline:none;border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.12)}
    .rv-reply-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}

    /* ===== Review Actions ===== */
    .rv-review-actions{margin:14px 0 0 58px}

    /* ===== Responsive ===== */
    @media(max-width:1100px){
        .rv-summary-grid{grid-template-columns:1fr 1fr}
    }
    @media(max-width:640px){
        .rv-summary-grid{grid-template-columns:1fr}
        .rv-filter-form{flex-direction:column}
        .rv-filter-group{width:100%}
        .rv-select,.rv-input{min-width:0;width:100%}
        .rv-review-comment,.rv-reply-box,.rv-reply-form,.rv-review-actions{margin-left:0}
    }
    </style>

    <script>
    function openReply(id, isEdit) {
        var form = document.getElementById('reply-form-' + id);
        var trigger = document.getElementById('reply-trigger-' + id);
        form.style.display = 'block';
        if (trigger) trigger.style.display = 'none';
        document.getElementById('reply-textarea-' + id).focus();
    }

    function closeReply(id) {
        var form = document.getElementById('reply-form-' + id);
        var trigger = document.getElementById('reply-trigger-' + id);
        form.style.display = 'none';
        if (trigger) trigger.style.display = 'block';
    }

    function saveReply(id) {
        var textarea = document.getElementById('reply-textarea-' + id);
        var reply = textarea.value.trim();

        if (!reply) {
            textarea.style.borderColor = '#ef4444';
            textarea.focus();
            return;
        }

        var btn = event.currentTarget;
        btn.disabled = true;
        btn.innerHTML = '<i class="lucide-loader spin"></i> Salvando...';

        var formData = new FormData();
        formData.append('ajax_reply', '1');
        formData.append('review_id', id);
        formData.append('reply', reply);

        fetch('avaliacoes.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                // Reload to show updated reply
                location.reload();
            } else {
                alert(data.message || 'Erro ao salvar resposta');
                btn.disabled = false;
                btn.innerHTML = '<i class="lucide-send"></i> Salvar Resposta';
            }
        })
        .catch(function() {
            alert('Erro de conexao. Tente novamente.');
            btn.disabled = false;
            btn.innerHTML = '<i class="lucide-send"></i> Salvar Resposta';
        });
    }
    </script>
    <style>.spin{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}</style>
</body>
</html>
