<?php
/**
 * QR Code Table Ordering API
 *
 * GET                      - Get QR config + table list
 * GET action=qr_code       - Get QR code data for a specific table
 * POST action=configure    - Enable/disable QR ordering + settings
 * POST action=create_table - Create a new table
 * POST action=update_table - Update an existing table
 * POST action=bulk_create  - Bulk-create tables (e.g., 1..20)
 * DELETE                   - Delete a table by id
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = (int)$payload['uid'];

    // Tables om_market_qr_tables, om_market_qr_config created via migration
    // Column om_market_orders.table_id added via migration

    // Helper: get store slug for QR code URLs
    function getStoreUrl(PDO $db, int $partnerId): string {
        $stmt = $db->prepare("SELECT slug FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch();
        $slug = $partner['slug'] ?? $partnerId;
        return "https://superbora.com.br/loja/{$slug}";
    }

    // Helper: generate QR URL for a table
    function generateQrUrl(PDO $db, int $partnerId, int $tableNumero): string {
        $storeUrl = getStoreUrl($db, $partnerId);
        return "{$storeUrl}?mesa={$tableNumero}";
    }

    // ===== GET =====
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $action = trim($_GET['action'] ?? '');

        if ($action === 'qr_code') {
            $tableId = (int)($_GET['table_id'] ?? 0);
            if (!$tableId) {
                response(false, null, "table_id e obrigatorio", 400);
            }

            $stmt = $db->prepare("SELECT * FROM om_market_qr_tables WHERE id = ? AND partner_id = ?");
            $stmt->execute([$tableId, $partnerId]);
            $table = $stmt->fetch();

            if (!$table) {
                response(false, null, "Mesa nao encontrada", 404);
            }

            $qrUrl = $table['qr_code_url'] ?: generateQrUrl($db, $partnerId, (int)$table['numero']);
            $qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . urlencode($qrUrl);

            response(true, [
                "table_id" => (int)$table['id'],
                "numero" => (int)$table['numero'],
                "nome" => $table['nome'],
                "qr_url" => $qrUrl,
                "qr_image_url" => $qrImageUrl
            ], "QR code gerado");
        }

        // Default GET: config + tables list
        $stmtConfig = $db->prepare("SELECT * FROM om_market_qr_config WHERE partner_id = ?");
        $stmtConfig->execute([$partnerId]);
        $config = $stmtConfig->fetch();

        if (!$config) {
            $config = [
                'enabled' => false,
                'allow_payment_at_table' => true,
                'auto_accept' => false
            ];
        }

        $stmtTables = $db->prepare("
            SELECT t.*,
                   (SELECT COUNT(*) FROM om_market_orders o
                    WHERE o.table_id = t.id
                      AND o.partner_id = t.partner_id
                      AND o.status IN ('pendente', 'aceito', 'preparando', 'pronto')
                   ) as active_orders
            FROM om_market_qr_tables t
            WHERE t.partner_id = ?
            ORDER BY t.numero ASC
        ");
        $stmtTables->execute([$partnerId]);
        $tables = $stmtTables->fetchAll();

        $storeUrl = getStoreUrl($db, $partnerId);

        $tableList = [];
        foreach ($tables as $table) {
            $qrUrl = $table['qr_code_url'] ?: generateQrUrl($db, $partnerId, (int)$table['numero']);
            $qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qrUrl);

            $tableList[] = [
                "id" => (int)$table['id'],
                "numero" => (int)$table['numero'],
                "nome" => $table['nome'],
                "capacidade" => (int)$table['capacidade'],
                "ativo" => (bool)$table['ativo'],
                "qr_url" => $qrUrl,
                "qr_image_url" => $qrImageUrl,
                "active_orders" => (int)$table['active_orders'],
                "created_at" => $table['created_at']
            ];
        }

        response(true, [
            "config" => [
                "enabled" => (bool)$config['enabled'],
                "allow_payment_at_table" => (bool)$config['allow_payment_at_table'],
                "auto_accept" => (bool)$config['auto_accept']
            ],
            "tables" => $tableList,
            "store_url" => $storeUrl,
            "total_tables" => count($tableList)
        ], "QR ordering config");
    }

    // ===== POST =====
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if (!$action) {
            response(false, null, "action e obrigatoria", 400);
        }

        switch ($action) {
            case 'configure':
                $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : null;
                $allowPayment = isset($input['allow_payment_at_table']) ? (bool)$input['allow_payment_at_table'] : null;
                $autoAccept = isset($input['auto_accept']) ? (bool)$input['auto_accept'] : null;

                // Upsert config
                $stmt = $db->prepare("
                    INSERT INTO om_market_qr_config (partner_id, enabled, allow_payment_at_table, auto_accept, created_at, updated_at)
                    VALUES (?, COALESCE(?, 0), COALESCE(?, 1), COALESCE(?, 0), NOW(), NOW())
                    ON CONFLICT (partner_id) DO UPDATE SET
                        enabled = COALESCE(?, om_market_qr_config.enabled),
                        allow_payment_at_table = COALESCE(?, om_market_qr_config.allow_payment_at_table),
                        auto_accept = COALESCE(?, om_market_qr_config.auto_accept),
                        updated_at = NOW()
                ");
                $stmt->execute([
                    $partnerId,
                    $enabled, $allowPayment, $autoAccept,
                    $enabled, $allowPayment, $autoAccept
                ]);

                response(true, [
                    "enabled" => $enabled,
                    "allow_payment_at_table" => $allowPayment,
                    "auto_accept" => $autoAccept
                ], "Configuracao atualizada");
                break;

            case 'create_table':
                $numero = (int)($input['numero'] ?? 0);
                $nome = trim($input['nome'] ?? '');
                $capacidade = (int)($input['capacidade'] ?? 4);

                if ($numero <= 0) {
                    response(false, null, "Numero da mesa deve ser maior que zero", 400);
                }
                if ($capacidade <= 0) $capacidade = 4;
                if ($capacidade > 100) $capacidade = 100;

                // Check duplicate
                $stmtCheck = $db->prepare("SELECT id FROM om_market_qr_tables WHERE partner_id = ? AND numero = ?");
                $stmtCheck->execute([$partnerId, $numero]);
                if ($stmtCheck->fetch()) {
                    response(false, null, "Ja existe uma mesa com numero $numero", 409);
                }

                $qrUrl = generateQrUrl($db, $partnerId, $numero);

                $stmt = $db->prepare("
                    INSERT INTO om_market_qr_tables (partner_id, numero, nome, capacidade, ativo, qr_code_url, created_at)
                    VALUES (?, ?, ?, ?, 1, ?, NOW())
                    RETURNING id
                ");
                $stmt->execute([$partnerId, $numero, $nome ?: null, $capacidade, $qrUrl]);
                $newId = (int)$stmt->fetchColumn();

                response(true, [
                    "id" => $newId,
                    "numero" => $numero,
                    "nome" => $nome ?: null,
                    "capacidade" => $capacidade,
                    "qr_url" => $qrUrl,
                    "qr_image_url" => "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qrUrl)
                ], "Mesa criada");
                break;

            case 'update_table':
                $tableId = (int)($input['table_id'] ?? 0);
                if (!$tableId) {
                    response(false, null, "table_id e obrigatorio", 400);
                }

                // Verify ownership
                $stmtCheck = $db->prepare("SELECT id, numero FROM om_market_qr_tables WHERE id = ? AND partner_id = ?");
                $stmtCheck->execute([$tableId, $partnerId]);
                $existing = $stmtCheck->fetch();
                if (!$existing) {
                    response(false, null, "Mesa nao encontrada", 404);
                }

                $sets = [];
                $params = [];

                if (isset($input['numero'])) {
                    $newNumero = (int)$input['numero'];
                    if ($newNumero <= 0) {
                        response(false, null, "Numero da mesa deve ser maior que zero", 400);
                    }
                    // Check duplicate (excluding self)
                    $stmtDup = $db->prepare("SELECT id FROM om_market_qr_tables WHERE partner_id = ? AND numero = ? AND id != ?");
                    $stmtDup->execute([$partnerId, $newNumero, $tableId]);
                    if ($stmtDup->fetch()) {
                        response(false, null, "Ja existe outra mesa com numero $newNumero", 409);
                    }
                    $sets[] = "numero = ?";
                    $params[] = $newNumero;
                    // Update QR URL
                    $sets[] = "qr_code_url = ?";
                    $params[] = generateQrUrl($db, $partnerId, $newNumero);
                }
                if (isset($input['nome'])) {
                    $sets[] = "nome = ?";
                    $params[] = trim($input['nome']) ?: null;
                }
                if (isset($input['capacidade'])) {
                    $cap = max(1, min(100, (int)$input['capacidade']));
                    $sets[] = "capacidade = ?";
                    $params[] = $cap;
                }
                if (isset($input['ativo'])) {
                    $sets[] = "ativo = ?";
                    $params[] = (bool)$input['ativo'];
                }

                if (empty($sets)) {
                    response(false, null, "Nenhum campo para atualizar", 400);
                }

                $params[] = $tableId;
                $params[] = $partnerId;
                $sql = "UPDATE om_market_qr_tables SET " . implode(', ', $sets) . " WHERE id = ? AND partner_id = ?";
                $db->prepare($sql)->execute($params);

                response(true, ["table_id" => $tableId], "Mesa atualizada");
                break;

            case 'bulk_create':
                $from = (int)($input['from'] ?? 1);
                $to = (int)($input['to'] ?? 20);

                if ($from <= 0) $from = 1;
                if ($to <= 0 || $to > 200) {
                    response(false, null, "Intervalo invalido (maximo 200 mesas)", 400);
                }
                if ($from > $to) {
                    response(false, null, "'from' deve ser menor ou igual a 'to'", 400);
                }
                if (($to - $from + 1) > 100) {
                    response(false, null, "Maximo 100 mesas por vez", 400);
                }

                $created = 0;
                $skipped = 0;
                $capacidade = (int)($input['capacidade'] ?? 4);
                if ($capacidade <= 0) $capacidade = 4;

                for ($i = $from; $i <= $to; $i++) {
                    $qrUrl = generateQrUrl($db, $partnerId, $i);
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO om_market_qr_tables (partner_id, numero, capacidade, ativo, qr_code_url, created_at)
                            VALUES (?, ?, ?, 1, ?, NOW())
                            ON CONFLICT (partner_id, numero) DO NOTHING
                        ");
                        $stmt->execute([$partnerId, $i, $capacidade, $qrUrl]);
                        if ($stmt->rowCount() > 0) {
                            $created++;
                        } else {
                            $skipped++;
                        }
                    } catch (Exception $e) {
                        $skipped++;
                    }
                }

                response(true, [
                    "created" => $created,
                    "skipped" => $skipped,
                    "range" => "$from-$to"
                ], "$created mesas criadas ($skipped ja existiam)");
                break;

            default:
                response(false, null, "Acao invalida. Use: configure, create_table, update_table, bulk_create", 400);
        }
    }

    // ===== DELETE =====
    if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            // Try from body
            $input = getInput();
            $id = (int)($input['id'] ?? 0);
        }
        if (!$id) {
            response(false, null, "id da mesa e obrigatorio", 400);
        }

        // Check ownership
        $stmtCheck = $db->prepare("SELECT id FROM om_market_qr_tables WHERE id = ? AND partner_id = ?");
        $stmtCheck->execute([$id, $partnerId]);
        if (!$stmtCheck->fetch()) {
            response(false, null, "Mesa nao encontrada", 404);
        }

        // Check active orders
        $stmtActive = $db->prepare("
            SELECT COUNT(*) FROM om_market_orders
            WHERE table_id = ? AND partner_id = ? AND status IN ('pendente', 'aceito', 'preparando', 'pronto')
        ");
        $stmtActive->execute([$id, $partnerId]);
        $activeCount = (int)$stmtActive->fetchColumn();
        if ($activeCount > 0) {
            response(false, null, "Nao e possivel excluir mesa com pedidos ativos ($activeCount pedido(s))", 409);
        }

        $stmt = $db->prepare("DELETE FROM om_market_qr_tables WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partnerId]);

        response(true, ["deleted_id" => $id], "Mesa excluida");
    }

    // Method not allowed
    if (!in_array($_SERVER["REQUEST_METHOD"], ['GET', 'POST', 'DELETE'])) {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/qr-ordering] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
