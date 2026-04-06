<?php
/**
 * GET /api/mercado/docs.php
 * Auto-generated API documentation endpoint
 *
 * Scans all PHP endpoint files in the /api/mercado/ directory tree
 * and returns structured JSON documentation organized by category.
 *
 * No auth required — public documentation endpoint.
 *
 * Query params:
 *   ?category=admin      — filter by category
 *   ?search=login        — search endpoint paths and descriptions
 *   ?auth=required       — filter: "required" or "none"
 *   ?method=POST         — filter by HTTP method
 */

// Lightweight bootstrap — no rate limiting, no DB, no auth
date_default_timezone_set('America/Sao_Paulo');

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: public, max-age=300"); // Cache 5 min
header("X-Content-Type-Options: nosniff");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── Configuration ──────────────────────────────────────────────────────────

$baseDir = __DIR__;
$baseUrl = 'https://superbora.com.br/api/mercado';

// Directories to skip (not API endpoints)
$skipDirs = [
    'config', 'helpers', 'sql', 'migrations', 'tests', 'workers',
    '.backups', '.github', '.shopper_disabled', 'landing'
];

// Files to skip
$skipFiles = [
    'docs.php', 'docs-ui.php'
];

// Category display names
$categoryNames = [
    'admin'         => 'Admin Panel',
    'admin/auth'    => 'Admin Authentication',
    'admin/callcenter' => 'Admin Call Center',
    'auth'          => 'Customer Authentication',
    'addresses'     => 'Addresses',
    'avaliacoes'    => 'Reviews & Ratings',
    'boraum'        => 'BoraUm Delivery',
    'busca'         => 'Search',
    'campaign'      => 'Campaigns',
    'carrinho'      => 'Cart',
    'cart'          => 'Cart (Legacy)',
    'categorias'    => 'Categories',
    'chat'          => 'Chat',
    'checkout'      => 'Checkout',
    'cobertura'     => 'Coverage Areas',
    'corporate'     => 'Corporate',
    'cron'          => 'Scheduled Tasks',
    'customer'      => 'Customer',
    'entrega'       => 'Delivery',
    'frete'         => 'Shipping & Freight',
    'gift-cards'    => 'Gift Cards',
    'group-order'   => 'Group Orders',
    'home'          => 'Home / Storefront',
    'horario'       => 'Business Hours',
    'intelligence'  => 'AI & Intelligence',
    'notifications' => 'Notifications',
    'orders'        => 'Orders',
    'pagamento'     => 'Payments',
    'painel'        => 'Partner Panel',
    'parceiros'     => 'Partners (Legacy)',
    'partner'       => 'Partner',
    'partner/auth'  => 'Partner Authentication',
    'pedido'        => 'Order Lifecycle',
    'pedidos'       => 'Order Listing',
    'pricing'       => 'Pricing',
    'produtos'      => 'Products',
    'reviews'       => 'Reviews',
    'store'         => 'Store',
    'subscriptions' => 'Subscriptions',
    'tracking'      => 'Order Tracking',
    'vitrine'       => 'Storefront API',
    'vitrine/customer' => 'Storefront Customer',
    'vitrine/orders'   => 'Storefront Orders',
    'webhook'       => 'Webhooks (Legacy)',
    'webhooks'      => 'Webhooks',
    'webhooks/audio' => 'Audio Webhooks',
    '_root'         => 'Root Endpoints',
];

// ── Scanner ────────────────────────────────────────────────────────────────

/**
 * Recursively find all PHP files, excluding non-endpoint directories
 */
