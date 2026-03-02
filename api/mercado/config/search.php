<?php
/**
 * MeiliSearch - Search engine integration
 *
 * Provides fast, typo-tolerant product search.
 * Meilisearch runs locally at 127.0.0.1:7700
 */

class SearchService {
    private static ?self $instance = null;
    private string $host;
    private string $adminKey;
    private string $searchKey;

    private function __construct() {
        $this->host = $_ENV['MEILI_HOST'] ?? 'http://127.0.0.1:7700';
        $this->adminKey = $_ENV['MEILI_ADMIN_KEY'] ?? '';
        $this->searchKey = $_ENV['MEILI_SEARCH_KEY'] ?? '';

        // SECURITY: Admin key must come from environment variables only.
        // Reading from a world-readable file is insecure.
        if (empty($this->adminKey)) {
            error_log('[SearchService] MEILI_ADMIN_KEY not configured in environment variables');
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Search products
     */
    public function searchProducts(string $query, array $options = []): array {
        $body = [
            'q' => $query,
            'limit' => $options['limit'] ?? 20,
            'offset' => $options['offset'] ?? 0,
            'attributesToHighlight' => ['name', 'description'],
            'highlightPreTag' => '<mark>',
            'highlightPostTag' => '</mark>',
        ];

        if (!empty($options['filter'])) {
            $body['filter'] = $options['filter'];
        }

        if (!empty($options['sort'])) {
            $body['sort'] = $options['sort'];
        }

        if (!empty($options['facets'])) {
            $body['facets'] = $options['facets'];
        }

        return $this->request('POST', '/indexes/products/search', $body);
    }

    /**
     * Index a single product
     */
    public function indexProduct(array $product): array {
        return $this->request('POST', '/indexes/products/documents', [$product], true);
    }

    /**
     * Index multiple products (batch)
     */
    public function indexProducts(array $products): array {
        return $this->request('POST', '/indexes/products/documents', $products, true);
    }

    /**
     * Delete a product from index
     */
    public function deleteProduct(int $productId): array {
        return $this->request('DELETE', "/indexes/products/documents/$productId", null, true);
    }

    /**
     * Configure the products index (run once during setup)
     */
    public function setupIndex(): array {
        // Create index
        $this->request('POST', '/indexes', [
            'uid' => 'products',
            'primaryKey' => 'id',
        ], true);

        // Set searchable attributes (order = priority)
        $this->request('PUT', '/indexes/products/settings/searchable-attributes', [
            'name', 'description', 'category_name', 'partner_name', 'brand', 'barcode'
        ], true);

        // Set filterable attributes
        $this->request('PUT', '/indexes/products/settings/filterable-attributes', [
            'partner_id', 'category_id', 'category_name', 'partner_name', 'price', 'in_stock', 'active'
        ], true);

        // Set sortable attributes
        $this->request('PUT', '/indexes/products/settings/sortable-attributes', [
            'price', 'name', 'created_at'
        ], true);

        // Set displayed attributes
        $this->request('PUT', '/indexes/products/settings/displayed-attributes', [
            'id', 'name', 'description', 'price', 'price_promo', 'image',
            'category_id', 'category_name', 'partner_id', 'partner_name',
            'brand', 'unit', 'in_stock', 'barcode'
        ], true);

        // Typo tolerance config
        $this->request('PATCH', '/indexes/products/settings/typo-tolerance', [
            'enabled' => true,
            'minWordSizeForTypos' => [
                'oneTypo' => 4,
                'twoTypos' => 8,
            ],
        ], true);

        return ['status' => 'configured'];
    }

    /**
     * Full re-index from database
     */
    public function reindexAll(PDO $db): array {
        // Products = base catalog + partner pricing
        // Each partner_product is a separate search result (same base product, different store)
        $stmt = $db->query("
            SELECT
                pp.id as id,
                COALESCE(p.name, '') as name,
                COALESCE(p.description, p.descricao, '') as description,
                COALESCE(pp.price, p.suggested_price, 0) as price,
                pp.price_promo,
                COALESCE(p.image, '') as image,
                p.category_id,
                COALESCE(c.name, '') as category_name,
                pp.partner_id,
                COALESCE(pa.name, '') as partner_name,
                COALESCE(p.brand, '') as brand,
                COALESCE(p.unit, '') as unit,
                COALESCE(p.barcode, '') as barcode,
                CASE WHEN COALESCE(pp.stock, 0) > 0 THEN true ELSE false END as in_stock,
                CASE WHEN pp.active = 1 THEN true ELSE false END as active,
                pp.created_at
            FROM om_market_partner_products pp
            JOIN om_market_products_base p ON p.product_id = pp.product_id
            LEFT JOIN om_market_categories c ON c.category_id = p.category_id
            LEFT JOIN om_market_partners pa ON pa.partner_id = pp.partner_id
            WHERE pp.active = 1 AND p.status = 1
        ");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast types
        foreach ($products as &$p) {
            $p['id'] = (int)$p['id'];
            $p['price'] = (float)$p['price'];
            $p['price_promo'] = $p['price_promo'] ? (float)$p['price_promo'] : null;
            $p['category_id'] = $p['category_id'] ? (int)$p['category_id'] : null;
            $p['partner_id'] = $p['partner_id'] ? (int)$p['partner_id'] : null;
            $p['in_stock'] = (bool)$p['in_stock'];
            $p['active'] = (bool)$p['active'];
        }

        // Also index from om_market_products (legacy table used by many partners)
        // Use negative IDs (offset by 1M) to avoid collisions with partner_products IDs
        $stmt2 = $db->query("
            SELECT
                (1000000 + p.product_id) as id,
                COALESCE(p.name, '') as name,
                COALESCE(p.description, '') as description,
                COALESCE(p.price, 0) as price,
                p.special_price as price_promo,
                COALESCE(p.image, p.image_url, '') as image,
                p.category_id,
                COALESCE(p.category, '') as category_name,
                p.partner_id,
                COALESCE(pa.trade_name, pa.name, '') as partner_name,
                COALESCE(p.brand, '') as brand,
                COALESCE(p.unit, '') as unit,
                COALESCE(p.barcode, '') as barcode,
                CASE WHEN COALESCE(p.in_stock, 1) = 1 THEN true ELSE false END as in_stock,
                true as active,
                p.date_added as created_at
            FROM om_market_products p
            LEFT JOIN om_market_partners pa ON pa.partner_id = p.partner_id
            WHERE p.status = 1 AND p.in_stock = 1
              AND p.partner_id NOT IN (
                  SELECT DISTINCT pp2.partner_id FROM om_market_partner_products pp2 WHERE pp2.active = 1
              )
        ");
        $legacyProducts = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($legacyProducts as &$lp) {
            $lp['id'] = (int)$lp['id'];
            $lp['price'] = (float)$lp['price'];
            $lp['price_promo'] = $lp['price_promo'] ? (float)$lp['price_promo'] : null;
            $lp['category_id'] = $lp['category_id'] ? (int)$lp['category_id'] : null;
            $lp['partner_id'] = $lp['partner_id'] ? (int)$lp['partner_id'] : null;
            $lp['in_stock'] = (bool)$lp['in_stock'];
            $lp['active'] = (bool)$lp['active'];
        }
        $products = array_merge($products, $legacyProducts);

        // Batch index (chunks of 500)
        $total = count($products);
        $indexed = 0;
        foreach (array_chunk($products, 500) as $chunk) {
            $this->indexProducts($chunk);
            $indexed += count($chunk);
        }

        return ['total' => $total, 'indexed' => $indexed];
    }

    /**
     * HTTP request to Meilisearch
     */
    private function request(string $method, string $path, $body = null, bool $admin = false): array {
        $key = $admin ? $this->adminKey : ($this->searchKey ?: $this->adminKey);

        $ch = curl_init($this->host . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['error' => 'Connection failed'];
        }

        return json_decode($response, true) ?: ['raw' => $response, 'http_code' => $httpCode];
    }
}

function om_search(): SearchService {
    return SearchService::getInstance();
}
