<?php
/**
 * API para listar lojas populares
 * Retorna lojas ordenadas por seguidores e visualizações
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$config_root = dirname(__DIR__);
require_once $config_root . '/config/database.php';

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error']);
    exit;
}

$limit = min(20, max(1, intval($_GET['limit'] ?? 8)));
$categoria = $_GET['categoria'] ?? '';

$sql = "
    SELECT
        l.loja_id,
        l.nome_loja,
        l.slug,
        l.logo_url as logo,
        l.descricao_curta as slogan,
        COALESCE((SELECT COUNT(*) FROM om_loja_seguidores s WHERE s.loja_id = l.loja_id), 0) as total_seguidores,
        COALESCE(l.views, 0) as visualizacoes,
        0 as total_vendas,
        (COALESCE((SELECT COUNT(*) FROM om_loja_seguidores s WHERE s.loja_id = l.loja_id), 0) * 2 + COALESCE(l.views, 0)) as popularidade
    FROM om_lojas_personalizadas l
    WHERE l.status = 'ativo'
    ORDER BY popularidade DESC
    LIMIT ?
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();
$lojas = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'lojas' => $lojas
]);