function scanEndpoints(string $dir, string $baseDir, array $skipDirs, array $skipFiles): array {
    $endpoints = [];
    $items = @scandir($dir);
    if (!$items) return $endpoints;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $fullPath = $dir . '/' . $item;
        $relativePath = ltrim(str_replace($baseDir, '', $fullPath), '/');

        // Check if directory should be skipped
        if (is_dir($fullPath)) {
            $dirName = $relativePath;
            $skipThis = false;
            foreach ($skipDirs as $skip) {
                if ($dirName === $skip || strpos($dirName, $skip . '/') === 0) {
                    $skipThis = true;
                    break;
                }
            }
            if (!$skipThis) {
                $endpoints = array_merge($endpoints, scanEndpoints($fullPath, $baseDir, $skipDirs, $skipFiles));
            }
            continue;
        }

        // Only PHP files
        if (pathinfo($item, PATHINFO_EXTENSION) !== 'php') continue;

        // Skip non-endpoint files
        if (in_array($item, $skipFiles)) continue;

        $endpoints[] = $fullPath;
    }

    return $endpoints;
}

/**
 * Extract documentation from a PHP file
 */
function extractEndpointInfo(string $filePath, string $baseDir): array {
    $content = @file_get_contents($filePath);
    if ($content === false) return [];

    $relativePath = '/' . ltrim(str_replace($baseDir, '', $filePath), '/');

    // ── Extract docblock ───────────────────────────────────────────
    $description = '';
    $docLines = [];
    if (preg_match('#/\*\*(.*?)\*/#s', $content, $docMatch)) {
        $rawDoc = $docMatch[1];
        $lines = explode("\n", $rawDoc);
        foreach ($lines as $line) {
            $line = trim($line);
            // Remove leading * from docblock lines (safe single-byte trim)
            $line = preg_replace('/^\*+\s*/', '', $line);
            // Remove decorative unicode box-drawing and line chars
            $line = preg_replace('/[═─┌┐└┘├┤┬┴┼│╔╗╚╝╠╣╦╩╬║▓▒░█▄▀■●○◆◇★☆]+/u', '', $line);
            $line = trim($line);
            if (empty($line)) continue;
            // Skip lines that are just the path or method+path (we already have it)
            if (preg_match('#^(GET|POST|PUT|PATCH|DELETE|OPTIONS)[/\s]+/api/#i', $line)) continue;
            if (preg_match('#^(GET|POST|PUT|PATCH|DELETE)(/\w+)+\s+/api/#i', $line)) continue;
            if (preg_match('#^/api/mercado/#', $line)) continue;
            // Skip @param, @return, etc. for the description
            if (preg_match('#^@#', $line)) continue;
            // Skip table-like content (lines with multiple | separators)
            if (substr_count($line, '|') >= 2) continue;
            $docLines[] = $line;
        }
        $description = implode(' ', array_slice($docLines, 0, 3)); // First 3 meaningful lines
        // Ensure valid UTF-8
        $description = mb_convert_encoding($description, 'UTF-8', 'UTF-8');
    }

    // ── Detect HTTP methods ────────────────────────────────────────
    $methods = [];

    // Explicit method checks
    if (preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]GET[\'"]/i', $content) ||
        preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]get[\'"]/i', $content)) {
        $methods[] = 'GET';
    }
    if (preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]POST[\'"]/i', $content) ||
        preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]post[\'"]/i', $content)) {
        $methods[] = 'POST';
    }
    if (preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]PUT[\'"]/i', $content) ||
        preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]put[\'"]/i', $content)) {
        $methods[] = 'PUT';
    }
    if (preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]PATCH[\'"]/i', $content) ||
        preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]patch[\'"]/i', $content)) {
        $methods[] = 'PATCH';
    }
    if (preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]DELETE[\'"]/i', $content) ||
        preg_match('/REQUEST_METHOD\b.*?===?\s*[\'"]delete[\'"]/i', $content)) {
        $methods[] = 'DELETE';
    }

    // Switch-based method routing
    if (preg_match('/switch\s*\(\s*\$_SERVER\s*\[\s*[\'"]REQUEST_METHOD[\'"]\s*\]/s', $content) ||
        preg_match('/switch\s*\(\s*\$method\b/s', $content)) {
        if (preg_match_all('/case\s+[\'"](\w+)[\'"]\s*:/i', $content, $caseMatches)) {
            foreach ($caseMatches[1] as $m) {
                $m = strtoupper($m);
                if (in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']) && !in_array($m, $methods)) {
                    $methods[] = $m;
                }
            }
        }
    }

    // Method check from docblock
    if (empty($methods) && preg_match('#\*\s*(GET|POST|PUT|PATCH|DELETE)\s+/api/#i', $content, $docMethod)) {
        $methods[] = strtoupper($docMethod[1]);
    }

    // Check for !== 'POST' patterns (means only POST is allowed)
    if (empty($methods) && preg_match('/REQUEST_METHOD.*?!==?\s*[\'"]POST[\'"]/i', $content)) {
        $methods[] = 'POST';
    }
    if (empty($methods) && preg_match('/REQUEST_METHOD.*?!==?\s*[\'"]GET[\'"]/i', $content)) {
        $methods[] = 'GET';
    }

    // If getInput() is called, likely POST
    if (empty($methods) && preg_match('/\bgetInput\s*\(\s*\)/', $content)) {
        $methods[] = 'POST';
    }

    // $_POST used directly
    if (empty($methods) && preg_match('/\$_POST\b/', $content)) {
        $methods[] = 'POST';
    }

    // Fallback: $_GET params or no method checks means likely GET
    if (empty($methods)) {
        if (preg_match('/\$_GET\b/', $content)) {
            $methods[] = 'GET';
        } else {
            $methods[] = 'GET'; // Default assumption
        }
    }

    sort($methods);

    // ── Detect auth requirement ────────────────────────────────────
    $authRequired = false;
    $authType = null;

    if (preg_match('/requireAdmin\s*\(/', $content)) {
        $authRequired = true;
        $authType = 'admin';
    } elseif (preg_match('/requirePartner\s*\(/', $content)) {
        $authRequired = true;
        $authType = 'partner';
    } elseif (preg_match('/requireCustomerAuth\s*\(/', $content)) {
        $authRequired = true;
        $authType = 'customer';
    } elseif (preg_match('/om_auth\(\)\s*->\s*requireAdmin/', $content)) {
        $authRequired = true;
        $authType = 'admin';
    } elseif (preg_match('/om_auth\(\)\s*->\s*requirePartner/', $content)) {
        $authRequired = true;
        $authType = 'partner';
    } elseif (preg_match('/getTokenFromRequest|validateToken|OmAuth/', $content)) {
        // Uses auth but may be optional
        if (preg_match('/Autenticacao necessaria|401|requireAuth/', $content)) {
            $authRequired = true;
            // Infer type from directory
            if (strpos($relativePath, '/admin/') !== false) {
                $authType = 'admin';
            } elseif (strpos($relativePath, '/partner/') !== false || strpos($relativePath, '/painel/') !== false) {
                $authType = 'partner';
            } else {
                $authType = 'customer';
            }
        } else {
            $authRequired = false;
            $authType = 'optional';
        }
    } elseif (preg_match('/admin_session_check|requireAdminAuth|admin_auth/', $content)) {
        $authRequired = true;
        $authType = 'admin';
    }

    // Webhook/cron files with HMAC or secret checks
    if (!$authRequired && preg_match('/HMAC|webhook_secret|X-Signature/i', $content)) {
        $authType = 'webhook_signature';
    }

    // ── Detect query parameters ────────────────────────────────────
    $queryParams = [];
    if (preg_match_all('/\$_GET\s*\[\s*[\'"](\w+)[\'"]\s*\]/', $content, $getMatches)) {
        $queryParams = array_unique($getMatches[1]);
        $queryParams = array_values(array_diff($queryParams, ['page', 'limit', 'offset'])); // Keep common ones but don't duplicate
    }

    // ── Detect request body fields ─────────────────────────────────
    $bodyFields = [];
    if (preg_match_all('/\$input\s*\[\s*[\'"](\w+)[\'"]\s*\]/', $content, $inputMatches)) {
        $bodyFields = array_values(array_unique($inputMatches[1]));
    }

    // ── Determine category ─────────────────────────────────────────
    $parts = explode('/', trim($relativePath, '/'));
    if (count($parts) <= 1) {
        $category = '_root';
    } elseif (count($parts) >= 3) {
        // Try two-level category first (e.g., admin/callcenter, partner/auth)
        $twoLevel = $parts[0] . '/' . $parts[1];
        if (isset($GLOBALS['categoryNames'][$twoLevel])) {
            $category = $twoLevel;
        } else {
            $category = $parts[0];
        }
    } else {
        $category = $parts[0];
    }

    return [
        'path'           => $relativePath,
        'methods'        => $methods,
        'auth_required'  => $authRequired,
        'auth_type'      => $authType,
        'description'    => $description ?: null,
        'query_params'   => !empty($queryParams) ? $queryParams : null,
        'body_fields'    => !empty($bodyFields) ? $bodyFields : null,
        'category'       => $category,
    ];
}

