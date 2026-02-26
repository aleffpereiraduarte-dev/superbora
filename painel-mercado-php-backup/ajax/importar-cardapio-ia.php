<?php
/**
 * POST /painel/mercado/ajax/importar-cardapio-ia.php
 * Importa itens extraidos pela IA para o catalogo de produtos
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

if (!isset($_SESSION['mercado_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nao autenticado']);
    exit;
}

require_once dirname(__DIR__, 3) . '/database.php';

$partner_id = (int)$_SESSION['mercado_id'];
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['items'])) {
    echo json_encode(['success' => false, 'message' => 'Nenhum item para importar']);
    exit;
}

$items = $input['items'];
$db = getDB();

$imported = 0;
$skipped = 0;
$errors = [];
$createdCategories = [];

try {
    $db->beginTransaction();

    foreach ($items as $idx => $item) {
        $nome = trim($item['nome'] ?? '');
        if (empty($nome)) {
            $skipped++;
            continue;
        }

        $descricao = trim($item['descricao'] ?? '');
        $preco = max(0, (float)($item['preco'] ?? 0));
        $categoria = trim($item['categoria'] ?? '');
        $categoriaId = (int)($item['categoria_id'] ?? 0);
        $ingredientes = trim($item['ingredientes'] ?? '');
        $observacoes = trim($item['observacoes'] ?? '');

        // Verificar duplicata por nome
        $stmtCheck = $db->prepare("SELECT product_id FROM om_market_products WHERE partner_id = ? AND LOWER(name) = LOWER(?) AND status = 1 LIMIT 1");
        $stmtCheck->execute([$partner_id, $nome]);
        if ($stmtCheck->fetch()) {
            $skipped++;
            continue;
        }

        // Criar categoria se nao existe
        if ($categoriaId <= 0 && !empty($categoria)) {
            // Procurar categoria existente
            $stmtFindCat = $db->prepare("SELECT category_id FROM om_market_categories WHERE partner_id = ? AND LOWER(name) = LOWER(?) AND status = 1 LIMIT 1");
            $stmtFindCat->execute([$partner_id, $categoria]);
            $existingCat = $stmtFindCat->fetch();

            if ($existingCat) {
                $categoriaId = (int)$existingCat['category_id'];
            } else {
                // Criar nova categoria
                $stmtNewCat = $db->prepare("INSERT INTO om_market_categories (partner_id, name, status, sort_order, created_at) VALUES (?, ?, 1, 0, NOW()) RETURNING category_id");
                $stmtNewCat->execute([$partner_id, $categoria]);
                $categoriaId = (int)$stmtNewCat->fetchColumn();
                $createdCategories[] = $categoria;
            }
        }

        // Montar descricao completa
        $fullDescription = $descricao;
        if (!empty($ingredientes) && stripos($descricao, $ingredientes) === false) {
            $fullDescription .= "\n\nIngredientes: " . $ingredientes;
        }
        if (!empty($observacoes)) {
            $fullDescription .= "\n\n" . $observacoes;
        }

        // Determinar dietary flags baseado em observacoes/ingredientes
        $dietaryFlags = [];
        $textoBusca = strtolower($observacoes . ' ' . $ingredientes . ' ' . $descricao);
        if (strpos($textoBusca, 'vegano') !== false || strpos($textoBusca, 'vegan') !== false) {
            $dietaryFlags[] = 'vegano';
        }
        if (strpos($textoBusca, 'vegetariano') !== false) {
            $dietaryFlags[] = 'vegetariano';
        }
        if (strpos($textoBusca, 'sem gluten') !== false || strpos($textoBusca, 'gluten free') !== false) {
            $dietaryFlags[] = 'sem_gluten';
        }
        if (strpos($textoBusca, 'sem lactose') !== false || strpos($textoBusca, 'lactose free') !== false) {
            $dietaryFlags[] = 'sem_lactose';
        }

        // Inserir produto
        $stmtInsert = $db->prepare("
            INSERT INTO om_market_products
                (partner_id, category_id, name, description, price, quantity, stock, unit, status, available, dietary_flags, created_at)
            VALUES (?, ?, ?, ?, ?, 999, 999, 'un', 1, 1, ?, NOW())
            RETURNING product_id
        ");
        $stmtInsert->execute([
            $partner_id,
            $categoriaId > 0 ? $categoriaId : null,
            $nome,
            trim($fullDescription),
            $preco,
            !empty($dietaryFlags) ? json_encode($dietaryFlags) : null
        ]);

        $productId = (int)$stmtInsert->fetchColumn();
        $imported++;
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "$imported produto(s) importado(s) com sucesso!" . ($skipped > 0 ? " $skipped pulado(s) (duplicados ou sem nome)." : ''),
        'data' => [
            'imported' => $imported,
            'skipped' => $skipped,
            'categories_created' => $createdCategories
        ]
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log("[importar-cardapio-ia] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao importar: ' . $e->getMessage()]);
}
