<?php
/**
 * POST /api/mercado/admin/page-builder.php
 *
 * Salva/publica paginas criadas no editor visual do admin.
 *
 * Body: {
 *   action: 'save'|'publish'|'list'|'delete',
 *   name?: string,
 *   blocks?: array,
 *   page_id?: int
 * }
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
    $admin_id = (int)$payload['uid'];

    // Ensure table exists
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_page_builder (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255),
                blocks JSONB DEFAULT '[]',
                status VARCHAR(20) DEFAULT 'draft',
                created_by INT,
                updated_by INT,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
    } catch (\Exception $e) { /* table already exists */ }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // List pages
        $stmt = $db->query("SELECT id, name, slug, status, created_at, updated_at FROM om_page_builder ORDER BY updated_at DESC");
        $pages = $stmt->fetchAll();
        response(true, ['pages' => $pages], "Paginas listadas");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $action = strip_tags(trim($input['action'] ?? ''));
    $name = strip_tags(trim($input['name'] ?? ''));
    $blocks = $input['blocks'] ?? [];
    $page_id = (int)($input['page_id'] ?? 0);

    if (!in_array($action, ['save', 'publish', 'delete', 'list'])) {
        response(false, null, "action invalida. Aceitas: save, publish, delete, list", 400);
    }

    if ($action === 'list') {
        $stmt = $db->query("SELECT id, name, slug, status, created_at, updated_at FROM om_page_builder ORDER BY updated_at DESC");
        response(true, ['pages' => $stmt->fetchAll()], "Paginas listadas");
    }

    if ($action === 'delete') {
        if (!$page_id) response(false, null, "page_id obrigatorio", 400);
        $db->prepare("DELETE FROM om_page_builder WHERE id = ?")->execute([$page_id]);
        om_audit()->log('page_delete', 'page', $page_id, null, null, "Pagina #{$page_id} excluida");
        response(true, ['page_id' => $page_id], "Pagina excluida");
    }

    // save or publish
    if (!$name) response(false, null, "name obrigatorio", 400);

    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim($slug, '-');
    $status = ($action === 'publish') ? 'published' : 'draft';
    $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE);

    // Check if page with this name exists
    $stmt = $db->prepare("SELECT id FROM om_page_builder WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Update
        $stmt = $db->prepare("UPDATE om_page_builder SET blocks = ?::jsonb, status = ?, slug = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$blocksJson, $status, $slug, $admin_id, $existing['id']]);
        $page_id = (int)$existing['id'];
        $msg = ($action === 'publish') ? "Pagina atualizada e publicada" : "Pagina salva como rascunho";
    } else {
        // Insert
        $stmt = $db->prepare("INSERT INTO om_page_builder (name, slug, blocks, status, created_by, updated_by) VALUES (?, ?, ?::jsonb, ?, ?, ?) RETURNING id");
        $stmt->execute([$name, $slug, $blocksJson, $status, $admin_id, $admin_id]);
        $row = $stmt->fetch();
        $page_id = (int)$row['id'];
        $msg = ($action === 'publish') ? "Pagina criada e publicada" : "Pagina criada como rascunho";
    }

    om_audit()->log('page_' . $action, 'page', $page_id, null, ['name' => $name, 'status' => $status, 'blocks_count' => count($blocks)], $msg);

    response(true, ['page_id' => $page_id, 'slug' => $slug, 'status' => $status], $msg);

} catch (Exception $e) {
    error_log("[admin/page-builder] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
