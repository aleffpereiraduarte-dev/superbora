<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - COMPONENTES REUTILIZAVEIS
 * ══════════════════════════════════════════════════════════════════════════════
 * Use: require_once __DIR__ . '/includes/components.php';
 */

/**
 * Renderiza um card de produto
 */
function renderProductCard($product, $cart = [], $showQuickAdd = true) {
    $p = $product;
    $preco_final = $p['price_promo'] > 0 && $p['price_promo'] < $p['price'] ? $p['price_promo'] : $p['price'];
    $tem_promo = $p['price_promo'] > 0 && $p['price_promo'] < $p['price'];
    $desconto = $tem_promo ? round((1 - $p['price_promo'] / $p['price']) * 100) : 0;
    $in_cart = isset($cart[$p['product_id']]);
    $cart_qty = $in_cart ? ($cart[$p['product_id']]['qty'] ?? 1) : 0;
    $stock = $p['stock'] ?? 99;

    ob_start();
    ?>
    <div class="product-card" data-id="<?= $p['product_id'] ?>">
        <div class="product-card__image" onclick="openProductModal(<?= $p['product_id'] ?>)">
            <img src="<?= htmlspecialchars($p['image'] ?: '/mercado/assets/img/no-image.png') ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy" onerror="this.src='/mercado/assets/img/no-image.png'">

            <?php if ($tem_promo || $stock <= 5): ?>
            <div class="product-card__badges">
                <?php if ($tem_promo): ?>
                <span class="product-card__badge product-card__badge--promo">-<?= $desconto ?>%</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($stock > 0 && !$in_cart && $showQuickAdd): ?>
            <div class="product-card__quick-add">
                <button class="product-card__quick-btn" onclick="event.stopPropagation(); addToCart(<?= $p['product_id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>, <?= $preco_final ?>, <?= htmlspecialchars(json_encode($p['image'] ?? ''), ENT_QUOTES) ?>)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="product-card__info">
            <?php if (!empty($p['brand'])): ?><div class="product-card__brand"><?= htmlspecialchars($p['brand']) ?></div><?php endif; ?>
            <div class="product-card__name" onclick="openProductModal(<?= $p['product_id'] ?>)"><?= htmlspecialchars($p['name']) ?></div>
            <?php if (!empty($p['unit'])): ?><div class="product-card__unit"><?= htmlspecialchars($p['unit']) ?></div><?php endif; ?>

            <div class="product-card__footer">
                <div class="product-card__prices">
                    <?php if ($tem_promo): ?><span class="product-card__price-old">R$ <?= number_format($p['price'], 2, ',', '.') ?></span><?php endif; ?>
                    <span class="product-card__price <?= $tem_promo ? 'product-card__price--promo' : '' ?>">R$ <?= number_format($preco_final, 2, ',', '.') ?></span>
                </div>

                <?php if ($stock > 0): ?>
                    <?php if (!$in_cart): ?>
                    <button class="product-card__add-btn" onclick="addToCart(<?= $p['product_id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>, <?= $preco_final ?>, <?= htmlspecialchars(json_encode($p['image'] ?? ''), ENT_QUOTES) ?>)" id="addBtn-<?= $p['product_id'] ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                    <?php endif; ?>

                    <div class="product-card__qty <?= $in_cart ? 'show' : '' ?>" id="qty-<?= $p['product_id'] ?>">
                        <button class="product-card__qty-btn" onclick="changeQty(<?= $p['product_id'] ?>, -1)">−</button>
                        <span class="product-card__qty-value" id="qtyVal-<?= $p['product_id'] ?>"><?= $cart_qty ?></span>
                        <button class="product-card__qty-btn" onclick="changeQty(<?= $p['product_id'] ?>, 1)">+</button>
                    </div>
                <?php else: ?>
                    <span class="product-card__out-of-stock">Esgotado</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Renderiza um card de recomendacao AI
 */
function renderAICard($product, $showReason = true) {
    $rec = $product;
    $preco = $rec['price_promo'] > 0 && $rec['price_promo'] < $rec['price'] ? $rec['price_promo'] : $rec['price'];
    $tem_promo = $rec['price_promo'] > 0 && $rec['price_promo'] < $rec['price'];
    $reason = $rec['recommendation_reason'] ?? 'popular';
    $badges = [
        'bought_together' => '🛒',
        'purchase_history' => '🔄',
        'trending' => '🔥',
        'same_category' => '📦',
        'on_sale' => '💰',
        'popular' => '⭐'
    ];
    $badge = $badges[$reason] ?? '⭐';

    ob_start();
    ?>
    <div class="ai-card" onclick="openProductModal(<?= $rec['product_id'] ?>)">
        <div class="ai-card__image">
            <?php if ($showReason): ?>
            <span class="ai-card__reason"><?= $badge ?></span>
            <?php endif; ?>
            <img src="<?= htmlspecialchars($rec['image'] ?: '/mercado/assets/img/no-image.png') ?>" alt="" loading="lazy" onerror="this.src='/mercado/assets/img/no-image.png'">
        </div>
        <div class="ai-card__info">
            <div class="ai-card__name"><?= htmlspecialchars($rec['name']) ?></div>
            <div class="ai-card__prices">
                <?php if ($tem_promo): ?>
                <span class="ai-card__price-old">R$ <?= number_format($rec['price'], 2, ',', '.') ?></span>
                <?php endif; ?>
                <span class="ai-card__price">R$ <?= number_format($preco, 2, ',', '.') ?></span>
            </div>
            <button class="ai-card__add" onclick="event.stopPropagation(); addToCart(<?= $rec['product_id'] ?>, <?= htmlspecialchars(json_encode($rec['name']), ENT_QUOTES) ?>, <?= $preco ?>, <?= htmlspecialchars(json_encode($rec['image'] ?? ''), ENT_QUOTES) ?>)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Adicionar
            </button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Renderiza o badge de membership
 */
function renderMembershipBadge($membership) {
    if (!$membership) return '';

    ob_start();
    ?>
    <span class="membership-badge" style="background: <?= htmlspecialchars($membership['color']) ?>">
        <?= $membership['level_icon'] ?> <?= strtoupper($membership['level_name']) ?>
    </span>
    <?php
    return ob_get_clean();
}

/**
 * Renderiza um toast notification
 */
function renderToastContainer() {
    return '<div class="toast" id="toast"></div>';
}

/**
 * Renderiza o script de toast
 */
function renderToastScript() {
    return "
    <script>
    function showToast(msg, type = '') {
        const t = document.getElementById('toast');
        if (!t) return;
        t.textContent = msg;
        t.className = 'toast show' + (type ? ' ' + type : '');
        setTimeout(() => t.classList.remove('show'), 3000);
    }
    </script>
    ";
}

/**
 * Renderiza breadcrumb
 */
function renderBreadcrumb($items) {
    ob_start();
    ?>
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <ol class="breadcrumb__list">
            <?php foreach ($items as $i => $item): ?>
            <li class="breadcrumb__item">
                <?php if ($i < count($items) - 1): ?>
                <a href="<?= htmlspecialchars($item['url']) ?>" class="breadcrumb__link"><?= htmlspecialchars($item['label']) ?></a>
                <svg class="breadcrumb__separator" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
                <?php else: ?>
                <span class="breadcrumb__current"><?= htmlspecialchars($item['label']) ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <?php
    return ob_get_clean();
}

/**
 * Renderiza botao primario
 */
function renderButton($text, $options = []) {
    $type = $options['type'] ?? 'primary';
    $size = $options['size'] ?? 'md';
    $icon = $options['icon'] ?? '';
    $href = $options['href'] ?? '';
    $onclick = $options['onclick'] ?? '';
    $disabled = $options['disabled'] ?? false;
    $fullWidth = $options['fullWidth'] ?? false;

    $classes = "btn btn--{$type} btn--{$size}";
    if ($fullWidth) $classes .= ' btn--full';
    if ($disabled) $classes .= ' btn--disabled';

    $attrs = '';
    if ($onclick) $attrs .= " onclick=\"{$onclick}\"";
    if ($disabled) $attrs .= ' disabled';

    ob_start();
    if ($href && !$disabled): ?>
    <a href="<?= htmlspecialchars($href) ?>" class="<?= $classes ?>"<?= $attrs ?>>
        <?php if ($icon): ?><span class="btn__icon"><?= $icon ?></span><?php endif; ?>
        <span class="btn__text"><?= htmlspecialchars($text) ?></span>
    </a>
    <?php else: ?>
    <button class="<?= $classes ?>"<?= $attrs ?>>
        <?php if ($icon): ?><span class="btn__icon"><?= $icon ?></span><?php endif; ?>
        <span class="btn__text"><?= htmlspecialchars($text) ?></span>
    </button>
    <?php endif;
    return ob_get_clean();
}

/**
 * Renderiza loading spinner
 */
function renderSpinner($size = 'md') {
    return "<div class=\"spinner spinner--{$size}\"><div class=\"spinner__circle\"></div></div>";
}

/**
 * Renderiza skeleton loader
 */
function renderSkeleton($type = 'text', $width = '100%') {
    return "<div class=\"skeleton skeleton--{$type}\" style=\"width: {$width}\"></div>";
}

/**
 * Renderiza empty state
 */
function renderEmptyState($icon, $title, $description, $action = null) {
    ob_start();
    ?>
    <div class="empty-state">
        <div class="empty-state__icon"><?= $icon ?></div>
        <h3 class="empty-state__title"><?= htmlspecialchars($title) ?></h3>
        <p class="empty-state__description"><?= htmlspecialchars($description) ?></p>
        <?php if ($action): ?>
        <div class="empty-state__action">
            <?= renderButton($action['text'], $action['options'] ?? []) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Formata preco em reais
 */
function formatPrice($value) {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

/**
 * Renderiza badge de desconto
 */
function renderDiscountBadge($originalPrice, $promoPrice) {
    if ($promoPrice <= 0 || $promoPrice >= $originalPrice) return '';
    $discount = round((1 - $promoPrice / $originalPrice) * 100);
    return "<span class=\"badge badge--discount\">-{$discount}%</span>";
}

/**
 * Renderiza meta tags para SEO
 */
function renderSEOMeta($title, $description, $image = '', $url = '') {
    $siteName = 'SuperBora';
    ob_start();
    ?>
    <title><?= htmlspecialchars($title) ?> - <?= $siteName ?></title>
    <meta name="description" content="<?= htmlspecialchars($description) ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= $siteName ?>">
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($description) ?>">
    <?php if ($image): ?><meta property="og:image" content="<?= htmlspecialchars($image) ?>"><?php endif; ?>
    <?php if ($url): ?><meta property="og:url" content="<?= htmlspecialchars($url) ?>"><?php endif; ?>

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">
    <?php if ($image): ?><meta name="twitter:image" content="<?= htmlspecialchars($image) ?>"><?php endif; ?>
    <?php
    return ob_get_clean();
}
