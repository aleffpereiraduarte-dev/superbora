<?php
/**
 * API: Logout do Trabalhador
 * POST /api/logout.php
 */
require_once 'db.php';

session_start();

$workerId = $_SESSION['worker_id'] ?? null;

if ($workerId) {
    $db = getDB();
    if ($db) {
        try {
            // Colocar offline
            $stmt = $db->prepare("UPDATE " . table('workers') . " SET is_online = 0 WHERE id = ?");
            $stmt->execute([$workerId]);

            // Log de acesso
            $stmt = $db->prepare("
                INSERT INTO " . table('access_logs') . " (worker_id, action, ip, user_agent, created_at)
                VALUES (?, 'logout', ?, ?, NOW())
            ");
            $stmt->execute([$workerId, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
        } catch (Exception $e) {
            error_log("Logout error: " . $e->getMessage());
        }
    }
}

// Destruir sessão
$_SESSION = [];
session_destroy();

jsonSuccess(['redirect' => 'login.php'], 'Logout realizado');
