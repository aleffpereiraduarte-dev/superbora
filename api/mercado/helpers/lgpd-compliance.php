<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * LGPD COMPLIANCE TOOLKIT
 * Audit logging, PII masking, consent management, data deletion, data export.
 * Implements Brazil's Lei Geral de Protecao de Dados requirements.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/lgpd-compliance.php';
 *   auditLog($db, 'data_access', 'agent', '42', [...]);
 *   $masked = maskPII('CPF: 123.456.789-00');
 *   processDataDeletionRequest($db, '5511999998888', 'customer_request');
 *
 * Tables (from sql/047_enterprise_ai.sql):
 *   om_audit_log, om_customer_consent, om_data_deletion_requests
 */

/**
 * Write an entry to the LGPD audit log.
 *
 * @param PDO    $db        Database connection
 * @param string $eventType Event type: 'conversation', 'data_access', 'data_delete', 'consent_change', 'pii_access', 'compensation'
 * @param string $actorType Who performed the action: 'system', 'ai', 'agent', 'customer', 'admin'
 * @param ?string $actorId  Identifier for the actor (agent ID, system name, etc.)
 * @param array  $data      Event data: customer_phone, customer_id, resource_type, resource_id, action, details, pii_fields
 * @return void
 */
function auditLog(PDO $db, string $eventType, string $actorType, ?string $actorId, array $data): void {
    try {
        $customerPhone = $data['customer_phone'] ?? null;
        $customerId    = $data['customer_id'] ?? null;
        $resourceType  = $data['resource_type'] ?? null;
        $resourceId    = $data['resource_id'] ?? null;
        $action        = $data['action'] ?? 'unknown';
        $details       = $data['details'] ?? [];
        $piiFields     = $data['pii_fields'] ?? [];

        // Capture IP address if available
        $ipAddress = $_SERVER['REMOTE_ADDR']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? null;

        // Convert pii_fields array to PostgreSQL array literal
        $piiFieldsPg = null;
        if (!empty($piiFields)) {
            $piiFieldsPg = '{' . implode(',', array_map(function ($f) {
                return '"' . str_replace('"', '\\"', $f) . '"';
            }, $piiFields)) . '}';
        }

        $stmt = $db->prepare("
            INSERT INTO om_audit_log (
                event_type, actor_type, actor_id, customer_phone, customer_id,
                resource_type, resource_id, action, details, pii_fields_accessed, ip_address
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?)
        ");
        $stmt->execute([
            $eventType,
            $actorType,
            $actorId,
            $customerPhone,
            $customerId,
            $resourceType,
            $resourceId ? (string)$resourceId : null,
            $action,
            is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
            $piiFieldsPg,
            $ipAddress,
        ]);
    } catch (Exception $e) {
        // Audit logging must NEVER break the calling flow
        error_log("[lgpd] auditLog error: " . $e->getMessage());
    }
}

/**
 * Mask PII in text: CPF, phone, email, credit card numbers.
 *
 * @param string $text Input text containing potential PII
 * @return string      Text with PII masked
 */
function maskPII(string $text): string {
    // ── CPF: 123.456.789-00 → ***.***.***-00 ──
    $text = preg_replace(
        '/(\d{3})[.\s]?(\d{3})[.\s]?(\d{3})[-.\s]?(\d{2})/',
        '***.***.***-$4',
        $text
    );

    // ── Phone: +5511999998888 → +5511****8888, 11999998888 → 11****8888 ──
    // International format with +55
    $text = preg_replace(
        '/(\+55\d{2})\d{4,5}(\d{4})/',
        '$1****$2',
        $text
    );
    // National format: (11) 99999-8888 or 11999998888
    $text = preg_replace(
        '/(\(?\d{2}\)?[\s-]?)\d{4,5}([-\s]?\d{4})/',
        '$1****$2',
        $text
    );

    // ── Email: test@example.com → t***@example.com ──
    $text = preg_replace_callback(
        '/([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/',
        function ($matches) {
            $local = $matches[1];
            $domain = $matches[2];
            $masked = strlen($local) > 1
                ? $local[0] . str_repeat('*', min(3, strlen($local) - 1))
                : '*';
            return $masked . '@' . $domain;
        },
        $text
    );

    // ── Credit card: 4111111111111111 or 4111 1111 1111 1111 → ****-****-****-1111 ──
    $text = preg_replace(
        '/\b(\d{4})[\s-]?(\d{4})[\s-]?(\d{4})[\s-]?(\d{4})\b/',
        '****-****-****-$4',
        $text
    );

    return $text;
}

/**
 * Get all consent records for a phone number.
 *
 * @param PDO    $db    Database connection
 * @param string $phone Customer phone number
 * @return array        Array of consent records
 */
function getCustomerConsent(PDO $db, string $phone): array {
    try {
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        $stmt = $db->prepare("
            SELECT id, customer_id, customer_phone, consent_type, granted,
                   granted_at, revoked_at, source, created_at, updated_at
            FROM om_customer_consent
            WHERE customer_phone = ?
            ORDER BY consent_type ASC
        ");
        $stmt->execute([$phone]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast types
        foreach ($records as &$r) {
            $r['id'] = (int)$r['id'];
            $r['customer_id'] = $r['customer_id'] ? (int)$r['customer_id'] : null;
            $r['granted'] = (bool)$r['granted'];
        }
        unset($r);

        return $records;

    } catch (Exception $e) {
        error_log("[lgpd] getCustomerConsent error for phone {$phone}: " . $e->getMessage());
        return [];
    }
}

/**
 * Create or update a consent record.
 *
 * @param PDO     $db          Database connection
 * @param string  $phone       Customer phone
 * @param ?int    $customerId  Customer ID (nullable)
 * @param string  $consentType Consent type: 'proactive_messages', 'data_processing', 'marketing', 'voice_recording', 'ai_training'
 * @param bool    $granted     Whether consent is granted
 * @param string  $source      Source: 'whatsapp', 'voice', 'app', 'admin'
 * @return void
 */
function updateConsent(PDO $db, string $phone, ?int $customerId, string $consentType, bool $granted, string $source): void {
    try {
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        $stmt = $db->prepare("
            INSERT INTO om_customer_consent (
                customer_phone, customer_id, consent_type, granted, granted_at, revoked_at, source, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                CASE WHEN ? THEN NOW() ELSE NULL END,
                CASE WHEN ? THEN NULL ELSE NOW() END,
                ?, NOW()
            )
            ON CONFLICT (customer_phone, consent_type) DO UPDATE SET
                customer_id = COALESCE(EXCLUDED.customer_id, om_customer_consent.customer_id),
                granted = EXCLUDED.granted,
                granted_at = CASE WHEN EXCLUDED.granted THEN NOW() ELSE om_customer_consent.granted_at END,
                revoked_at = CASE WHEN EXCLUDED.granted THEN NULL ELSE NOW() END,
                source = EXCLUDED.source,
                updated_at = NOW()
        ");
        $stmt->execute([
            $phone,
            $customerId,
            $consentType,
            $granted,
            $granted,  // for CASE WHEN granted
            $granted,  // for CASE WHEN NOT granted
            $source,
        ]);

        // Audit log
        auditLog($db, 'consent_change', 'customer', $customerId ? (string)$customerId : $phone, [
            'customer_phone' => $phone,
            'customer_id' => $customerId,
            'resource_type' => 'consent',
            'resource_id' => $consentType,
            'action' => $granted ? 'grant' : 'revoke',
            'details' => [
                'consent_type' => $consentType,
                'granted' => $granted,
                'source' => $source,
            ],
            'pii_fields' => ['phone'],
        ]);

    } catch (Exception $e) {
        error_log("[lgpd] updateConsent error: " . $e->getMessage());
    }
}

/**
 * Process a data deletion request (LGPD right to be forgotten).
 *
 * Deletes personal data from AI/communication tables, anonymizes call/chat records,
 * and preserves order records (legal requirement) with masked PII.
 *
 * @param PDO    $db     Database connection
 * @param string $phone  Customer phone number
 * @param string $source Request source: 'whatsapp', 'voice', 'admin', 'api'
 * @return array         Summary: request_id, tables_affected, total_rows
 */
function processDataDeletionRequest(PDO $db, string $phone, string $source): array {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    $tablesAffected = [];
    $totalRows = 0;

    try {
        // ── 1. Create deletion request record ──
        $stmt = $db->prepare("
            INSERT INTO om_data_deletion_requests (customer_phone, request_source, status)
            VALUES (?, ?, 'processing')
            RETURNING id
        ");
        $stmt->execute([$phone, $source]);
        $requestId = (int)$stmt->fetchColumn();

        // Look up customer_id
        $customerId = null;
        try {
            $stmtCust = $db->prepare("SELECT customer_id FROM om_customers WHERE phone LIKE ?");
            $stmtCust->execute(['%' . $phone . '%']);
            $customerId = $stmtCust->fetchColumn() ?: null;
        } catch (Exception $e) {
            // om_customers may not have phone, skip
        }

        // Update request with customer_id
        if ($customerId) {
            $db->prepare("UPDATE om_data_deletion_requests SET customer_id = ? WHERE id = ?")
               ->execute([$customerId, $requestId]);
        }

        // ── 2. Delete from om_ai_call_memory (full delete — AI memory is not legally required) ──
        try {
            $stmt = $db->prepare("DELETE FROM om_ai_call_memory WHERE customer_phone = ?");
            $stmt->execute([$phone]);
            $rows = $stmt->rowCount();
            if ($rows > 0) {
                $tablesAffected[] = ['table' => 'om_ai_call_memory', 'rows_deleted' => $rows, 'action' => 'deleted'];
                $totalRows += $rows;
            }
        } catch (Exception $e) {
            error_log("[lgpd] Error deleting from om_ai_call_memory: " . $e->getMessage());
        }

        // Also delete by customer_id if available
        if ($customerId) {
            try {
                $stmt = $db->prepare("DELETE FROM om_ai_call_memory WHERE customer_id = ? AND customer_phone != ?");
                $stmt->execute([$customerId, $phone]);
                $rows = $stmt->rowCount();
                if ($rows > 0) {
                    $tablesAffected[] = ['table' => 'om_ai_call_memory (by customer_id)', 'rows_deleted' => $rows, 'action' => 'deleted'];
                    $totalRows += $rows;
                }
            } catch (Exception $e) {
                // skip
            }
        }

        // ── 3. Anonymize om_callcenter_wa_messages (keep structure, mask content) ──
        try {
            $stmt = $db->prepare("
                UPDATE om_callcenter_wa_messages
                SET message = '[DADOS REMOVIDOS POR SOLICITACAO LGPD]',
                    media_url = NULL
                WHERE conversation_id IN (
                    SELECT id FROM om_callcenter_whatsapp WHERE phone = ?
                )
            ");
            $stmt->execute([$phone]);
            $rows = $stmt->rowCount();
            if ($rows > 0) {
                $tablesAffected[] = ['table' => 'om_callcenter_wa_messages', 'rows_deleted' => $rows, 'action' => 'anonymized'];
                $totalRows += $rows;
            }
        } catch (Exception $e) {
            error_log("[lgpd] Error anonymizing om_callcenter_wa_messages: " . $e->getMessage());
        }

        // ── 4. Delete from om_whatsapp_proactive_log ──
        if ($customerId) {
            try {
                $stmt = $db->prepare("DELETE FROM om_whatsapp_proactive_log WHERE customer_id = ?");
                $stmt->execute([$customerId]);
                $rows = $stmt->rowCount();
                if ($rows > 0) {
                    $tablesAffected[] = ['table' => 'om_whatsapp_proactive_log', 'rows_deleted' => $rows, 'action' => 'deleted'];
                    $totalRows += $rows;
                }
            } catch (Exception $e) {
                error_log("[lgpd] Error deleting from om_whatsapp_proactive_log: " . $e->getMessage());
            }
        }

        // ── 5. Anonymize om_callcenter_calls (keep for records, mask PII) ──
        try {
            $maskedPhone = maskPhoneForStorage($phone);
            $stmt = $db->prepare("
                UPDATE om_callcenter_calls
                SET customer_name = NULL,
                    customer_phone = ?,
                    transcription = CASE WHEN transcription IS NOT NULL THEN '[TRANSCRICAO REMOVIDA - LGPD]' ELSE NULL END,
                    recording_url = NULL,
                    ai_summary = CASE WHEN ai_summary IS NOT NULL THEN '[RESUMO REMOVIDO - LGPD]' ELSE NULL END,
                    notes = CASE WHEN notes IS NOT NULL THEN '[NOTAS REMOVIDAS - LGPD]' ELSE NULL END
                WHERE customer_phone = ?
            ");
            $stmt->execute([$maskedPhone, $phone]);
            $rows = $stmt->rowCount();
            if ($rows > 0) {
                $tablesAffected[] = ['table' => 'om_callcenter_calls', 'rows_deleted' => $rows, 'action' => 'anonymized'];
                $totalRows += $rows;
            }
        } catch (Exception $e) {
            error_log("[lgpd] Error anonymizing om_callcenter_calls: " . $e->getMessage());
        }

        // ── 6. Anonymize om_callcenter_whatsapp ──
        try {
            $maskedPhone = maskPhoneForStorage($phone);
            $stmt = $db->prepare("
                UPDATE om_callcenter_whatsapp
                SET customer_name = NULL,
                    phone = ?,
                    ai_context = '{}'
                WHERE phone = ?
            ");
            $stmt->execute([$maskedPhone, $phone]);
            $rows = $stmt->rowCount();
            if ($rows > 0) {
                $tablesAffected[] = ['table' => 'om_callcenter_whatsapp', 'rows_deleted' => $rows, 'action' => 'anonymized'];
                $totalRows += $rows;
            }
        } catch (Exception $e) {
            error_log("[lgpd] Error anonymizing om_callcenter_whatsapp: " . $e->getMessage());
        }

        // ── 7. Anonymize order records (legal requirement to keep, but mask PII) ──
        try {
            $maskedPhone = maskPhoneForStorage($phone);
            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET customer_phone = ?,
                    notes = CASE WHEN notes IS NOT NULL THEN '[NOTAS REMOVIDAS - LGPD]' ELSE NULL END
                WHERE customer_phone = ?
            ");
            $stmt->execute([$maskedPhone, $phone]);
            $rows = $stmt->rowCount();
            if ($rows > 0) {
                $tablesAffected[] = ['table' => 'om_market_orders', 'rows_deleted' => $rows, 'action' => 'anonymized'];
                $totalRows += $rows;
            }
        } catch (Exception $e) {
            error_log("[lgpd] Error anonymizing om_market_orders: " . $e->getMessage());
        }

        // ── 8. Delete consent records (they requested deletion, so consent is void) ──
        try {
            $stmt = $db->prepare("DELETE FROM om_customer_consent WHERE customer_phone = ?");
            $stmt->execute([$phone]);
            $rows = $stmt->rowCount();
            if ($rows > 0) {
                $tablesAffected[] = ['table' => 'om_customer_consent', 'rows_deleted' => $rows, 'action' => 'deleted'];
                $totalRows += $rows;
            }
        } catch (Exception $e) {
            error_log("[lgpd] Error deleting from om_customer_consent: " . $e->getMessage());
        }

        // ── 9. Delete CLV data ──
        if ($customerId) {
            try {
                $stmt = $db->prepare("DELETE FROM om_customer_clv WHERE customer_id = ?");
                $stmt->execute([$customerId]);
                $rows = $stmt->rowCount();
                if ($rows > 0) {
                    $tablesAffected[] = ['table' => 'om_customer_clv', 'rows_deleted' => $rows, 'action' => 'deleted'];
                    $totalRows += $rows;
                }
            } catch (Exception $e) {
                error_log("[lgpd] Error deleting from om_customer_clv: " . $e->getMessage());
            }
        }

        // ── 10. Mark deletion request as completed ──
        $db->prepare("
            UPDATE om_data_deletion_requests
            SET status = 'completed', tables_affected = ?, completed_at = NOW()
            WHERE id = ?
        ")->execute([json_encode($tablesAffected, JSON_UNESCAPED_UNICODE), $requestId]);

        // ── 11. Audit the deletion itself ──
        auditLog($db, 'data_delete', 'system', 'lgpd-compliance', [
            'customer_phone' => maskPhoneForStorage($phone),
            'customer_id' => $customerId,
            'resource_type' => 'customer_data',
            'resource_id' => (string)$requestId,
            'action' => 'delete',
            'details' => [
                'request_id' => $requestId,
                'source' => $source,
                'tables_affected_count' => count($tablesAffected),
                'total_rows' => $totalRows,
            ],
            'pii_fields' => ['phone', 'name', 'transcription', 'messages'],
        ]);

        return [
            'success' => true,
            'request_id' => $requestId,
            'tables_affected' => $tablesAffected,
            'total_rows' => $totalRows,
            'status' => 'completed',
        ];

    } catch (Exception $e) {
        error_log("[lgpd] processDataDeletionRequest critical error: " . $e->getMessage());

        // Try to mark request as failed
        if (isset($requestId)) {
            try {
                $db->prepare("
                    UPDATE om_data_deletion_requests
                    SET status = 'failed', tables_affected = ?
                    WHERE id = ?
                ")->execute([json_encode($tablesAffected, JSON_UNESCAPED_UNICODE), $requestId]);
            } catch (Exception $e2) {
                // nothing we can do
            }
        }

        return [
            'success' => false,
            'error' => 'deletion_failed',
            'request_id' => $requestId ?? null,
            'tables_affected' => $tablesAffected,
            'total_rows' => $totalRows,
        ];
    }
}

/**
 * Mask a phone number for storage in anonymized records.
 * Keeps country code + area code, masks the rest: 5511999998888 → 5511****8888
 */
function maskPhoneForStorage(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    $len = strlen($digits);

    if ($len >= 11) {
        // Brazilian: DDI(2) + DDD(2) + ****XXXX(8-9)
        $prefix = substr($digits, 0, 4);
        $suffix = substr($digits, -4);
        return $prefix . '****' . $suffix;
    }

    // Short number — mask middle
    if ($len > 4) {
        return substr($digits, 0, 2) . str_repeat('*', $len - 4) . substr($digits, -2);
    }

    return str_repeat('*', $len);
}

/**
 * Get audit log dashboard data.
 *
 * @param PDO    $db     Database connection
 * @param string $period Period: '7d', '30d', '90d'
 * @return array         Dashboard metrics
 */
function getAuditDashboard(PDO $db, string $period = '30d'): array {
    try {
        $intervalMap = [
            '7d'  => '7 days',
            '30d' => '30 days',
            '90d' => '90 days',
        ];
        $interval = $intervalMap[$period] ?? '30 days';

        // ── Events by type ──
        $stmtType = $db->prepare("
            SELECT event_type, action, COUNT(*) as count
            FROM om_audit_log
            WHERE created_at > NOW() - INTERVAL '{$interval}'
            GROUP BY event_type, action
            ORDER BY count DESC
        ");
        $stmtType->execute();
        $eventsByType = $stmtType->fetchAll(PDO::FETCH_ASSOC);

        foreach ($eventsByType as &$e) {
            $e['count'] = (int)$e['count'];
        }
        unset($e);

        // ── PII access count ──
        $stmtPii = $db->prepare("
            SELECT COUNT(*) as count
            FROM om_audit_log
            WHERE created_at > NOW() - INTERVAL '{$interval}'
              AND pii_fields_accessed IS NOT NULL
              AND array_length(pii_fields_accessed, 1) > 0
        ");
        $stmtPii->execute();
        $piiAccessCount = (int)$stmtPii->fetchColumn();

        // ── PII fields breakdown ──
        $stmtPiiFields = $db->prepare("
            SELECT unnest(pii_fields_accessed) as field, COUNT(*) as count
            FROM om_audit_log
            WHERE created_at > NOW() - INTERVAL '{$interval}'
              AND pii_fields_accessed IS NOT NULL
            GROUP BY field
            ORDER BY count DESC
        ");
        $stmtPiiFields->execute();
        $piiFieldsBreakdown = $stmtPiiFields->fetchAll(PDO::FETCH_ASSOC);

        // ── Deletion requests ──
        $stmtDeletion = $db->prepare("
            SELECT status, COUNT(*) as count
            FROM om_data_deletion_requests
            WHERE created_at > NOW() - INTERVAL '{$interval}'
            GROUP BY status
        ");
        $stmtDeletion->execute();
        $deletionRequests = $stmtDeletion->fetchAll(PDO::FETCH_ASSOC);

        // ── Consent stats ──
        $stmtConsent = $db->query("
            SELECT consent_type, granted, COUNT(*) as count
            FROM om_customer_consent
            GROUP BY consent_type, granted
            ORDER BY consent_type, granted DESC
        ");
        $consentStats = $stmtConsent->fetchAll(PDO::FETCH_ASSOC);

        foreach ($consentStats as &$cs) {
            $cs['count'] = (int)$cs['count'];
            $cs['granted'] = (bool)$cs['granted'];
        }
        unset($cs);

        // ── Data access patterns (by actor type) ──
        $stmtActors = $db->prepare("
            SELECT actor_type, COUNT(*) as count,
                   COUNT(DISTINCT customer_id) as unique_customers
            FROM om_audit_log
            WHERE created_at > NOW() - INTERVAL '{$interval}'
            GROUP BY actor_type
            ORDER BY count DESC
        ");
        $stmtActors->execute();
        $accessPatterns = $stmtActors->fetchAll(PDO::FETCH_ASSOC);

        foreach ($accessPatterns as &$ap) {
            $ap['count'] = (int)$ap['count'];
            $ap['unique_customers'] = (int)$ap['unique_customers'];
        }
        unset($ap);

        // ── Recent deletion requests (last 10) ──
        $stmtRecentDel = $db->query("
            SELECT id, customer_phone, request_source, status, tables_affected, completed_at, created_at
            FROM om_data_deletion_requests
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $recentDeletions = $stmtRecentDel->fetchAll(PDO::FETCH_ASSOC);

        // Mask phone in deletion results
        foreach ($recentDeletions as &$rd) {
            $rd['id'] = (int)$rd['id'];
            $rd['customer_phone'] = maskPhoneForStorage($rd['customer_phone'] ?? '');
            $rd['tables_affected'] = json_decode($rd['tables_affected'] ?? '[]', true);
        }
        unset($rd);

        return [
            'period' => $period,
            'events_by_type' => $eventsByType,
            'pii_access' => [
                'total_accesses' => $piiAccessCount,
                'fields_breakdown' => $piiFieldsBreakdown,
            ],
            'deletion_requests' => $deletionRequests,
            'recent_deletions' => $recentDeletions,
            'consent_stats' => $consentStats,
            'access_patterns' => $accessPatterns,
        ];

    } catch (Exception $e) {
        error_log("[lgpd] getAuditDashboard error: " . $e->getMessage());
        return [
            'error' => 'dashboard_failed',
            'period' => $period,
            'events_by_type' => [],
            'pii_access' => [],
            'deletion_requests' => [],
            'consent_stats' => [],
        ];
    }
}

/**
 * Export all customer data (LGPD data portability right).
 * Returns a structured array of all data associated with the given phone number.
 *
 * @param PDO    $db    Database connection
 * @param string $phone Customer phone number
 * @return array        All customer data, structured by table/category
 */
function exportCustomerData(PDO $db, string $phone): array {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    $export = [
        'exported_at' => date('Y-m-d H:i:s'),
        'phone' => $phone,
        'sections' => [],
    ];

    // Look up customer_id
    $customerId = null;
    try {
        $stmtCust = $db->prepare("SELECT customer_id, name, email, phone, created_at FROM om_customers WHERE phone LIKE ?");
        $stmtCust->execute(['%' . $phone . '%']);
        $customer = $stmtCust->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            $customerId = (int)$customer['customer_id'];
            $export['customer_id'] = $customerId;
            $export['sections']['profile'] = $customer;
        }
    } catch (Exception $e) {
        error_log("[lgpd] exportCustomerData profile error: " . $e->getMessage());
    }

    // ── Orders ──
    try {
        $params = [];
        $where = '';
        if ($customerId) {
            $where = 'WHERE customer_id = ?';
            $params = [$customerId];
        } else {
            $where = 'WHERE customer_phone = ?';
            $params = [$phone];
        }

        $stmt = $db->prepare("
            SELECT order_id, order_number, partner_name, total, status,
                   forma_pagamento, date_added, date_modified
            FROM om_market_orders
            {$where}
            ORDER BY date_added DESC
        ");
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($orders)) {
            $export['sections']['orders'] = $orders;
        }
    } catch (Exception $e) {
        error_log("[lgpd] exportCustomerData orders error: " . $e->getMessage());
    }

    // ── Addresses ──
    if ($customerId) {
        try {
            $stmt = $db->prepare("
                SELECT address_id, label, street, number, complement, neighborhood, city, state, zipcode
                FROM om_customer_addresses
                WHERE customer_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$customerId]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($addresses)) {
                $export['sections']['addresses'] = $addresses;
            }
        } catch (Exception $e) {
            // table may not exist
        }
    }

    // ── Cashback ──
    if ($customerId) {
        try {
            $stmt = $db->prepare("SELECT balance, total_earned, total_used FROM om_cashback_wallet WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt2 = $db->prepare("
                SELECT type, amount, description, created_at FROM om_cashback_transactions
                WHERE customer_id = ? ORDER BY created_at DESC
            ");
            $stmt2->execute([$customerId]);
            $transactions = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            if ($wallet || !empty($transactions)) {
                $export['sections']['cashback'] = [
                    'wallet' => $wallet ?: null,
                    'transactions' => $transactions,
                ];
            }
        } catch (Exception $e) {
            // skip
        }
    }

    // ── Consent records ──
    try {
        $consent = getCustomerConsent($db, $phone);
        if (!empty($consent)) {
            $export['sections']['consent'] = $consent;
        }
    } catch (Exception $e) {
        // skip
    }

    // ── Call center calls ──
    try {
        $stmt = $db->prepare("
            SELECT id, direction, status, duration_seconds, ai_summary, ai_sentiment, created_at
            FROM om_callcenter_calls
            WHERE customer_phone = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$phone]);
        $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($calls)) {
            $export['sections']['call_center_calls'] = $calls;
        }
    } catch (Exception $e) {
        // skip
    }

    // ── WhatsApp conversations ──
    try {
        $stmt = $db->prepare("
            SELECT w.id, w.status, w.created_at,
                   (SELECT COUNT(*) FROM om_callcenter_wa_messages m WHERE m.conversation_id = w.id) as message_count
            FROM om_callcenter_whatsapp w
            WHERE w.phone = ?
            ORDER BY w.created_at DESC
        ");
        $stmt->execute([$phone]);
        $waConversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($waConversations)) {
            $export['sections']['whatsapp_conversations'] = $waConversations;
        }
    } catch (Exception $e) {
        // skip
    }

    // ── AI Memory ──
    try {
        $stmt = $db->prepare("
            SELECT memory_type, memory_key, memory_value, confidence, times_confirmed, last_used_at
            FROM om_ai_call_memory
            WHERE customer_phone = ?
            ORDER BY last_used_at DESC
        ");
        $stmt->execute([$phone]);
        $memory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($memory)) {
            $export['sections']['ai_memory'] = $memory;
        }
    } catch (Exception $e) {
        // skip
    }

    // ── CLV data ──
    if ($customerId) {
        try {
            $stmt = $db->prepare("SELECT * FROM om_customer_clv WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $clv = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($clv) {
                $export['sections']['customer_clv'] = $clv;
            }
        } catch (Exception $e) {
            // skip
        }
    }

    // Audit this export
    auditLog($db, 'data_access', 'system', 'lgpd-export', [
        'customer_phone' => $phone,
        'customer_id' => $customerId,
        'resource_type' => 'customer_data',
        'action' => 'export',
        'details' => [
            'sections_exported' => array_keys($export['sections']),
        ],
        'pii_fields' => ['phone', 'name', 'email', 'address', 'orders', 'financial'],
    ]);

    return $export;
}
