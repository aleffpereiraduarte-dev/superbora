<?php
/**
 * POST /api/mercado/partner/import-ifood.php
 * Import menu from iFood public page
 *
 * action=fetch_menu  — Fetch and parse iFood store menu
 *   Body: { "action": "fetch_menu", "ifood_url": "https://www.ifood.com.br/delivery/..." }
 *   Returns: { categories: [...], store_name: "...", product_count: N }
 *
 * action=import — Import parsed products into partner catalog
 *   Body: { "action": "import", "products": [ { name, description, price, image_url, category } ] }
 *   Returns: { imported: N, skipped: N, categories_created: N }
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $input = getInput();
    $action = trim($input['action'] ?? '');

    if ($action === 'fetch_menu') {
        handleFetchMenu($input, $partner_id);
    } elseif ($action === 'import') {
        handleImport($input, $db, $partner_id);
    } else {
        response(false, null, "action obrigatoria: fetch_menu ou import", 400);
    }

} catch (Exception $e) {
    error_log("[import-ifood] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar importacao", 500);
}

// ═══════════════════════════════════════════════════════════════
// FETCH MENU from iFood
// ═══════════════════════════════════════════════════════════════
function handleFetchMenu($input, $partner_id) {
    $url = trim($input['ifood_url'] ?? '');

    if (empty($url)) {
        response(false, null, "ifood_url obrigatoria", 400);
    }

    // Validate it looks like an iFood URL
    if (!preg_match('#ifood\.com\.br/(delivery|menu)/.+#i', $url)) {
        response(false, null, "URL invalida. Use uma URL do iFood como: https://www.ifood.com.br/delivery/cidade/loja/uuid", 400);
    }

    // Extract store slug and UUID from URL
    // Format: /delivery/{city}/{slug}/{uuid}
    // or: /menu/{city}/{slug}/{uuid}
    $parsed = parse_url($url);
    $pathParts = array_values(array_filter(explode('/', $parsed['path'] ?? '')));
    // pathParts: [delivery, city, slug, uuid]

    $storeSlug = $pathParts[2] ?? '';
    $storeUuid = $pathParts[3] ?? '';

    if (empty($storeSlug)) {
        response(false, null, "Nao foi possivel extrair o identificador da loja da URL", 400);
    }

    // Strategy 1: Try the iFood catalog API (public, no auth needed)
    $menuData = fetchIfoodCatalogApi($storeUuid, $storeSlug);

    // Strategy 2: Scrape the HTML page and extract __NEXT_DATA__
    if (!$menuData) {
        $menuData = scrapeIfoodPage($url);
    }

    // Strategy 3: Try the merchant API
    if (!$menuData) {
        $menuData = fetchIfoodMerchantApi($storeUuid);
    }

    if (!$menuData || empty($menuData['categories'])) {
        response(false, null, "Nao foi possivel obter o cardapio. Verifique a URL e tente novamente. A loja pode ter protecao contra scraping.", 400);
    }

    // Count products
    $productCount = 0;
    foreach ($menuData['categories'] as $cat) {
        $productCount += count($cat['products'] ?? []);
    }

    response(true, [
        'store_name' => $menuData['store_name'] ?? $storeSlug,
        'categories' => $menuData['categories'],
        'product_count' => $productCount,
        'source' => $menuData['source'] ?? 'unknown'
    ], "Cardapio carregado com sucesso");
}

/**
 * Try iFood's catalog API endpoint
 */
function fetchIfoodCatalogApi($uuid, $slug) {
    if (empty($uuid)) return null;

    $urls = [
        "https://marketplace.ifood.com.br/v1/merchants/{$uuid}/catalog",
        "https://marketplace.ifood.com.br/v2/merchants/{$uuid}/catalog",
        "https://wsloja.ifood.com.br/ifood-ws-v3/restaurants/{$uuid}/menu",
    ];

    foreach ($urls as $apiUrl) {
        $html = httpGet($apiUrl, [
            'User-Agent: Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Accept: application/json',
            'app_id: br.com.brainweb.ifood',
            'platform: ANDROID',
        ]);

        if (!$html) continue;

        $data = json_decode($html, true);
        if (!$data) continue;

        // Parse catalog format
        $result = parseCatalogResponse($data);
        if ($result && !empty($result['categories'])) {
            $result['source'] = 'catalog_api';
            return $result;
        }
    }

    return null;
}

/**
 * Scrape the iFood web page and extract __NEXT_DATA__ JSON
 */
