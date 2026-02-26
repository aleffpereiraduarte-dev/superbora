<?php
/**
 * API: Agenda/Horários de Trabalho
 * GET /api/schedule.php - Obter agenda
 * POST /api/schedule.php - Salvar agenda
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Obter agenda
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $db->prepare("
            SELECT day_of_week, is_active, start_time, end_time
            FROM " . table('schedules') . "
            WHERE worker_id = ?
            ORDER BY day_of_week
        ");
        $stmt->execute([$workerId]);
        $schedule = $stmt->fetchAll();

        // Se não tiver agenda, criar padrão
        if (empty($schedule)) {
            $default = [];
            for ($i = 0; $i < 7; $i++) {
                $default[] = [
                    'day_of_week' => $i,
                    'is_active' => $i > 0 && $i < 6, // Seg-Sex ativo
                    'start_time' => '08:00',
                    'end_time' => '18:00'
                ];
            }
            $schedule = $default;
        }

        // Pausas programadas
        $stmt = $db->prepare("
            SELECT id, start_datetime, end_datetime, reason
            FROM " . table('scheduled_breaks') . "
            WHERE worker_id = ? AND end_datetime > NOW()
            ORDER BY start_datetime
        ");
        $stmt->execute([$workerId]);
        $breaks = $stmt->fetchAll();

        jsonSuccess([
            'schedule' => $schedule,
            'breaks' => $breaks
        ]);

    } catch (Exception $e) {
        error_log("Schedule GET error: " . $e->getMessage());
        jsonError('Erro ao buscar agenda', 500);
    }
}

// POST - Salvar agenda
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    $schedule = $input['schedule'] ?? [];

    if (empty($schedule) || !is_array($schedule)) {
        jsonError('Agenda inválida');
    }

    try {
        $db->beginTransaction();

        // Limpar agenda atual
        $stmt = $db->prepare("DELETE FROM " . table('schedules') . " WHERE worker_id = ?");
        $stmt->execute([$workerId]);

        // Inserir nova agenda
        $stmt = $db->prepare("
            INSERT INTO " . table('schedules') . "
            (worker_id, day_of_week, is_active, start_time, end_time)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($schedule as $day) {
            $dayOfWeek = intval($day['day_of_week'] ?? 0);
            $isActive = (bool)($day['is_active'] ?? false);
            $startTime = $day['start_time'] ?? '08:00';
            $endTime = $day['end_time'] ?? '18:00';

            if ($dayOfWeek < 0 || $dayOfWeek > 6) continue;

            $stmt->execute([$workerId, $dayOfWeek, $isActive ? 1 : 0, $startTime, $endTime]);
        }

        $db->commit();

        jsonSuccess([], 'Agenda salva com sucesso');

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Schedule POST error: " . $e->getMessage());
        jsonError('Erro ao salvar agenda', 500);
    }
}

jsonError('Método não permitido', 405);
