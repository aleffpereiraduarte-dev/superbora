<?php
/**
 * Customer Report Endpoint
 * Allows customers to report inappropriate content (reviews, chat, etc.)
 * Required by Apple App Store Guideline 1.2 (User Generated Content)
 */
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(['error' => 'Method not allowed'], 405);
}

$auth = requireCustomerAuth();
$customerId = $auth['customer_id'];

$input = json_decode(file_get_contents('php://input'), true);
$type = trim($input['type'] ?? '');
$targetId = trim($input['target_id'] ?? '');
$reason = trim($input['reason'] ?? '');

if (!$type || !$targetId || !$reason) {
    response(['error' => 'type, target_id and reason are required'], 400);
}

$validTypes = ['review', 'chat', 'user', 'group', 'store'];
if (!in_array($type, $validTypes)) {
    response(['error' => 'Invalid report type'], 400);
}

$db = getDB();

// Create reports table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS om_content_reports (
    id SERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL,
    report_type VARCHAR(30) NOT NULL,
    target_id VARCHAR(100) NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    reviewed_by INTEGER,
    reviewed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(customer_id, report_type, target_id)
)");

// Check for duplicate report
$check = $db->prepare("SELECT id FROM om_content_reports WHERE customer_id = ? AND report_type = ? AND target_id = ?");
$check->execute([$customerId, $type, $targetId]);
if ($check->fetch()) {
    response(['success' => true, 'message' => 'Denuncia ja registrada anteriormente']);
}

$stmt = $db->prepare("INSERT INTO om_content_reports (customer_id, report_type, target_id, reason) VALUES (?, ?, ?, ?)");
$stmt->execute([$customerId, $type, $targetId, $reason]);

response(['success' => true, 'message' => 'Denuncia registrada com sucesso']);
