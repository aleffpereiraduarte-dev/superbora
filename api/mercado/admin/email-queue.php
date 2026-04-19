<?php
/**
 * GET/POST /api/mercado/admin/email-queue.php
 *
 * Admin monitoring for the EmailSender transactional queue.
 *
 * GET:
 *   ?action=stats                       — Aggregate counts by status + 24h window
 *   ?action=list&status=pending&page=1  — List queue rows (paginated)
 *   ?action=templates                   — List all templates
 *   ?action=row&id=123                  — Read a single queue row
 *
 * POST (JSON):
 *   action=retry&id=123                 — Reset failed row back to pending
 *   action=cancel&id=123                — Cancel a pending row
 *   action=process                      — Trigger immediate queue processing
 *   action=test_send&to=foo@bar.com&template_key=welcome&vars={...}
 *                                       — Send a test template to an address
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/email-sender.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    om_auth()->requireAdmin();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_REQUEST['action'] ?? null;

    if ($method === 'GET') {
        handleGet($db, $action);
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? $action;
        handlePost($db, $action, $input);
    } else {
        response(false, null, 'Metodo nao permitido', 405);
    }
} catch (Exception $e) {
    error_log('[admin/email-queue] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}

// ---------------------------------------------------------------------------

function handleGet(PDO $db, ?string $action): void {
    switch ($action) {
        case 'stats':
            $byStatus = $db->query("
                SELECT status, COUNT(*) AS n
                FROM om_transactional_email_queue
                GROUP BY status
            ")->fetchAll(PDO::FETCH_ASSOC);

            $last24h = $db->query("
                SELECT
                    COUNT(*) FILTER (WHERE status='sent'    AND sent_at    >= NOW() - INTERVAL '24 hours') AS sent_24h,
                    COUNT(*) FILTER (WHERE status='failed'  AND created_at >= NOW() - INTERVAL '24 hours') AS failed_24h,
                    COUNT(*) FILTER (WHERE status='pending' AND scheduled_at <= NOW())                     AS pending_now,
                    COUNT(*) FILTER (WHERE status='pending' AND scheduled_at >  NOW())                     AS scheduled_future
                FROM om_transactional_email_queue
            ")->fetch(PDO::FETCH_ASSOC);

            $byTemplate = $db->query("
                SELECT COALESCE(template_key,'(none)') AS template_key,
                       COUNT(*) AS n,
                       SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
                       SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed
                FROM om_transactional_email_queue
                WHERE created_at >= NOW() - INTERVAL '7 days'
                GROUP BY template_key
                ORDER BY n DESC
                LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);

            $sender = new EmailSender($db);

            response(true, [
                'provider'          => $sender->getProvider(),
                'mock_mode'         => $sender->isMockMode(),
                'by_status'         => $byStatus,
                'last_24h'          => $last24h,
                'by_template_7d'    => $byTemplate,
            ], 'ok');
            return;

        case 'list':
            $status = $_GET['status'] ?? null;
            $template = $_GET['template_key'] ?? null;
            $q = trim($_GET['q'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $where = ['1=1'];
            $params = [];
            if ($status) { $where[] = 'status = ?'; $params[] = $status; }
            if ($template) { $where[] = 'template_key = ?'; $params[] = $template; }
            if ($q !== '') { $where[] = '(to_email ILIKE ? OR subject ILIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
            $whereSql = implode(' AND ', $where);

            $countStmt = $db->prepare("SELECT COUNT(*) n FROM om_transactional_email_queue WHERE $whereSql");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetch()['n'];

            $stmt = $db->prepare("
                SELECT id, to_email, to_name, subject, template_key, status, attempts,
                       error_message, provider_message_id, scheduled_at, sent_at, created_at
                FROM om_transactional_email_queue
                WHERE $whereSql
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ");
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);

            response(true, [
                'rows'       => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total / max(1,$limit))],
            ], 'ok');
            return;

        case 'templates':
            $rows = $db->query("
                SELECT template_key, subject, variables, updated_at,
                       length(body_html) AS html_size
                FROM om_email_templates
                ORDER BY template_key
            ")->fetchAll(PDO::FETCH_ASSOC);
            response(true, ['templates' => $rows], 'ok');
            return;

        case 'row':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { response(false, null, 'id invalido', 400); return; }
            $stmt = $db->prepare("SELECT * FROM om_transactional_email_queue WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { response(false, null, 'nao encontrado', 404); return; }
            response(true, ['row' => $row], 'ok');
            return;

        default:
            response(false, null, 'action invalida', 400);
    }
}

function handlePost(PDO $db, ?string $action, array $input): void {
    switch ($action) {
        case 'retry': {
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) { response(false, null, 'id invalido', 400); return; }
            $stmt = $db->prepare("
                UPDATE om_transactional_email_queue
                SET status='pending', attempts=0, error_message=NULL, scheduled_at=NOW()
                WHERE id = ? AND status IN ('failed','sending')
            ");
            $stmt->execute([$id]);
            response($stmt->rowCount() > 0, ['affected' => $stmt->rowCount()], $stmt->rowCount() ? 'retry agendado' : 'nada para retry');
            return;
        }
        case 'cancel': {
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) { response(false, null, 'id invalido', 400); return; }
            $stmt = $db->prepare("
                UPDATE om_transactional_email_queue
                SET status='cancelled', error_message='cancelled by admin'
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$id]);
            response($stmt->rowCount() > 0, ['affected' => $stmt->rowCount()], $stmt->rowCount() ? 'cancelado' : 'nao estava pendente');
            return;
        }
        case 'process': {
            $sender = new EmailSender($db);
            $stats = $sender->processQueue(50, 3);
            response(true, ['stats' => $stats, 'provider' => $sender->getProvider()], 'processado');
            return;
        }
        case 'test_send': {
            $to  = filter_var($input['to'] ?? '', FILTER_VALIDATE_EMAIL);
            $key = $input['template_key'] ?? '';
            $vars = is_array($input['vars'] ?? null) ? $input['vars'] : [];
            if (!$to)  { response(false, null, 'email invalido',     400); return; }
            if (!$key) { response(false, null, 'template_key ausente', 400); return; }
            $sender = new EmailSender($db);
            $id = $sender->sendTemplate($to, $key, $vars);
            if ($id === false) { response(false, null, 'falha ao enfileirar', 500); return; }
            response(true, ['queue_id' => $id], 'enfileirado');
            return;
        }
        default:
            response(false, null, 'action invalida', 400);
    }
}
