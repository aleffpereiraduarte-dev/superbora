<?php
/**
 * /api/mercado/admin/repasse-manual.php
 *
 * Repasse manual para parceiros (payout avulso pelo admin).
 *
 * POST { partner_id, amount, reason, reference? }
 * - Valida parceiro existe
 * - Cria registro em om_market_repasses com status='approved', type='manual'
 * - Audit log com admin_id
 * - Retorna repasse_id
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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    // SECURITY: Only manager/rh can process manual repasses
    $admin_role = $payload['data']['role'] ?? $payload['type'] ?? '';
    if (!in_array($admin_role, ['manager', 'rh', 'superadmin'])) {
        response(false, null, "Apenas manager ou RH podem criar repasses manuais", 403);
    }

    $input = getInput();
    $partner_id = (int)($input['partner_id'] ?? 0);
    $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
    $reason = trim($input['reason'] ?? '');
    $reference = trim($input['reference'] ?? '');

    // Validacoes
    if (!$partner_id) response(false, null, "partner_id obrigatorio", 400);
    if ($amount <= 0) response(false, null, "amount obrigatorio e deve ser maior que zero", 400);
    if (!$reason) response(false, null, "reason obrigatorio", 400);

    $db->beginTransaction();

    // Verificar se parceiro existe (com lock para evitar race conditions)
    $stmt = $db->prepare("
        SELECT partner_id, name, status
        FROM om_market_partners
        WHERE partner_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$partner_id]);
    $partner = $stmt->fetch();

    if (!$partner) {
        $db->rollBack();
        response(false, null, "Parceiro nao encontrado", 404);
    }

    // Garantir que a tabela existe
    ensureRepassesTable($db);

    // Criar registro de repasse manual
    $stmt = $db->prepare("
        INSERT INTO om_market_repasses
            (partner_id, amount, status, type, reason, reference, admin_id, created_at)
        VALUES (?, ?, 'approved', 'manual', ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([$partner_id, $amount, $reason, $reference ?: null, $admin_id]);
    $row = $stmt->fetch();
    $repasse_id = (int)$row['id'];

    $db->commit();

    // Registro de auditoria
    om_audit()->log(
        'repasse_manual',
        'partner',
        $partner_id,
        null,
        [
            'repasse_id' => $repasse_id,
            'amount' => $amount,
            'reason' => $reason,
            'reference' => $reference,
            'type' => 'manual',
            'status' => 'approved'
        ],
        "Repasse manual de R$ " . number_format($amount, 2, ',', '.') . " para parceiro '{$partner['name']}' (#{$partner_id}) pelo admin #{$admin_id}. Motivo: {$reason}"
    );

    response(true, [
        'repasse_id' => $repasse_id,
        'partner_id' => $partner_id,
        'partner_name' => $partner['name'],
        'amount' => $amount,
        'reason' => $reason,
        'reference' => $reference ?: null,
        'type' => 'manual',
        'status' => 'approved',
        'admin_id' => $admin_id
    ], "Repasse manual criado com sucesso");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/repasse-manual] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// =================== FUNCOES AUXILIARES ===================

/**
 * Garante que a tabela om_market_repasses existe com as colunas necessarias.
 * DDL idempotente - seguro para executar multiplas vezes.
 */
function ensureRepassesTable(PDO $db): void {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_market_repasses (
                id SERIAL PRIMARY KEY,
                partner_id INTEGER NOT NULL,
                amount NUMERIC(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                type VARCHAR(20) NOT NULL DEFAULT 'automatic',
                reason TEXT,
                reference VARCHAR(255),
                admin_id INTEGER,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
        // Add columns that may not exist yet (idempotent)
        $columns = [
            'type' => "ALTER TABLE om_market_repasses ADD COLUMN IF NOT EXISTS type VARCHAR(20) NOT NULL DEFAULT 'automatic'",
            'reason' => "ALTER TABLE om_market_repasses ADD COLUMN IF NOT EXISTS reason TEXT",
            'reference' => "ALTER TABLE om_market_repasses ADD COLUMN IF NOT EXISTS reference VARCHAR(255)",
            'admin_id' => "ALTER TABLE om_market_repasses ADD COLUMN IF NOT EXISTS admin_id INTEGER",
        ];
        foreach ($columns as $col => $ddl) {
            try { $db->exec($ddl); } catch (Exception $e) { /* column may already exist */ }
        }
    } catch (Exception $e) {
        // Table may already exist in a different shape — that's OK
        error_log("[repasse-manual] ensureRepassesTable: " . $e->getMessage());
    }
}
