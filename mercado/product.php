<?php
/**
 * ============================================================================
 * ONEMUNDO MERCADO - PAGINA DE PRODUTO (ALIAS)
 * ============================================================================
 * Redireciona para produto.php (versao em portugues)
 * ============================================================================
 */

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($product_id) {
    header('Location: /mercado/produto-view.php?id=' . $product_id);
} else {
    header('Location: /mercado/');
}
exit;