function scrapeIfoodPage($url) {
    $html = httpGet($url, [
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8',
    ]);

    if (!$html) return null;

    // Extract __NEXT_DATA__ from the page (iFood uses Next.js)
    if (preg_match('#<script id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $html, $m)) {
        $nextData = json_decode($m[1], true);
        if ($nextData) {
            return parseNextData($nextData);
        }
    }

    // Fallback: look for embedded JSON catalog data
    if (preg_match('#window\.__APOLLO_STATE__\s*=\s*(\{.+?\});#s', $html, $m)) {
        $apolloData = json_decode($m[1], true);
        if ($apolloData) {
            return parseApolloState($apolloData);
        }
    }

    // Fallback: try to find structured data (JSON-LD)
    if (preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $m)) {
        $ldData = json_decode($m[1], true);
        if ($ldData && ($ldData['@type'] ?? '') === 'Restaurant') {
            return parseLdJson($ldData);
        }
    }

    return null;
}

/**
 * Try the merchant-specific API
 */
function fetchIfoodMerchantApi($uuid) {
    if (empty($uuid)) return null;

    $apiUrl = "https://marketplace.ifood.com.br/v1/merchants/{$uuid}?latitude=-23.5505&longitude=-46.6333";
    $html = httpGet($apiUrl, [
        'User-Agent: Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'Accept: application/json',
    ]);

    if (!$html) return null;

    $data = json_decode($html, true);
    if (!$data || !isset($data['menu'])) return null;

    return parseMerchantMenu($data);
}

// ═══════════════════════════════════════════════════════════════
// PARSERS
// ═══════════════════════════════════════════════════════════════

function parseCatalogResponse($data) {
    $categories = [];
    $storeName = $data['name'] ?? $data['merchant']['name'] ?? '';

    // catalog v1/v2 format
    $menuData = $data['data']['menu'] ?? $data['menu'] ?? $data['catalog'] ?? $data;

    // Handle array of categories/groups
    $groups = $menuData['categories'] ?? $menuData['groups'] ?? $menuData ?? [];

    if (!is_array($groups)) return null;

    foreach ($groups as $group) {
        $catName = $group['name'] ?? $group['description'] ?? 'Sem categoria';
        $products = [];

        $items = $group['items'] ?? $group['products'] ?? $group['itens'] ?? [];
        foreach ($items as $item) {
            $price = $item['unitPrice'] ?? $item['price'] ?? $item['unitMinPrice'] ?? 0;
            // iFood prices are sometimes in centavos
            if ($price > 10000) $price = $price / 100;

            $products[] = [
                'name' => $item['description'] ?? $item['name'] ?? '',
                'description' => $item['details'] ?? $item['additionalInfo'] ?? '',
                'price' => round((float)$price, 2),
                'image_url' => $item['logoUrl'] ?? $item['image'] ?? $item['imageUrl'] ?? '',
                'category' => $catName,
            ];
        }

        if (!empty($products)) {
            $categories[] = [
                'name' => $catName,
                'products' => $products
            ];
        }
    }

    return ['store_name' => $storeName, 'categories' => $categories];
}

function parseNextData($nextData) {
    $props = $nextData['props']['pageProps'] ?? $nextData['props']['initialProps'] ?? [];
    $storeName = $props['merchant']['name'] ?? $props['restaurant']['name'] ?? '';

    // Navigate to menu data
    $menu = $props['menu'] ?? $props['catalog'] ?? $props['merchant']['menu'] ?? [];
    $categories = [];

    if (isset($menu['categories'])) {
        $menu = $menu['categories'];
    } elseif (isset($menu['menu'])) {
        $menu = $menu['menu'];
    }

    if (!is_array($menu)) return null;

    foreach ($menu as $section) {
        $catName = $section['name'] ?? $section['title'] ?? 'Sem categoria';
        $products = [];

        $items = $section['items'] ?? $section['products'] ?? $section['itens'] ?? [];
        foreach ($items as $item) {
            $price = $item['unitPrice'] ?? $item['price'] ?? $item['unitMinPrice'] ?? 0;
            if ($price > 10000) $price = $price / 100;

            $imgUrl = '';
            if (!empty($item['logoUrl'])) $imgUrl = $item['logoUrl'];
            elseif (!empty($item['image'])) $imgUrl = $item['image'];
            elseif (!empty($item['resources'])) {
                foreach ($item['resources'] as $res) {
                    if (($res['type'] ?? '') === 'LOGO' || !empty($res['fileName'])) {
                        $imgUrl = $res['fileName'] ?? $res['url'] ?? '';
                        break;
                    }
                }
            }

            $products[] = [
                'name' => $item['description'] ?? $item['name'] ?? '',
                'description' => $item['details'] ?? $item['additionalInfo'] ?? $item['formattedDetails'] ?? '',
                'price' => round((float)$price, 2),
                'image_url' => $imgUrl,
                'category' => $catName,
            ];
        }

        if (!empty($products)) {
            $categories[] = ['name' => $catName, 'products' => $products];
        }
    }

    return ['store_name' => $storeName, 'categories' => $categories];
}

