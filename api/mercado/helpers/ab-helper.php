<?php
/**
 * A/B Testing Helper
 *
 * Provides functions for variant assignment and conversion tracking.
 * Uses consistent hashing so the same customer always gets the same variant.
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/ab-helper.php';
 *   $variant = getVariant($db, $testId, $customerId);
 *   // ... later ...
 *   trackConversion($db, $testId, $customerId, 29.90);
 */

/**
 * Get the assigned variant for a customer in an A/B test.
 * Creates assignment if customer hasn't been assigned yet.
 * Uses consistent hashing: crc32(test_id . '_' . customer_id) % total_weight
 *
 * @param PDO $db Database connection
 * @param int $testId A/B test ID
 * @param int $customerId Customer ID
 * @return string|null Variant name, or null if test not found/active
 */
function getVariant(PDO $db, int $testId, int $customerId): ?string {
    // Check if customer already has an assignment
    $stmt = $db->prepare("SELECT variant_name FROM sb_ab_assignments WHERE test_id = ? AND customer_id = ?");
    $stmt->execute([$testId, $customerId]);
    $existing = $stmt->fetch();
    if ($existing) {
        return $existing['variant_name'];
    }

    // Load active test
    $stmt = $db->prepare("SELECT id, variants, total_participants FROM sb_ab_tests WHERE id = ? AND status = 'active'");
    $stmt->execute([$testId]);
    $test = $stmt->fetch();
    if (!$test) {
        return null;
    }

    $variants = json_decode($test['variants'], true);
    if (!$variants || !is_array($variants)) {
        return null;
    }

    // Consistent hashing: crc32 of "testId_customerId" mod total weight
    $totalWeight = 0;
    foreach ($variants as $v) {
        $totalWeight += (int)($v['weight'] ?? 1);
    }
    if ($totalWeight <= 0) {
        return null;
    }

    $hash = abs(crc32($testId . '_' . $customerId)) % $totalWeight;
    $cumulative = 0;
    $assignedVariant = $variants[0]['name']; // fallback
    foreach ($variants as $v) {
        $cumulative += (int)($v['weight'] ?? 1);
        if ($hash < $cumulative) {
            $assignedVariant = $v['name'];
            break;
        }
    }

    // Create assignment (ON CONFLICT DO NOTHING for race condition safety)
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO sb_ab_assignments (test_id, customer_id, variant_name, assigned_at)
            VALUES (?, ?, ?, NOW())
            ON CONFLICT (test_id, customer_id) DO NOTHING
        ");
        $stmt->execute([$testId, $customerId, $assignedVariant]);

        // Increment total_participants only if we actually inserted
        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("UPDATE sb_ab_tests SET total_participants = total_participants + 1 WHERE id = ?");
            $stmt->execute([$testId]);
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("[ab-helper] Assignment error: " . $e->getMessage());
        // On conflict, read the existing assignment
        $stmt = $db->prepare("SELECT variant_name FROM sb_ab_assignments WHERE test_id = ? AND customer_id = ?");
        $stmt->execute([$testId, $customerId]);
        $existing = $stmt->fetch();
        return $existing ? $existing['variant_name'] : null;
    }

    return $assignedVariant;
}

/**
 * Track a conversion for a customer in an A/B test.
 *
 * @param PDO   $db         Database connection
 * @param int   $testId     A/B test ID
 * @param int   $customerId Customer ID
 * @param float $value      Conversion value (default 1 for boolean conversion)
 * @return bool True if conversion was tracked
 */
function trackConversion(PDO $db, int $testId, int $customerId, float $value = 1): bool {
    $db->beginTransaction();
    try {
        // Update assignment to converted (only if not already converted — idempotent)
        $stmt = $db->prepare("
            UPDATE sb_ab_assignments
            SET converted = true, conversion_value = COALESCE(conversion_value, 0) + ?
            WHERE test_id = ? AND customer_id = ? AND converted = false
        ");
        $stmt->execute([$value, $testId, $customerId]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            return false; // Already converted or no assignment
        }

        // Get the variant name to update aggregate stats
        $stmt = $db->prepare("SELECT variant_name FROM sb_ab_assignments WHERE test_id = ? AND customer_id = ?");
        $stmt->execute([$testId, $customerId]);
        $assignment = $stmt->fetch();
        if (!$assignment) {
            $db->rollBack();
            return false;
        }

        // Update variant stats in the JSONB variants array
        $variantName = $assignment['variant_name'];
        $stmt = $db->prepare("SELECT variants FROM sb_ab_tests WHERE id = ? FOR UPDATE");
        $stmt->execute([$testId]);
        $test = $stmt->fetch();
        if (!$test) {
            $db->rollBack();
            return false;
        }

        $variants = json_decode($test['variants'], true);
        foreach ($variants as &$v) {
            if ($v['name'] === $variantName) {
                $v['conversions'] = ($v['conversions'] ?? 0) + 1;
                $v['total_value'] = ($v['total_value'] ?? 0) + $value;
                break;
            }
        }
        unset($v);

        $stmt = $db->prepare("UPDATE sb_ab_tests SET variants = ?::jsonb WHERE id = ?");
        $stmt->execute([json_encode($variants), $testId]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("[ab-helper] Conversion tracking error: " . $e->getMessage());
        return false;
    }
}
