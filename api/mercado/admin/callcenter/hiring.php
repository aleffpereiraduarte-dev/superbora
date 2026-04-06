<?php
/**
 * /api/mercado/admin/callcenter/hiring.php
 * Hiring & recruitment management for SuperBora support agents
 *
 * GET                          — List open positions + stats
 * GET ?id=X                    — Position detail with candidates
 * GET ?candidates=true         — All candidates with filters
 * GET ?candidate_id=X          — Single candidate detail
 * POST action=create_position  — Create new job position
 * POST action=update_position  — Update position
 * POST action=close_position   — Close position
 * POST action=add_candidate    — Add candidate to position
 * POST action=update_candidate — Update candidate stage/status
 * POST action=hire             — Hire candidate → create admin + agent + RH employee
 * POST action=revoke_access    — Revoke agent access
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
    // GET — List positions, candidates, or detail
    // ════════════════════════════════════════════════════════════════
    if ($method === 'GET') {

        // Single candidate detail
        if (isset($_GET['candidate_id'])) {
            $cid = (int)$_GET['candidate_id'];
            $stmt = $db->prepare("
                SELECT c.*, v.titulo as vaga_titulo, v.codigo as vaga_codigo
                FROM om_rh_candidaturas c
                LEFT JOIN om_rh_vagas v ON v.id = c.vaga_id
                WHERE c.id = ?
            ");
            $stmt->execute([$cid]);
            $candidate = $stmt->fetch();
            if (!$candidate) response(false, null, 'Candidato nao encontrado', 404);

            // Get history
            $histStmt = $db->prepare("
                SELECT * FROM om_rh_application_history
                WHERE application_id = ?
                ORDER BY created_at DESC
            ");
            $histStmt->execute([$cid]);
            $history = $histStmt->fetchAll();

            $candidate['history'] = $history;
            response(true, $candidate);
        }

        // Candidates list
        if (!empty($_GET['candidates'])) {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
            $offset = ($page - 1) * $limit;

            $conditions = ["v.departamento_id IS NOT NULL OR v.id IS NOT NULL"];
            $params = [];

            // Filter by position
            if (!empty($_GET['vaga_id'])) {
                $conditions[] = "c.vaga_id = ?";
                $params[] = (int)$_GET['vaga_id'];
            }

            // Filter by stage
            if (!empty($_GET['etapa'])) {
                $conditions[] = "c.etapa = ?";
                $params[] = $_GET['etapa'];
            }

            // Filter by status
            if (!empty($_GET['status'])) {
                $conditions[] = "c.status = ?";
                $params[] = $_GET['status'];
            }

            // Search
            if (!empty($_GET['search'])) {
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $_GET['search']);
                $conditions[] = "(c.nome ILIKE ? OR c.email ILIKE ? OR c.telefone ILIKE ?)";
                $s = '%' . $escaped . '%';
                $params[] = $s;
                $params[] = $s;
                $params[] = $s;
            }

            // Only SuperBora positions (filter by department or tag)
            $conditions[] = "(v.titulo ILIKE '%superbora%' OR v.titulo ILIKE '%suporte%' OR v.titulo ILIKE '%atendente%' OR v.titulo ILIKE '%call center%')";

            $where = 'WHERE ' . implode(' AND ', $conditions);

            $countStmt = $db->prepare("
                SELECT COUNT(*) as total FROM om_rh_candidaturas c
                LEFT JOIN om_rh_vagas v ON v.id = c.vaga_id
                {$where}
            ");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetch()['total'];

            $params[] = $limit;
            $params[] = $offset;
            $stmt = $db->prepare("
                SELECT c.id, c.vaga_id, c.nome, c.email, c.telefone, c.cpf,
                       c.etapa, c.status, c.score_ia, c.data_entrevista,
                       c.pretensao_salarial, c.created_at, c.updated_at,
                       v.titulo as vaga_titulo, v.codigo as vaga_codigo
                FROM om_rh_candidaturas c
                LEFT JOIN om_rh_vagas v ON v.id = c.vaga_id
                {$where}
                ORDER BY c.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($params);
            $candidates = $stmt->fetchAll();

            response(true, [
                'candidates' => $candidates,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit),
            ]);
        }

        // Single position detail
        if (isset($_GET['id'])) {
            $vid = (int)$_GET['id'];
            $stmt = $db->prepare("SELECT * FROM om_rh_vagas WHERE id = ?");
            $stmt->execute([$vid]);
            $vaga = $stmt->fetch();
            if (!$vaga) response(false, null, 'Vaga nao encontrada', 404);

            // Get candidates for this position
            $candStmt = $db->prepare("
                SELECT id, nome, email, telefone, etapa, status, score_ia,
                       data_entrevista, created_at
                FROM om_rh_candidaturas
                WHERE vaga_id = ?
                ORDER BY
                    CASE status WHEN 'em_analise' THEN 1 WHEN 'aprovado' THEN 2 WHEN 'reprovado' THEN 3 ELSE 4 END,
                    created_at DESC
            ");
            $candStmt->execute([$vid]);
            $vaga['candidatos'] = $candStmt->fetchAll();

            response(true, $vaga);
        }

        // List positions — default
        $statusFilter = $_GET['status'] ?? '';
        $conditions = [];
        $params = [];

        if ($statusFilter && in_array($statusFilter, ['aberta', 'fechada', 'pausada', 'rascunho'])) {
            $conditions[] = "v.status = ?";
            $params[] = $statusFilter;
        }

        // Filter SuperBora-related positions
        $conditions[] = "(v.titulo ILIKE '%superbora%' OR v.titulo ILIKE '%suporte%' OR v.titulo ILIKE '%atendente%' OR v.titulo ILIKE '%call center%')";

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $db->prepare("
            SELECT v.*,
                   (SELECT COUNT(*) FROM om_rh_candidaturas c WHERE c.vaga_id = v.id) as total_candidatos,
                   (SELECT COUNT(*) FROM om_rh_candidaturas c WHERE c.vaga_id = v.id AND c.status = 'em_analise') as pendentes,
                   (SELECT COUNT(*) FROM om_rh_candidaturas c WHERE c.vaga_id = v.id AND c.status = 'aprovado') as aprovados
            FROM om_rh_vagas v
            {$where}
            ORDER BY v.urgente DESC, v.created_at DESC
        ");
        $stmt->execute($params);
        $vagas = $stmt->fetchAll();

        // Stats
        $statsStmt = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE status = 'aberta') as vagas_abertas,
                COUNT(*) FILTER (WHERE status = 'fechada') as vagas_fechadas,
                COALESCE(SUM(quantidade_vagas) FILTER (WHERE status = 'aberta'), 0) as posicoes_abertas,
                (SELECT COUNT(*) FROM om_rh_candidaturas c
                 JOIN om_rh_vagas v2 ON v2.id = c.vaga_id
                 WHERE c.status = 'em_analise'
                 AND (v2.titulo ILIKE '%superbora%' OR v2.titulo ILIKE '%suporte%' OR v2.titulo ILIKE '%atendente%' OR v2.titulo ILIKE '%call center%')
                ) as candidatos_pendentes,
                (SELECT COUNT(*) FROM om_callcenter_agents) as agentes_ativos,
                (SELECT COUNT(*) FROM om_callcenter_agents WHERE status = 'online') as agentes_online
            FROM om_rh_vagas
            WHERE (titulo ILIKE '%superbora%' OR titulo ILIKE '%suporte%' OR titulo ILIKE '%atendente%' OR titulo ILIKE '%call center%')
        ");
        $stats = $statsStmt->fetch();

        response(true, [
            'vagas' => $vagas,
            'stats' => $stats,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // POST — Actions
    // ════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $action = $input['action'] ?? '';

        // ── Create Position ─────────────────────────────────────────
        if ($action === 'create_position') {
            $titulo = trim($input['titulo'] ?? '');
            $descricao = trim($input['descricao'] ?? '');
            $requisitos = trim($input['requisitos'] ?? '');
            $responsabilidades = trim($input['responsabilidades'] ?? '');
            $diferenciais = trim($input['diferenciais'] ?? '');
            $tipoContrato = $input['tipo_contrato'] ?? 'CLT';
            $modalidade = $input['modalidade'] ?? 'presencial';
            $nivel = $input['nivel'] ?? 'junior';
            $salarioMin = isset($input['salario_min']) ? (float)$input['salario_min'] : null;
            $salarioMax = isset($input['salario_max']) ? (float)$input['salario_max'] : null;
            $salarioVisivel = (int)($input['salario_visivel'] ?? 0);
            $beneficios = trim($input['beneficios'] ?? '');
            $quantidade = max(1, (int)($input['quantidade_vagas'] ?? 1));
            $urgente = (int)($input['urgente'] ?? 0);

            if (empty($titulo)) {
                response(false, null, 'Titulo obrigatorio', 400);
            }

            // Generate code
            $code = 'SB-' . strtoupper(substr(md5(uniqid()), 0, 6));

            $stmt = $db->prepare("
                INSERT INTO om_rh_vagas
                    (titulo, codigo, descricao, requisitos, responsabilidades, diferenciais,
                     tipo_contrato, modalidade, nivel, salario_min, salario_max, salario_visivel,
                     beneficios, quantidade_vagas, urgente, status, data_abertura,
                     recrutador_id, visualizacoes, candidaturas_count, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aberta', CURRENT_DATE, ?, 0, 0, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([
                $titulo, $code, $descricao, $requisitos, $responsabilidades, $diferenciais,
                $tipoContrato, $modalidade, $nivel, $salarioMin, $salarioMax, $salarioVisivel,
                $beneficios, $quantidade, $urgente, $adminId
            ]);
            $newId = (int)$stmt->fetch()['id'];

            response(true, ['id' => $newId, 'codigo' => $code], 'Vaga criada com sucesso');
        }

        // ── Update Position ─────────────────────────────────────────
        if ($action === 'update_position') {
            $id = (int)($input['id'] ?? 0);
            if (!$id) response(false, null, 'ID obrigatorio', 400);

            $fields = [];
            $params = [];
            $allowed = ['titulo', 'descricao', 'requisitos', 'responsabilidades', 'diferenciais',
                         'tipo_contrato', 'modalidade', 'nivel', 'salario_min', 'salario_max',
                         'salario_visivel', 'beneficios', 'quantidade_vagas', 'urgente', 'status'];

            foreach ($allowed as $f) {
                if (isset($input[$f])) {
                    $fields[] = "{$f} = ?";
                    $params[] = $input[$f];
                }
            }

            if (empty($fields)) response(false, null, 'Nenhum campo para atualizar', 400);

            $fields[] = "updated_at = NOW()";
            $params[] = $id;

            $sql = "UPDATE om_rh_vagas SET " . implode(', ', $fields) . " WHERE id = ? RETURNING id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $updated = $stmt->fetch();

            if (!$updated) response(false, null, 'Vaga nao encontrada', 404);
            response(true, ['id' => $id], 'Vaga atualizada');
        }

        // ── Close Position ──────────────────────────────────────────
        if ($action === 'close_position') {
            $id = (int)($input['id'] ?? 0);
            if (!$id) response(false, null, 'ID obrigatorio', 400);

            $stmt = $db->prepare("
                UPDATE om_rh_vagas SET status = 'fechada', data_fechamento = CURRENT_DATE, updated_at = NOW()
                WHERE id = ? RETURNING id
            ");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) response(false, null, 'Vaga nao encontrada', 404);
            response(true, ['id' => $id], 'Vaga fechada');
        }

        // ── Add Candidate ───────────────────────────────────────────
        if ($action === 'add_candidate') {
            $vagaId = (int)($input['vaga_id'] ?? 0);
            $nome = trim($input['nome'] ?? '');
            $email = trim($input['email'] ?? '');
            $telefone = trim($input['telefone'] ?? '');
            $cpf = preg_replace('/[^0-9]/', '', $input['cpf'] ?? '');
            $linkedin = trim($input['linkedin'] ?? '');
            $pretensao = isset($input['pretensao_salarial']) ? (float)$input['pretensao_salarial'] : null;
            $disponibilidade = $input['disponibilidade_inicio'] ?? null;
            $comoConheceu = trim($input['como_conheceu'] ?? '');
            $carta = trim($input['carta_apresentacao'] ?? '');

            if (!$vagaId || empty($nome) || empty($email)) {
                response(false, null, 'vaga_id, nome e email obrigatorios', 400);
            }

            $stmt = $db->prepare("
                INSERT INTO om_rh_candidaturas
                    (vaga_id, nome, email, telefone, cpf, linkedin, carta_apresentacao,
                     pretensao_salarial, disponibilidade_inicio, como_conheceu,
                     etapa, status, recrutador_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'triagem', 'em_analise', ?, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([
                $vagaId, $nome, $email, $telefone, $cpf, $linkedin, $carta,
                $pretensao, $disponibilidade, $comoConheceu, $adminId
            ]);
            $newId = (int)$stmt->fetch()['id'];

            // Update count
            $db->prepare("UPDATE om_rh_vagas SET candidaturas_count = candidaturas_count + 1 WHERE id = ?")->execute([$vagaId]);

            response(true, ['id' => $newId], 'Candidato adicionado');
        }

        // ── Update Candidate ────────────────────────────────────────
        if ($action === 'update_candidate') {
            $id = (int)($input['id'] ?? 0);
            if (!$id) response(false, null, 'ID obrigatorio', 400);

            $fields = [];
            $params = [];
            $allowed = ['etapa', 'status', 'feedback', 'notas_internas', 'data_entrevista',
                         'avaliacao_entrevista', 'score_ia'];

            foreach ($allowed as $f) {
                if (isset($input[$f])) {
                    $fields[] = "{$f} = ?";
                    $params[] = $input[$f];
                }
            }

            if (empty($fields)) response(false, null, 'Nenhum campo para atualizar', 400);

            $fields[] = "updated_at = NOW()";
            $params[] = $id;

            $sql = "UPDATE om_rh_candidaturas SET " . implode(', ', $fields) . " WHERE id = ? RETURNING id, etapa, status";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $updated = $stmt->fetch();

            if (!$updated) response(false, null, 'Candidato nao encontrado', 404);

            // Log history
            try {
                $db->prepare("
                    INSERT INTO om_rh_application_history (application_id, action, details, performed_by, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ")->execute([
                    $id,
                    'stage_change',
                    json_encode(['etapa' => $input['etapa'] ?? null, 'status' => $input['status'] ?? null, 'feedback' => $input['feedback'] ?? null]),
                    $adminId
                ]);
            } catch (\Exception $e) {
                // non-fatal
            }

            response(true, $updated, 'Candidato atualizado');
        }

        // ── Hire Candidate — Full provisioning ──────────────────────
        if ($action === 'hire') {
            $candidateId = (int)($input['candidate_id'] ?? 0);
            if (!$candidateId) response(false, null, 'candidate_id obrigatorio', 400);

            // Get candidate
            $candStmt = $db->prepare("SELECT * FROM om_rh_candidaturas WHERE id = ?");
            $candStmt->execute([$candidateId]);
            $candidate = $candStmt->fetch();
            if (!$candidate) response(false, null, 'Candidato nao encontrado', 404);

            $displayName = $input['display_name'] ?? $candidate['nome'];
            $email = $input['email'] ?? $candidate['email'];
            $phone = $input['telefone'] ?? $candidate['telefone'] ?? '';
            $password = $input['password'] ?? 'SuperBora' . date('Y') . '!';
            $cargo = $input['cargo'] ?? 'Atendente de Suporte';
            $departamento = $input['departamento'] ?? 'Suporte';
            $salario = isset($input['salario']) ? (float)$input['salario'] : ($candidate['pretensao_salarial'] ? (float)$candidate['pretensao_salarial'] : null);
            $skills = $input['skills'] ?? ['atendimento', 'pedidos'];
            $extension = $input['extension'] ?? null;

            $db->beginTransaction();
            try {
                // 1. Create om_admins entry
                $hashedPw = password_hash($password, PASSWORD_BCRYPT);
                $adminStmt = $db->prepare("
                    INSERT INTO om_admins (name, email, password, phone, role, cargo, departamento, status, aprovado_rh, aprovado_por, aprovado_em, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'suporte', ?, ?, 1, 1, ?, NOW(), NOW(), NOW())
                    RETURNING admin_id
                ");
                $adminStmt->execute([$displayName, $email, $hashedPw, $phone, $cargo, $departamento, $adminId]);
                $newAdminId = (int)$adminStmt->fetch()['admin_id'];

                // 2. Create om_rh_employees entry
                $empStmt = $db->prepare("
                    INSERT INTO om_rh_employees
                        (full_name, email, phone, cpf, department, position, admission_date, salary, status, is_active, created_at, updated_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE, ?, 'ativo', 1, NOW(), NOW(), ?)
                    RETURNING id
                ");
                $empStmt->execute([
                    $displayName, $email, $phone,
                    $candidate['cpf'] ?? null,
                    $departamento, $cargo, $salario, $adminId
                ]);
                $rhEmployeeId = (int)$empStmt->fetch()['id'];

                // 3. Create om_callcenter_agents entry
                $agentStmt = $db->prepare("
                    INSERT INTO om_callcenter_agents
                        (admin_id, display_name, extension, status, skills, max_concurrent, rh_employee_id, created_at, updated_at)
                    VALUES (?, ?, ?, 'offline', ?, 3, ?, NOW(), NOW())
                    RETURNING id
                ");
                $skillsArr = is_array($skills) ? $skills : explode(',', $skills);
                $agentStmt->execute([
                    $newAdminId, $displayName, $extension,
                    '{' . implode(',', array_map(fn($s) => '"' . trim($s) . '"', $skillsArr)) . '}',
                    $rhEmployeeId
                ]);
                $agentId = (int)$agentStmt->fetch()['id'];

                // 4. Update candidate status
                $db->prepare("
                    UPDATE om_rh_candidaturas SET status = 'contratado', etapa = 'contratado', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$candidateId]);

                // 5. Create admission flow
                try {
                    $token = bin2hex(random_bytes(32));
                    $db->prepare("
                        INSERT INTO om_rh_admission_flows
                            (user_id, job_id, candidate_name, candidate_email, candidate_cpf, status, token, token_expires_at, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, 'completed', ?, NOW() + INTERVAL '7 days', ?, NOW())
                    ")->execute([
                        $rhEmployeeId, $candidate['vaga_id'], $displayName, $email,
                        $candidate['cpf'] ?? '00000000000', $token, $adminId
                    ]);
                } catch (\Exception $e) {
                    // non-fatal
                }

                // 6. Create onboarding tasks
                $onboardingTasks = [
                    ['Configurar acesso ao sistema', 'Criar login e permissoes no painel admin', 'acesso', 3],
                    ['Treinamento do sistema de pedidos', 'Aprender a usar o order builder', 'treinamento', 5],
                    ['Treinamento do softphone', 'Configurar e testar o softphone Twilio', 'treinamento', 5],
                    ['Conhecer protocolos de atendimento', 'Estudar scripts e protocolos do call center', 'treinamento', 7],
                    ['Setup do WhatsApp', 'Configurar acesso ao canal WhatsApp', 'acesso', 3],
                    ['Acompanhamento com supervisor', 'Primeiro dia de atendimento supervisionado', 'acompanhamento', 14],
                ];
                foreach ($onboardingTasks as $task) {
                    try {
                        $db->prepare("
                            INSERT INTO om_rh_onboarding_tasks
                                (rh_user_id, task_name, task_description, category, due_date, completed, priority, created_at)
                            VALUES (?, ?, ?, ?, CURRENT_DATE + INTERVAL '1 day' * ?, 0, 'alta', NOW())
                        ")->execute([$rhEmployeeId, $task[0], $task[1], $task[2], $task[3]]);
                    } catch (\Exception $e) {
                        // non-fatal
                    }
                }

                $db->commit();

                response(true, [
                    'admin_id' => $newAdminId,
                    'agent_id' => $agentId,
                    'rh_employee_id' => $rhEmployeeId,
                    'display_name' => $displayName,
                    'email' => $email,
                    'password' => $password,
                    'message' => 'Contratacao completa! Admin, agente e funcionario RH criados.',
                ]);

            } catch (\Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // ── Revoke Access ───────────────────────────────────────────
        if ($action === 'revoke_access') {
            $agentId = (int)($input['agent_id'] ?? 0);
            if (!$agentId) response(false, null, 'agent_id obrigatorio', 400);

            // Get agent info
            $agStmt = $db->prepare("SELECT * FROM om_callcenter_agents WHERE id = ?");
            $agStmt->execute([$agentId]);
            $agent = $agStmt->fetch();
            if (!$agent) response(false, null, 'Agente nao encontrado', 404);

            $db->beginTransaction();
            try {
                // Disable agent
                $db->prepare("UPDATE om_callcenter_agents SET status = 'offline', updated_at = NOW() WHERE id = ?")->execute([$agentId]);

                // Disable admin account
                $db->prepare("UPDATE om_admins SET status = 0, updated_at = NOW() WHERE admin_id = ?")->execute([$agent['admin_id']]);

                // Deactivate RH employee
                if ($agent['rh_employee_id']) {
                    $db->prepare("UPDATE om_rh_employees SET is_active = 0, status = 'desligado', resignation_date = CURRENT_DATE, updated_at = NOW() WHERE id = ?")->execute([$agent['rh_employee_id']]);
                }

                $db->commit();
                response(true, ['agent_id' => $agentId], 'Acesso revogado com sucesso');
            } catch (\Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        // ── List Active Agents (for management) ─────────────────────
        if ($action === 'list_agents') {
            $stmt = $db->query("
                SELECT a.id, a.admin_id, a.display_name, a.extension, a.status, a.skills,
                       a.max_concurrent, a.rh_employee_id, a.created_at,
                       adm.email, adm.phone, adm.status as admin_status, adm.last_login,
                       e.full_name as rh_name, e.department, e.position, e.admission_date, e.salary,
                       (SELECT COUNT(*) FROM om_callcenter_calls c WHERE c.agent_id = a.id AND c.created_at::date = CURRENT_DATE) as calls_today,
                       (SELECT COUNT(*) FROM om_callcenter_calls c WHERE c.agent_id = a.id AND c.status = 'completed') as total_calls
                FROM om_callcenter_agents a
                LEFT JOIN om_admins adm ON adm.admin_id = a.admin_id
                LEFT JOIN om_rh_employees e ON e.id = a.rh_employee_id
                ORDER BY a.status = 'online' DESC, a.display_name ASC
            ");
            $agents = $stmt->fetchAll();

            response(true, ['agents' => $agents]);
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Method not allowed', 405);

} catch (\Throwable $e) {
    error_log("[callcenter/hiring] Error: " . $e->getMessage());
    response(false, null, 'Erro interno: ' . $e->getMessage(), 500);
}
