<?php
/**
 * GET/POST /api/mercado/admin/email-campaigns.php
 *
 * Email Campaign Management for marketing emails.
 *
 * GET:
 *   (no params)              — List campaigns with stats
 *   ?action=preview&id=X     — Preview campaign with sample data
 *
 * POST:
 *   (no action)              — Create new campaign
 *   ?action=send&id=X        — Send campaign (batched, 50 per run)
 *   ?action=update&id=X      — Update draft campaign
 *   ?action=delete&id=X      — Delete draft campaign
 *
 * Requires admin authentication.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

// ─── Create tables if needed ──────────────────────────────────
function ensureEmailCampaignTables(PDO $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db->exec("
        CREATE TABLE IF NOT EXISTS om_email_campaigns (
            id SERIAL PRIMARY KEY,
            subject VARCHAR(255) NOT NULL,
            body_html TEXT NOT NULL,
            segment_type VARCHAR(50) NOT NULL DEFAULT 'all',
            segment_config JSONB DEFAULT '{}',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            scheduled_at TIMESTAMP NULL,
            sent_at TIMESTAMP NULL,
            total_sent INT NOT NULL DEFAULT 0,
            total_opened INT NOT NULL DEFAULT 0,
            total_clicked INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS om_email_campaign_sends (
            id SERIAL PRIMARY KEY,
            campaign_id INT NOT NULL,
            customer_id INT NULL,
            email VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            sent_at TIMESTAMP NULL,
            opened_at TIMESTAMP NULL,
            clicked_at TIMESTAMP NULL,
            error_message TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    // Indexes for performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_email_campaigns_status ON om_email_campaigns(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_email_campaign_sends_campaign ON om_email_campaign_sends(campaign_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_email_campaign_sends_email ON om_email_campaign_sends(email)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_email_campaign_sends_customer ON om_email_campaign_sends(customer_id)");

    // Unsubscribe tracking
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_email_unsubscribes (
            id SERIAL PRIMARY KEY,
            customer_id INT NULL,
            email VARCHAR(255) NOT NULL,
            reason VARCHAR(255) NULL,
            unsubscribed_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE(email)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_email_unsub_email ON om_email_unsubscribes(email)");
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    ensureEmailCampaignTables($db);

    $method = $_SERVER['REQUEST_METHOD'];

    // ═══════════════════════════════════════════════════════════
    // GET — List campaigns or preview
    // ═══════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';

        // ── Preview a campaign with sample data ────────────
        if ($action === 'preview') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) response(false, null, 'ID obrigatorio', 400);

            $stmt = $db->prepare("SELECT * FROM om_email_campaigns WHERE id = ?");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch();
            if (!$campaign) response(false, null, 'Campanha nao encontrada', 404);

            // Load templates to render preview if needed
            require_once __DIR__ . '/../helpers/email-templates.php';

            // For preview, show the raw body_html wrapped in sample layout
            $previewHtml = $campaign['body_html'];

            // If body_html references a template function, render with sample data
            $sampleName = 'Maria Silva';
            if (strpos($previewHtml, '{{template:') !== false) {
                preg_match('/\{\{template:(\w+)\}\}/', $previewHtml, $m);
                $tpl = $m[1] ?? '';
                $previewHtml = match($tpl) {
                    'welcome' => emailTemplate_welcome($sampleName),
                    'abandoned_cart' => emailTemplate_abandoned_cart($sampleName, [
                        ['name' => 'Arroz Integral 1kg', 'quantity' => 2, 'price' => 8.99],
                        ['name' => 'Leite Desnatado 1L', 'quantity' => 3, 'price' => 5.49],
                    ], ['Mercado Boa Praca']),
                    'win_back' => emailTemplate_win_back($sampleName, 30, 'VOLTESB10'),
                    'promo' => emailTemplate_promo($sampleName, 'Promocao de Exemplo', 'Descricao da promocao aqui.', 'PROMO20', date('Y-m-d', strtotime('+7 days'))),
                    'new_stores' => emailTemplate_new_stores($sampleName, [
                        ['name' => 'Mercado Exemplo', 'category' => 'Mercado', 'description' => 'Produtos frescos todos os dias'],
                    ]),
                    'order_summary' => emailTemplate_order_summary($sampleName, '12345', [
                        ['name' => 'Banana Prata', 'quantity' => 6, 'price' => 1.29],
                        ['name' => 'Pao Frances', 'quantity' => 10, 'price' => 0.59],
                    ], 13.64),
                    default => $previewHtml,
                };
            }

            response(true, [
                'campaign' => $campaign,
                'preview_html' => $previewHtml,
            ]);
        }

        // ── List campaigns ─────────────────────────────────
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $statusFilter = $_GET['status'] ?? null;
        $search = $_GET['search'] ?? null;

        $where = ["1=1"];
        $params = [];

        if ($statusFilter) {
            $allowed = ['draft', 'scheduled', 'sending', 'sent', 'cancelled'];
            if (in_array($statusFilter, $allowed, true)) {
                $where[] = "c.status = ?";
                $params[] = $statusFilter;
            }
        }

        if ($search) {
            $where[] = "c.subject ILIKE ?";
            $params[] = "%{$search}%";
        }

        $whereSql = implode(' AND ', $where);

        // Count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_email_campaigns c WHERE {$whereSql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        // Fetch campaigns with computed rates
        $stmt = $db->prepare("
            SELECT c.*,
                   CASE WHEN c.total_sent > 0
                        THEN ROUND(c.total_opened * 100.0 / c.total_sent, 1)
                        ELSE 0 END as open_rate,
                   CASE WHEN c.total_sent > 0
                        THEN ROUND(c.total_clicked * 100.0 / c.total_sent, 1)
                        ELSE 0 END as click_rate
            FROM om_email_campaigns c
            WHERE {$whereSql}
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $campaigns = $stmt->fetchAll();

        response(true, [
            'campaigns' => $campaigns,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // POST — Create, send, update, or delete campaign
    // ═══════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $action = $_GET['action'] ?? 'create';
        $input = getInput();

        // ── Send campaign ──────────────────────────────────
        if ($action === 'send') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) response(false, null, 'ID obrigatorio', 400);

            $stmt = $db->prepare("SELECT * FROM om_email_campaigns WHERE id = ? FOR UPDATE");
            $db->beginTransaction();
            $stmt->execute([$id]);
            $campaign = $stmt->fetch();

            if (!$campaign) {
                $db->rollBack();
                response(false, null, 'Campanha nao encontrada', 404);
            }

            if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
                $db->rollBack();
                response(false, null, 'Campanha ja esta sendo enviada ou foi enviada', 400);
            }

            // Mark as sending
            $db->prepare("UPDATE om_email_campaigns SET status = 'sending', sent_at = NOW(), updated_at = NOW() WHERE id = ?")
               ->execute([$id]);
            $db->commit();

            // Build recipient list based on segment
            $recipients = _buildRecipientList($db, $campaign['segment_type'], $campaign['segment_config']);

            if (empty($recipients)) {
                $db->prepare("UPDATE om_email_campaigns SET status = 'sent', total_sent = 0, updated_at = NOW() WHERE id = ?")
                   ->execute([$id]);
                response(true, ['message' => 'Nenhum destinatario encontrado para este segmento', 'sent' => 0]);
            }

            // Load email sending functions
            require_once __DIR__ . '/../helpers/email.php';
            require_once __DIR__ . '/../helpers/email-templates.php';

            $sent = 0;
            $failed = 0;
            $batchSize = 50;
            $batches = array_chunk($recipients, $batchSize);

            foreach ($batches as $batch) {
                foreach ($batch as $recipient) {
                    $email = $recipient['email'];
                    $customerName = $recipient['name'] ?: 'Cliente';
                    $customerId = (int)($recipient['customer_id'] ?? 0);

                    // Personalize body_html: replace {{name}} placeholder
                    $personalizedBody = str_replace(
                        ['{{name}}', '{{email}}'],
                        [htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'), htmlspecialchars($email, ENT_QUOTES, 'UTF-8')],
                        $campaign['body_html']
                    );

                    // Check for template reference
                    if (strpos($personalizedBody, '{{template:') !== false) {
                        preg_match('/\{\{template:(\w+)(?::(.+?))?\}\}/', $personalizedBody, $m);
                        $tpl = $m[1] ?? '';
                        $personalizedBody = _renderTemplateForRecipient($tpl, $customerName, $campaign);
                    }

                    // Insert send record
                    $stmtInsert = $db->prepare("
                        INSERT INTO om_email_campaign_sends (campaign_id, customer_id, email, status, created_at)
                        VALUES (?, ?, ?, 'pending', NOW())
                        RETURNING id
                    ");
                    $stmtInsert->execute([$id, $customerId, $email]);
                    $sendId = (int)$stmtInsert->fetch()['id'];

                    // Send email
                    $success = sendEmail($email, $campaign['subject'], $personalizedBody, $db, $customerId, 'campaign');

                    if ($success) {
                        $db->prepare("UPDATE om_email_campaign_sends SET status = 'sent', sent_at = NOW() WHERE id = ?")
                           ->execute([$sendId]);
                        $sent++;
                    } else {
                        $db->prepare("UPDATE om_email_campaign_sends SET status = 'failed', error_message = 'Send failed' WHERE id = ?")
                           ->execute([$sendId]);
                        $failed++;
                    }

                    // Small delay between emails to avoid rate limiting
                    usleep(100000); // 100ms
                }
            }

            // Update campaign totals
            $db->prepare("
                UPDATE om_email_campaigns
                SET status = 'sent', total_sent = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$sent, $id]);

            // Audit log
            OmAudit::getInstance()->log('email_campaign_sent', 'admin', $admin_id, [
                'campaign_id' => $id,
                'subject' => $campaign['subject'],
                'total_sent' => $sent,
                'total_failed' => $failed,
            ]);

            response(true, [
                'message' => "Campanha enviada: {$sent} emails enviados, {$failed} falharam",
                'sent' => $sent,
                'failed' => $failed,
            ]);
        }

        // ── Update draft campaign ──────────────────────────
        if ($action === 'update') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) response(false, null, 'ID obrigatorio', 400);

            $stmt = $db->prepare("SELECT status FROM om_email_campaigns WHERE id = ?");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch();
            if (!$campaign) response(false, null, 'Campanha nao encontrada', 404);
            if ($campaign['status'] !== 'draft') response(false, null, 'Somente campanhas em rascunho podem ser editadas', 400);

            $fields = [];
            $params = [];

            if (isset($input['subject'])) {
                $fields[] = "subject = ?";
                $params[] = trim($input['subject']);
            }
            if (isset($input['body_html'])) {
                $fields[] = "body_html = ?";
                $params[] = $input['body_html'];
            }
            if (isset($input['segment_type'])) {
                $allowed = ['all', 'vip', 'inactive', 'new', 'city'];
                if (!in_array($input['segment_type'], $allowed, true)) {
                    response(false, null, 'Segmento invalido', 400);
                }
                $fields[] = "segment_type = ?";
                $params[] = $input['segment_type'];
            }
            if (isset($input['segment_config'])) {
                $fields[] = "segment_config = ?";
                $params[] = json_encode($input['segment_config']);
            }
            if (isset($input['scheduled_at'])) {
                $fields[] = "scheduled_at = ?";
                $params[] = $input['scheduled_at'] ?: null;
                if ($input['scheduled_at']) {
                    $fields[] = "status = 'scheduled'";
                }
            }

            if (empty($fields)) response(false, null, 'Nenhum campo para atualizar', 400);

            $fields[] = "updated_at = NOW()";
            $params[] = $id;
            $fieldsSql = implode(', ', $fields);

            $db->prepare("UPDATE om_email_campaigns SET {$fieldsSql} WHERE id = ?")->execute($params);

            response(true, ['message' => 'Campanha atualizada']);
        }

        // ── Delete draft campaign ──────────────────────────
        if ($action === 'delete') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) response(false, null, 'ID obrigatorio', 400);

            $stmt = $db->prepare("SELECT status FROM om_email_campaigns WHERE id = ?");
            $stmt->execute([$id]);
            $campaign = $stmt->fetch();
            if (!$campaign) response(false, null, 'Campanha nao encontrada', 404);
            if (!in_array($campaign['status'], ['draft', 'cancelled'], true)) {
                response(false, null, 'Somente rascunhos ou canceladas podem ser excluidas', 400);
            }

            $db->prepare("DELETE FROM om_email_campaign_sends WHERE campaign_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM om_email_campaigns WHERE id = ?")->execute([$id]);

            response(true, ['message' => 'Campanha excluida']);
        }

        // ── Create new campaign ────────────────────────────
        if ($action === 'create') {
            $subject = trim($input['subject'] ?? '');
            $bodyHtml = $input['body_html'] ?? '';
            $segmentType = $input['segment_type'] ?? 'all';
            $segmentConfig = $input['segment_config'] ?? [];
            $scheduledAt = $input['scheduled_at'] ?? null;

            if (empty($subject)) response(false, null, 'Assunto obrigatorio', 400);
            if (empty($bodyHtml)) response(false, null, 'Corpo do email obrigatorio', 400);

            $allowedSegments = ['all', 'vip', 'inactive', 'new', 'city'];
            if (!in_array($segmentType, $allowedSegments, true)) {
                response(false, null, 'Segmento invalido. Permitidos: ' . implode(', ', $allowedSegments), 400);
            }

            $status = $scheduledAt ? 'scheduled' : 'draft';

            $stmt = $db->prepare("
                INSERT INTO om_email_campaigns (subject, body_html, segment_type, segment_config, status, scheduled_at, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([
                $subject,
                $bodyHtml,
                $segmentType,
                json_encode($segmentConfig),
                $status,
                $scheduledAt,
                $admin_id,
            ]);
            $campaignId = (int)$stmt->fetch()['id'];

            // Count estimated recipients
            $recipientCount = count(_buildRecipientList($db, $segmentType, json_encode($segmentConfig)));

            OmAudit::getInstance()->log('email_campaign_created', 'admin', $admin_id, [
                'campaign_id' => $campaignId,
                'subject' => $subject,
                'segment' => $segmentType,
            ]);

            response(true, [
                'id' => $campaignId,
                'status' => $status,
                'estimated_recipients' => $recipientCount,
                'message' => 'Campanha criada',
            ], '', 201);
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Metodo nao permitido', 405);

} catch (PDOException $e) {
    error_log("[email-campaigns] DB error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
} catch (Exception $e) {
    error_log("[email-campaigns] Error: " . $e->getMessage());
    response(false, null, $e->getMessage(), 400);
}

// ═══════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════

/**
 * Build recipient list based on segment type.
 * Excludes unsubscribed customers and customers without email.
 *
 * @param PDO $db
 * @param string $segmentType all|vip|inactive|new|city
 * @param string|array $segmentConfig JSON config or decoded array
 * @return array [['customer_id' => X, 'email' => '...', 'name' => '...'], ...]
 */