function parseApolloState($apolloData) {
    $categories = [];
    $storeName = '';
    $categoryMap = [];
    $productsByCategory = [];

    foreach ($apolloData as $key => $value) {
        if (is_array($value)) {
            // Find restaurant name
            if (isset($value['name']) && (strpos($key, 'Restaurant') !== false || strpos($key, 'Merchant') !== false)) {
                $storeName = $value['name'];
            }
            // Find categories
            if (isset($value['name']) && isset($value['items']) && is_array($value['items'])) {
                $catName = $value['name'];
                $categoryMap[$key] = $catName;
                $productsByCategory[$catName] = [];
                foreach ($value['items'] as $itemRef) {
                    $itemKey = $itemRef['__ref'] ?? (is_string($itemRef) ? $itemRef : '');
                    if ($itemKey && isset($apolloData[$itemKey])) {
                        $item = $apolloData[$itemKey];
                        $price = $item['unitPrice'] ?? $item['price'] ?? 0;
                        if ($price > 10000) $price = $price / 100;

                        $productsByCategory[$catName][] = [
                            'name' => $item['description'] ?? $item['name'] ?? '',
                            'description' => $item['details'] ?? '',
                            'price' => round((float)$price, 2),
                            'image_url' => $item['logoUrl'] ?? $item['image'] ?? '',
                            'category' => $catName,
                        ];
                    }
                }
            }
        }
    }

    foreach ($productsByCategory as $catName => $products) {
        if (!empty($products)) {
            $categories[] = ['name' => $catName, 'products' => $products];
        }
    }

    return ['store_name' => $storeName, 'categories' => $categories];
}

function parseLdJson($ldData) {
    $storeName = $ldData['name'] ?? '';
    $categories = [];

    if (isset($ldData['hasMenu']['hasMenuSection'])) {
        foreach ($ldData['hasMenu']['hasMenuSection'] as $section) {
            $catName = $section['name'] ?? 'Sem categoria';
            $products = [];
            foreach ($section['hasMenuItem'] ?? [] as $item) {
                $price = 0;
                if (isset($item['offers']['price'])) $price = (float)$item['offers']['price'];

                $products[] = [
                    'name' => $item['name'] ?? '',
                    'description' => $item['description'] ?? '',
                    'price' => round($price, 2),
                    'image_url' => $item['image'] ?? '',
                    'category' => $catName,
                ];
            }
            if (!empty($products)) {
                $categories[] = ['name' => $catName, 'products' => $products];
            }
        }
    }

    return ['store_name' => $storeName, 'categories' => $categories];
}

function parseMerchantMenu($data) {
    $storeName = $data['name'] ?? '';
    $categories = [];

    foreach ($data['menu'] as $section) {
        $catName = $section['name'] ?? 'Sem categoria';
        $products = [];

        foreach ($section['itens'] ?? $section['items'] ?? [] as $item) {
            $price = $item['unitPrice'] ?? $item['price'] ?? 0;
            if ($price > 10000) $price = $price / 100;

            $products[] = [
                'name' => $item['description'] ?? $item['name'] ?? '',
                'description' => $item['details'] ?? '',
                'price' => round((float)$price, 2),
                'image_url' => $item['logoUrl'] ?? $item['image'] ?? '',
                'category' => $catName,
            ];
        }

        if (!empty($products)) {
            $categories[] = ['name' => $catName, 'products' => $products];
        }
    }

    return ['store_name' => $storeName, 'categories' => $categories];
}

