<?php
/**
 * Cron Job Logger — tracks execution of cron jobs in om_cron_log.
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/cron-logger.php';
 *   $logId = cronStart($db, 'cleanup');
 *   try {
 *       // ... do work ...
 *       cronEnd($db, $logId, 'success', $rowsAffected);
 *   } catch (Exception $e) {
 *       cronEnd($db, $logId, 'failed', 0, $e->getMessage());
 *   }
 */

/**
 * Record the start of a cron job. Returns the log row ID.
 */
function cronStart(PDO $db, string $jobName): int {
    $stmt = $db->prepare("INSERT INTO om_cron_log (job_name, status) VALUES (?, 'running') RETURNING id");
    $stmt->execute([$jobName]);
    return (int)$stmt->fetchColumn();
}

/**
 * Record the completion (or failure) of a cron job.
 */
function cronEnd(PDO $db, int $logId, string $status = 'success', int $rowsAffected = 0, ?string $error = null): void {
    $db->prepare(
        "UPDATE om_cron_log
         SET finished_at = NOW(),
             duration_ms = EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000,
             status = ?,
             rows_affected = ?,
             error_message = ?
         WHERE id = ?"
    )->execute([$status, $rowsAffected, $error, $logId]);
}

/**
 * Check whether a cron job should run (idempotency guard).
 * Returns false if another instance of the same job is already running
 * (started within the last hour and not yet finished).
 */
function cronShouldRun(PDO $db, string $jobName, int $minIntervalSeconds = 60): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM om_cron_log
         WHERE job_name = ?
           AND status = 'running'
           AND started_at > NOW() - INTERVAL '1 hour'
         LIMIT 1"
    );
    $stmt->execute([$jobName]);
    return !$stmt->fetch(); // Only run if no other instance is running
}