function _buildRecipientList(PDO $db, string $segmentType, $segmentConfig): array {
    $config = is_string($segmentConfig) ? (json_decode($segmentConfig, true) ?: []) : $segmentConfig;

    $baseSql = "
        SELECT DISTINCT c.customer_id, c.email, c.name
        FROM om_customers c
        WHERE c.email IS NOT NULL
          AND c.email != ''
          AND c.is_active = 1
          AND c.email NOT IN (SELECT email FROM om_email_unsubscribes)
    ";

    $params = [];

    switch ($segmentType) {
        case 'vip':
            // Customers with > 5 orders or > R$500 total spent
            $minOrders = (int)($config['min_orders'] ?? 5);
            $minSpent = (float)($config['min_spent'] ?? 500);
            $baseSql .= "
                AND (
                    (SELECT COUNT(*) FROM om_market_orders o WHERE o.customer_id = c.customer_id AND o.status NOT IN ('cancelado','reembolsado')) >= ?
                    OR (SELECT COALESCE(SUM(o2.total), 0) FROM om_market_orders o2 WHERE o2.customer_id = c.customer_id AND o2.status NOT IN ('cancelado','reembolsado')) >= ?
                )
            ";
            $params = [$minOrders, $minSpent];
            break;

        case 'inactive':
            // No orders in the last N days (default 30)
            $days = (int)($config['days'] ?? 30);
            $baseSql .= "
                AND c.customer_id NOT IN (
                    SELECT DISTINCT o.customer_id FROM om_market_orders o
                    WHERE o.created_at > NOW() - INTERVAL '{$days} days'
                )
                AND c.customer_id IN (
                    SELECT DISTINCT o2.customer_id FROM om_market_orders o2
                )
            ";
            break;

        case 'new':
            // Registered in the last N days (default 7)
            $days = (int)($config['days'] ?? 7);
            $baseSql .= " AND c.created_at > NOW() - INTERVAL '{$days} days'";
            break;

        case 'city':
            // Customers in a specific city
            $city = $config['city'] ?? '';
            if (empty($city)) break;
            $baseSql .= "
                AND c.customer_id IN (
                    SELECT DISTINCT a.customer_id FROM om_customer_addresses a
                    WHERE a.city ILIKE ?
                )
            ";
            $params = ["%{$city}%"];
            break;

        case 'all':
        default:
            // All active customers with email
            break;
    }

    $baseSql .= " ORDER BY c.customer_id ASC LIMIT 10000"; // Safety cap

    $stmt = $db->prepare($baseSql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Render a named template for a specific recipient.
 */
function _renderTemplateForRecipient(string $template, string $name, array $campaign): string {
    require_once __DIR__ . '/../helpers/email-templates.php';

    return match($template) {
        'welcome' => emailTemplate_welcome($name),
        'win_back' => emailTemplate_win_back($name, 30, 'VOLTESB10'),
        'promo' => emailTemplate_promo(
            $name,
            $campaign['subject'] ?? 'Promocao SuperBora',
            'Confira esta oferta especial!',
            'PROMO20',
            date('Y-m-d', strtotime('+7 days'))
        ),
        'new_stores' => emailTemplate_new_stores($name, []),
        default => $campaign['body_html'],
    };
}