// ═══════════════════════════════════════════════════════════════
// IMPORT products into the catalog
// ═══════════════════════════════════════════════════════════════
function handleImport($input, $db, $partner_id) {
    $products = $input['products'] ?? [];

    if (empty($products) || !is_array($products)) {
        response(false, null, "Lista de produtos obrigatoria", 400);
    }

    if (count($products) > 500) {
        response(false, null, "Maximo de 500 produtos por importacao", 400);
    }

    $imported = 0;
    $skipped = 0;
    $categoriesCreated = 0;
    $categoryCache = []; // name => category_id
    $errors = [];

    // Pre-load existing categories
    $stmt = $db->prepare("SELECT category_id, name FROM om_market_categories WHERE parent_id IS NULL OR parent_id = 0 ORDER BY name");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $categoryCache[mb_strtolower(trim($row['name']))] = (int)$row['category_id'];
    }

    $db->beginTransaction();

    try {
        foreach ($products as $idx => $p) {
            $name = trim($p['name'] ?? '');
            $description = trim($p['description'] ?? '');
            $price = round((float)($p['price'] ?? 0), 2);
            $imageUrl = trim($p['image_url'] ?? '');
            $categoryName = trim($p['category'] ?? 'Importados iFood');

            if (empty($name)) {
                $skipped++;
                continue;
            }
            if ($price <= 0) {
                $skipped++;
                $errors[] = "Produto '{$name}' ignorado: preco invalido";
                continue;
            }

            // Get or create category
            $catKey = mb_strtolower($categoryName);
            if (!isset($categoryCache[$catKey])) {
                $stmtCat = $db->prepare("INSERT INTO om_market_categories (name, slug, status, sort_order) VALUES (?, ?, 1, 0) RETURNING category_id");
                $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(removeAccents($categoryName)));
                $stmtCat->execute([$categoryName, $slug]);
                $catId = (int)$stmtCat->fetchColumn();
                $categoryCache[$catKey] = $catId;
                $categoriesCreated++;
            }
            $categoryId = $categoryCache[$catKey];

            // Check for duplicate (same name + partner)
            $stmtDup = $db->prepare("
                SELECT pb.product_id FROM om_market_products_base pb
                INNER JOIN om_market_products_price pp ON pp.product_id = pb.product_id
                WHERE LOWER(pb.name) = LOWER(?) AND pp.partner_id = ?
                LIMIT 1
            ");
            $stmtDup->execute([$name, $partner_id]);
            if ($stmtDup->fetch()) {
                $skipped++;
                continue;
            }

            // Download image if available
            $localImage = '';
            if (!empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $localImage = downloadProductImage($imageUrl, $partner_id);
            }

            // Insert into products_base
            $stmtProd = $db->prepare("
                INSERT INTO om_market_products_base (name, description, category_id, image, status, date_added)
                VALUES (?, ?, ?, ?, 1, NOW())
                RETURNING product_id
            ");
            $stmtProd->execute([$name, $description, $categoryId, $localImage]);
            $productId = (int)$stmtProd->fetchColumn();

            // Insert pricing for this partner
            $stmtPrice = $db->prepare("
                INSERT INTO om_market_products_price (product_id, partner_id, price, stock, status, date_added)
                VALUES (?, ?, ?, 999, 'active', NOW())
            ");
            $stmtPrice->execute([$productId, $partner_id, $price]);

            $imported++;
        }

        $db->commit();

        // Audit log
        if (class_exists('OmAudit')) {
            OmAudit::getInstance()->log(
                'import_ifood',
                "Importou {$imported} produtos do iFood",
                'partner',
                $partner_id
            );
        }

        response(true, [
            'imported' => $imported,
            'skipped' => $skipped,
            'categories_created' => $categoriesCreated,
            'errors' => array_slice($errors, 0, 10)
        ], "Importacao concluida: {$imported} produtos importados, {$skipped} ignorados");

    } catch (Exception $e) {
        $db->rollBack();
        error_log("[import-ifood] Import failed: " . $e->getMessage());
        response(false, null, "Erro durante importacao: " . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════════════

function httpGet($url, $headers = []) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => 'gzip, deflate',
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300 && !empty($result)) {
        return $result;
    }
    return null;
}

function downloadProductImage($url, $partner_id) {
    $uploadDir = dirname(__DIR__, 3) . '/storage/uploads/products/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $imageData = httpGet($url);
    if (!$imageData) return '';

    // Detect extension from content
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($imageData);
    $ext = 'jpg';
    if ($mime === 'image/png') $ext = 'png';
    elseif ($mime === 'image/webp') $ext = 'webp';
    elseif ($mime === 'image/gif') $ext = 'gif';

    // Only allow images
    if (strpos($mime, 'image/') !== 0) return '';

    $filename = 'ifood_' . $partner_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filepath = $uploadDir . $filename;

    if (file_put_contents($filepath, $imageData) !== false) {
        return '/storage/uploads/products/' . $filename;
    }

    return '';
}

function removeAccents($str) {
    $map = [
        'a' => 'àáâãäå', 'e' => 'èéêë', 'i' => 'ìíîï',
        'o' => 'òóôõö', 'u' => 'ùúûü', 'c' => 'ç', 'n' => 'ñ',
    ];
    foreach ($map as $replacement => $chars) {
        $str = preg_replace('/[' . $chars . ']/ui', $replacement, $str);
    }
    return $str;
}
