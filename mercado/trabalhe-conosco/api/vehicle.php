<?php
/**
 * API: Veículo do Trabalhador
 * GET /api/vehicle.php - Obter dados do veículo
 * POST /api/vehicle.php - Salvar/atualizar veículo
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Obter veículo
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $db->prepare("
            SELECT type, brand, model, year, color, plate, renavam,
                   document_url, document_status, document_expires,
                   created_at, updated_at
            FROM " . table('vehicles') . "
            WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $vehicle = $stmt->fetch();

        jsonSuccess(['vehicle' => $vehicle]);

    } catch (Exception $e) {
        error_log("Vehicle GET error: " . $e->getMessage());
        jsonError('Erro ao buscar veículo', 500);
    }
}

// POST - Salvar veículo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();

    $type = $input['type'] ?? ''; // moto, carro, bike, pe
    $brand = $input['brand'] ?? '';
    $model = $input['model'] ?? '';
    $year = intval($input['year'] ?? 0);
    $color = $input['color'] ?? '';
    $plate = strtoupper(preg_replace('/[^A-Z0-9]/', '', $input['plate'] ?? ''));
    $renavam = preg_replace('/\D/', '', $input['renavam'] ?? '');

    // Validações
    if (empty($type)) {
        jsonError('Tipo de veículo é obrigatório');
    }

    // Para moto/carro precisa de mais dados
    if (in_array($type, ['moto', 'carro'])) {
        if (empty($brand) || empty($model) || empty($plate)) {
            jsonError('Marca, modelo e placa são obrigatórios');
        }

        // Validar placa (formato antigo ou Mercosul)
        if (!preg_match('/^[A-Z]{3}[0-9][0-9A-Z][0-9]{2}$/', $plate)) {
            jsonError('Formato de placa inválido');
        }
    }

    try {
        // Verificar se já existe
        $stmt = $db->prepare("SELECT id FROM " . table('vehicles') . " WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $exists = $stmt->fetch();

        if ($exists) {
            // Atualizar
            $stmt = $db->prepare("
                UPDATE " . table('vehicles') . "
                SET type = ?, brand = ?, model = ?, year = ?, color = ?, 
                    plate = ?, renavam = ?, updated_at = NOW()
                WHERE worker_id = ?
            ");
            $stmt->execute([$type, $brand, $model, $year, $color, $plate, $renavam, $workerId]);
        } else {
            // Inserir
            $stmt = $db->prepare("
                INSERT INTO " . table('vehicles') . "
                (worker_id, type, brand, model, year, color, plate, renavam, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$workerId, $type, $brand, $model, $year, $color, $plate, $renavam]);
        }

        jsonSuccess([], 'Veículo salvo com sucesso');

    } catch (Exception $e) {
        error_log("Vehicle POST error: " . $e->getMessage());
        jsonError('Erro ao salvar veículo', 500);
    }
}

jsonError('Método não permitido', 405);
