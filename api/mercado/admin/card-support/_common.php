<?php
/**
 * Shared helpers for the /admin/card-support/* endpoints.
 *
 * - Ensures support tables exist (self-healing, no migration required)
 * - Re-exports card table bootstrap
 * - Provides small utilities used by multiple endpoints
 */

require_once __DIR__ . "/../../customer/card/_common.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

/**
 * Bootstrap the two support tables and reconcile the events table.
 * Safe to call on every request.
 */
function ensureCardSupportTables(PDO $db): void {
    // Card base tables
    ensureCardTables($db);

    $db->exec("
        CREATE TABLE IF NOT EXISTS om_card_support_notes (
            id SERIAL PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            card_id INTEGER,
            agent_id INTEGER NOT NULL,
            agent_name VARCHAR(200),
            note TEXT NOT NULL,
            visibility VARCHAR(20) DEFAULT 'internal',
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_card_notes_customer ON om_card_support_notes(customer_id, created_at DESC)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS om_card_support_tickets (
            id SERIAL PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            card_id INTEGER,
            subject VARCHAR(200) NOT NULL,
            description TEXT,
            category VARCHAR(50),
            priority VARCHAR(20) DEFAULT 'normal',
            status VARCHAR(30) DEFAULT 'open',
            assigned_to INTEGER,
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP,
            resolved_at TIMESTAMP
        )
    ");
    $db->exec("ALTER TABLE om_card_support_tickets ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_card_tickets_status ON om_card_support_tickets(status, priority, created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_card_tickets_customer ON om_card_support_tickets(customer_id, created_at DESC)");

    // Reconcile events table: metadata column is referenced in this module
    try { $db->exec("ALTER TABLE om_credit_card_events ADD COLUMN IF NOT EXISTS metadata JSONB"); } catch (Exception $e) { /* ignore */ }
}

/**
 * Common admin bootstrap used by all endpoints: auth, DB, tables.
 *
 * @return array{db: PDO, admin: array, admin_id: int, admin_name: string}
 */
function bootstrapCardSupport(): array {
    setCorsHeaders();
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();
    $adminId = (int)($payload['uid'] ?? $payload['user_id'] ?? $payload['id'] ?? 0);
    $adminName = (string)($payload['name'] ?? $payload['username'] ?? 'Admin');
    ensureCardSupportTables($db);
    return ['db' => $db, 'admin' => $payload, 'admin_id' => $adminId, 'admin_name' => $adminName];
}

/**
 * CPF mask helper (shows last 5 digits only).
 * Avoid clash with the maskCpf() defined in helpers/encryption.php.
 */
if (!function_exists('cardSupportMaskCpf')) {
    function cardSupportMaskCpf(?string $cpf): ?string {
        $digits = preg_replace('/\D/', '', (string)$cpf);
        if (strlen($digits) !== 11) return null;
        return '***.***.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
    }
}

/**
 * Encode a value for logging to card events.
 */
function logCardSupportEvent(PDO $db, int $cardId, int $customerId, string $eventType, int $adminId, array $metadata = [], ?string $notes = null): void {
    try {
        $stmt = $db->prepare("
            INSERT INTO om_credit_card_events (card_id, customer_id, event_type, actor_type, actor_id, payload, metadata, notes)
            VALUES (?, ?, ?, 'admin', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $cardId, $customerId, $eventType, $adminId,
            json_encode($metadata, JSON_UNESCAPED_UNICODE),
            json_encode($metadata, JSON_UNESCAPED_UNICODE),
            $notes,
        ]);
    } catch (Exception $e) {
        error_log('[card-support-event] ' . $e->getMessage());
    }
}
