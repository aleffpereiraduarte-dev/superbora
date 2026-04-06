<?php
/**
 * /api/mercado/admin/callcenter/onboarding.php
 * Onboarding management for SuperBora support agents
 *
 * GET                              — List all onboarding flows for agents
 * GET ?agent_id=X                  — Single agent onboarding detail (tasks, docs, trainings)
 * GET ?documents=true&employee_id=X — List documents for employee
 * POST action=update_task          — Mark onboarding task complete/incomplete
 * POST action=add_task             — Add custom onboarding task
 * POST action=upload_document      — Upload employee document (multipart)
 * POST action=review_document      — Approve/reject document
 * POST action=delete_document      — Delete a document
 * POST action=assign_training      — Assign training to employee
 * POST action=update_training      — Update training status/score
 * POST action=add_note             — Add onboarding note/history
 * POST action=update_flow_status   — Update admission flow status
 */

require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════════
    // GET
    // ════════════════════════════════════════════════════════════════
    if ($method === 'GET') {

        // Documents for specific employee
        if (!empty($_GET['documents']) && !empty($_GET['employee_id'])) {
            $empId = (int)$_GET['employee_id'];

            // From om_rh_documentos
            $docStmt = $db->prepare("
                SELECT id, tipo, nome, descricao, arquivo, arquivo_original, tamanho, mime_type,
                       data_emissao, data_validade, status, observacoes, created_at
                FROM om_rh_documentos
                WHERE funcionario_id = ?
                ORDER BY created_at DESC
            ");
            $docStmt->execute([$empId]);
            $docs = $docStmt->fetchAll();

            // From admission_documents if there's an admission flow
            $admDocs = [];
            $flowStmt = $db->prepare("
                SELECT f.flow_id, f.status as flow_status,
                       d.doc_id, d.doc_type, d.file_path, d.original_name, d.status as doc_status,
                       d.review_notes, d.uploaded_at, d.reviewed_at
                FROM om_rh_admission_flows f
                LEFT JOIN om_rh_admission_documents d ON d.flow_id = f.flow_id
                WHERE f.user_id = ?
                ORDER BY d.uploaded_at DESC
            ");
            $flowStmt->execute([$empId]);
            $admDocs = $flowStmt->fetchAll();

            response(true, [
                'documents' => $docs,
                'admission_documents' => $admDocs,
            ]);
        }

        // Single agent onboarding detail
        if (!empty($_GET['agent_id'])) {
            $agentId = (int)$_GET['agent_id'];

            // Get agent + employee info
            $agStmt = $db->prepare("
                SELECT a.id as agent_id, a.admin_id, a.display_name, a.rh_employee_id, a.status, a.created_at,
                       e.full_name, e.email, e.phone, e.cpf, e.department, e.position,
                       e.admission_date, e.salary, e.status as emp_status,
                       adm.email as admin_email, adm.last_login
                FROM om_callcenter_agents a
                LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id
                LEFT JOIN om_admins adm ON adm.admin_id = a.admin_id
                WHERE a.id = ?
            ");
            $agStmt->execute([$agentId]);
            $agent = $agStmt->fetch();
            if (!$agent) response(false, null, 'Agente nao encontrado', 404);

            $rhId = $agent['rh_employee_id'] ? (int)$agent['rh_employee_id'] : null;

            // Onboarding tasks
            $tasks = [];
            if ($rhId) {
                $taskStmt = $db->prepare("
                    SELECT task_id, task_name, task_description, category, due_date,
                           completed, completed_at, priority, created_at
                    FROM om_rh_onboarding_tasks
                    WHERE rh_user_id = ?
                    ORDER BY completed ASC, due_date ASC
                ");
                $taskStmt->execute([$rhId]);
                $tasks = $taskStmt->fetchAll();
            }

            // Documents
            $docs = [];
            if ($rhId) {
                $docStmt = $db->prepare("
                    SELECT id, tipo, nome, arquivo, arquivo_original, tamanho, mime_type,
                           data_validade, status, created_at
                    FROM om_rh_documentos
                    WHERE funcionario_id = ?
                    ORDER BY created_at DESC
                ");
                $docStmt->execute([$rhId]);
                $docs = $docStmt->fetchAll();
            }

            // Admission flow & docs
            $admissionFlow = null;
            $admissionDocs = [];
            if ($rhId) {
                $flowStmt = $db->prepare("
                    SELECT * FROM om_rh_admission_flows WHERE user_id = ? ORDER BY created_at DESC LIMIT 1
                ");
                $flowStmt->execute([$rhId]);
                $admissionFlow = $flowStmt->fetch();

                if ($admissionFlow) {
                    $admDocStmt = $db->prepare("
                        SELECT * FROM om_rh_admission_documents WHERE flow_id = ? ORDER BY uploaded_at DESC
                    ");
                    $admDocStmt->execute([$admissionFlow['flow_id']]);
                    $admissionDocs = $admDocStmt->fetchAll();
                }
            }

            // Trainings
            $trainings = [];
            if ($rhId) {
                $trStmt = $db->prepare("
                    SELECT et.id, et.training_id, et.status, et.started_at, et.completed_at,
                           et.expires_at, et.score, et.certificate_url, et.notes,
                           t.name as training_name, t.description as training_desc,
                           t.category, t.duration_hours, t.is_mandatory, t.content_url
                    FROM om_rh_employee_trainings et
                    JOIN om_rh_trainings t ON t.training_id = et.training_id
                    WHERE et.employee_id = ?
                    ORDER BY et.status = 'em_andamento' DESC, et.created_at DESC
                ");
                $trStmt->execute([$rhId]);
                $trainings = $trStmt->fetchAll();
            }

            // Onboarding history/notes
            $history = [];
            if ($admissionFlow) {
                $histStmt = $db->prepare("
                    SELECT * FROM om_rh_onboarding_history
                    WHERE onboarding_id = ?
                    ORDER BY changed_at DESC
                ");
                $histStmt->execute([$admissionFlow['flow_id']]);
                $history = $histStmt->fetchAll();
            }

            // Calculate progress
            $totalTasks = count($tasks);
            $completedTasks = count(array_filter($tasks, fn($t) => $t['completed'] == 1));
            $totalTrainings = count($trainings);
            $completedTrainings = count(array_filter($trainings, fn($t) => $t['status'] === 'concluido'));

            $requiredDocs = ['rg', 'cpf', 'comprovante_residencia', 'foto_3x4', 'exame_admissional'];
            $uploadedDocTypes = array_map(fn($d) => $d['tipo'], $docs);
            $admUploadedTypes = array_map(fn($d) => $d['doc_type'], $admissionDocs);
            $allDocTypes = array_unique(array_merge($uploadedDocTypes, $admUploadedTypes));
            $docsComplete = count(array_intersect($requiredDocs, $allDocTypes));

            response(true, [
                'agent' => $agent,
                'tasks' => $tasks,
                'documents' => $docs,
                'admission_flow' => $admissionFlow,
                'admission_documents' => $admissionDocs,
                'trainings' => $trainings,
                'history' => $history,
                'progress' => [
                    'tasks_total' => $totalTasks,
                    'tasks_completed' => $completedTasks,
                    'tasks_percent' => $totalTasks > 0 ? round($completedTasks / $totalTasks * 100) : 0,
                    'docs_required' => count($requiredDocs),
                    'docs_uploaded' => $docsComplete,
                    'docs_percent' => round($docsComplete / count($requiredDocs) * 100),
                    'trainings_total' => $totalTrainings,
                    'trainings_completed' => $completedTrainings,
                    'trainings_percent' => $totalTrainings > 0 ? round($completedTrainings / $totalTrainings * 100) : 0,
                    'overall_percent' => $totalTasks + count($requiredDocs) + $totalTrainings > 0
                        ? round(($completedTasks + $docsComplete + $completedTrainings) / ($totalTasks + count($requiredDocs) + $totalTrainings) * 100)
                        : 0,
                ],
                'required_documents' => $requiredDocs,
            ]);
        }

        // List all agents with onboarding status
        $stmt = $db->query("
            SELECT a.id as agent_id, a.display_name, a.rh_employee_id, a.status, a.created_at,
                   e.full_name, e.email, e.admission_date, e.position, e.status as emp_status,
                   adm.last_login,
                   (SELECT COUNT(*) FROM om_rh_onboarding_tasks t WHERE t.rh_user_id = a.rh_employee_id) as total_tasks,
                   (SELECT COUNT(*) FROM om_rh_onboarding_tasks t WHERE t.rh_user_id = a.rh_employee_id AND t.completed = 1) as completed_tasks,
                   (SELECT COUNT(*) FROM om_rh_documentos d WHERE d.funcionario_id = a.rh_employee_id) as total_docs,
                   (SELECT COUNT(*) FROM om_rh_employee_trainings et WHERE et.employee_id = a.rh_employee_id) as total_trainings,
                   (SELECT COUNT(*) FROM om_rh_employee_trainings et WHERE et.employee_id = a.rh_employee_id AND et.status = 'concluido') as completed_trainings,
                   f.flow_id, f.status as flow_status
            FROM om_callcenter_agents a
            LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id
            LEFT JOIN om_admins adm ON adm.admin_id = a.admin_id
            LEFT JOIN om_rh_admission_flows f ON f.user_id = a.rh_employee_id
            ORDER BY a.created_at DESC
        ");
        $agents = $stmt->fetchAll();

        // Available trainings for assignment
        $trStmt = $db->query("SELECT training_id, name, category, duration_hours, is_mandatory FROM om_rh_trainings WHERE status = 'ativo' ORDER BY name");
        $availableTrainings = $trStmt->fetchAll();

        response(true, [
            'agents' => $agents,
            'available_trainings' => $availableTrainings,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // POST
    // ════════════════════════════════════════════════════════════════
    if ($method === 'POST') {

        // Handle multipart for file uploads
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false) {
            $input = $_POST;
        } else {
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        }
        $action = $input['action'] ?? '';

        // ── Update Task ─────────────────────────────────────────────
        if ($action === 'update_task') {
            $taskId = (int)($input['task_id'] ?? 0);
            $completed = (int)($input['completed'] ?? 0);
            if (!$taskId) response(false, null, 'task_id obrigatorio', 400);

            $stmt = $db->prepare("
                UPDATE om_rh_onboarding_tasks
                SET completed = ?, completed_at = ?, completed_by = ?, updated_at = NOW()
                WHERE task_id = ?
                RETURNING task_id
            ");
            $stmt->execute([
                $completed,
                $completed ? date('Y-m-d H:i:s') : null,
                $completed ? $adminId : null,
                $taskId
            ]);
            if (!$stmt->fetch()) response(false, null, 'Task nao encontrada', 404);
            response(true, ['task_id' => $taskId], $completed ? 'Tarefa concluida' : 'Tarefa reaberta');
        }

        // ── Add Task ────────────────────────────────────────────────
        if ($action === 'add_task') {
            $rhUserId = (int)($input['rh_user_id'] ?? 0);
            $taskName = trim($input['task_name'] ?? '');
            $taskDesc = trim($input['task_description'] ?? '');
            $category = $input['category'] ?? 'geral';
            $priority = $input['priority'] ?? 'media';
            $dueDays = (int)($input['due_days'] ?? 7);

            if (!$rhUserId || empty($taskName)) {
                response(false, null, 'rh_user_id e task_name obrigatorios', 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_rh_onboarding_tasks
                    (rh_user_id, task_name, task_description, category, due_date, completed, priority, created_at)
                VALUES (?, ?, ?, ?, CURRENT_DATE + INTERVAL '1 day' * ?, 0, ?, NOW())
                RETURNING task_id
            ");
            $stmt->execute([$rhUserId, $taskName, $taskDesc, $category, $dueDays, $priority]);
            $newId = (int)$stmt->fetch()['task_id'];
            response(true, ['task_id' => $newId], 'Tarefa adicionada');
        }

        // ── Upload Document ─────────────────────────────────────────
        if ($action === 'upload_document') {
            $empId = (int)($input['employee_id'] ?? 0);
            $tipo = trim($input['tipo'] ?? '');
            $nome = trim($input['nome'] ?? '');

            if (!$empId || empty($tipo)) {
                response(false, null, 'employee_id e tipo obrigatorios', 400);
            }

            if (empty($_FILES['file'])) {
                response(false, null, 'Arquivo obrigatorio', 400);
            }

            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                response(false, null, 'Erro no upload: ' . $file['error'], 400);
            }

            // Validate file
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($file['size'] > $maxSize) {
                response(false, null, 'Arquivo muito grande (max 10MB)', 400);
            }

            $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($detectedMime, $allowedMimes)) {
                response(false, null, 'Tipo de arquivo nao permitido. Use PDF, JPG, PNG ou DOC.', 400);
            }

            // Generate safe filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
            $fileName = "emp_{$empId}_{$tipo}_" . time() . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
            $uploadDir = dirname(__DIR__, 4) . '/storage/uploads/onboarding/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $destPath = $uploadDir . $fileName;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                response(false, null, 'Falha ao salvar arquivo', 500);
            }

            // Save to om_rh_documentos
            $stmt = $db->prepare("
                INSERT INTO om_rh_documentos
                    (funcionario_id, tipo, nome, arquivo, arquivo_original, tamanho, mime_type, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente', NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([
                $empId, $tipo,
                $nome ?: $file['name'],
                'onboarding/' . $fileName,
                $file['name'],
                $file['size'],
                $detectedMime
            ]);
            $docId = (int)$stmt->fetch()['id'];

            // Also save to admission_documents if there's a flow
            $flowStmt = $db->prepare("SELECT flow_id FROM om_rh_admission_flows WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
            $flowStmt->execute([$empId]);
            $flow = $flowStmt->fetch();

            if ($flow) {
                $admDocTypes = ['rg', 'cpf', 'ctps', 'comprovante_residencia', 'foto_3x4',
                                'exame_admissional', 'certidao', 'pis', 'titulo_eleitor',
                                'reservista', 'cnh', 'diploma'];
                if (in_array($tipo, $admDocTypes)) {
                    try {
                        $db->prepare("
                            INSERT INTO om_rh_admission_documents (flow_id, doc_type, file_path, original_name, status, uploaded_at)
                            VALUES (?, ?, ?, ?, 'uploaded', NOW())
                            ON CONFLICT (flow_id, doc_type) DO UPDATE SET
                                file_path = EXCLUDED.file_path,
                                original_name = EXCLUDED.original_name,
                                status = 'uploaded',
                                uploaded_at = NOW()
                        ")->execute([$flow['flow_id'], $tipo, 'onboarding/' . $fileName, $file['name']]);
                    } catch (\Exception $e) {
                        // non-fatal — idx might not exist
                        try {
                            $db->prepare("
                                INSERT INTO om_rh_admission_documents (flow_id, doc_type, file_path, original_name, status, uploaded_at)
                                VALUES (?, ?, ?, ?, 'uploaded', NOW())
                            ")->execute([$flow['flow_id'], $tipo, 'onboarding/' . $fileName, $file['name']]);
                        } catch (\Exception $e2) { /* non-fatal */ }
                    }
                }
            }

            response(true, [
                'doc_id' => $docId,
                'file_path' => 'onboarding/' . $fileName,
                'file_name' => $file['name'],
            ], 'Documento enviado com sucesso');
        }

        // ── Review Document ─────────────────────────────────────────
        if ($action === 'review_document') {
            $docId = (int)($input['doc_id'] ?? 0);
            $status = $input['status'] ?? ''; // aprovado, reprovado
            $notes = trim($input['notes'] ?? '');

            if (!$docId || !in_array($status, ['aprovado', 'reprovado'])) {
                response(false, null, 'doc_id e status (aprovado/reprovado) obrigatorios', 400);
            }

            $stmt = $db->prepare("
                UPDATE om_rh_documentos
                SET status = ?, aprovador_id = ?, data_aprovacao = NOW(), observacoes = ?, updated_at = NOW()
                WHERE id = ?
                RETURNING id
            ");
            $stmt->execute([$status, $adminId, $notes ?: null, $docId]);
            if (!$stmt->fetch()) response(false, null, 'Documento nao encontrado', 404);
            response(true, ['doc_id' => $docId], 'Documento ' . $status);
        }

        // ── Delete Document ─────────────────────────────────────────
        if ($action === 'delete_document') {
            $docId = (int)($input['doc_id'] ?? 0);
            if (!$docId) response(false, null, 'doc_id obrigatorio', 400);

            // Get file path first
            $stmt = $db->prepare("SELECT arquivo FROM om_rh_documentos WHERE id = ?");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();
            if (!$doc) response(false, null, 'Documento nao encontrado', 404);

            // Delete file
            $filePath = dirname(__DIR__, 4) . '/storage/uploads/' . $doc['arquivo'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete record
            $db->prepare("DELETE FROM om_rh_documentos WHERE id = ?")->execute([$docId]);
            response(true, ['doc_id' => $docId], 'Documento removido');
        }

        // ── Assign Training ─────────────────────────────────────────
        if ($action === 'assign_training') {
            $empId = (int)($input['employee_id'] ?? 0);
            $trainingId = (int)($input['training_id'] ?? 0);

            if (!$empId || !$trainingId) {
                response(false, null, 'employee_id e training_id obrigatorios', 400);
            }

            // Check if already assigned
            $checkStmt = $db->prepare("SELECT id FROM om_rh_employee_trainings WHERE employee_id = ? AND training_id = ?");
            $checkStmt->execute([$empId, $trainingId]);
            if ($checkStmt->fetch()) {
                response(false, null, 'Treinamento ja atribuido', 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_rh_employee_trainings (employee_id, training_id, status, created_at)
                VALUES (?, ?, 'pendente', NOW())
                RETURNING id
            ");
            $stmt->execute([$empId, $trainingId]);
            $newId = (int)$stmt->fetch()['id'];
            response(true, ['id' => $newId], 'Treinamento atribuido');
        }

        // ── Update Training ─────────────────────────────────────────
        if ($action === 'update_training') {
            $id = (int)($input['id'] ?? 0);
            if (!$id) response(false, null, 'id obrigatorio', 400);

            $fields = [];
            $params = [];

            if (isset($input['status'])) {
                $fields[] = "status = ?";
                $params[] = $input['status'];
                if ($input['status'] === 'em_andamento' && empty($input['started_at'])) {
                    $fields[] = "started_at = NOW()";
                }
                if ($input['status'] === 'concluido') {
                    $fields[] = "completed_at = NOW()";
                }
            }
            if (isset($input['score'])) {
                $fields[] = "score = ?";
                $params[] = (float)$input['score'];
            }
            if (isset($input['notes'])) {
                $fields[] = "notes = ?";
                $params[] = $input['notes'];
            }

            if (empty($fields)) response(false, null, 'Nenhum campo', 400);

            $params[] = $id;
            $sql = "UPDATE om_rh_employee_trainings SET " . implode(', ', $fields) . " WHERE id = ? RETURNING id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            if (!$stmt->fetch()) response(false, null, 'Treinamento nao encontrado', 404);
            response(true, ['id' => $id], 'Treinamento atualizado');
        }

        // ── Add Note / History ──────────────────────────────────────
        if ($action === 'add_note') {
            $flowId = (int)($input['flow_id'] ?? 0);
            $note = trim($input['notes'] ?? '');
            $actionLabel = trim($input['action_label'] ?? 'Nota');

            if (!$flowId || empty($note)) {
                response(false, null, 'flow_id e notes obrigatorios', 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_rh_onboarding_history
                    (onboarding_id, action, notes, changed_by, changed_at)
                VALUES (?, ?, ?, ?, NOW())
                RETURNING history_id
            ");
            $stmt->execute([$flowId, $actionLabel, $note, $adminId]);
            $newId = (int)$stmt->fetch()['history_id'];
            response(true, ['history_id' => $newId], 'Nota adicionada');
        }

        // ── Update Flow Status ──────────────────────────────────────
        if ($action === 'update_flow_status') {
            $flowId = (int)($input['flow_id'] ?? 0);
            $status = $input['status'] ?? '';

            $allowed = ['invited', 'in_progress', 'documents', 'exam', 'contract', 'completed', 'cancelled'];
            if (!$flowId || !in_array($status, $allowed)) {
                response(false, null, 'flow_id e status valido obrigatorios', 400);
            }

            $stmt = $db->prepare("
                UPDATE om_rh_admission_flows
                SET status = ?, completed_at = ?
                WHERE flow_id = ?
                RETURNING flow_id
            ");
            $stmt->execute([
                $status,
                $status === 'completed' ? date('Y-m-d H:i:s') : null,
                $flowId
            ]);
            if (!$stmt->fetch()) response(false, null, 'Fluxo nao encontrado', 404);

            // Log history
            try {
                $db->prepare("
                    INSERT INTO om_rh_onboarding_history (onboarding_id, to_status, action, changed_by, changed_at)
                    VALUES (?, ?, 'status_change', ?, NOW())
                ")->execute([$flowId, $status, $adminId]);
            } catch (\Exception $e) { /* non-fatal */ }

            response(true, ['flow_id' => $flowId], 'Status atualizado');
        }

        // ── Create Training ─────────────────────────────────────────
        if ($action === 'create_training') {
            $name = trim($input['name'] ?? '');
            $desc = trim($input['description'] ?? '');
            $category = $input['category'] ?? 'suporte';
            $durationHours = isset($input['duration_hours']) ? (float)$input['duration_hours'] : 1;
            $isMandatory = (int)($input['is_mandatory'] ?? 0);
            $contentUrl = trim($input['content_url'] ?? '');

            if (empty($name)) response(false, null, 'name obrigatorio', 400);

            $stmt = $db->prepare("
                INSERT INTO om_rh_trainings (name, description, category, duration_hours, is_mandatory, content_url, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'ativo', ?, NOW())
                RETURNING training_id
            ");
            $stmt->execute([$name, $desc, $category, $durationHours, $isMandatory, $contentUrl ?: null, $adminId]);
            $newId = (int)$stmt->fetch()['training_id'];
            response(true, ['training_id' => $newId], 'Treinamento criado');
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Method not allowed', 405);

} catch (\Throwable $e) {
    error_log("[callcenter/onboarding] Error: " . $e->getMessage());
    response(false, null, 'Erro interno: ' . $e->getMessage(), 500);
}
