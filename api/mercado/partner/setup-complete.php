<?php
/**
 * GET/POST /api/mercado/partner/setup-complete.php
 * Wizard de configuracao inicial do parceiro (primeiro login apos aprovacao)
 *
 * GET  - Retorna status atual de cada etapa do setup
 * POST - Salva dados de uma etapa especifica
 *        Body: { step: "logo|hours|bank|menu_done", data: { ... } }
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // Garantir que a coluna first_setup_complete exista
    try {
        $db->exec("ALTER TABLE om_market_partners ADD COLUMN first_setup_complete SMALLINT DEFAULT 0");
    } catch (Exception $e) {
        // coluna ja existe
    }

    // -------------------------------------------------------------------
    // GET - Retorna status de cada etapa do setup
    // -------------------------------------------------------------------
    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT logo, banner, horario_funcionamento,
                   bank_name, bank_agency, bank_account, pix_type, pix_key,
                   first_setup_complete
            FROM om_market_partners
            WHERE partner_id = ?
        ");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch();

        if (!$partner) {
            response(false, null, "Parceiro nao encontrado", 404);
        }

        $logoComplete  = !empty($partner['logo']);
        $hoursComplete = !empty($partner['horario_funcionamento']);
        $bankComplete  = !empty($partner['bank_name']);

        // Verificar se o parceiro ja possui pelo menos 1 produto (menu_done)
        $stmtMenu = $db->prepare("
            SELECT COUNT(*) FROM om_market_products WHERE partner_id = ?
        ");
        $stmtMenu->execute([$partnerId]);
        $productCount = (int)$stmtMenu->fetchColumn();
        $menuComplete = $productCount > 0;

        response(true, [
            'first_setup_complete' => (bool)$partner['first_setup_complete'],
            'steps' => [
                'logo' => [
                    'complete' => $logoComplete,
                    'data' => [
                        'logo'   => $partner['logo'],
                        'banner' => $partner['banner'],
                    ],
                ],
                'hours' => [
                    'complete' => $hoursComplete,
                    'data' => $partner['horario_funcionamento']
                        ? json_decode($partner['horario_funcionamento'], true)
                        : null,
                ],
                'bank' => [
                    'complete' => $bankComplete,
                    'data' => [
                        'bank_name'    => $partner['bank_name'],
                        'bank_agency'  => $partner['bank_agency'],
                        'bank_account' => $partner['bank_account'],
                        'pix_type'     => $partner['pix_type'],
                        'pix_key'      => $partner['pix_key'],
                    ],
                ],
                'menu_done' => [
                    'complete' => $menuComplete,
                    'product_count' => $productCount,
                ],
            ],
        ], "Status do setup");
    }

    // -------------------------------------------------------------------
    // POST - Salvar dados de uma etapa
    // -------------------------------------------------------------------
    if ($method === 'POST') {
        $input = getInput();

        $step = $input['step'] ?? null;
        $data = $input['data'] ?? [];

        $allowedSteps = ['logo', 'hours', 'bank', 'menu_done'];
        if (!$step || !in_array($step, $allowedSteps, true)) {
            response(false, null, "Step invalido. Use: logo, hours, bank ou menu_done", 400);
        }

        // Verificar se parceiro existe
        $stmtCheck = $db->prepare("SELECT partner_id FROM om_market_partners WHERE partner_id = ?");
        $stmtCheck->execute([$partnerId]);
        if (!$stmtCheck->fetch()) {
            response(false, null, "Parceiro nao encontrado", 404);
        }

        // Processar cada step
        switch ($step) {

            // ---- LOGO & BANNER ----
            case 'logo':
                $logo   = isset($data['logo'])   ? sanitizeOutput($data['logo'])   : null;
                $banner = isset($data['banner'])  ? sanitizeOutput($data['banner']) : null;

                if (empty($logo)) {
                    response(false, null, "Campo logo e obrigatorio", 400);
                }

                $stmt = $db->prepare("
                    UPDATE om_market_partners
                    SET logo = ?, banner = ?, updated_at = NOW()
                    WHERE partner_id = ?
                ");
                $stmt->execute([$logo, $banner, $partnerId]);
                break;

            // ---- HORARIO DE FUNCIONAMENTO ----
            case 'hours':
                $hours = $data['hours'] ?? $data;

                if (empty($hours) || !is_array($hours)) {
                    response(false, null, "Campo hours e obrigatorio (objeto com dias da semana)", 400);
                }

                // Validar estrutura: cada dia deve ter open e close
                $diasValidos = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
                foreach ($hours as $dia => $horario) {
                    if (!in_array($dia, $diasValidos, true)) {
                        response(false, null, "Dia invalido: $dia. Use: " . implode(', ', $diasValidos), 400);
                    }
                    if (!is_array($horario) || !isset($horario['open']) || !isset($horario['close'])) {
                        response(false, null, "Cada dia deve ter campos open e close", 400);
                    }
                    // Validar formato HH:MM
                    if (!preg_match('/^\d{2}:\d{2}$/', $horario['open']) ||
                        !preg_match('/^\d{2}:\d{2}$/', $horario['close'])) {
                        response(false, null, "Formato de horario invalido para $dia. Use HH:MM", 400);
                    }
                }

                $hoursJson = json_encode($hours, JSON_UNESCAPED_UNICODE);

                $stmt = $db->prepare("
                    UPDATE om_market_partners
                    SET horario_funcionamento = ?, updated_at = NOW()
                    WHERE partner_id = ?
                ");
                $stmt->execute([$hoursJson, $partnerId]);
                break;

            // ---- DADOS BANCARIOS ----
            case 'bank':
                $bankName    = isset($data['bank_name'])    ? sanitizeOutput($data['bank_name'])    : null;
                $bankAgency  = isset($data['bank_agency'])  ? preg_replace('/[^0-9\-]/', '', $data['bank_agency'])  : null;
                $bankAccount = isset($data['bank_account']) ? preg_replace('/[^0-9\-]/', '', $data['bank_account']) : null;
                $pixType     = isset($data['pix_type'])     ? sanitizeOutput($data['pix_type'])     : null;
                $pixKey      = isset($data['pix_key'])      ? sanitizeOutput($data['pix_key'])      : null;

                if (empty($bankName)) {
                    response(false, null, "Campo bank_name e obrigatorio", 400);
                }

                // Validar pix_type se fornecido
                $pixTypesValidos = ['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'];
                if ($pixType && !in_array($pixType, $pixTypesValidos, true)) {
                    response(false, null, "pix_type invalido. Use: " . implode(', ', $pixTypesValidos), 400);
                }

                $stmt = $db->prepare("
                    UPDATE om_market_partners
                    SET bank_name = ?, bank_agency = ?, bank_account = ?,
                        pix_type = ?, pix_key = ?, updated_at = NOW()
                    WHERE partner_id = ?
                ");
                $stmt->execute([$bankName, $bankAgency, $bankAccount, $pixType, $pixKey, $partnerId]);
                break;

            // ---- MENU DONE (marcacao) ----
            case 'menu_done':
                // Nenhuma atualizacao de coluna necessaria.
                // O menu_done e determinado pela existencia de produtos.
                // Este step apenas dispara a verificacao de conclusao abaixo.
                break;
        }

        // -----------------------------------------------------------
        // Verificar se todos os steps obrigatorios estao completos
        // -----------------------------------------------------------
        $stmtVerify = $db->prepare("
            SELECT logo, horario_funcionamento, bank_name
            FROM om_market_partners
            WHERE partner_id = ?
        ");
        $stmtVerify->execute([$partnerId]);
        $current = $stmtVerify->fetch();

        $allComplete = !empty($current['logo'])
                    && !empty($current['horario_funcionamento'])
                    && !empty($current['bank_name']);

        if ($allComplete) {
            $stmtFinish = $db->prepare("
                UPDATE om_market_partners
                SET first_setup_complete = 1, updated_at = NOW()
                WHERE partner_id = ?
            ");
            $stmtFinish->execute([$partnerId]);
        }

        response(true, [
            'step' => $step,
            'saved' => true,
            'first_setup_complete' => $allComplete,
        ], "Etapa '$step' salva com sucesso");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/setup-complete] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar setup", 500);
}
