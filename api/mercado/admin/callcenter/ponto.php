<?php
/**
 * /api/mercado/admin/callcenter/ponto.php
 * Clock-in/out for support agents — writes to om_rh_clock_records
 *
 * GET              — Current status + today's records
 * POST action=clock_in   — Punch in (entrada)
 * POST action=clock_out  — Punch out (saída)
 * POST action=break_start — Start break (intervalo)
 * POST action=break_end   — End break (retorno)
 * GET  ?history=true&from=&to= — Clock history for period
 */

require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    // Get agent + linked RH employee
    $agentStmt = $db->prepare("
        SELECT a.id as agent_id, a.display_name, a.rh_employee_id,
               e.full_name as rh_name, e.department, e.position
        FROM om_callcenter_agents a
        LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id
        WHERE a.admin_id = ?
        LIMIT 1
    ");
    $agentStmt->execute([$adminId]);
    $agent = $agentStmt->fetch();

    if (!$agent) {
        response(false, null, 'Agente não encontrado para este admin', 404);
    }

    $agentId = (int)$agent['agent_id'];
    $rhEmployeeId = $agent['rh_employee_id'] ? (int)$agent['rh_employee_id'] : null;

    if (!$rhEmployeeId) {
        response(false, null, 'Agente não vinculado ao RH. Peça ao admin para vincular.', 400);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════════
    // GET — Current status + today's records
    // ════════════════════════════════════════════════════════════════
    if ($method === 'GET') {

        // History mode
        if (!empty($_GET['history'])) {
            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-d');

            $histStmt = $db->prepare("
                SELECT record_id, record_type, record_time, ip_address, location, notes, is_manual
                FROM om_rh_clock_records
                WHERE employee_id = ?
                  AND record_time::date BETWEEN ?::date AND ?::date
                ORDER BY record_time ASC
            ");
            $histStmt->execute([$rhEmployeeId, $from, $to]);
            $records = $histStmt->fetchAll();

            // Group by day
            $days = [];
            foreach ($records as $r) {
                $day = substr($r['record_time'], 0, 10);
                if (!isset($days[$day])) $days[$day] = ['date' => $day, 'records' => [], 'total_hours' => 0];
                $days[$day]['records'][] = [
                    'id' => (int)$r['record_id'],
                    'type' => $r['record_type'],
                    'time' => $r['record_time'],
                    'ip' => $r['ip_address'],
                    'location' => $r['location'],
                    'notes' => $r['notes'],
                    'manual' => (bool)$r['is_manual'],
                ];
            }

            // Calculate hours per day
            foreach ($days as &$day) {
                $totalSeconds = 0;
                $lastEntry = null;
                foreach ($day['records'] as $rec) {
                    if (in_array($rec['type'], ['entrada', 'retorno'])) {
                        $lastEntry = strtotime($rec['time']);
                    } elseif (in_array($rec['type'], ['saida', 'intervalo']) && $lastEntry) {
                        $totalSeconds += strtotime($rec['time']) - $lastEntry;
                        $lastEntry = null;
                    }
                }
                // If still clocked in, count until now
                if ($lastEntry) {
                    $totalSeconds += time() - $lastEntry;
                }
                $day['total_hours'] = round($totalSeconds / 3600, 2);
            }
            unset($day);

            // Monthly summary
            $totalHours = array_sum(array_column($days, 'total_hours'));
            $daysWorked = count(array_filter($days, fn($d) => $d['total_hours'] > 0));

            response(true, [
                'employee' => [
                    'name' => $agent['rh_name'] ?? $agent['display_name'],
                    'department' => $agent['department'],
                    'position' => $agent['position'],
                ],
                'period' => ['from' => $from, 'to' => $to],
                'days' => array_values($days),
                'summary' => [
                    'total_hours' => round($totalHours, 2),
                    'days_worked' => $daysWorked,
                    'avg_hours_per_day' => $daysWorked > 0 ? round($totalHours / $daysWorked, 2) : 0,
                ]
            ]);
        }

        // Current status — today's records
        $todayStmt = $db->prepare("
            SELECT record_id, record_type, record_time, ip_address, location, notes
            FROM om_rh_clock_records
            WHERE employee_id = ?
              AND record_time::date = CURRENT_DATE
            ORDER BY record_time ASC
        ");
        $todayStmt->execute([$rhEmployeeId]);
        $todayRecords = $todayStmt->fetchAll();

        // Determine current status
        $currentStatus = 'off'; // off, working, break
        $lastClockIn = null;
        $totalWorkedSeconds = 0;
        $lastEntry = null;

        foreach ($todayRecords as $r) {
            if (in_array($r['record_type'], ['entrada', 'retorno'])) {
                $currentStatus = 'working';
                $lastEntry = strtotime($r['record_time']);
                if ($r['record_type'] === 'entrada') $lastClockIn = $r['record_time'];
            } elseif ($r['record_type'] === 'intervalo') {
                $currentStatus = 'break';
                if ($lastEntry) {
                    $totalWorkedSeconds += strtotime($r['record_time']) - $lastEntry;
                    $lastEntry = null;
                }
            } elseif ($r['record_type'] === 'saida') {
                $currentStatus = 'off';
                if ($lastEntry) {
                    $totalWorkedSeconds += strtotime($r['record_time']) - $lastEntry;
                    $lastEntry = null;
                }
            }
        }

        // If still working, add current time
        if ($lastEntry && $currentStatus === 'working') {
            $totalWorkedSeconds += time() - $lastEntry;
        }

        $records = [];
        foreach ($todayRecords as $r) {
            $records[] = [
                'id' => (int)$r['record_id'],
                'type' => $r['record_type'],
                'time' => $r['record_time'],
                'ip' => $r['ip_address'],
                'location' => $r['location'],
            ];
        }

        response(true, [
            'employee' => [
                'name' => $agent['rh_name'] ?? $agent['display_name'],
                'department' => $agent['department'],
                'position' => $agent['position'],
                'rh_id' => $rhEmployeeId,
            ],
            'status' => $currentStatus,
            'clock_in_time' => $lastClockIn,
            'worked_hours' => round($totalWorkedSeconds / 3600, 2),
            'worked_display' => sprintf('%02d:%02d', floor($totalWorkedSeconds / 3600), floor(($totalWorkedSeconds % 3600) / 60)),
            'records' => $records,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // POST — Clock actions
    // ════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $action = $input['action'] ?? '';

        $typeMap = [
            'clock_in' => 'entrada',
            'clock_out' => 'saida',
            'break_start' => 'intervalo',
            'break_end' => 'retorno',
        ];

        if (!isset($typeMap[$action])) {
            response(false, null, 'Ação inválida. Use: clock_in, clock_out, break_start, break_end', 400);
        }

        $recordType = $typeMap[$action];

        // Validate state transitions
        $lastStmt = $db->prepare("
            SELECT record_type FROM om_rh_clock_records
            WHERE employee_id = ? AND record_time::date = CURRENT_DATE
            ORDER BY record_time DESC LIMIT 1
        ");
        $lastStmt->execute([$rhEmployeeId]);
        $lastRecord = $lastStmt->fetch();
        $lastType = $lastRecord['record_type'] ?? null;

        $validTransitions = [
            'entrada' => [null, 'saida'],          // Can clock in if: first of day or after clock out
            'saida' => ['entrada', 'retorno'],       // Can clock out if: working or returned from break
            'intervalo' => ['entrada', 'retorno'],   // Can start break if: working
            'retorno' => ['intervalo'],              // Can end break if: on break
        ];

        if (!in_array($lastType, $validTransitions[$recordType], true)) {
            $statusLabels = [
                null => 'sem registro hoje',
                'entrada' => 'trabalhando',
                'saida' => 'fora do expediente',
                'intervalo' => 'em intervalo',
                'retorno' => 'trabalhando',
            ];
            $currentLabel = $statusLabels[$lastType] ?? $lastType;
            response(false, null, "Não pode registrar '{$recordType}' agora. Status atual: {$currentLabel}", 400);
        }

        // Get client info
        $ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $device = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $notes = trim($input['notes'] ?? '');
        $lat = isset($input['latitude']) ? (float)$input['latitude'] : null;
        $lng = isset($input['longitude']) ? (float)$input['longitude'] : null;

        $insertStmt = $db->prepare("
            INSERT INTO om_rh_clock_records
                (employee_id, record_type, record_time, ip_address, device_info, latitude, longitude, notes, is_manual, created_at)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, 0, NOW())
            RETURNING record_id, record_time
        ");
        $insertStmt->execute([
            $rhEmployeeId, $recordType, $ip, substr($device, 0, 255),
            $lat, $lng, $notes ?: null
        ]);
        $newRecord = $insertStmt->fetch();

        // Update agent status based on clock action
        $agentStatus = match($action) {
            'clock_in', 'break_end' => 'online',
            'clock_out' => 'offline',
            'break_start' => 'break',
            default => null,
        };
        if ($agentStatus) {
            $db->prepare("UPDATE om_callcenter_agents SET status = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$agentStatus, $agentId]);
        }

        // Log to RH ponto_log too
        try {
            $db->prepare("
                INSERT INTO om_rh_ponto_log (employee_id, tipo, data_hora, ip, dispositivo, created_at)
                VALUES (?, ?, NOW(), ?, ?, NOW())
            ")->execute([$rhEmployeeId, $recordType, $ip, substr($device, 0, 100)]);
        } catch (\Exception $e) {
            // ponto_log table might have different structure — non-fatal
        }

        $actionLabels = [
            'clock_in' => 'Entrada registrada',
            'clock_out' => 'Saída registrada',
            'break_start' => 'Intervalo iniciado',
            'break_end' => 'Retorno registrado',
        ];

        response(true, [
            'message' => $actionLabels[$action],
            'record_id' => (int)$newRecord['record_id'],
            'record_type' => $recordType,
            'record_time' => $newRecord['record_time'],
            'agent_status' => $agentStatus,
        ]);
    }

} catch (\Throwable $e) {
    error_log("[callcenter/ponto] Error: " . $e->getMessage());
    response(false, null, 'Erro interno: ' . $e->getMessage(), 500);
}
