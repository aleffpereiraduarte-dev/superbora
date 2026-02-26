<?php
/**
 * API: Configurações do Trabalhador
 * GET /api/settings.php - Obter configurações
 * PUT /api/settings.php - Atualizar configurações
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) { jsonError('Erro de conexão', 500); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $db->prepare("SELECT * FROM " . table('worker_settings') . " WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $settings = $stmt->fetch();
        
        if (!$settings) {
            $settings = ['push_enabled'=>1,'sound_enabled'=>1,'vibration_enabled'=>1,'auto_accept_enabled'=>0,'max_distance'=>10,'dark_mode'=>0];
            $stmt = $db->prepare("INSERT INTO " . table('worker_settings') . " (worker_id, push_enabled, sound_enabled, vibration_enabled) VALUES (?, 1, 1, 1)");
            $stmt->execute([$workerId]);
        }
        jsonSuccess(['settings' => $settings]);
    } catch (Exception $e) { jsonError('Erro ao buscar configurações', 500); }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = getJsonInput();
    $allowed = ['push_enabled','sound_enabled','vibration_enabled','auto_accept_enabled','max_distance','dark_mode','language'];
    $updates = []; $params = [];
    
    foreach ($allowed as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[] = is_bool($input[$field]) ? ($input[$field] ? 1 : 0) : $input[$field];
        }
    }
    
    if (empty($updates)) { jsonError('Nenhum campo para atualizar'); }
    $params[] = $workerId;
    
    try {
        $stmt = $db->prepare("UPDATE " . table('worker_settings') . " SET " . implode(', ', $updates) . " WHERE worker_id = ?");
        $stmt->execute($params);
        jsonSuccess([], 'Configurações salvas');
    } catch (Exception $e) { jsonError('Erro ao salvar', 500); }
}

jsonError('Método não permitido', 405);
