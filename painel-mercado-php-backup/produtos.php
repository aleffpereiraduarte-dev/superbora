<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Gestão de Produtos v2.0
 * Com Scanner de Código de Barras, Busca no Catálogo Base e Localização
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
$db = getDB();

$mercado_id = $_SESSION['mercado_id'];
$mercado_nome = $_SESSION['mercado_nome'];

// Processar ações
$message = '';
$error = '';

// ═══════════════════════════════════════════════════════════════════
// CSV EXPORT — must run before any HTML output
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv') {
    $stmt = $db->prepare("
        SELECT sku, barcode, name, category, price, suggested_price, quantity, unit, status, allergens, dietary_flags
        FROM om_market_products
        WHERE partner_id = ?
        ORDER BY name ASC
    ");
    $stmt->execute([$mercado_id]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="produtos_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['SKU', 'Codigo Barras', 'Nome', 'Categoria', 'Preco', 'Preco Sugerido', 'Estoque', 'Unidade', 'Status', 'Alergenos', 'Dieta'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['sku'] ?? '',
            $r['barcode'] ?? '',
            $r['name'],
            $r['category'] ?? '',
            number_format($r['price'], 2, ',', ''),
            number_format($r['suggested_price'] ?? 0, 2, ',', ''),
            $r['quantity'],
            $r['unit'] ?? 'un',
            $r['status'] ? 'Ativo' : 'Inativo',
            $r['allergens'] ?? '[]',
            $r['dietary_flags'] ?? '[]'
        ], ';');
    }
    fclose($out);
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// BULK ACTIONS (AJAX — returns JSON)
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $bulk = $_POST['bulk_action'];
    $ids_raw = $_POST['product_ids'] ?? '';
    $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'Nenhum produto selecionado']);
        exit;
    }

    // Validate ownership: all product IDs must belong to this mercado
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_products WHERE product_id IN ($placeholders) AND partner_id = ?");
    $stmt->execute(array_merge($ids, [$mercado_id]));
    if ($stmt->fetchColumn() != count($ids)) {
        echo json_encode(['success' => false, 'message' => 'Acesso negado a um ou mais produtos']);
        exit;
    }

    try {
        if ($bulk === 'bulk_edit_price') {
            $mode = $_POST['price_mode'] ?? ''; // fixed, increase_pct, decrease_pct
            $value = floatval(str_replace(['.', ','], ['', '.'], $_POST['price_value'] ?? '0'));
            if ($value <= 0) {
                echo json_encode(['success' => false, 'message' => 'Valor invalido']);
                exit;
            }
            if ($mode === 'fixed') {
                $stmt = $db->prepare("UPDATE om_market_products SET price = ? WHERE product_id IN ($placeholders) AND partner_id = ?");
                $stmt->execute(array_merge([$value], $ids, [$mercado_id]));
            } elseif ($mode === 'increase_pct') {
                $factor = 1 + ($value / 100);
                $stmt = $db->prepare("UPDATE om_market_products SET price = ROUND(price * $factor, 2) WHERE product_id IN ($placeholders) AND partner_id = ?");
                $stmt->execute(array_merge($ids, [$mercado_id]));
            } elseif ($mode === 'decrease_pct') {
                $factor = 1 - ($value / 100);
                if ($factor < 0) $factor = 0;
                $stmt = $db->prepare("UPDATE om_market_products SET price = ROUND(price * $factor, 2) WHERE product_id IN ($placeholders) AND partner_id = ?");
                $stmt->execute(array_merge($ids, [$mercado_id]));
            } else {
                echo json_encode(['success' => false, 'message' => 'Modo invalido']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => count($ids) . ' produtos atualizados (preco)']);
            exit;

        } elseif ($bulk === 'bulk_edit_stock') {
            $mode = $_POST['stock_mode'] ?? ''; // fixed, add
            $value = intval($_POST['stock_value'] ?? 0);
            if ($mode === 'fixed') {
                if ($value < 0) $value = 0;
                $stmt = $db->prepare("UPDATE om_market_products SET quantity = ? WHERE product_id IN ($placeholders) AND partner_id = ?");
                $stmt->execute(array_merge([$value], $ids, [$mercado_id]));
            } elseif ($mode === 'add') {
                $stmt = $db->prepare("UPDATE om_market_products SET quantity = GREATEST(0, quantity + ?) WHERE product_id IN ($placeholders) AND partner_id = ?");
                $stmt->execute(array_merge([$value], $ids, [$mercado_id]));
            } else {
                echo json_encode(['success' => false, 'message' => 'Modo invalido']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => count($ids) . ' produtos atualizados (estoque)']);
            exit;

        } elseif ($bulk === 'bulk_toggle_status') {
            $stmt = $db->prepare("UPDATE om_market_products SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END WHERE product_id IN ($placeholders) AND partner_id = ?");
            $stmt->execute(array_merge($ids, [$mercado_id]));
            echo json_encode(['success' => true, 'message' => count($ids) . ' produtos alternados (status)']);
            exit;

        } else {
            echo json_encode(['success' => false, 'message' => 'Acao bulk desconhecida']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════
// CSV IMPORT (AJAX — returns JSON)
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Erro no upload do arquivo']);
        exit;
    }

    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle) {
        echo json_encode(['success' => false, 'message' => 'Nao foi possivel ler o arquivo']);
        exit;
    }

    // Skip BOM if present
    $bom = fread($handle, 3);
    if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
        rewind($handle);
    }

    // Read header
    $header = fgetcsv($handle, 0, ';');
    if (!$header || count($header) < 3) {
        // Try comma separator
        rewind($handle);
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            rewind($handle);
        }
        $header = fgetcsv($handle, 0, ',');
    }

    if (!$header || count($header) < 3) {
        fclose($handle);
        echo json_encode(['success' => false, 'message' => 'Formato de CSV invalido. Use ponto-e-virgula como separador.']);
        exit;
    }

    // Detect separator
    $sep = (count($header) >= 3) ? ';' : ',';
    // Normalize header
    $header = array_map(function($h) {
        return strtolower(trim(preg_replace('/[^\w]/', '', $h)));
    }, $header);

    $updated = 0;
    $created = 0;
    $errors = 0;
    $errorMessages = [];

    $row_num = 1;
    while (($row = fgetcsv($handle, 0, $sep)) !== false) {
        $row_num++;
        if (count($row) < 3) { $errors++; continue; }

        $data = [];
        foreach ($header as $i => $col) {
            $data[$col] = $row[$i] ?? '';
        }

        $name = trim($data['nome'] ?? '');
        $barcode = trim($data['codigobarras'] ?? $data['barcode'] ?? '');
        $sku = trim($data['sku'] ?? '');
        $category = trim($data['categoria'] ?? $data['category'] ?? '');
        $price_str = trim($data['preco'] ?? $data['price'] ?? '0');
        $price = floatval(str_replace(['.', ','], ['', '.'], $price_str));
        $suggested_str = trim($data['precosugerido'] ?? $data['suggestedprice'] ?? '0');
        $suggested_price = floatval(str_replace(['.', ','], ['', '.'], $suggested_str)) ?: null;
        $quantity = intval($data['estoque'] ?? $data['quantity'] ?? 0);
        $unit = trim($data['unidade'] ?? $data['unit'] ?? 'un') ?: 'un';
        $status_str = trim($data['status'] ?? '1');
        $status = (strtolower($status_str) === 'inativo' || $status_str === '0') ? 0 : 1;
        $allergens = trim($data['alergenos'] ?? $data['allergens'] ?? '[]') ?: '[]';
        $dietary = trim($data['dieta'] ?? $data['dietaryflags'] ?? '[]') ?: '[]';

        if (!$name) {
            $errors++;
            $errorMessages[] = "Linha $row_num: nome vazio";
            continue;
        }
        if ($price <= 0) {
            $errors++;
            $errorMessages[] = "Linha $row_num: preco invalido ($name)";
            continue;
        }

        // Try match by barcode first, then SKU
        $existing = null;
        if ($barcode) {
            $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE partner_id = ? AND barcode = ?");
            $stmt->execute([$mercado_id, $barcode]);
            $existing = $stmt->fetchColumn();
        }
        if (!$existing && $sku) {
            $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE partner_id = ? AND sku = ?");
            $stmt->execute([$mercado_id, $sku]);
            $existing = $stmt->fetchColumn();
        }

        if ($existing) {
            $stmt = $db->prepare("
                UPDATE om_market_products SET
                    name = ?, category = ?, price = ?, suggested_price = ?,
                    quantity = ?, unit = ?, sku = ?, barcode = ?, status = ?,
                    allergens = ?, dietary_flags = ?
                WHERE product_id = ? AND partner_id = ?
            ");
            $stmt->execute([$name, $category, $price, $suggested_price, $quantity, $unit, $sku, $barcode, $status, $allergens, $dietary, $existing, $mercado_id]);
            $updated++;
        } else {
            $stmt = $db->prepare("
                INSERT INTO om_market_products
                (partner_id, category, name, price, suggested_price, quantity, unit, sku, barcode, status, allergens, dietary_flags, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$mercado_id, $category, $name, $price, $suggested_price, $quantity, $unit, $sku, $barcode, $status, $allergens, $dietary]);
            $created++;
        }
    }
    fclose($handle);

    $msg = "$updated produtos atualizados, $created novos";
    if ($errors > 0) $msg .= ", $errors erros";
    echo json_encode([
        'success' => true,
        'message' => $msg,
        'updated' => $updated,
        'created' => $created,
        'errors' => $errors,
        'errorDetails' => array_slice($errorMessages, 0, 10)
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_status') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $stmt = $db->prepare("UPDATE om_market_products SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$product_id, $mercado_id]);
        $message = 'Status do produto atualizado';
    }

    if ($action === 'toggle_available') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $stmt = $db->prepare("SELECT available FROM om_market_products WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$product_id, $mercado_id]);
        $current = $stmt->fetchColumn();
        $new_val = $current ? 0 : 1;
        $unavailable_at = $new_val ? null : date('Y-m-d H:i:s');
        $stmt = $db->prepare("UPDATE om_market_products SET available = ?, unavailable_at = ? WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$new_val, $unavailable_at, $product_id, $mercado_id]);
        $message = $new_val ? 'Produto reativado' : 'Produto pausado (indisponivel)';
    }

    if ($action === 'update_stock') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $stmt = $db->prepare("UPDATE om_market_products SET quantity = ? WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$quantity, $product_id, $mercado_id]);
        $message = 'Estoque atualizado';
    }

    if ($action === 'update_price') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $price = floatval(str_replace(['.', ','], ['', '.'], $_POST['price'] ?? 0));
        $stmt = $db->prepare("UPDATE om_market_products SET price = ? WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$price, $product_id, $mercado_id]);
        $message = 'Preço atualizado';
    }

    if ($action === 'delete') {
        $product_id = intval($_POST['product_id'] ?? 0);
        // Remove combo items (both as combo and as component)
        $db->prepare("DELETE FROM om_market_combo_items WHERE combo_product_id = ? OR item_product_id = ?")->execute([$product_id, $product_id]);
        $stmt = $db->prepare("DELETE FROM om_market_products WHERE product_id = ? AND partner_id = ?");
        $stmt->execute([$product_id, $mercado_id]);
        $message = 'Produto removido';
    }

    if ($action === 'create' || $action === 'update') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $base_product_id = intval($_POST['base_product_id'] ?? 0) ?: null;
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 0);
        $category = trim($_POST['category_name'] ?? '');
        $price = floatval(str_replace(['.', ','], ['', '.'], $_POST['price'] ?? 0));
        $suggested_price = floatval(str_replace(['.', ','], ['', '.'], $_POST['suggested_price'] ?? 0)) ?: null;
        $quantity = intval($_POST['quantity'] ?? 0);
        $unit = trim($_POST['unit'] ?? 'un');
        $sku = trim($_POST['sku'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $weight = trim($_POST['weight'] ?? '');
        $image = trim($_POST['image'] ?? '');

        // Localização
        $location_aisle = trim($_POST['location_aisle'] ?? '');
        $location_shelf = trim($_POST['location_shelf'] ?? '');
        $location_section = trim($_POST['location_section'] ?? '');
        $location_notes = trim($_POST['location_notes'] ?? '');

        // Allergens & Dietary Flags
        $allergens_raw = $_POST['allergens'] ?? '[]';
        $dietary_raw = $_POST['dietary_flags'] ?? '[]';
        // Validate JSON arrays
        $allergens_arr = json_decode($allergens_raw, true);
        $dietary_arr = json_decode($dietary_raw, true);
        $allergens_json = is_array($allergens_arr) ? json_encode(array_values($allergens_arr)) : '[]';
        $dietary_json = is_array($dietary_arr) ? json_encode(array_values($dietary_arr)) : '[]';

        // Multiple images
        $images_raw = $_POST['images'] ?? '[]';
        $images_arr = json_decode($images_raw, true);
        $images_json = is_array($images_arr) ? json_encode(array_values(array_slice($images_arr, 0, 5))) : '[]';

        // Combo
        $is_combo = !empty($_POST['is_combo']);
        $combo_items_raw = $_POST['combo_items'] ?? '[]';
        $combo_items = json_decode($combo_items_raw, true);
        if (!is_array($combo_items)) $combo_items = [];

        if ($name && $price > 0) {
            if ($action === 'create') {
                $stmt = $db->prepare("
                    INSERT INTO om_market_products
                    (partner_id, base_product_id, category_id, category, name, description, price, suggested_price,
                     quantity, unit, sku, barcode, brand, weight, image, images,
                     location_aisle, location_shelf, location_section, location_notes,
                     allergens, dietary_flags, is_combo, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                    RETURNING product_id
                ");
                $stmt->execute([
                    $mercado_id, $base_product_id, $category_id, $category, $name, $description,
                    $price, $suggested_price, $quantity, $unit, $sku, $barcode, $brand, $weight, $image, $images_json,
                    $location_aisle, $location_shelf, $location_section, $location_notes,
                    $allergens_json, $dietary_json, $is_combo ? 1 : 0
                ]);
                $new_product_id = $stmt->fetchColumn();

                // Save combo items
                if ($is_combo && !empty($combo_items)) {
                    $stmt_combo = $db->prepare("INSERT INTO om_market_combo_items (combo_product_id, item_product_id, quantity) VALUES (?, ?, ?) ON CONFLICT (combo_product_id, item_product_id) DO UPDATE SET quantity = EXCLUDED.quantity");
                    foreach ($combo_items as $ci) {
                        $ci_id = intval($ci['product_id'] ?? 0);
                        $ci_qty = max(1, intval($ci['quantity'] ?? 1));
                        if ($ci_id > 0) {
                            $stmt_combo->execute([$new_product_id, $ci_id, $ci_qty]);
                        }
                    }
                }

                $message = 'Produto cadastrado com sucesso!';
            } else {
                $stmt = $db->prepare("
                    UPDATE om_market_products SET
                        base_product_id = ?, category_id = ?, category = ?, name = ?, description = ?,
                        price = ?, suggested_price = ?, quantity = ?, unit = ?, sku = ?, barcode = ?,
                        brand = ?, weight = ?, image = ?, images = ?,
                        location_aisle = ?, location_shelf = ?, location_section = ?, location_notes = ?,
                        allergens = ?, dietary_flags = ?, is_combo = ?
                    WHERE product_id = ? AND partner_id = ?
                ");
                $stmt->execute([
                    $base_product_id, $category_id, $category, $name, $description,
                    $price, $suggested_price, $quantity, $unit, $sku, $barcode, $brand, $weight, $image, $images_json,
                    $location_aisle, $location_shelf, $location_section, $location_notes,
                    $allergens_json, $dietary_json, $is_combo ? 1 : 0,
                    $product_id, $mercado_id
                ]);

                // Update combo items — delete old, insert new
                $db->prepare("DELETE FROM om_market_combo_items WHERE combo_product_id = ?")->execute([$product_id]);
                if ($is_combo && !empty($combo_items)) {
                    $stmt_combo = $db->prepare("INSERT INTO om_market_combo_items (combo_product_id, item_product_id, quantity) VALUES (?, ?, ?) ON CONFLICT (combo_product_id, item_product_id) DO UPDATE SET quantity = EXCLUDED.quantity");
                    foreach ($combo_items as $ci) {
                        $ci_id = intval($ci['product_id'] ?? 0);
                        $ci_qty = max(1, intval($ci['quantity'] ?? 1));
                        if ($ci_id > 0) {
                            $stmt_combo->execute([$product_id, $ci_id, $ci_qty]);
                        }
                    }
                }

                $message = 'Produto atualizado com sucesso!';
            }
        } else {
            $error = 'Nome e preço são obrigatórios';
        }
    }

    // Importar do catálogo base (Brain 57.000+ produtos)
    if ($action === 'import_from_base') {
        $base_id = intval($_POST['base_product_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT product_id, barcode, name, descricao as description, brand,
                   ai_category as category, unit, size, image, suggested_price
            FROM om_market_products_base
            WHERE product_id = ?
        ");
        $stmt->execute([$base_id]);
        $base_product = $stmt->fetch();

        if ($base_product) {
            // Verificar se já existe
            $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE partner_id = ? AND barcode = ?");
            $stmt->execute([$mercado_id, $base_product['barcode']]);
            if ($stmt->fetch()) {
                $error = 'Este produto já está cadastrado no seu catálogo';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO om_market_products
                    (partner_id, base_product_id, category, name, description, price, suggested_price,
                     quantity, unit, barcode, brand, weight, image, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 1, NOW())
                ");
                $stmt->execute([
                    $mercado_id, $base_product['product_id'], $base_product['category'],
                    $base_product['name'], $base_product['description'],
                    $base_product['suggested_price'] ?? 0, $base_product['suggested_price'],
                    $base_product['unit'], $base_product['barcode'], $base_product['brand'],
                    $base_product['size'], $base_product['image']
                ]);
                $message = "Produto '{$base_product['name']}' importado! Defina o preço e localização.";
            }
        }
    }

    // Solicitar cadastro de produto novo (não existe no Brain)
    if ($action === 'request_new_product') {
        $name = trim($_POST['name'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $category = trim($_POST['category_name'] ?? '');
        $price = floatval(str_replace(['.', ','], ['', '.'], $_POST['price'] ?? 0));

        if ($name && $barcode) {
            // Inserir na tabela de produtos pendentes ou diretamente no brain
            $stmt = $db->prepare("
                INSERT INTO om_market_products_base
                (barcode, name, brand, ai_category, suggested_price, added_by_partner, status, date_added)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
                ON CONFLICT (barcode) DO UPDATE SET name = EXCLUDED.name
            ");
            $stmt->execute([$barcode, $name, $brand, $category, $price, $mercado_id]);
            $new_base_id = $db->lastInsertId();

            // Adicionar ao catálogo do mercado
            $stmt = $db->prepare("
                INSERT INTO om_market_products
                (partner_id, base_product_id, category, name, price, suggested_price,
                 quantity, unit, barcode, brand, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 'un', ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $mercado_id, $new_base_id, $category, $name, $price, $price, $barcode, $brand
            ]);

            $message = "Produto '$name' cadastrado! Nossa equipe irá enriquecer os dados em breve.";
        } else {
            $error = 'Nome e código de barras são obrigatórios';
        }
    }
}

// ═══════════════════════════════════════════════════════════════════
// COMBO PRODUCT SEARCH (AJAX — returns JSON)
// ═══════════════════════════════════════════════════════════════════
if (isset($_GET['combo_search'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode(['products' => []]);
        exit;
    }
    $stmt = $db->prepare("
        SELECT product_id, name, price
        FROM om_market_products
        WHERE partner_id = ? AND status = 1 AND is_combo = FALSE AND name ILIKE ?
        ORDER BY name ASC
        LIMIT 15
    ");
    $stmt->execute([$mercado_id, "%$q%"]);
    echo json_encode(['products' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// LOAD COMBO ITEMS (AJAX — returns JSON)
// ═══════════════════════════════════════════════════════════════════
if (isset($_GET['load_combo_items'])) {
    header('Content-Type: application/json; charset=utf-8');
    $pid = intval($_GET['product_id'] ?? 0);
    // Verify ownership
    $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?");
    $stmt->execute([$pid, $mercado_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['items' => []]);
        exit;
    }
    $stmt = $db->prepare("
        SELECT ci.item_product_id as product_id, ci.quantity, p.name
        FROM om_market_combo_items ci
        JOIN om_market_products p ON p.product_id = ci.item_product_id
        WHERE ci.combo_product_id = ?
        ORDER BY p.name
    ");
    $stmt->execute([$pid]);
    echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// Filtros
$categoria_filter = $_GET['categoria'] ?? '';
$status_filter = $_GET['status'] ?? '';
$busca = $_GET['busca'] ?? '';
$pagina = max(1, intval($_GET['pagina'] ?? 1));
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

// Query base
$where = "WHERE p.partner_id = ?";
$params = [$mercado_id];

if ($categoria_filter) {
    $where .= " AND (p.category_id = ? OR p.category = ?)";
    $params[] = $categoria_filter;
    $params[] = $categoria_filter;
}

if ($status_filter !== '') {
    $where .= " AND p.status = ?";
    $params[] = $status_filter;
}

if ($busca) {
    $where .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.brand LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

// Total para paginação
$stmt = $db->prepare("SELECT COUNT(*) FROM om_market_products p $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_paginas = ceil($total / $por_pagina);

// Buscar produtos
$sql = "SELECT p.*, c.name as category_name
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON p.category_id = c.category_id
        $where
        ORDER BY p.name ASC
        LIMIT $por_pagina OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$produtos = $stmt->fetchAll();

// Buscar categorias para filtro
$stmt = $db->query("SELECT category_id, name FROM om_market_categories WHERE status = 1 ORDER BY sort_order, name");
$categorias = $stmt->fetchAll();

// Categorias únicas dos produtos base (Brain)
$stmt = $db->query("
    SELECT DISTINCT ai_category as category
    FROM om_market_products_base
    WHERE ai_category IS NOT NULL AND ai_category != '' AND status = 1
    ORDER BY ai_category
    LIMIT 50
");
$categorias_base = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Estatísticas
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as ativos,
        SUM(CASE WHEN quantity <= 5 AND quantity > 0 THEN 1 ELSE 0 END) as estoque_baixo,
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as sem_estoque,
        SUM(CASE WHEN location_aisle IS NULL OR location_aisle = '' THEN 1 ELSE 0 END) as sem_localizacao
    FROM om_market_products
    WHERE partner_id = ?
");
$stmt->execute([$mercado_id]);
$stats = $stmt->fetch();

// Total de produtos na base (Brain com 57.000+ produtos)
$stmt = $db->query("SELECT COUNT(*) FROM om_market_products_base WHERE status = 1");
$total_base = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produtos - Painel do Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
</head>
<body class="om-app-layout">
    <!-- Sidebar -->
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>OneMundo</span>'">
        </div>

        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link">
                <i class="lucide-layout-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="pedidos.php" class="om-sidebar-link">
                <i class="lucide-shopping-bag"></i>
                <span>Pedidos</span>
            </a>
            <a href="produtos.php" class="om-sidebar-link active">
                <i class="lucide-package"></i>
                <span>Produtos</span>
            </a>
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
            <a href="categorias.php" class="om-sidebar-link">
                <i class="lucide-tags"></i>
                <span>Categorias</span>
            </a>
            <a href="faturamento.php" class="om-sidebar-link">
                <i class="lucide-bar-chart-3"></i>
                <span>Faturamento</span>
            </a>
            <a href="repasses.php" class="om-sidebar-link">
                <i class="lucide-wallet"></i>
                <span>Repasses</span>
            </a>
            <a href="avaliacoes.php" class="om-sidebar-link">
                <i class="lucide-star"></i>
                <span>Avaliacoes</span>
            </a>
            <a href="horarios.php" class="om-sidebar-link">
                <i class="lucide-clock"></i>
                <span>Horários</span>
            </a>
            <a href="perfil.php" class="om-sidebar-link">
                <i class="lucide-settings"></i>
                <span>Configurações</span>
            </a>
        </nav>

        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link">
                <i class="lucide-log-out"></i>
                <span>Sair</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="om-main-content">
        <!-- Topbar -->
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>

            <h1 class="om-topbar-title">Produtos</h1>

            <div class="om-topbar-actions">
                <form method="POST" style="display:inline;" id="formExportCsv">
                    <input type="hidden" name="action" value="export_csv">
                    <button type="submit" class="om-btn om-btn-outline" title="Exportar CSV">
                        <i class="lucide-download"></i>
                        <span class="om-hide-mobile">Exportar CSV</span>
                    </button>
                </form>
                <button class="om-btn om-btn-outline" onclick="abrirModalImportCsv()" title="Importar CSV">
                    <i class="lucide-upload"></i>
                    <span class="om-hide-mobile">Importar CSV</span>
                </button>
                <button class="om-btn om-btn-outline" onclick="abrirScanner()" title="Escanear Código de Barras">
                    <i class="lucide-scan-barcode"></i>
                    <span class="om-hide-mobile">Scanner</span>
                </button>
                <button class="om-btn om-btn-secondary" onclick="abrirCatalogo()" title="Buscar no Catálogo">
                    <i class="lucide-database"></i>
                    <span class="om-hide-mobile">Catálogo (<?= number_format($total_base, 0, ',', '.') ?>)</span>
                </button>
                <button class="om-btn om-btn-primary" onclick="abrirModalProduto()">
                    <i class="lucide-plus"></i>
                    <span class="om-hide-mobile">Novo Produto</span>
                </button>
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="om-page-content">
            <?php if ($message): ?>
            <div class="om-alert om-alert-success om-mb-4">
                <i class="lucide-check-circle"></i>
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($message) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="om-alert om-alert-error om-mb-4">
                <i class="lucide-alert-circle"></i>
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="om-stats-grid om-mb-6">
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-primary-light">
                        <i class="lucide-package"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['total'] ?? 0 ?></span>
                        <span class="om-stat-label">Total de Produtos</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light">
                        <i class="lucide-check-circle"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['ativos'] ?? 0 ?></span>
                        <span class="om-stat-label">Ativos</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-warning-light">
                        <i class="lucide-alert-triangle"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['estoque_baixo'] ?? 0 ?></span>
                        <span class="om-stat-label">Estoque Baixo</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-info-light">
                        <i class="lucide-map-pin"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['sem_localizacao'] ?? 0 ?></span>
                        <span class="om-stat-label">Sem Localização</span>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="om-card om-mb-6">
                <div class="om-card-body">
                    <form method="GET" class="om-filters-form">
                        <div class="om-form-row">
                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Buscar</label>
                                <input type="text" name="busca" class="om-input" placeholder="Nome, SKU, código de barras ou marca..." value="<?= htmlspecialchars($busca) ?>">
                            </div>

                            <div class="om-form-group om-col-md-3">
                                <label class="om-label">Categoria</label>
                                <select name="categoria" class="om-select">
                                    <option value="">Todas</option>
                                    <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['category_id'] ?>" <?= $categoria_filter == $cat['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                    <?php foreach ($categorias_base as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>" <?= $categoria_filter == $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="om-form-group om-col-md-2">
                                <label class="om-label">Status</label>
                                <select name="status" class="om-select">
                                    <option value="">Todos</option>
                                    <option value="1" <?= $status_filter === '1' ? 'selected' : '' ?>>Ativo</option>
                                    <option value="0" <?= $status_filter === '0' ? 'selected' : '' ?>>Inativo</option>
                                </select>
                            </div>

                            <div class="om-form-group om-col-md-3 om-flex om-items-end om-gap-2">
                                <button type="submit" class="om-btn om-btn-primary">
                                    <i class="lucide-search"></i> Filtrar
                                </button>
                                <a href="produtos.php" class="om-btn om-btn-outline">Limpar</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista de Produtos -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">Catálogo de Produtos</h3>
                    <span class="om-badge om-badge-neutral"><?= $total ?> produtos</span>
                </div>

                <div class="om-table-responsive">
                    <table class="om-table">
                        <thead>
                            <tr>
                                <th style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                                <th>Produto</th>
                                <th>Localização</th>
                                <th class="om-text-right">Preço</th>
                                <th class="om-text-center">Estoque</th>
                                <th class="om-text-center">Disponivel</th>
                                <th class="om-text-center">Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($produtos)): ?>
                            <tr>
                                <td colspan="8" class="om-text-center om-py-8">
                                    <div class="om-empty-state">
                                        <i class="lucide-package om-text-4xl om-text-muted"></i>
                                        <p class="om-mt-2">Nenhum produto encontrado</p>
                                        <div class="om-flex om-gap-2 om-justify-center om-mt-4">
                                            <button class="om-btn om-btn-secondary om-btn-sm" onclick="abrirScanner()">
                                                <i class="lucide-scan-barcode"></i> Escanear
                                            </button>
                                            <button class="om-btn om-btn-primary om-btn-sm" onclick="abrirCatalogo()">
                                                <i class="lucide-database"></i> Buscar no Catálogo
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($produtos as $produto):
                                $p_allergens = json_decode($produto['allergens'] ?? '[]', true) ?: [];
                                $p_dietary = json_decode($produto['dietary_flags'] ?? '[]', true) ?: [];
                                $p_images = json_decode($produto['images'] ?? '[]', true) ?: [];
                                $p_extra_images = count($p_images);
                            ?>
                            <tr>
                                <td><input type="checkbox" class="bulk-check" value="<?= $produto['product_id'] ?>" onchange="updateBulkBar()"></td>
                                <td>
                                    <div class="om-flex om-items-center om-gap-3">
                                        <div class="om-product-thumb" style="position:relative;">
                                            <?php if ($produto['image']): ?>
                                            <img src="<?= htmlspecialchars($produto['image']) ?>" alt="" onerror="this.parentElement.innerHTML='<i class=\'lucide-package\'></i>'">
                                            <?php else: ?>
                                            <i class="lucide-package"></i>
                                            <?php endif; ?>
                                            <?php if ($p_extra_images > 0): ?>
                                            <span class="om-img-count-badge">+<?= $p_extra_images ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="om-font-medium">
                                                <?php if (!empty($produto['is_combo'])): ?>
                                                <span class="om-combo-badge">COMBO</span>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($produto['name']) ?>
                                                <?php foreach ($p_dietary as $df): ?>
                                                    <?php if ($df === 'vegano'): ?><span class="om-diet-badge om-diet-vegano" title="Vegano">&#127793; Vegano</span>
                                                    <?php elseif ($df === 'vegetariano'): ?><span class="om-diet-badge om-diet-vegetariano" title="Vegetariano">&#129388; Vegetariano</span>
                                                    <?php elseif ($df === 'sem_gluten'): ?><span class="om-diet-badge om-diet-semgluten" title="Sem Gluten">&#127806; Sem Gluten</span>
                                                    <?php elseif ($df === 'sem_lactose'): ?><span class="om-diet-badge om-diet-semlactose" title="Sem Lactose">&#129371; Sem Lactose</span>
                                                    <?php elseif ($df === 'organico'): ?><span class="om-diet-badge om-diet-organico" title="Organico">&#127807; Organico</span>
                                                    <?php elseif ($df === 'zero_acucar'): ?><span class="om-diet-badge om-diet-zeroacucar" title="Zero Acucar">&#128293; Zero Acucar</span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="om-text-xs om-text-muted">
                                                <?php if ($produto['brand']): ?>
                                                <span class="om-mr-2"><?= htmlspecialchars($produto['brand']) ?></span>
                                                <?php endif; ?>
                                                <?php if ($produto['barcode']): ?>
                                                <span class="om-text-mono"><?= htmlspecialchars($produto['barcode']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($p_allergens)): ?>
                                                <span class="om-allergen-inline" title="Alergenos: <?= htmlspecialchars(implode(', ', $p_allergens)) ?>">&#9888;&#65039; <?= htmlspecialchars(implode(', ', $p_allergens)) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($produto['location_aisle'] || $produto['location_section']): ?>
                                    <div class="om-location-badge">
                                        <?php if ($produto['location_aisle']): ?>
                                        <span class="om-badge om-badge-info">
                                            <i class="lucide-map-pin"></i>
                                            <?= htmlspecialchars($produto['location_aisle']) ?>
                                            <?= $produto['location_shelf'] ? '/' . $produto['location_shelf'] : '' ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($produto['location_section']): ?>
                                        <span class="om-text-xs om-text-muted"><?= htmlspecialchars($produto['location_section']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                    <button class="om-btn om-btn-xs om-btn-ghost om-text-warning" onclick="editarProduto(<?= htmlspecialchars(json_encode($produto)) ?>)" title="Adicionar localização">
                                        <i class="lucide-map-pin-off"></i> Definir
                                    </button>
                                    <?php endif; ?>
                                </td>
                                <td class="om-text-right">
                                    <div>
                                        <span class="om-font-semibold">R$ <?= number_format($produto['price'], 2, ',', '.') ?></span>
                                        <?php if ($produto['suggested_price'] && $produto['suggested_price'] != $produto['price']): ?>
                                        <div class="om-text-xs om-text-muted">
                                            Sugerido: R$ <?= number_format($produto['suggested_price'], 2, ',', '.') ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="om-text-center">
                                    <?php
                                    $qty = $produto['quantity'];
                                    $qty_class = $qty > 5 ? 'success' : ($qty > 0 ? 'warning' : 'error');
                                    ?>
                                    <span class="om-badge om-badge-<?= $qty_class ?>"><?= $qty ?> <?= $produto['unit'] ?></span>
                                </td>
                                <td class="om-text-center">
                                    <form method="POST" class="om-inline-form">
                                        <input type="hidden" name="action" value="toggle_available">
                                        <input type="hidden" name="product_id" value="<?= $produto['product_id'] ?>">
                                        <button type="submit" class="om-switch <?= ($produto['available'] ?? 1) ? 'active' : '' ?>" title="<?= ($produto['available'] ?? 1) ? 'Disponivel - clique para pausar' : 'Pausado - clique para reativar' ?>">
                                            <span class="om-switch-slider"></span>
                                        </button>
                                    </form>
                                </td>
                                <td class="om-text-center">
                                    <form method="POST" class="om-inline-form">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="product_id" value="<?= $produto['product_id'] ?>">
                                        <button type="submit" class="om-switch <?= $produto['status'] ? 'active' : '' ?>">
                                            <span class="om-switch-slider"></span>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="om-btn-group">
                                        <button class="om-btn om-btn-sm om-btn-ghost" onclick="editarProduto(<?= htmlspecialchars(json_encode($produto)) ?>)" title="Editar">
                                            <i class="lucide-pencil"></i>
                                        </button>
                                        <a href="produto-opcoes.php?product_id=<?= $produto['product_id'] ?>" class="om-btn om-btn-sm om-btn-ghost" title="Opcoes/Complementos">
                                            <i class="lucide-layers"></i>
                                        </a>
                                        <button class="om-btn om-btn-sm om-btn-ghost" onclick="ajustarEstoque(<?= $produto['product_id'] ?>, <?= $produto['quantity'] ?>)" title="Ajustar Estoque">
                                            <i class="lucide-package-plus"></i>
                                        </button>
                                        <button class="om-btn om-btn-sm om-btn-ghost om-text-error" onclick="confirmarExclusao(<?= $produto['product_id'] ?>, '<?= htmlspecialchars(addslashes($produto['name'])) ?>')" title="Excluir">
                                            <i class="lucide-trash-2"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                <div class="om-card-footer">
                    <div class="om-pagination">
                        <?php if ($pagina > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>" class="om-pagination-btn">
                            <i class="lucide-chevron-left"></i>
                        </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $pagina - 2); $i <= min($total_paginas, $pagina + 2); $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"
                           class="om-pagination-btn <?= $i === $pagina ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                        <?php endfor; ?>

                        <?php if ($pagina < $total_paginas): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>" class="om-pagination-btn">
                            <i class="lucide-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal Scanner -->
    <div id="modalScanner" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharScanner()"></div>
        <div class="om-modal-content om-modal-md">
            <div class="om-modal-header">
                <h3 class="om-modal-title"><i class="lucide-scan-barcode"></i> Scanner de Código de Barras</h3>
                <button class="om-modal-close" onclick="fecharScanner()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <div class="om-modal-body">
                <div id="scanner-container">
                    <div id="scanner-reader" style="width: 100%; max-width: 400px; margin: 0 auto;"></div>
                </div>
                <div class="om-text-center om-mt-4">
                    <p class="om-text-muted om-text-sm">Aponte a câmera para o código de barras do produto</p>
                </div>
                <div class="om-divider om-my-4">
                    <span>ou digite manualmente</span>
                </div>
                <div class="om-form-group">
                    <div class="om-input-group">
                        <input type="text" id="barcodeManual" class="om-input" placeholder="Digite o código de barras..." maxlength="20">
                        <button type="button" class="om-btn om-btn-primary" onclick="buscarPorCodigo(document.getElementById('barcodeManual').value)">
                            <i class="lucide-search"></i>
                        </button>
                    </div>
                </div>
                <div id="scanner-result" class="om-mt-4" style="display: none;">
                    <!-- Resultado da busca -->
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Catálogo Base -->
    <div id="modalCatalogo" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharCatalogo()"></div>
        <div class="om-modal-content om-modal-lg">
            <div class="om-modal-header">
                <h3 class="om-modal-title"><i class="lucide-database"></i> Catálogo de Produtos Base</h3>
                <button class="om-modal-close" onclick="fecharCatalogo()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <div class="om-modal-body">
                <div class="om-form-row om-mb-4">
                    <div class="om-form-group om-col-8">
                        <input type="text" id="catalogoBusca" class="om-input" placeholder="Buscar por nome, marca ou código de barras..." onkeyup="debounce(buscarCatalogo, 300)()">
                    </div>
                    <div class="om-form-group om-col-4">
                        <select id="catalogoCategoria" class="om-select" onchange="buscarCatalogo()">
                            <option value="">Todas categorias</option>
                            <?php foreach ($categorias_base as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="catalogo-result" class="om-catalogo-grid">
                    <p class="om-text-center om-text-muted om-py-8">
                        <i class="lucide-search om-text-3xl"></i><br>
                        Digite para buscar produtos no catálogo
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Produto -->
    <div id="modalProduto" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModal()"></div>
        <div class="om-modal-content om-modal-lg">
            <div class="om-modal-header">
                <h3 class="om-modal-title" id="modalProdutoTitle">Novo Produto</h3>
                <button class="om-modal-close" onclick="fecharModal()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST" id="formProduto">
                <div class="om-modal-body">
                    <input type="hidden" name="action" id="produtoAction" value="create">
                    <input type="hidden" name="product_id" id="produtoId" value="">
                    <input type="hidden" name="base_product_id" id="produtoBaseId" value="">

                    <!-- Tabs -->
                    <div class="om-tabs om-mb-4">
                        <button type="button" class="om-tab active" onclick="showTab('tab-info')">
                            <i class="lucide-info"></i> Informações
                        </button>
                        <button type="button" class="om-tab" onclick="showTab('tab-preco')">
                            <i class="lucide-dollar-sign"></i> Preço e Estoque
                        </button>
                        <button type="button" class="om-tab" onclick="showTab('tab-local')">
                            <i class="lucide-map-pin"></i> Localização
                        </button>
                        <button type="button" class="om-tab" onclick="showTab('tab-allergens')">
                            <i class="lucide-shield-alert"></i> Alergenos/Dieta
                        </button>
                        <button type="button" class="om-tab" onclick="showTab('tab-combo')" id="tabComboBtn">
                            <i class="lucide-layers"></i> Combo/Kit
                        </button>
                    </div>

                    <!-- Tab Info -->
                    <div id="tab-info" class="om-tab-content active">
                        <div class="om-form-row">
                            <div class="om-form-group om-col-md-8">
                                <label class="om-label">Nome do Produto *</label>
                                <input type="text" name="name" id="produtoNome" class="om-input" required>
                            </div>

                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Marca</label>
                                <input type="text" name="brand" id="produtoMarca" class="om-input" placeholder="Ex: Nestlé">
                            </div>
                        </div>

                        <div class="om-form-group">
                            <label class="om-label">Descrição</label>
                            <textarea name="description" id="produtoDescricao" class="om-input" rows="2"></textarea>
                        </div>

                        <div class="om-form-row">
                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Categoria</label>
                                <input type="text" name="category_name" id="produtoCategoriaNome" class="om-input" placeholder="Ex: Laticínios" list="categorias-list">
                                <datalist id="categorias-list">
                                    <?php foreach ($categorias_base as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Código de Barras (EAN)</label>
                                <input type="text" name="barcode" id="produtoBarcode" class="om-input" placeholder="7891234567890">
                            </div>

                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Peso/Volume</label>
                                <input type="text" name="weight" id="produtoPeso" class="om-input" placeholder="Ex: 1kg, 500ml">
                            </div>
                        </div>

                        <div class="om-form-group">
                            <label class="om-label">Imagem Principal</label>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <div id="produtoImagemPreview" style="width:64px;height:64px;border-radius:8px;background:var(--om-gray-100);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;border:2px dashed var(--om-gray-300);cursor:pointer;" onclick="document.getElementById('produtoImagemFile').click()">
                                    <i class="lucide-camera" style="font-size:1.5rem;color:var(--om-gray-400);"></i>
                                </div>
                                <div style="flex:1;">
                                    <input type="file" id="produtoImagemFile" accept="image/*" style="display:none;" onchange="uploadProdutoImagem(this)">
                                    <input type="text" name="image" id="produtoImagem" class="om-input" placeholder="URL da imagem principal ou clique para enviar">
                                    <small class="om-text-muted">Clique na area ao lado ou cole uma URL</small>
                                </div>
                            </div>
                        </div>

                        <div class="om-form-group">
                            <label class="om-label">Fotos Adicionais <span class="om-text-muted om-text-xs">(até 5 fotos)</span></label>
                            <input type="hidden" name="images" id="produtoImages" value="[]">
                            <div class="om-images-gallery" id="imagesGallery">
                                <!-- Dynamic gallery items rendered by JS -->
                            </div>
                            <div style="margin-top:8px;">
                                <input type="file" id="produtoExtraImageFile" accept="image/*" style="display:none;" onchange="uploadExtraImage(this)">
                            </div>
                        </div>
                    </div>

                    <!-- Tab Preço -->
                    <div id="tab-preco" class="om-tab-content">
                        <div class="om-alert om-alert-info om-mb-4" id="alertaPrecoSugerido" style="display: none;">
                            <i class="lucide-sparkles"></i>
                            <div class="om-alert-content">
                                <div class="om-alert-title">Preço Sugerido pela IA</div>
                                <div class="om-alert-message">
                                    Com base na média de mercado, sugerimos: <strong id="precoSugeridoValor">R$ 0,00</strong>
                                </div>
                            </div>
                        </div>

                        <div class="om-form-row">
                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Seu Preço de Venda *</label>
                                <div class="om-input-group">
                                    <span class="om-input-prefix">R$</span>
                                    <input type="text" name="price" id="produtoPreco" class="om-input om-input-lg" required placeholder="0,00">
                                </div>
                            </div>

                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Preço Sugerido (referência)</label>
                                <div class="om-input-group">
                                    <span class="om-input-prefix">R$</span>
                                    <input type="text" name="suggested_price" id="produtoPrecoSugerido" class="om-input" placeholder="0,00" readonly>
                                </div>
                            </div>

                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Quantidade em Estoque</label>
                                <input type="number" name="quantity" id="produtoQuantidade" class="om-input" min="0" value="0">
                            </div>
                        </div>

                        <div class="om-form-row">
                            <div class="om-form-group om-col-md-6">
                                <label class="om-label">Unidade</label>
                                <select name="unit" id="produtoUnidade" class="om-select">
                                    <option value="un">Unidade (un)</option>
                                    <option value="kg">Quilograma (kg)</option>
                                    <option value="g">Grama (g)</option>
                                    <option value="l">Litro (l)</option>
                                    <option value="ml">Mililitro (ml)</option>
                                    <option value="cx">Caixa (cx)</option>
                                    <option value="pct">Pacote (pct)</option>
                                </select>
                            </div>

                            <div class="om-form-group om-col-md-6">
                                <label class="om-label">SKU (Código Interno)</label>
                                <input type="text" name="sku" id="produtoSku" class="om-input" placeholder="Opcional">
                            </div>
                        </div>
                    </div>

                    <!-- Tab Localização -->
                    <div id="tab-local" class="om-tab-content">
                        <div class="om-alert om-alert-warning om-mb-4">
                            <i class="lucide-info"></i>
                            <div class="om-alert-content">
                                <div class="om-alert-message">
                                    A localização ajuda o shopper a encontrar o produto mais rápido na loja!
                                </div>
                            </div>
                        </div>

                        <div class="om-form-row">
                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Corredor</label>
                                <input type="text" name="location_aisle" id="produtoCorred" class="om-input" placeholder="Ex: A1, B2, 03">
                            </div>

                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Prateleira</label>
                                <input type="text" name="location_shelf" id="produtoPrateleira" class="om-input" placeholder="Ex: 1, 2, Superior">
                            </div>

                            <div class="om-form-group om-col-md-4">
                                <label class="om-label">Seção</label>
                                <input type="text" name="location_section" id="produtoSecao" class="om-input" placeholder="Ex: Frios, Bebidas" list="secoes-list">
                                <datalist id="secoes-list">
                                    <option value="Frios">
                                    <option value="Congelados">
                                    <option value="Laticínios">
                                    <option value="Bebidas">
                                    <option value="Padaria">
                                    <option value="Açougue">
                                    <option value="Hortifruti">
                                    <option value="Mercearia">
                                    <option value="Limpeza">
                                    <option value="Higiene">
                                    <option value="Pet Shop">
                                </datalist>
                            </div>
                        </div>

                        <div class="om-form-group">
                            <label class="om-label">Observações de Localização</label>
                            <textarea name="location_notes" id="produtoLocalObs" class="om-input" rows="2" placeholder="Ex: Próximo ao caixa, em promoção na ponta de gôndola..."></textarea>
                        </div>
                    </div>

                    <!-- Tab Alergenos/Dieta -->
                    <div id="tab-allergens" class="om-tab-content">
                        <input type="hidden" name="allergens" id="produtoAllergens" value="[]">
                        <input type="hidden" name="dietary_flags" id="produtoDietaryFlags" value="[]">

                        <div class="om-form-group">
                            <label class="om-label">Alergenos</label>
                            <p class="om-text-xs om-text-muted om-mb-2">Selecione os alergenos presentes neste produto</p>
                            <div class="om-chip-group" id="allergenChips">
                                <button type="button" class="om-chip" data-value="gluten" onclick="toggleChip(this, 'allergen')">Gluten</button>
                                <button type="button" class="om-chip" data-value="lactose" onclick="toggleChip(this, 'allergen')">Lactose</button>
                                <button type="button" class="om-chip" data-value="nozes" onclick="toggleChip(this, 'allergen')">Nozes</button>
                                <button type="button" class="om-chip" data-value="soja" onclick="toggleChip(this, 'allergen')">Soja</button>
                                <button type="button" class="om-chip" data-value="ovos" onclick="toggleChip(this, 'allergen')">Ovos</button>
                                <button type="button" class="om-chip" data-value="frutos_do_mar" onclick="toggleChip(this, 'allergen')">Frutos do Mar</button>
                            </div>
                        </div>

                        <div class="om-form-group om-mt-4">
                            <label class="om-label">Dieta / Selos</label>
                            <p class="om-text-xs om-text-muted om-mb-2">Selecione os selos alimentares deste produto</p>
                            <div class="om-chip-group" id="dietaryChips">
                                <button type="button" class="om-chip" data-value="vegano" onclick="toggleChip(this, 'dietary')">&#127793; Vegano</button>
                                <button type="button" class="om-chip" data-value="vegetariano" onclick="toggleChip(this, 'dietary')">&#129388; Vegetariano</button>
                                <button type="button" class="om-chip" data-value="sem_gluten" onclick="toggleChip(this, 'dietary')">&#127806; Sem Gluten</button>
                                <button type="button" class="om-chip" data-value="sem_lactose" onclick="toggleChip(this, 'dietary')">&#129371; Sem Lactose</button>
                                <button type="button" class="om-chip" data-value="organico" onclick="toggleChip(this, 'dietary')">&#127807; Organico</button>
                                <button type="button" class="om-chip" data-value="zero_acucar" onclick="toggleChip(this, 'dietary')">&#128293; Zero Acucar</button>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Combo/Kit -->
                    <div id="tab-combo" class="om-tab-content">
                        <input type="hidden" name="combo_items" id="produtoComboItems" value="[]">

                        <div class="om-form-group">
                            <label class="om-label" style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" name="is_combo" id="produtoIsCombo" value="1" onchange="toggleComboUI()">
                                Este produto é um Combo/Kit
                            </label>
                            <span class="om-form-help">Ative para montar um combo com outros produtos do seu catalogo</span>
                        </div>

                        <div id="comboSection" style="display:none;">
                            <div class="om-alert om-alert-info om-mb-4">
                                <div class="om-alert-content">
                                    <div class="om-alert-message">
                                        Adicione os produtos que fazem parte deste combo. O preco do combo pode ser diferente da soma dos itens.
                                    </div>
                                </div>
                            </div>

                            <div class="om-form-group">
                                <label class="om-label">Buscar produto para adicionar</label>
                                <input type="text" id="comboSearchInput" class="om-input" placeholder="Digite o nome do produto..." oninput="searchComboProduct(this.value)">
                                <div id="comboSearchResults" class="om-combo-search-results" style="display:none;"></div>
                            </div>

                            <div class="om-form-group">
                                <label class="om-label">Itens do Combo</label>
                                <div id="comboItemsList" class="om-combo-items">
                                    <div class="om-text-center om-text-muted om-py-4" id="comboEmpty">
                                        Nenhum item adicionado ao combo
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-primary">
                        <i class="lucide-check"></i> Salvar Produto
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Estoque -->
    <div id="modalEstoque" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalEstoque()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title">Ajustar Estoque</h3>
                <button class="om-modal-close" onclick="fecharModalEstoque()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST">
                <div class="om-modal-body">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="product_id" id="estoqueProductId">

                    <div class="om-form-group">
                        <label class="om-label">Nova Quantidade</label>
                        <input type="number" name="quantity" id="estoqueQuantidade" class="om-input om-input-lg" min="0" required>
                    </div>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModalEstoque()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-primary">Atualizar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Confirmar Exclusão -->
    <div id="modalExclusao" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalExclusao()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title">Confirmar Exclusão</h3>
                <button class="om-modal-close" onclick="fecharModalExclusao()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST">
                <div class="om-modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="product_id" id="excluirProductId">

                    <p class="om-text-center">
                        <i class="lucide-alert-triangle om-text-4xl om-text-warning"></i>
                    </p>
                    <p class="om-text-center om-mt-4">
                        Tem certeza que deseja excluir o produto<br>
                        <strong id="excluirProductName"></strong>?
                    </p>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModalExclusao()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-error">Excluir</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Action Bar -->
    <div class="bulk-bar" id="bulkBar" style="display:none">
        <span id="bulkCount">0 selecionados</span>
        <button class="om-btn om-btn-sm om-btn-primary" onclick="bulkEditPrice()"><i class="lucide-dollar-sign"></i> Alterar Preco</button>
        <button class="om-btn om-btn-sm om-btn-secondary" onclick="bulkEditStock()"><i class="lucide-package-plus"></i> Alterar Estoque</button>
        <button class="om-btn om-btn-sm om-btn-outline" onclick="bulkToggleStatus()"><i class="lucide-toggle-left"></i> Ativar/Desativar</button>
    </div>

    <!-- Modal Bulk Preco -->
    <div id="modalBulkPreco" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalBulkPreco()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title"><i class="lucide-dollar-sign"></i> Alterar Preco em Massa</h3>
                <button class="om-modal-close" onclick="fecharModalBulkPreco()"><i class="lucide-x"></i></button>
            </div>
            <div class="om-modal-body">
                <div class="om-form-group">
                    <label class="om-label">Modo</label>
                    <select id="bulkPriceMode" class="om-select" onchange="updateBulkPriceLabel()">
                        <option value="fixed">Definir preco fixo</option>
                        <option value="increase_pct">Aumentar %</option>
                        <option value="decrease_pct">Diminuir %</option>
                    </select>
                </div>
                <div class="om-form-group om-mt-3">
                    <label class="om-label" id="bulkPriceLabel">Preco (R$)</label>
                    <div class="om-input-group">
                        <span class="om-input-prefix" id="bulkPricePrefix">R$</span>
                        <input type="text" id="bulkPriceValue" class="om-input om-input-lg" placeholder="0,00">
                    </div>
                </div>
            </div>
            <div class="om-modal-footer">
                <button class="om-btn om-btn-outline" onclick="fecharModalBulkPreco()">Cancelar</button>
                <button class="om-btn om-btn-primary" onclick="executeBulkPrice()"><i class="lucide-check"></i> Aplicar</button>
            </div>
        </div>
    </div>

    <!-- Modal Bulk Estoque -->
    <div id="modalBulkEstoque" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalBulkEstoque()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title"><i class="lucide-package-plus"></i> Alterar Estoque em Massa</h3>
                <button class="om-modal-close" onclick="fecharModalBulkEstoque()"><i class="lucide-x"></i></button>
            </div>
            <div class="om-modal-body">
                <div class="om-form-group">
                    <label class="om-label">Modo</label>
                    <select id="bulkStockMode" class="om-select">
                        <option value="fixed">Definir estoque fixo</option>
                        <option value="add">Adicionar ao estoque (+/-)</option>
                    </select>
                </div>
                <div class="om-form-group om-mt-3">
                    <label class="om-label">Quantidade</label>
                    <input type="number" id="bulkStockValue" class="om-input om-input-lg" placeholder="0" min="0">
                </div>
            </div>
            <div class="om-modal-footer">
                <button class="om-btn om-btn-outline" onclick="fecharModalBulkEstoque()">Cancelar</button>
                <button class="om-btn om-btn-primary" onclick="executeBulkStock()"><i class="lucide-check"></i> Aplicar</button>
            </div>
        </div>
    </div>

    <!-- Modal Importar CSV -->
    <div id="modalImportCsv" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalImportCsv()"></div>
        <div class="om-modal-content om-modal-lg">
            <div class="om-modal-header">
                <h3 class="om-modal-title"><i class="lucide-upload"></i> Importar Produtos via CSV</h3>
                <button class="om-modal-close" onclick="fecharModalImportCsv()"><i class="lucide-x"></i></button>
            </div>
            <div class="om-modal-body">
                <div class="om-alert om-alert-info om-mb-4">
                    <i class="lucide-info"></i>
                    <div class="om-alert-content">
                        <div class="om-alert-message">
                            Formato esperado: CSV com separador <strong>ponto-e-virgula (;)</strong><br>
                            Colunas: <strong>SKU; Codigo Barras; Nome; Categoria; Preco; Preco Sugerido; Estoque; Unidade; Status; Alergenos; Dieta</strong><br>
                            Produtos existentes serao atualizados pelo codigo de barras ou SKU.
                        </div>
                    </div>
                </div>
                <div class="om-form-group">
                    <label class="om-label">Arquivo CSV</label>
                    <input type="file" id="csvFileInput" class="om-input" accept=".csv,.txt" onchange="previewCsv(this)">
                </div>
                <div id="csvPreview" style="display:none;" class="om-mt-4">
                    <h4 class="om-font-medium om-mb-2">Preview (primeiras 5 linhas)</h4>
                    <div class="om-table-responsive">
                        <table class="om-table om-table-sm" id="csvPreviewTable">
                            <thead id="csvPreviewHead"></thead>
                            <tbody id="csvPreviewBody"></tbody>
                        </table>
                    </div>
                </div>
                <div id="csvResult" style="display:none;" class="om-mt-4"></div>
            </div>
            <div class="om-modal-footer">
                <button class="om-btn om-btn-outline" onclick="fecharModalImportCsv()">Cancelar</button>
                <button class="om-btn om-btn-primary" id="btnImportCsv" onclick="executeCsvImport()" disabled>
                    <i class="lucide-upload"></i> Importar
                </button>
            </div>
        </div>
    </div>

    <style>
    .om-product-thumb {
        width: 48px;
        height: 48px;
        border-radius: var(--om-radius-md);
        background: var(--om-gray-100);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
    }
    .om-product-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .om-product-thumb i {
        font-size: 1.5rem;
        color: var(--om-gray-400);
    }
    .om-inline-form {
        display: inline;
    }
    .om-location-badge {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .om-location-badge .om-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        width: fit-content;
    }
    .om-location-badge .om-badge i {
        font-size: 12px;
    }
    .om-tabs {
        display: flex;
        gap: 4px;
        border-bottom: 1px solid var(--om-gray-200);
        padding-bottom: 4px;
    }
    .om-tab {
        padding: 8px 16px;
        border: none;
        background: none;
        cursor: pointer;
        border-radius: var(--om-radius-md) var(--om-radius-md) 0 0;
        color: var(--om-gray-600);
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }
    .om-tab:hover {
        background: var(--om-gray-100);
    }
    .om-tab.active {
        background: var(--om-primary);
        color: white;
    }
    .om-tab-content {
        display: none;
    }
    .om-tab-content.active {
        display: block;
    }
    .om-divider {
        display: flex;
        align-items: center;
        text-align: center;
        color: var(--om-gray-500);
        font-size: 0.875rem;
    }
    .om-divider::before,
    .om-divider::after {
        content: '';
        flex: 1;
        border-bottom: 1px solid var(--om-gray-200);
    }
    .om-divider span {
        padding: 0 12px;
    }
    .om-catalogo-grid {
        max-height: 400px;
        overflow-y: auto;
    }
    .om-catalogo-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        border: 1px solid var(--om-gray-200);
        border-radius: var(--om-radius-md);
        margin-bottom: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .om-catalogo-item:hover {
        border-color: var(--om-primary);
        background: var(--om-primary-light);
    }
    .om-catalogo-item-img {
        width: 50px;
        height: 50px;
        border-radius: var(--om-radius-sm);
        background: var(--om-gray-100);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .om-catalogo-item-img img {
        max-width: 100%;
        max-height: 100%;
    }
    .om-catalogo-item-info {
        flex: 1;
    }
    .om-catalogo-item-name {
        font-weight: 600;
        margin-bottom: 2px;
    }
    .om-catalogo-item-details {
        font-size: 0.75rem;
        color: var(--om-gray-500);
    }
    .om-catalogo-item-price {
        font-weight: 600;
        color: var(--om-primary);
    }
    .om-hide-mobile {
        display: inline;
    }
    @media (max-width: 768px) {
        .om-hide-mobile {
            display: none;
        }
    }
    .om-text-mono {
        font-family: monospace;
        font-size: 0.75rem;
    }
    .om-btn-xs {
        padding: 4px 8px;
        font-size: 0.75rem;
    }

    /* ═══ Bulk Action Bar ═══ */
    .bulk-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: var(--om-gray-900, #1a1a2e);
        color: #fff;
        padding: 12px 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 1000;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
        animation: slideUp 0.2s ease;
    }
    .bulk-bar span {
        font-weight: 600;
        margin-right: auto;
    }
    @keyframes slideUp {
        from { transform: translateY(100%); }
        to { transform: translateY(0); }
    }

    /* ═══ Chip / Toggle Buttons ═══ */
    .om-chip-group {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .om-chip {
        padding: 6px 14px;
        border-radius: 20px;
        border: 1.5px solid var(--om-gray-300);
        background: var(--om-gray-50, #f8f9fa);
        cursor: pointer;
        font-size: 0.85rem;
        transition: all 0.15s;
        color: var(--om-gray-700);
    }
    .om-chip:hover {
        border-color: var(--om-primary);
        background: var(--om-primary-light, #e8f0fe);
    }
    .om-chip.active {
        background: var(--om-primary);
        color: #fff;
        border-color: var(--om-primary);
    }

    /* ═══ Dietary Badges (product list) ═══ */
    .om-diet-badge {
        display: inline-block;
        font-size: 0.65rem;
        padding: 1px 6px;
        border-radius: 10px;
        margin-left: 4px;
        vertical-align: middle;
        font-weight: 600;
        line-height: 1.5;
    }
    .om-diet-vegano { background: #d4edda; color: #155724; }
    .om-diet-vegetariano { background: #e8f5e9; color: #2e7d32; }
    .om-diet-semgluten { background: #fff3cd; color: #856404; }
    .om-diet-semlactose { background: #e1f5fe; color: #01579b; }
    .om-diet-organico { background: #c8e6c9; color: #1b5e20; }
    .om-diet-zeroacucar { background: #fce4ec; color: #c62828; }

    .om-allergen-inline {
        color: var(--om-warning, #e67e22);
        font-size: 0.7rem;
        margin-left: 6px;
    }

    /* ═══ Image Count Badge ═══ */
    .om-img-count-badge {
        position: absolute;
        bottom: -2px;
        right: -2px;
        background: var(--om-primary, #4f46e5);
        color: #fff;
        font-size: 0.6rem;
        font-weight: 700;
        padding: 1px 4px;
        border-radius: 8px;
        line-height: 1.2;
        min-width: 18px;
        text-align: center;
    }

    /* ═══ Combo Badge ═══ */
    .om-combo-badge {
        display: inline-block;
        background: linear-gradient(135deg, #7c3aed, #a855f7);
        color: #fff;
        font-size: 0.6rem;
        font-weight: 700;
        padding: 1px 6px;
        border-radius: 4px;
        vertical-align: middle;
        margin-right: 4px;
        letter-spacing: 0.5px;
    }

    /* ═══ Multi-Image Gallery ═══ */
    .om-images-gallery {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 8px;
    }
    .om-images-gallery .om-gallery-item {
        position: relative;
        width: 72px;
        height: 72px;
        border-radius: 8px;
        overflow: hidden;
        border: 2px solid var(--om-gray-200);
        background: var(--om-gray-100);
        flex-shrink: 0;
    }
    .om-gallery-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .om-gallery-item .om-gallery-remove {
        position: absolute;
        top: 2px;
        right: 2px;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: rgba(220,38,38,0.9);
        color: #fff;
        border: none;
        cursor: pointer;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
    }
    .om-gallery-add {
        width: 72px;
        height: 72px;
        border-radius: 8px;
        border: 2px dashed var(--om-gray-300);
        background: var(--om-gray-50);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--om-gray-400);
        font-size: 1.5rem;
        transition: all 0.2s;
    }
    .om-gallery-add:hover {
        border-color: var(--om-primary);
        color: var(--om-primary);
        background: var(--om-primary-light, #e8f0fe);
    }

    /* ═══ Combo Items List ═══ */
    .om-combo-items {
        border: 1px solid var(--om-gray-200);
        border-radius: 8px;
        overflow: hidden;
        margin-top: 8px;
    }
    .om-combo-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 12px;
        border-bottom: 1px solid var(--om-gray-100);
    }
    .om-combo-item:last-child {
        border-bottom: none;
    }
    .om-combo-item-name {
        flex: 1;
        font-size: 0.875rem;
        font-weight: 500;
    }
    .om-combo-item-qty {
        width: 60px;
    }
    .om-combo-search-results {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid var(--om-gray-200);
        border-radius: 8px;
        margin-top: 4px;
    }
    .om-combo-search-item {
        padding: 8px 12px;
        cursor: pointer;
        font-size: 0.875rem;
        border-bottom: 1px solid var(--om-gray-100);
        transition: background 0.15s;
    }
    .om-combo-search-item:hover {
        background: var(--om-primary-light, #e8f0fe);
    }
    .om-combo-search-item:last-child {
        border-bottom: none;
    }

    /* ═══ CSV Preview Table ═══ */
    .om-table-sm td, .om-table-sm th {
        padding: 4px 8px;
        font-size: 0.8rem;
    }
    </style>

    <script>
    let html5QrCode = null;
    let debounceTimer = null;

    // Tabs
    function showTab(tabId) {
        document.querySelectorAll('.om-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.om-tab-content').forEach(t => t.classList.remove('active'));
        document.querySelector(`[onclick="showTab('${tabId}')"]`).classList.add('active');
        document.getElementById(tabId).classList.add('active');
    }

    // Scanner
    function abrirScanner() {
        document.getElementById('modalScanner').classList.add('open');
        startScanner();
    }

    function fecharScanner() {
        document.getElementById('modalScanner').classList.remove('open');
        stopScanner();
    }

    function startScanner() {
        if (html5QrCode) return;

        html5QrCode = new Html5Qrcode("scanner-reader");
        html5QrCode.start(
            { facingMode: "environment" },
            { fps: 10, qrbox: { width: 250, height: 100 } },
            (decodedText) => {
                buscarPorCodigo(decodedText);
            },
            (error) => {}
        ).catch(err => {
            console.log("Câmera não disponível:", err);
            document.getElementById('scanner-reader').innerHTML =
                '<p class="om-text-center om-text-muted om-py-4"><i class="lucide-camera-off om-text-2xl"></i><br>Câmera não disponível. Use o campo manual.</p>';
        });
    }

    function stopScanner() {
        if (html5QrCode) {
            html5QrCode.stop().then(() => {
                html5QrCode = null;
            }).catch(() => {});
        }
    }

    function buscarPorCodigo(barcode) {
        if (!barcode || barcode.length < 3) return;

        const resultDiv = document.getElementById('scanner-result');
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<p class="om-text-center"><i class="lucide-loader-2 om-animate-spin"></i> Buscando...</p>';

        fetch(`/api/mercado/produtos/buscar-base.php?barcode=${encodeURIComponent(barcode)}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.product) {
                    const p = data.product;
                    resultDiv.innerHTML = `
                        <div class="om-catalogo-item" onclick="importarProduto(${p.id})">
                            <div class="om-catalogo-item-img">
                                ${p.image ? `<img src="${p.image}" alt="">` : '<i class="lucide-package"></i>'}
                            </div>
                            <div class="om-catalogo-item-info">
                                <div class="om-catalogo-item-name">${p.name}</div>
                                <div class="om-catalogo-item-details">
                                    ${p.brand || ''} ${p.weight || ''}<br>
                                    <span class="om-text-mono">${p.ean}</span>
                                </div>
                            </div>
                            <div>
                                <div class="om-catalogo-item-price">R$ ${parseFloat(p.suggested_price || 0).toFixed(2).replace('.', ',')}</div>
                                <small class="om-text-muted">Sugerido</small>
                            </div>
                        </div>
                        <p class="om-text-center om-text-sm om-text-muted">Clique para adicionar ao seu catálogo</p>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <div class="om-alert om-alert-warning">
                            <i class="lucide-search-x"></i>
                            <div class="om-alert-content">
                                <div class="om-alert-message">Produto não encontrado no catálogo base.</div>
                            </div>
                        </div>
                        <button type="button" class="om-btn om-btn-primary om-btn-block om-mt-2" onclick="criarProdutoManual('${barcode}')">
                            <i class="lucide-plus"></i> Cadastrar Manualmente
                        </button>
                    `;
                }
            })
            .catch(err => {
                resultDiv.innerHTML = '<p class="om-text-error">Erro ao buscar produto</p>';
            });
    }

    function importarProduto(baseId) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="import_from_base">
            <input type="hidden" name="base_product_id" value="${baseId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }

    function criarProdutoManual(barcode) {
        fecharScanner();
        abrirModalProduto();
        document.getElementById('produtoBarcode').value = barcode;
    }

    // Catálogo
    function abrirCatalogo() {
        document.getElementById('modalCatalogo').classList.add('open');
        document.getElementById('catalogoBusca').focus();
    }

    function fecharCatalogo() {
        document.getElementById('modalCatalogo').classList.remove('open');
    }

    function debounce(func, wait) {
        return function(...args) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => func.apply(this, args), wait);
        };
    }

    function buscarCatalogo() {
        const busca = document.getElementById('catalogoBusca').value;
        const categoria = document.getElementById('catalogoCategoria').value;

        if (busca.length < 2 && !categoria) {
            document.getElementById('catalogo-result').innerHTML =
                '<p class="om-text-center om-text-muted om-py-8">Digite ao menos 2 caracteres para buscar</p>';
            return;
        }

        document.getElementById('catalogo-result').innerHTML =
            '<p class="om-text-center om-py-4"><i class="lucide-loader-2 om-animate-spin"></i> Buscando...</p>';

        fetch(`/api/mercado/produtos/buscar-base.php?q=${encodeURIComponent(busca)}&categoria=${encodeURIComponent(categoria)}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.products && data.products.length > 0) {
                    let html = '';
                    data.products.forEach(p => {
                        html += `
                            <div class="om-catalogo-item" onclick="selecionarProdutoBase(${JSON.stringify(p).replace(/"/g, '&quot;')})">
                                <div class="om-catalogo-item-img">
                                    ${p.image ? `<img src="${p.image}" alt="">` : '<i class="lucide-package"></i>'}
                                </div>
                                <div class="om-catalogo-item-info">
                                    <div class="om-catalogo-item-name">${p.name}</div>
                                    <div class="om-catalogo-item-details">
                                        ${p.brand || ''} ${p.weight || ''} | ${p.category || ''}<br>
                                        <span class="om-text-mono">${p.ean || ''}</span>
                                    </div>
                                </div>
                                <div class="om-text-right">
                                    <div class="om-catalogo-item-price">R$ ${parseFloat(p.suggested_price || 0).toFixed(2).replace('.', ',')}</div>
                                    <small class="om-text-muted">Sugerido</small>
                                </div>
                            </div>
                        `;
                    });
                    document.getElementById('catalogo-result').innerHTML = html;
                } else {
                    document.getElementById('catalogo-result').innerHTML =
                        '<p class="om-text-center om-text-muted om-py-8"><i class="lucide-package-x om-text-3xl"></i><br>Nenhum produto encontrado</p>';
                }
            })
            .catch(err => {
                document.getElementById('catalogo-result').innerHTML =
                    '<p class="om-text-center om-text-error">Erro ao buscar produtos</p>';
            });
    }

    function selecionarProdutoBase(produto) {
        fecharCatalogo();
        abrirModalProduto();

        // Preencher formulário
        document.getElementById('produtoBaseId').value = produto.id;
        document.getElementById('produtoNome').value = produto.name || '';
        document.getElementById('produtoMarca').value = produto.brand || '';
        document.getElementById('produtoDescricao').value = produto.description || '';
        document.getElementById('produtoCategoriaNome').value = produto.category || '';
        document.getElementById('produtoBarcode').value = produto.ean || '';
        document.getElementById('produtoPeso').value = produto.weight || '';
        document.getElementById('produtoImagem').value = produto.image || '';
        document.getElementById('produtoUnidade').value = produto.unit || 'un';

        // Preço sugerido
        if (produto.suggested_price) {
            document.getElementById('produtoPrecoSugerido').value = parseFloat(produto.suggested_price).toFixed(2).replace('.', ',');
            document.getElementById('precoSugeridoValor').textContent = 'R$ ' + parseFloat(produto.suggested_price).toFixed(2).replace('.', ',');
            document.getElementById('alertaPrecoSugerido').style.display = 'flex';
        }
    }

    // Modal Produto
    function abrirModalProduto() {
        document.getElementById('modalProdutoTitle').textContent = 'Novo Produto';
        document.getElementById('produtoAction').value = 'create';
        document.getElementById('formProduto').reset();
        document.getElementById('produtoId').value = '';
        document.getElementById('produtoBaseId').value = '';
        document.getElementById('alertaPrecoSugerido').style.display = 'none';
        resetAllChips();
        // Reset images gallery
        productImages = [];
        renderImagesGallery();
        // Reset combo
        comboItems = [];
        renderComboItems();
        document.getElementById('produtoIsCombo').checked = false;
        toggleComboUI();
        // Reset image preview
        document.getElementById('produtoImagemPreview').innerHTML = '<i class="lucide-camera" style="font-size:1.5rem;color:var(--om-gray-400);"></i>';
        showTab('tab-info');
        document.getElementById('modalProduto').classList.add('open');
    }

    function editarProduto(produto) {
        document.getElementById('modalProdutoTitle').textContent = 'Editar Produto';
        document.getElementById('produtoAction').value = 'update';
        document.getElementById('produtoId').value = produto.product_id;
        document.getElementById('produtoBaseId').value = produto.base_product_id || '';
        document.getElementById('produtoNome').value = produto.name || '';
        document.getElementById('produtoMarca').value = produto.brand || '';
        document.getElementById('produtoDescricao').value = produto.description || '';
        document.getElementById('produtoCategoriaNome').value = produto.category || '';
        document.getElementById('produtoPreco').value = parseFloat(produto.price).toFixed(2).replace('.', ',');
        document.getElementById('produtoPrecoSugerido').value = produto.suggested_price ? parseFloat(produto.suggested_price).toFixed(2).replace('.', ',') : '';
        document.getElementById('produtoQuantidade').value = produto.quantity || 0;
        document.getElementById('produtoUnidade').value = produto.unit || 'un';
        document.getElementById('produtoSku').value = produto.sku || '';
        document.getElementById('produtoBarcode').value = produto.barcode || '';
        document.getElementById('produtoPeso').value = produto.weight || '';
        document.getElementById('produtoImagem').value = produto.image || '';

        // Localização
        document.getElementById('produtoCorred').value = produto.location_aisle || '';
        document.getElementById('produtoPrateleira').value = produto.location_shelf || '';
        document.getElementById('produtoSecao').value = produto.location_section || '';
        document.getElementById('produtoLocalObs').value = produto.location_notes || '';

        // Atualizar preview da imagem
        atualizarPreviewImagem();

        // Allergens / Dietary
        setChipsFromJson('allergen', produto.allergens || '[]');
        setChipsFromJson('dietary', produto.dietary_flags || '[]');

        // Multi-images
        try {
            productImages = JSON.parse(produto.images || '[]');
            if (!Array.isArray(productImages)) productImages = [];
        } catch(e) {
            productImages = [];
        }
        renderImagesGallery();

        // Combo
        const isCombo = !!produto.is_combo;
        document.getElementById('produtoIsCombo').checked = isCombo;
        toggleComboUI();
        comboItems = [];
        if (isCombo) {
            // Load combo items via AJAX
            fetch(`produtos.php?load_combo_items=1&product_id=${produto.product_id}`)
                .then(r => r.json())
                .then(data => {
                    if (data.items) {
                        comboItems = data.items;
                        renderComboItems();
                    }
                })
                .catch(() => {});
        }
        renderComboItems();

        if (produto.suggested_price) {
            document.getElementById('precoSugeridoValor').textContent = 'R$ ' + parseFloat(produto.suggested_price).toFixed(2).replace('.', ',');
            document.getElementById('alertaPrecoSugerido').style.display = 'flex';
        } else {
            document.getElementById('alertaPrecoSugerido').style.display = 'none';
        }

        showTab('tab-info');
        document.getElementById('modalProduto').classList.add('open');
    }

    function fecharModal() {
        document.getElementById('modalProduto').classList.remove('open');
    }

    function ajustarEstoque(productId, quantidade) {
        document.getElementById('estoqueProductId').value = productId;
        document.getElementById('estoqueQuantidade').value = quantidade;
        document.getElementById('modalEstoque').classList.add('open');
    }

    function fecharModalEstoque() {
        document.getElementById('modalEstoque').classList.remove('open');
    }

    function confirmarExclusao(productId, productName) {
        document.getElementById('excluirProductId').value = productId;
        document.getElementById('excluirProductName').textContent = productName;
        document.getElementById('modalExclusao').classList.add('open');
    }

    function fecharModalExclusao() {
        document.getElementById('modalExclusao').classList.remove('open');
    }

    // Fechar modais com ESC
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            fecharModal();
            fecharModalEstoque();
            fecharModalExclusao();
            fecharScanner();
            fecharCatalogo();
            fecharModalBulkPreco();
            fecharModalBulkEstoque();
            fecharModalImportCsv();
        }
    });

    // Máscara de preço
    document.getElementById('produtoPreco').addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value) {
            value = (parseInt(value) / 100).toFixed(2);
            e.target.value = value.replace('.', ',');
        }
    });

    // Enter no campo de código de barras manual
    document.getElementById('barcodeManual').addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            buscarPorCodigo(e.target.value);
        }
    });

    // Upload de imagem do produto
    async function uploadProdutoImagem(input) {
        if (!input.files || !input.files[0]) return;

        const file = input.files[0];
        const preview = document.getElementById('produtoImagemPreview');

        // Preview local
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
        };
        reader.readAsDataURL(file);

        // Upload
        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', 'product');

        try {
            const res = await fetch('/api/mercado/upload.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('produtoImagem').value = data.data.url;
            } else {
                alert('Erro ao enviar: ' + data.message);
            }
        } catch(e) {
            alert('Erro de conexao ao enviar imagem');
        }
    }

    // Atualizar preview quando imagem muda no formulario
    function atualizarPreviewImagem() {
        const url = document.getElementById('produtoImagem').value;
        const preview = document.getElementById('produtoImagemPreview');
        if (url) {
            preview.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;" onerror="this.parentElement.innerHTML=\'<i class=lucide-camera style=font-size:1.5rem;color:var(--om-gray-400)></i>\'">';
        } else {
            preview.innerHTML = '<i class="lucide-camera" style="font-size:1.5rem;color:var(--om-gray-400);"></i>';
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ALLERGEN / DIETARY CHIP TOGGLE
    // ═══════════════════════════════════════════════════════════════
    function toggleChip(btn, type) {
        btn.classList.toggle('active');
        updateChipHiddenField(type);
    }

    function updateChipHiddenField(type) {
        const containerId = type === 'allergen' ? 'allergenChips' : 'dietaryChips';
        const hiddenId = type === 'allergen' ? 'produtoAllergens' : 'produtoDietaryFlags';
        const chips = document.querySelectorAll('#' + containerId + ' .om-chip.active');
        const values = [];
        chips.forEach(c => values.push(c.dataset.value));
        document.getElementById(hiddenId).value = JSON.stringify(values);
    }

    function setChipsFromJson(type, jsonStr) {
        const containerId = type === 'allergen' ? 'allergenChips' : 'dietaryChips';
        const hiddenId = type === 'allergen' ? 'produtoAllergens' : 'produtoDietaryFlags';
        let arr = [];
        try { arr = JSON.parse(jsonStr || '[]'); } catch(e) {}
        document.querySelectorAll('#' + containerId + ' .om-chip').forEach(chip => {
            chip.classList.toggle('active', arr.includes(chip.dataset.value));
        });
        document.getElementById(hiddenId).value = JSON.stringify(arr);
    }

    function resetAllChips() {
        document.querySelectorAll('.om-chip').forEach(c => c.classList.remove('active'));
        document.getElementById('produtoAllergens').value = '[]';
        document.getElementById('produtoDietaryFlags').value = '[]';
    }

    // ═══════════════════════════════════════════════════════════════
    // BULK SELECTION
    // ═══════════════════════════════════════════════════════════════
    function getSelectedIds() {
        const checked = document.querySelectorAll('.bulk-check:checked');
        return Array.from(checked).map(c => c.value);
    }

    function toggleSelectAll(master) {
        document.querySelectorAll('.bulk-check').forEach(cb => {
            cb.checked = master.checked;
        });
        updateBulkBar();
    }

    function updateBulkBar() {
        const ids = getSelectedIds();
        const bar = document.getElementById('bulkBar');
        if (ids.length > 0) {
            bar.style.display = 'flex';
            document.getElementById('bulkCount').textContent = ids.length + ' selecionado' + (ids.length > 1 ? 's' : '');
        } else {
            bar.style.display = 'none';
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // BULK PRICE
    // ═══════════════════════════════════════════════════════════════
    function bulkEditPrice() {
        document.getElementById('bulkPriceMode').value = 'fixed';
        document.getElementById('bulkPriceValue').value = '';
        updateBulkPriceLabel();
        document.getElementById('modalBulkPreco').classList.add('open');
    }

    function fecharModalBulkPreco() {
        document.getElementById('modalBulkPreco').classList.remove('open');
    }

    function updateBulkPriceLabel() {
        const mode = document.getElementById('bulkPriceMode').value;
        const label = document.getElementById('bulkPriceLabel');
        const prefix = document.getElementById('bulkPricePrefix');
        if (mode === 'fixed') {
            label.textContent = 'Preco (R$)';
            prefix.textContent = 'R$';
        } else {
            label.textContent = 'Percentual (%)';
            prefix.textContent = '%';
        }
    }

    function executeBulkPrice() {
        const ids = getSelectedIds();
        if (ids.length === 0) return;
        const mode = document.getElementById('bulkPriceMode').value;
        const value = document.getElementById('bulkPriceValue').value;
        if (!value) { alert('Informe o valor'); return; }

        const formData = new FormData();
        formData.append('bulk_action', 'bulk_edit_price');
        formData.append('product_ids', ids.join(','));
        formData.append('price_mode', mode);
        formData.append('price_value', value);

        fetch('produtos.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fecharModalBulkPreco();
                    location.reload();
                } else {
                    alert(data.message || 'Erro');
                }
            })
            .catch(() => alert('Erro de conexao'));
    }

    // ═══════════════════════════════════════════════════════════════
    // BULK STOCK
    // ═══════════════════════════════════════════════════════════════
    function bulkEditStock() {
        document.getElementById('bulkStockMode').value = 'fixed';
        document.getElementById('bulkStockValue').value = '';
        document.getElementById('modalBulkEstoque').classList.add('open');
    }

    function fecharModalBulkEstoque() {
        document.getElementById('modalBulkEstoque').classList.remove('open');
    }

    function executeBulkStock() {
        const ids = getSelectedIds();
        if (ids.length === 0) return;
        const mode = document.getElementById('bulkStockMode').value;
        const value = document.getElementById('bulkStockValue').value;
        if (value === '') { alert('Informe a quantidade'); return; }

        const formData = new FormData();
        formData.append('bulk_action', 'bulk_edit_stock');
        formData.append('product_ids', ids.join(','));
        formData.append('stock_mode', mode);
        formData.append('stock_value', value);

        fetch('produtos.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fecharModalBulkEstoque();
                    location.reload();
                } else {
                    alert(data.message || 'Erro');
                }
            })
            .catch(() => alert('Erro de conexao'));
    }

    // ═══════════════════════════════════════════════════════════════
    // BULK TOGGLE STATUS
    // ═══════════════════════════════════════════════════════════════
    function bulkToggleStatus() {
        const ids = getSelectedIds();
        if (ids.length === 0) return;
        if (!confirm('Alternar status de ' + ids.length + ' produto(s)?')) return;

        const formData = new FormData();
        formData.append('bulk_action', 'bulk_toggle_status');
        formData.append('product_ids', ids.join(','));

        fetch('produtos.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Erro');
                }
            })
            .catch(() => alert('Erro de conexao'));
    }

    // ═══════════════════════════════════════════════════════════════
    // CSV IMPORT
    // ═══════════════════════════════════════════════════════════════
    function abrirModalImportCsv() {
        document.getElementById('csvFileInput').value = '';
        document.getElementById('csvPreview').style.display = 'none';
        document.getElementById('csvResult').style.display = 'none';
        document.getElementById('btnImportCsv').disabled = true;
        document.getElementById('modalImportCsv').classList.add('open');
    }

    function fecharModalImportCsv() {
        document.getElementById('modalImportCsv').classList.remove('open');
    }

    function previewCsv(input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            const text = e.target.result;
            const lines = text.split(/\r?\n/).filter(l => l.trim());
            if (lines.length < 2) {
                alert('Arquivo CSV vazio ou com apenas cabecalho');
                return;
            }

            // Detect separator
            const sep = lines[0].includes(';') ? ';' : ',';
            const parseRow = (line) => {
                const result = [];
                let current = '';
                let inQuotes = false;
                for (let i = 0; i < line.length; i++) {
                    const c = line[i];
                    if (c === '"') {
                        inQuotes = !inQuotes;
                    } else if (c === sep && !inQuotes) {
                        result.push(current.trim());
                        current = '';
                    } else {
                        current += c;
                    }
                }
                result.push(current.trim());
                return result;
            };

            const header = parseRow(lines[0]);
            let headHtml = '<tr>';
            header.forEach(h => { headHtml += '<th>' + h + '</th>'; });
            headHtml += '</tr>';
            document.getElementById('csvPreviewHead').innerHTML = headHtml;

            let bodyHtml = '';
            const previewCount = Math.min(lines.length - 1, 5);
            for (let i = 1; i <= previewCount; i++) {
                const cols = parseRow(lines[i]);
                bodyHtml += '<tr>';
                cols.forEach(c => { bodyHtml += '<td>' + c + '</td>'; });
                bodyHtml += '</tr>';
            }
            document.getElementById('csvPreviewBody').innerHTML = bodyHtml;
            document.getElementById('csvPreview').style.display = 'block';
            document.getElementById('btnImportCsv').disabled = false;
            document.getElementById('csvResult').style.display = 'none';
        };
        reader.readAsText(file, 'UTF-8');
    }

    // ═══════════════════════════════════════════════════════════════
    // MULTI-IMAGE GALLERY
    // ═══════════════════════════════════════════════════════════════
    let productImages = [];

    function renderImagesGallery() {
        const gallery = document.getElementById('imagesGallery');
        let html = '';
        productImages.forEach((url, idx) => {
            html += `
                <div class="om-gallery-item">
                    <img src="${url}" alt="Foto ${idx+1}" onerror="this.style.display='none'">
                    <button type="button" class="om-gallery-remove" onclick="removeExtraImage(${idx})" title="Remover">&times;</button>
                </div>
            `;
        });
        if (productImages.length < 5) {
            html += `<div class="om-gallery-add" onclick="document.getElementById('produtoExtraImageFile').click()" title="Adicionar foto">+</div>`;
        }
        gallery.innerHTML = html;
        document.getElementById('produtoImages').value = JSON.stringify(productImages);
    }

    function removeExtraImage(idx) {
        productImages.splice(idx, 1);
        renderImagesGallery();
    }

    async function uploadExtraImage(input) {
        if (!input.files || !input.files[0]) return;
        if (productImages.length >= 5) {
            alert('Maximo de 5 fotos adicionais');
            return;
        }

        const file = input.files[0];
        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', 'product');

        try {
            const res = await fetch('/api/mercado/upload.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                productImages.push(data.data.url);
                renderImagesGallery();
            } else {
                alert('Erro ao enviar: ' + data.message);
            }
        } catch(e) {
            alert('Erro de conexao ao enviar imagem');
        }
        input.value = '';
    }

    // ═══════════════════════════════════════════════════════════════
    // COMBO / KIT
    // ═══════════════════════════════════════════════════════════════
    let comboItems = []; // [{product_id, name, quantity}]
    let comboSearchTimer = null;

    function toggleComboUI() {
        const checked = document.getElementById('produtoIsCombo').checked;
        document.getElementById('comboSection').style.display = checked ? 'block' : 'none';
    }

    function renderComboItems() {
        const list = document.getElementById('comboItemsList');
        const empty = document.getElementById('comboEmpty');
        if (comboItems.length === 0) {
            empty.style.display = 'block';
            list.innerHTML = '';
            list.appendChild(empty);
        } else {
            empty.style.display = 'none';
            let html = '';
            comboItems.forEach((item, idx) => {
                html += `
                    <div class="om-combo-item">
                        <div class="om-combo-item-name">${item.name}</div>
                        <input type="number" class="om-input om-combo-item-qty" value="${item.quantity}" min="1"
                               onchange="updateComboItemQty(${idx}, this.value)" style="width:60px;padding:4px 8px;text-align:center;">
                        <button type="button" class="om-btn om-btn-xs om-btn-ghost om-text-error" onclick="removeComboItem(${idx})" title="Remover">
                            <i class="lucide-trash-2"></i>
                        </button>
                    </div>
                `;
            });
            list.innerHTML = html;
        }
        document.getElementById('produtoComboItems').value = JSON.stringify(comboItems);
    }

    function updateComboItemQty(idx, val) {
        comboItems[idx].quantity = Math.max(1, parseInt(val) || 1);
        document.getElementById('produtoComboItems').value = JSON.stringify(comboItems);
    }

    function removeComboItem(idx) {
        comboItems.splice(idx, 1);
        renderComboItems();
    }

    function addComboItem(productId, productName) {
        // Avoid duplicates
        if (comboItems.find(ci => ci.product_id === productId)) {
            alert('Este produto ja esta no combo');
            return;
        }
        comboItems.push({ product_id: productId, name: productName, quantity: 1 });
        renderComboItems();
        document.getElementById('comboSearchInput').value = '';
        document.getElementById('comboSearchResults').style.display = 'none';
    }

    function searchComboProduct(query) {
        clearTimeout(comboSearchTimer);
        const resultsDiv = document.getElementById('comboSearchResults');
        if (query.length < 2) {
            resultsDiv.style.display = 'none';
            return;
        }
        comboSearchTimer = setTimeout(function() {
            fetch(`produtos.php?combo_search=1&q=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.products && data.products.length > 0) {
                        let html = '';
                        data.products.forEach(p => {
                            html += `<div class="om-combo-search-item" onclick="addComboItem(${p.product_id}, '${p.name.replace(/'/g, "\\'")}')">
                                ${p.name} <span class="om-text-muted om-text-xs">R$ ${parseFloat(p.price).toFixed(2).replace('.', ',')}</span>
                            </div>`;
                        });
                        resultsDiv.innerHTML = html;
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.innerHTML = '<div class="om-text-center om-text-muted om-py-2">Nenhum produto encontrado</div>';
                        resultsDiv.style.display = 'block';
                    }
                })
                .catch(() => {
                    resultsDiv.style.display = 'none';
                });
        }, 300);
    }

    function executeCsvImport() {
        const fileInput = document.getElementById('csvFileInput');
        if (!fileInput.files || !fileInput.files[0]) {
            alert('Selecione um arquivo CSV');
            return;
        }

        const btn = document.getElementById('btnImportCsv');
        btn.disabled = true;
        btn.innerHTML = '<i class="lucide-loader-2 om-animate-spin"></i> Importando...';

        const formData = new FormData();
        formData.append('action', 'import_csv');
        formData.append('csv_file', fileInput.files[0]);

        fetch('produtos.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                const resultDiv = document.getElementById('csvResult');
                resultDiv.style.display = 'block';
                if (data.success) {
                    let html = '<div class="om-alert om-alert-success"><i class="lucide-check-circle"></i><div class="om-alert-content"><div class="om-alert-message">' + data.message + '</div></div></div>';
                    if (data.errorDetails && data.errorDetails.length > 0) {
                        html += '<div class="om-alert om-alert-warning om-mt-2"><i class="lucide-alert-triangle"></i><div class="om-alert-content"><div class="om-alert-message">';
                        data.errorDetails.forEach(e => { html += e + '<br>'; });
                        html += '</div></div></div>';
                    }
                    html += '<p class="om-text-center om-mt-3"><button class="om-btn om-btn-primary om-btn-sm" onclick="location.reload()">Recarregar Pagina</button></p>';
                    resultDiv.innerHTML = html;
                } else {
                    resultDiv.innerHTML = '<div class="om-alert om-alert-error"><i class="lucide-alert-circle"></i><div class="om-alert-content"><div class="om-alert-message">' + (data.message || 'Erro desconhecido') + '</div></div></div>';
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="lucide-upload"></i> Importar';
            })
            .catch(() => {
                alert('Erro de conexao');
                btn.disabled = false;
                btn.innerHTML = '<i class="lucide-upload"></i> Importar';
            });
    }
    </script>
</body>
</html>