// ── Main execution ─────────────────────────────────────────────────────────

// Make categoryNames available to extractEndpointInfo
$GLOBALS['categoryNames'] = $categoryNames;

$allFiles = scanEndpoints($baseDir, $baseDir, $skipDirs, $skipFiles);
$endpoints = [];

foreach ($allFiles as $file) {
    $info = extractEndpointInfo($file, $baseDir);
    if (!empty($info)) {
        $endpoints[] = $info;
    }
}

// Sort endpoints by path
usort($endpoints, function ($a, $b) {
    return strcmp($a['path'], $b['path']);
});

// ── Apply filters ──────────────────────────────────────────────────────────

$filterCategory = $_GET['category'] ?? null;
$filterSearch   = $_GET['search'] ?? null;
$filterAuth     = $_GET['auth'] ?? null;
$filterMethod   = strtoupper($_GET['method'] ?? '');

if ($filterCategory || $filterSearch || $filterAuth || $filterMethod) {
    $endpoints = array_filter($endpoints, function ($ep) use ($filterCategory, $filterSearch, $filterAuth, $filterMethod) {
        if ($filterCategory && $ep['category'] !== $filterCategory) return false;
        if ($filterSearch) {
            $haystack = strtolower($ep['path'] . ' ' . ($ep['description'] ?? ''));
            if (strpos($haystack, strtolower($filterSearch)) === false) return false;
        }
        if ($filterAuth === 'required' && !$ep['auth_required']) return false;
        if ($filterAuth === 'none' && $ep['auth_required']) return false;
        if ($filterMethod && !in_array($filterMethod, $ep['methods'])) return false;
        return true;
    });
    $endpoints = array_values($endpoints);
}

