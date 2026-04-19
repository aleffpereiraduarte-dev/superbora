<?php
/**
 * GET/POST /api/mercado/admin/editor.php
 * Admin visual editor: banners, push history, saved messages.
 *
 * GET views: banners, push_history, messages
 * POST actions: save_banner, delete_banner, toggle_banner,
 *               send_push, schedule_push,
 *               save_message, delete_message
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

    $method = $_SERVER['REQUEST_METHOD'];

    // =================== GET ===================
    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'banners';

        if ($view === 'banners') {
            $stmt = $db->query("SELECT * FROM om_admin_banners ORDER BY sort_order ASC, id DESC");
            response(['banners' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($view === 'push_history') {
            $stmt = $db->query("SELECT * FROM om_admin_push_history ORDER BY created_at DESC LIMIT 50");
            response(['history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($view === 'messages') {
            $stmt = $db->query("SELECT * FROM om_admin_saved_messages ORDER BY id DESC");
            response(['messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        response(['error' => 'Unknown view'], 400);
    }

    // =================== POST ===================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        // ---------- Banners ----------
        if ($action === 'save_banner') {
            $id = (int)($input['id'] ?? 0);
            $title = trim($input['title'] ?? '');
            $image_url = trim($input['image_url'] ?? '');
            $link = trim($input['link'] ?? '');
            $sort = (int)($input['sort_order'] ?? 0);
            $enabled = (int)($input['enabled'] ?? 1);

            if ($id > 0) {
                $stmt = $db->prepare("UPDATE om_admin_banners SET title=?, image_url=?, link=?, sort_order=?, enabled=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$title, $image_url, $link, $sort, $enabled, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO om_admin_banners (title, image_url, link, sort_order, enabled, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())");
                $stmt->execute([$title, $image_url, $link, $sort, $enabled]);
                $id = (int)$db->lastInsertId();
            }
            OmAudit::getInstance()->log($adminId, 'admin', 'editor_banner_save', ['banner_id' => $id]);
            response(['success' => true, 'id' => $id]);
        }

        if ($action === 'delete_banner') {
            $id = (int)($input['id'] ?? 0);
            $db->prepare("DELETE FROM om_admin_banners WHERE id=?")->execute([$id]);
            OmAudit::getInstance()->log($adminId, 'admin', 'editor_banner_delete', ['banner_id' => $id]);
            response(['success' => true]);
        }

        if ($action === 'toggle_banner') {
            $id = (int)($input['id'] ?? 0);
            $enabled = (int)($input['enabled'] ?? 0);
            $db->prepare("UPDATE om_admin_banners SET enabled=?, updated_at=NOW() WHERE id=?")->execute([$enabled, $id]);
            response(['success' => true]);
        }

        // ---------- Push ----------
        if ($action === 'send_push') {
            $title = trim($input['title'] ?? '');
            $body = trim($input['body'] ?? '');
            $target = $input['target'] ?? 'all';

            if ($title === '' || $body === '') {
                response(['error' => 'Title and body required'], 400);
            }

            // Log the push
            $stmt = $db->prepare("INSERT INTO om_admin_push_history (title, body, target, sent_by, created_at) VALUES (?,?,?,?,NOW())");
            $stmt->execute([$title, $body, $target, $adminId]);

            // Actually send via NotificationSender
            if (file_exists(__DIR__ . '/../helpers/NotificationSender.php')) {
                require_once __DIR__ . '/../helpers/NotificationSender.php';
                $sender = new NotificationSender($db);
                $sender->sendBulkPush($title, $body, $target);
            }

            OmAudit::getInstance()->log($adminId, 'admin', 'editor_push_sent', ['title' => $title, 'target' => $target]);
            response(['success' => true]);
        }

        if ($action === 'schedule_push') {
            $title = trim($input['title'] ?? '');
            $body = trim($input['body'] ?? '');
            $target = $input['target'] ?? 'all';
            $scheduled_at = $input['scheduled_at'] ?? null;

            $stmt = $db->prepare("INSERT INTO om_admin_push_history (title, body, target, sent_by, scheduled_at, status, created_at) VALUES (?,?,?,?,?,?,NOW())");
            $stmt->execute([$title, $body, $target, $adminId, $scheduled_at, 'scheduled']);
            OmAudit::getInstance()->log($adminId, 'admin', 'editor_push_scheduled', ['title' => $title]);
            response(['success' => true]);
        }

        // ---------- Saved Messages ----------
        if ($action === 'save_message') {
            $id = (int)($input['id'] ?? 0);
            $title = trim($input['title'] ?? '');
            $content = trim($input['content'] ?? '');
            $type = trim($input['type'] ?? 'quick_reply');

            if ($id > 0) {
                $stmt = $db->prepare("UPDATE om_admin_saved_messages SET title=?, content=?, type=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$title, $content, $type, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO om_admin_saved_messages (title, content, type, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())");
                $stmt->execute([$title, $content, $type]);
                $id = (int)$db->lastInsertId();
            }
            response(['success' => true, 'id' => $id]);
        }

        if ($action === 'delete_message') {
            $id = (int)($input['id'] ?? 0);
            $db->prepare("DELETE FROM om_admin_saved_messages WHERE id=?")->execute([$id]);
            response(['success' => true]);
        }

        response(['error' => 'Unknown action'], 400);
    }

    response(['error' => 'Method not allowed'], 405);

} catch (Exception $e) {
    response(['error' => $e->getMessage()], 500);
}
