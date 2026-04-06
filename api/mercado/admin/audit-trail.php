<?php
/**
 * GET /api/mercado/admin/audit-trail.php
 *
 * Visual timeline of admin audit log entries.
 *
 * Query params:
 *   page=1          Pagination page
 *   limit=50        Items per page (max 100)
 *   admin_id=X      Filter by admin user
 *   action=X        Filter by action type (login, update_order, refund, etc)
 *   resource_type=X Filter by resource (order, customer, partner, etc)
 *   from=YYYY-MM-DD Start date
 *   to=YYYY-MM-DD   End date
 *   search=X        Search in details JSONB
 *
 * Returns paginated list grouped by date for timeline display.
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

    // ── Parse filters ──
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $admin_id = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : null;
    $action = isset($_GET['action']) ? trim($_GET['action']) : null;
    $resource_type = isset($_GET['resource_type']) ? trim($_GET['resource_type']) : null;
    $from = isset($_GET['from']) ? trim($_GET['from']) : null;
    $to = isset($_GET['to']) ? trim($_GET['to']) : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;

    // ── Action: list available filters ──
    if (($_GET['action_meta'] ?? '') === 'filters') {
        $actions_list = $db->query("
            SELECT DISTINCT action, COUNT(*) as count
            FROM sb_admin_audit_log
            GROUP BY action ORDER BY count DESC
        ")->fetchAll();

        $resource_types = $db->query("
            SELECT DISTINCT resource_type, COUNT(*) as count
            FROM sb_admin_audit_log
            GROUP BY resource_type ORDER BY count DESC
        ")->fetchAll();

        $admins_list = $db->query("
            SELECT DISTINCT a.admin_id, COALESCE(adm.name, 'Admin #' || a.admin_id) as name
            FROM sb_admin_audit_log a
            LEFT JOIN om_admins adm ON adm.admin_id = a.admin_id
            ORDER BY name
        ")->fetchAll();

        response(true, [
            'actions' => $actions_list,
            'resource_types' => $resource_types,
            'admins' => $admins_list,
        ]);
    }

    // ── Action: stats summary ──
    if (($_GET['action_meta'] ?? '') === 'stats') {
        $today_count = (int)$db->query("
            SELECT COUNT(*) as c FROM sb_admin_audit_log WHERE DATE(created_at) = CURRENT_DATE
        ")->fetch()['c'];

        $week_count = (int)$db->query("
            SELECT COUNT(*) as c FROM sb_admin_audit_log WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'
        ")->fetch()['c'];

        $top_admins = $db->query("
            SELECT a.admin_id, COALESCE(adm.name, 'Admin #' || a.admin_id) as name, COUNT(*) as actions
            FROM sb_admin_audit_log a
            LEFT JOIN om_admins adm ON adm.admin_id = a.admin_id
            WHERE a.created_at >= CURRENT_DATE - INTERVAL '7 days'
            GROUP BY a.admin_id, adm.name
            ORDER BY actions DESC
            LIMIT 10
        ")->fetchAll();

        $top_actions = $db->query("
            SELECT action, COUNT(*) as count
            FROM sb_admin_audit_log
            WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'
            GROUP BY action
            ORDER BY count DESC
            LIMIT 10
        ")->fetchAll();

        $hourly = $db->query("
            SELECT EXTRACT(HOUR FROM created_at)::int as hour, COUNT(*) as count
            FROM sb_admin_audit_log
            WHERE DATE(created_at) = CURRENT_DATE
            GROUP BY hour
            ORDER BY hour
        ")->fetchAll();

        response(true, [
            'today_count' => $today_count,
            'week_count' => $week_count,
            'top_admins' => $top_admins,
            'top_actions' => $top_actions,
            'hourly_today' => $hourly,
        ]);
    }

    // ── Build WHERE clause ──
    $where = ["1=1"];
    $params = [];

    if ($admin_id) {
        $where[] = "a.admin_id = ?";
        $params[] = $admin_id;
    }
    if ($action) {
        $where[] = "a.action = ?";
        $params[] = $action;
    }
    if ($resource_type) {
        $where[] = "a.resource_type = ?";
        $params[] = $resource_type;
    }
    if ($from) {
        // Validate date format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = "a.created_at >= ?";
            $params[] = $from . ' 00:00:00';
        }
    }
    if ($to) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = "a.created_at <= ?";
            $params[] = $to . ' 23:59:59';
        }
    }
    if ($search) {
        // Search in JSONB details — use containment or cast to text
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
        $where[] = "a.details::text ILIKE ?";
        $params[] = "%" . $escaped . "%";
    }

    $where_sql = implode(' AND ', $where);

    // ── Count total ──
    $count_stmt = $db->prepare("SELECT COUNT(*) as total FROM sb_admin_audit_log a WHERE {$where_sql}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetch()['total'];

    // ── Fetch entries with admin name ──
    $sql = "
        SELECT
            a.id,
            a.admin_id,
            COALESCE(adm.name, 'Admin #' || a.admin_id) as admin_name,
            adm.email as admin_email,
            adm.role as admin_role,
            a.action,
            a.resource_type,
            a.resource_id,
            a.details,
            a.ip_address,
            a.user_agent,
            a.created_at,
            DATE(a.created_at) as log_date
        FROM sb_admin_audit_log a
        LEFT JOIN om_admins adm ON adm.admin_id = a.admin_id
        WHERE {$where_sql}
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $fetch_params = array_merge($params, [$limit, $offset]);
    $stmt = $db->prepare($sql);
    $stmt->execute($fetch_params);
    $entries = $stmt->fetchAll();

    // ── Decode JSON details and group by date ──
    $timeline = [];
    foreach ($entries as &$entry) {
        if (!empty($entry['details']) && is_string($entry['details'])) {
            $entry['details'] = json_decode($entry['details'], true);
        }
        $date = $entry['log_date'];
        if (!isset($timeline[$date])) {
            $timeline[$date] = [];
        }
        $timeline[$date][] = $entry;
    }

    // Convert to ordered array of { date, entries }
    $timeline_array = [];
    foreach ($timeline as $date => $date_entries) {
        $timeline_array[] = [
            'date' => $date,
            'count' => count($date_entries),
            'entries' => $date_entries,
        ];
    }

    response(true, [
        'timeline' => $timeline_array,
        'entries' => $entries,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int)ceil($total / $limit),
        ],
    ], "Audit trail");

} catch (Exception $e) {
    error_log("[admin/audit-trail] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