// ── Group by category ──────────────────────────────────────────────────────

$categories = [];
foreach ($endpoints as $ep) {
    $cat = $ep['category'];
    if (!isset($categories[$cat])) {
        $categories[$cat] = [
            'name'      => $categoryNames[$cat] ?? ucfirst(str_replace(['-', '/'], [' ', ' > '], $cat)),
            'endpoints' => [],
        ];
    }

    // Remove category from endpoint data (it's already grouped)
    $endpointData = $ep;
    unset($endpointData['category']);

    // Remove null fields for cleaner output
    $endpointData = array_filter($endpointData, function ($v) { return $v !== null; });

    $categories[$cat]['endpoints'][] = $endpointData;
}

// Sort categories alphabetically, but put _root last
uksort($categories, function ($a, $b) {
    if ($a === '_root') return 1;
    if ($b === '_root') return -1;
    return strcmp($a, $b);
});

// Add endpoint count per category
foreach ($categories as &$cat) {
    $cat['count'] = count($cat['endpoints']);
}
unset($cat);

// ── Build response ─────────────────────────────────────────────────────────

$totalEndpoints = array_sum(array_column($categories, 'count'));

$response = [
    'success' => true,
    'data'    => [
        'api_name'        => 'SuperBora API',
        'version'         => '1.0',
        'base_url'        => $baseUrl,
        'generated_at'    => date('c'),
        'total_endpoints' => $totalEndpoints,
        'total_categories' => count($categories),
        'filters'         => [
            'category' => $filterCategory,
            'search'   => $filterSearch,
            'auth'     => $filterAuth,
            'method'   => $filterMethod ?: null,
        ],
        'categories'      => $categories,
    ],
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
