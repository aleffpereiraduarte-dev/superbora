<?php
/**
 * /api/mercado/admin/knowledge-base.php
 *
 * Agent Knowledge Base — CRUD with full-text search.
 *
 * GET: List articles
 *   ?category=X         Filter by category
 *   ?search=X           Full-text search (PostgreSQL ts_vector)
 *   ?tag=X              Filter by tag
 *   ?page=1&limit=20    Pagination
 *   ?id=X               Single article detail (increments view_count)
 *   ?action=popular      Top 10 most viewed
 *   ?action=categories   Category list with counts
 *
 * POST: Create article (admin auth)
 *   { title, content, category, tags[], is_published }
 *
 * PUT ?id=X: Update article (admin auth)
 *   { title?, content?, category?, tags[]?, is_published? }
 *
 * DELETE ?id=X: Delete article (admin auth)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    // ── Ensure table exists ──
    $db->exec("
        CREATE TABLE IF NOT EXISTS sb_knowledge_base (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            category VARCHAR(100) NOT NULL DEFAULT 'geral',
            tags TEXT[] DEFAULT '{}',
            search_vector TSVECTOR,
            author_id INT,
            is_published BOOLEAN DEFAULT true,
            view_count INT DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    // Ensure search vector trigger exists (idempotent)
    $db->exec("
        CREATE OR REPLACE FUNCTION kb_search_vector_update() RETURNS trigger AS \$\$
        BEGIN
            NEW.search_vector := setweight(to_tsvector('portuguese', COALESCE(NEW.title, '')), 'A')
                              || setweight(to_tsvector('portuguese', COALESCE(NEW.content, '')), 'B')
                              || setweight(to_tsvector('portuguese', COALESCE(array_to_string(NEW.tags, ' '), '')), 'C');
            NEW.updated_at := NOW();
            RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql
    ");

    $db->exec("
        DO \$\$ BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_trigger WHERE tgname = 'kb_search_vector_trigger'
            ) THEN
                CREATE TRIGGER kb_search_vector_trigger
                    BEFORE INSERT OR UPDATE OF title, content, tags ON sb_knowledge_base
                    FOR EACH ROW EXECUTE FUNCTION kb_search_vector_update();
            END IF;
        END \$\$
    ");

    // Ensure indexes exist
    $db->exec("CREATE INDEX IF NOT EXISTS idx_kb_category ON sb_knowledge_base(category)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_kb_search ON sb_knowledge_base USING GIN(search_vector)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_kb_tags ON sb_knowledge_base USING GIN(tags)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_kb_view_count ON sb_knowledge_base(view_count DESC)");

    $method = $_SERVER['REQUEST_METHOD'];

    // ================================================================
    // GET — List, search, single article, popular, categories
    // ================================================================
    if ($method === 'GET') {

        $action = $_GET['action'] ?? null;

        // ── Popular articles ──
        if ($action === 'popular') {
            $stmt = $db->query("
                SELECT id, title, category, tags, view_count, created_at, updated_at
                FROM sb_knowledge_base
                WHERE is_published = true
                ORDER BY view_count DESC
                LIMIT 10
            ");
            $articles = $stmt->fetchAll();
            foreach ($articles as &$a) {
                // PostgreSQL array to PHP array
                $a['tags'] = pgArrayToPhp($a['tags']);
            }
            response(true, ['articles' => $articles], "Artigos populares");
        }

        // ── Categories with count ──
        if ($action === 'categories') {
            $stmt = $db->query("
                SELECT category, COUNT(*) as count,
                       SUM(CASE WHEN is_published THEN 1 ELSE 0 END) as published
                FROM sb_knowledge_base
                GROUP BY category
                ORDER BY count DESC
            ");
            $categories = $stmt->fetchAll();
            response(true, ['categories' => $categories]);
        }

        // ── Single article by ID ──
        $articleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($articleId > 0) {
            // Increment view count
            $db->prepare("UPDATE sb_knowledge_base SET view_count = view_count + 1 WHERE id = ?")->execute([$articleId]);

            $stmt = $db->prepare("
                SELECT kb.*, COALESCE(adm.name, 'Sistema') as author_name
                FROM sb_knowledge_base kb
                LEFT JOIN om_admins adm ON adm.admin_id = kb.author_id
                WHERE kb.id = ?
            ");
            $stmt->execute([$articleId]);
            $article = $stmt->fetch();

            if (!$article) {
                response(false, null, "Artigo nao encontrado", 404);
            }

            $article['tags'] = pgArrayToPhp($article['tags']);
            unset($article['search_vector']); // Don't send internal field

            response(true, ['article' => $article]);
        }

        // ── List with filters and search ──
        $category = isset($_GET['category']) ? trim($_GET['category']) : null;
        $search = isset($_GET['search']) ? trim($_GET['search']) : null;
        $tag = isset($_GET['tag']) ? trim($_GET['tag']) : null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];
        $select_extra = "";
        $order_by = "kb.updated_at DESC";

        if ($category) {
            $where[] = "kb.category = ?";
            $params[] = $category;
        }

        if ($tag) {
            $where[] = "? = ANY(kb.tags)";
            $params[] = $tag;
        }

        if ($search) {
            // Full-text search with ranking and headline — use parameterized queries only
            $searchSafe = preg_replace('/[^\w\s\p{L}]/u', ' ', $search); // strip special chars
            $where[] = "kb.search_vector @@ plainto_tsquery('portuguese', ?)";
            $params[] = $searchSafe;
            $select_extra = ",
                ts_rank(kb.search_vector, plainto_tsquery('portuguese', ?)) as relevance,
                ts_headline('portuguese', kb.content, plainto_tsquery('portuguese', ?),
                    'StartSel=<mark>, StopSel=</mark>, MaxWords=60, MinWords=20, MaxFragments=2'
                ) as snippet";
            // Add search params for ts_rank and ts_headline (2 extra placeholders)
            $params[] = $searchSafe;
            $params[] = $searchSafe;
            $order_by = "relevance DESC, kb.updated_at DESC";
        }

        // Count
        $count_where = implode(' AND ', $where);
        $count_stmt = $db->prepare("SELECT COUNT(*) as total FROM sb_knowledge_base kb WHERE {$count_where}");
        $count_stmt->execute($params);
        $total = (int)$count_stmt->fetch()['total'];

        // Fetch
        $where_sql = implode(' AND ', $where);
        $sql = "
            SELECT kb.id, kb.title, kb.category, kb.tags, kb.is_published,
                   kb.view_count, kb.author_id, kb.created_at, kb.updated_at,
                   COALESCE(adm.name, 'Sistema') as author_name,
                   LEFT(kb.content, 300) as content_preview
                   {$select_extra}
            FROM sb_knowledge_base kb
            LEFT JOIN om_admins adm ON adm.admin_id = kb.author_id
            WHERE {$where_sql}
            ORDER BY {$order_by}
            LIMIT ? OFFSET ?
        ";
        $fetch_params = array_merge($params, [$limit, $offset]);
        $stmt = $db->prepare($sql);
        $stmt->execute($fetch_params);
        $articles = $stmt->fetchAll();

        foreach ($articles as &$a) {
            $a['tags'] = pgArrayToPhp($a['tags']);
        }

        response(true, [
            'articles' => $articles,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ], $search ? "Busca: {$search}" : "Artigos");
    }

    // ================================================================
    // POST — Create article
    // ================================================================
    if ($method === 'POST') {
        $input = getInput();

        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $category = trim($input['category'] ?? 'geral');
        $tags = $input['tags'] ?? [];
        $is_published = isset($input['is_published']) ? (bool)$input['is_published'] : true;

        if (empty($title) || empty($content)) {
            response(false, null, "Titulo e conteudo sao obrigatorios", 400);
        }

        // Validate category
        $valid_categories = ['policies', 'faq', 'troubleshooting', 'scripts', 'procedures', 'geral'];
        if (!in_array($category, $valid_categories, true)) {
            $category = 'geral';
        }

        // Sanitize tags
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }
        $tags = array_filter(array_map(function($t) {
            return substr(strip_tags(trim($t)), 0, 50);
        }, $tags));
        $tags = array_values($tags);

        $pg_tags = phpArrayToPg($tags);

        $stmt = $db->prepare("
            INSERT INTO sb_knowledge_base (title, content, category, tags, author_id, is_published)
            VALUES (?, ?, ?, ?::text[], ?, ?)
            RETURNING id
        ");
        $stmt->execute([$title, $content, $category, $pg_tags, $adminId, $is_published ? 'true' : 'false']);
        $newId = (int)$stmt->fetch()['id'];

        // Audit
        require_once __DIR__ . "/../helpers/audit-log.php";
        logAdminAction($db, $adminId, 'create_kb_article', 'knowledge_base', $newId, [
            'title' => $title,
            'category' => $category,
        ]);

        response(true, ['id' => $newId], "Artigo criado", 201);
    }

    // ================================================================
    // PUT — Update article
    // ================================================================
    if ($method === 'PUT') {
        $articleId = (int)($_GET['id'] ?? 0);
        if (!$articleId) {
            response(false, null, "ID do artigo obrigatorio", 400);
        }

        // Check exists
        $stmt = $db->prepare("SELECT id FROM sb_knowledge_base WHERE id = ?");
        $stmt->execute([$articleId]);
        if (!$stmt->fetch()) {
            response(false, null, "Artigo nao encontrado", 404);
        }

        $input = getInput();
        $updates = [];
        $params = [];

        if (isset($input['title']) && trim($input['title']) !== '') {
            $updates[] = "title = ?";
            $params[] = trim($input['title']);
        }
        if (isset($input['content']) && trim($input['content']) !== '') {
            $updates[] = "content = ?";
            $params[] = trim($input['content']);
        }
        if (isset($input['category'])) {
            $valid_categories = ['policies', 'faq', 'troubleshooting', 'scripts', 'procedures', 'geral'];
            $cat = trim($input['category']);
            if (in_array($cat, $valid_categories, true)) {
                $updates[] = "category = ?";
                $params[] = $cat;
            }
        }
        if (isset($input['tags'])) {
            $tags = $input['tags'];
            if (is_string($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            }
            $tags = array_filter(array_map(function($t) {
                return substr(strip_tags(trim($t)), 0, 50);
            }, $tags));
            $updates[] = "tags = ?::text[]";
            $params[] = phpArrayToPg(array_values($tags));
        }
        if (isset($input['is_published'])) {
            $updates[] = "is_published = ?";
            $params[] = (bool)$input['is_published'] ? 'true' : 'false';
        }

        if (empty($updates)) {
            response(false, null, "Nenhum campo para atualizar", 400);
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $articleId;

        $sql = "UPDATE sb_knowledge_base SET " . implode(', ', $updates) . " WHERE id = ?";
        $db->prepare($sql)->execute($params);

        // Audit
        require_once __DIR__ . "/../helpers/audit-log.php";
        logAdminAction($db, $adminId, 'update_kb_article', 'knowledge_base', $articleId, [
            'fields_updated' => array_keys($input),
        ]);

        response(true, ['id' => $articleId], "Artigo atualizado");
    }

    // ================================================================
    // DELETE — Delete article
    // ================================================================
    if ($method === 'DELETE') {
        $articleId = (int)($_GET['id'] ?? 0);
        if (!$articleId) {
            response(false, null, "ID do artigo obrigatorio", 400);
        }

        $stmt = $db->prepare("SELECT id, title FROM sb_knowledge_base WHERE id = ?");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch();
        if (!$article) {
            response(false, null, "Artigo nao encontrado", 404);
        }

        $db->prepare("DELETE FROM sb_knowledge_base WHERE id = ?")->execute([$articleId]);

        // Audit
        require_once __DIR__ . "/../helpers/audit-log.php";
        logAdminAction($db, $adminId, 'delete_kb_article', 'knowledge_base', $articleId, [
            'title' => $article['title'],
        ]);

        response(true, null, "Artigo removido");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/knowledge-base] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// ── Helper: PostgreSQL text[] to PHP array ──
function pgArrayToPhp(?string $pgArray): array {
    if (!$pgArray || $pgArray === '{}') return [];
    // Remove outer braces
    $inner = substr($pgArray, 1, -1);
    if ($inner === '') return [];
    // Parse quoted and unquoted elements
    $result = [];
    $current = '';
    $inQuote = false;
    $len = strlen($inner);
    for ($i = 0; $i < $len; $i++) {
        $char = $inner[$i];
        if ($char === '"' && !$inQuote) {
            $inQuote = true;
            continue;
        }
        if ($char === '"' && $inQuote) {
            $inQuote = false;
            continue;
        }
        if ($char === ',' && !$inQuote) {
            $result[] = $current;
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if ($current !== '') $result[] = $current;
    return $result;
}

// ── Helper: PHP array to PostgreSQL text[] literal ──
function phpArrayToPg(array $arr): string {
    if (empty($arr)) return '{}';
    $escaped = array_map(function($v) {
        $v = str_replace('\\', '\\\\', $v);
        $v = str_replace('"', '\\"', $v);
        return '"' . $v . '"';
    }, $arr);
    return '{' . implode(',', $escaped) . '}';
}
