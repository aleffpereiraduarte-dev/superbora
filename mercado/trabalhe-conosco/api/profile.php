<?php
/**
 * API: Perfil do Trabalhador
 * GET /api/profile.php - Obter perfil
 * PUT /api/profile.php - Atualizar perfil
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Obter perfil
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $db->prepare("
            SELECT 
                w.id, w.name, w.email, w.phone, w.cpf, w.photo,
                w.role, w.status, w.rating, w.total_orders, w.total_earnings,
                w.is_online, w.created_at,
                v.type as vehicle_type, v.brand as vehicle_brand, v.model as vehicle_model,
                v.plate as vehicle_plate, v.color as vehicle_color,
                b.bank_name, b.account_type, b.agency, b.account_number, b.pix_key
            FROM " . table('workers') . " w
            LEFT JOIN " . table('vehicles') . " v ON v.worker_id = w.id
            LEFT JOIN " . table('bank_accounts') . " b ON b.worker_id = w.id
            WHERE w.id = ?
        ");
        $stmt->execute([$workerId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            jsonError('Perfil não encontrado', 404);
        }

        // Buscar documentos
        $stmt = $db->prepare("
            SELECT type, status, file_url, expires_at
            FROM " . table('documents') . "
            WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $profile['documents'] = $stmt->fetchAll();

        // Buscar estatísticas
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_today,
                SUM(earnings) as earnings_today
            FROM " . table('orders') . "
            WHERE worker_id = ? AND DATE(completed_at) = CURRENT_DATE AND status = 'completed'
        ");
        $stmt->execute([$workerId]);
        $profile['stats_today'] = $stmt->fetch();

        jsonSuccess(['profile' => $profile]);

    } catch (Exception $e) {
        error_log("Profile GET error: " . $e->getMessage());
        jsonError('Erro ao obter perfil', 500);
    }
}

// PUT - Atualizar perfil
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = getJsonInput();
    
    $allowedFields = ['name', 'email', 'phone', 'photo'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[] = $input[$field];
        }
    }

    if (empty($updates)) {
        jsonError('Nenhum campo para atualizar');
    }

    $params[] = $workerId;

    try {
        $stmt = $db->prepare("
            UPDATE " . table('workers') . "
            SET " . implode(', ', $updates) . ", updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute($params);

        jsonSuccess([], 'Perfil atualizado com sucesso');

    } catch (Exception $e) {
        error_log("Profile PUT error: " . $e->getMessage());
        jsonError('Erro ao atualizar perfil', 500);
    }
}

jsonError('Método não permitido', 405);
