<?php
/**
 * GET/POST/DELETE /api/mercado/partner/campanhas.php
 * Campaign management for partners (push, whatsapp, sms, email)
 *
 * GET:                 List campaigns
 * GET action=templates: Get pre-built message templates
 * POST action=create:  Create a new campaign
 * POST action=send:    Send/trigger a campaign
 * POST action=cancel:  Cancel a scheduled campaign
 * DELETE:              Delete a campaign
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];
    $method = $_SERVER["REQUEST_METHOD"];

    // Tables created via migration

    // ===== GET =====
    if ($method === "GET") {
        $action = $_GET['action'] ?? '';

        // ----- Pre-built templates -----
        if ($action === 'templates') {
            $templates = [
                [
                    "id" => "welcome",
                    "nome" => "Boas-vindas",
                    "descricao" => "Mensagem para novos clientes",
                    "titulo" => "Bem-vindo a {loja}!",
                    "mensagem" => "Ola! Que bom ter voce aqui. Como boas-vindas, preparamos uma oferta especial so pra voce. Aproveite!",
                    "segmento_sugerido" => "new",
                ],
                [
                    "id" => "comeback",
                    "nome" => "Sentimos sua falta",
                    "descricao" => "Re-engajar clientes inativos",
                    "titulo" => "Sentimos sua falta!",
                    "mensagem" => "Faz tempo que voce nao nos visita! Preparamos uma oferta especial para seu retorno. Volte e aproveite!",
                    "segmento_sugerido" => "inactive",
                ],
                [
                    "id" => "birthday",
                    "nome" => "Aniversario",
                    "descricao" => "Parabenizar no aniversario",
                    "titulo" => "Feliz Aniversario!",
                    "mensagem" => "Feliz aniversario! Para comemorar, temos um presente especial para voce. Faca um pedido hoje e ganhe desconto!",
                    "segmento_sugerido" => "all",
                ],
                [
                    "id" => "promo",
                    "nome" => "Promocao Especial",
                    "descricao" => "Divulgar uma promocao",
                    "titulo" => "Promocao Imperdivel!",
                    "mensagem" => "Corre que e por tempo limitado! Temos ofertas incriveis esperando por voce. Nao perca!",
                    "segmento_sugerido" => "all",
                ],
                [
                    "id" => "new_product",
                    "nome" => "Novo Produto",
                    "descricao" => "Anunciar novidade no cardapio",
                    "titulo" => "Novidade no cardapio!",
                    "mensagem" => "Temos uma novidade deliciosa esperando por voce! Venha experimentar nosso novo produto. Voce vai adorar!",
                    "segmento_sugerido" => "returning",
                ],
                [
                    "id" => "weekend",
                    "nome" => "Especial Fim de Semana",
                    "descricao" => "Ofertas de fim de semana",
                    "titulo" => "Especial de Fim de Semana!",
                    "mensagem" => "O fim de semana chegou e trouxemos ofertas especiais pra voce! Peca agora e aproveite precos incriveis.",
                    "segmento_sugerido" => "all",
                ],
                [
                    "id" => "loyalty_reward",
                    "nome" => "Recompensa Fidelidade",
                    "descricao" => "Agradecer clientes fieis",
                    "titulo" => "Voce e especial para nos!",
                    "mensagem" => "Obrigado por ser um cliente fiel! Como forma de agradecimento, preparamos um desconto exclusivo so pra voce.",
                    "segmento_sugerido" => "champions",
                ],
            ];

            response(true, ["templates" => $templates], "Templates carregados");
        }

        // ----- List campaigns -----
        else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $status = trim($_GET['status'] ?? '');
            $tipo = trim($_GET['tipo'] ?? '');

            $where = ["c.partner_id = ?"];
            $params = [$partner_id];

            if ($status !== '' && in_array($status, ['rascunho', 'agendada', 'enviando', 'enviada', 'cancelada'], true)) {
                $where[] = "c.status = ?";
                $params[] = $status;
            }

            if ($tipo !== '' && in_array($tipo, ['push', 'whatsapp', 'sms', 'email'], true)) {
                $where[] = "c.tipo = ?";
                $params[] = $tipo;
            }

            $whereSQL = implode(" AND ", $where);

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_campaigns c WHERE {$whereSQL}");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            $params[] = $limit;
            $params[] = $offset;
            $stmt = $db->prepare("
                SELECT
                    c.*,
                    cp.code as cupom_code,
                    cp.discount_type as cupom_discount_type,
                    cp.discount_value as cupom_discount_value
                FROM om_market_campaigns c
                LEFT JOIN om_partner_coupons cp ON cp.id = c.cupom_id AND cp.partner_id = c.partner_id
                WHERE {$whereSQL}
                ORDER BY c.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($params);
            $campaigns = $stmt->fetchAll();

            $items = [];
            foreach ($campaigns as $camp) {
                $items[] = [
                    "id" => (int)$camp['id'],
                    "nome" => $camp['nome'],
                    "tipo" => $camp['tipo'],
                    "segmento_alvo" => $camp['segmento_alvo'],
                    "titulo" => $camp['titulo'],
                    "mensagem" => $camp['mensagem'],
                    "status" => $camp['status'],
                    "agendado_para" => $camp['agendado_para'],
                    "enviados" => (int)$camp['enviados'],
                    "abertos" => (int)$camp['abertos'],
                    "convertidos" => (int)$camp['convertidos'],
                    "cupom_id" => $camp['cupom_id'] ? (int)$camp['cupom_id'] : null,
                    "cupom_code" => $camp['cupom_code'] ?: null,
                    "cupom_desconto" => $camp['cupom_discount_value']
                        ? ($camp['cupom_discount_type'] === 'percent'
                            ? $camp['cupom_discount_value'] . '%'
                            : 'R$ ' . number_format((float)$camp['cupom_discount_value'], 2, ',', '.'))
                        : null,
                    "created_at" => $camp['created_at'],
                    "sent_at" => $camp['sent_at'],
                ];
            }

            // Overall stats
            $stmtStats = $db->prepare("
                SELECT
                    COUNT(*) as total_campaigns,
                    COALESCE(SUM(enviados), 0) as total_enviados,
                    COALESCE(SUM(abertos), 0) as total_abertos,
                    COALESCE(SUM(convertidos), 0) as total_convertidos
                FROM om_market_campaigns
                WHERE partner_id = ?
            ");
            $stmtStats->execute([$partner_id]);
            $stats = $stmtStats->fetch();

            $avgConversion = (int)$stats['total_enviados'] > 0
                ? round(((int)$stats['total_convertidos'] / (int)$stats['total_enviados']) * 100, 1)
                : 0;

            $pages = $total > 0 ? (int)ceil($total / $limit) : 1;

            response(true, [
                "items" => $items,
                "stats" => [
                    "total_campaigns" => (int)$stats['total_campaigns'],
                    "total_enviados" => (int)$stats['total_enviados'],
                    "total_abertos" => (int)$stats['total_abertos'],
                    "total_convertidos" => (int)$stats['total_convertidos'],
                    "avg_conversion" => $avgConversion,
                ],
                "pagination" => [
                    "total" => $total,
                    "page" => $page,
                    "pages" => $pages,
                    "limit" => $limit,
                ],
            ], "Campanhas listadas");
        }
    }

    // ===== POST =====
    elseif ($method === "POST") {
        $input = getInput();
        $action = $input['action'] ?? '';

        // ----- Create campaign -----
        if ($action === 'create') {
            $nome = trim($input['nome'] ?? '');
            $tipo = trim($input['tipo'] ?? '');
            $segmento_alvo = trim($input['segmento_alvo'] ?? 'all');
            $titulo = trim($input['titulo'] ?? '');
            $mensagem = trim($input['mensagem'] ?? '');
            $agendado_para = trim($input['agendado_para'] ?? '');
            $cupom_id = (int)($input['cupom_id'] ?? 0);

            // Validations
            if (empty($nome)) {
                response(false, null, "Nome da campanha obrigatorio", 400);
            }

            if (strlen($nome) > 255) {
                response(false, null, "Nome da campanha muito longo (max 255 caracteres)", 400);
            }

            if (!in_array($tipo, ['push', 'whatsapp', 'sms', 'email'], true)) {
                response(false, null, "Tipo invalido (push, whatsapp, sms, email)", 400);
            }

            if (!in_array($segmento_alvo, ['all', 'new', 'returning', 'inactive', 'champions'], true)) {
                response(false, null, "Segmento alvo invalido", 400);
            }

            if (empty($mensagem)) {
                response(false, null, "Mensagem obrigatoria", 400);
            }

            if (strlen($mensagem) > 5000) {
                response(false, null, "Mensagem muito longa (max 5000 caracteres)", 400);
            }

            if (strlen($titulo) > 255) {
                response(false, null, "Titulo muito longo (max 255 caracteres)", 400);
            }

            // Validate coupon ownership if provided
            if ($cupom_id > 0) {
                $stmtCoupon = $db->prepare("SELECT id FROM om_partner_coupons WHERE id = ? AND partner_id = ?");
                $stmtCoupon->execute([$cupom_id, $partner_id]);
                if (!$stmtCoupon->fetch()) {
                    response(false, null, "Cupom nao encontrado", 400);
                }
            }

            // Determine status
            $status = 'rascunho';
            if (!empty($agendado_para)) {
                $scheduledTime = strtotime($agendado_para);
                if (!$scheduledTime || $scheduledTime < time()) {
                    response(false, null, "Data de agendamento invalida ou no passado", 400);
                }
                $status = 'agendada';
            }

            $stmt = $db->prepare("
                INSERT INTO om_market_campaigns
                    (partner_id, nome, tipo, segmento_alvo, titulo, mensagem, cupom_id, status, agendado_para, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                RETURNING id
            ");
            $stmt->execute([
                $partner_id, $nome, $tipo, $segmento_alvo,
                $titulo ?: null, $mensagem,
                $cupom_id > 0 ? $cupom_id : null,
                $status,
                !empty($agendado_para) ? $agendado_para : null,
            ]);
            $newId = (int)$stmt->fetchColumn();

            om_audit()->log(OmAudit::ACTION_CREATE, 'campaign', $newId, null,
                ['nome' => $nome, 'tipo' => $tipo, 'segmento' => $segmento_alvo],
                "Campanha criada: {$nome}", 'partner', $partner_id);

            response(true, ["id" => $newId, "status" => $status], "Campanha criada");
        }

        // ----- Send campaign -----
        elseif ($action === 'send') {
            $campaign_id = (int)($input['campaign_id'] ?? 0);

            if (!$campaign_id) {
                response(false, null, "ID da campanha obrigatorio", 400);
            }

            // Check ownership and status
            $stmtCheck = $db->prepare("
                SELECT id, nome, tipo, segmento_alvo, status
                FROM om_market_campaigns
                WHERE id = ? AND partner_id = ?
            ");
            $stmtCheck->execute([$campaign_id, $partner_id]);
            $campaign = $stmtCheck->fetch();

            if (!$campaign) {
                response(false, null, "Campanha nao encontrada", 404);
            }

            if (in_array($campaign['status'], ['enviada', 'enviando', 'cancelada'], true)) {
                response(false, null, "Campanha ja foi " . $campaign['status'], 400);
            }

            // Count target audience
            $audienceCount = getAudienceCount($db, $partner_id, $campaign['segmento_alvo']);

            // Update campaign status
            $stmt = $db->prepare("
                UPDATE om_market_campaigns
                SET status = 'enviada', enviados = ?, sent_at = NOW()
                WHERE id = ? AND partner_id = ?
            ");
            $stmt->execute([$audienceCount, $campaign_id, $partner_id]);

            om_audit()->log(OmAudit::ACTION_UPDATE, 'campaign', $campaign_id, null,
                ['action' => 'send', 'enviados' => $audienceCount],
                "Campanha #{$campaign_id} enviada para {$audienceCount} clientes", 'partner', $partner_id);

            response(true, [
                "id" => $campaign_id,
                "enviados" => $audienceCount,
                "status" => "enviada"
            ], "Campanha enviada para {$audienceCount} clientes");
        }

        // ----- Cancel campaign -----
        elseif ($action === 'cancel') {
            $campaign_id = (int)($input['campaign_id'] ?? 0);

            if (!$campaign_id) {
                response(false, null, "ID da campanha obrigatorio", 400);
            }

            $stmtCheck = $db->prepare("
                SELECT id, status FROM om_market_campaigns WHERE id = ? AND partner_id = ?
            ");
            $stmtCheck->execute([$campaign_id, $partner_id]);
            $campaign = $stmtCheck->fetch();

            if (!$campaign) {
                response(false, null, "Campanha nao encontrada", 404);
            }

            if ($campaign['status'] === 'enviada') {
                response(false, null, "Nao e possivel cancelar uma campanha ja enviada", 400);
            }

            if ($campaign['status'] === 'cancelada') {
                response(false, null, "Campanha ja esta cancelada", 400);
            }

            $stmt = $db->prepare("
                UPDATE om_market_campaigns SET status = 'cancelada' WHERE id = ? AND partner_id = ?
            ");
            $stmt->execute([$campaign_id, $partner_id]);

            om_audit()->log(OmAudit::ACTION_UPDATE, 'campaign', $campaign_id, null,
                ['action' => 'cancel'],
                "Campanha #{$campaign_id} cancelada", 'partner', $partner_id);

            response(true, ["id" => $campaign_id, "status" => "cancelada"], "Campanha cancelada");
        }

        else {
            response(false, null, "Acao invalida", 400);
        }
    }

    // ===== DELETE =====
    elseif ($method === "DELETE") {
        $id = (int)($_GET['id'] ?? 0);

        if (!$id) {
            $input = getInput();
            $id = (int)($input['id'] ?? 0);
        }

        if (!$id) {
            response(false, null, "ID da campanha obrigatorio", 400);
        }

        $stmtCheck = $db->prepare("SELECT id, nome, status FROM om_market_campaigns WHERE id = ? AND partner_id = ?");
        $stmtCheck->execute([$id, $partner_id]);
        $campaign = $stmtCheck->fetch();

        if (!$campaign) {
            response(false, null, "Campanha nao encontrada", 404);
        }

        if ($campaign['status'] === 'enviando') {
            response(false, null, "Nao e possivel excluir uma campanha em envio", 400);
        }

        $stmt = $db->prepare("DELETE FROM om_market_campaigns WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partner_id]);

        om_audit()->log(OmAudit::ACTION_DELETE, 'campaign', $id, null, null,
            "Campanha #{$id} ({$campaign['nome']}) excluida", 'partner', $partner_id);

        response(true, ["id" => $id], "Campanha excluida");
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/campanhas] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

// ===== Helper Functions =====

function ensureCampaignTables(PDO $db): void {
    // No-op: tables created via migration
    return;
}

function getAudienceCount(PDO $db, int $partner_id, string $segment): int {
    // Count customers who have ordered from this partner, filtered by segment
    $baseQuery = "
        SELECT COUNT(DISTINCT o.customer_id)
        FROM om_market_orders o
        WHERE o.partner_id = ?
    ";

    switch ($segment) {
        case 'new':
            // Customers with only 1 order
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM (
                    SELECT o.customer_id
                    FROM om_market_orders o
                    WHERE o.partner_id = ?
                    GROUP BY o.customer_id
                    HAVING COUNT(*) = 1
                ) sub
            ");
            $stmt->execute([$partner_id]);
            return (int)$stmt->fetchColumn();

        case 'returning':
            // Customers with 2+ orders
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM (
                    SELECT o.customer_id
                    FROM om_market_orders o
                    WHERE o.partner_id = ?
                    GROUP BY o.customer_id
                    HAVING COUNT(*) >= 2
                ) sub
            ");
            $stmt->execute([$partner_id]);
            return (int)$stmt->fetchColumn();

        case 'inactive':
            // Customers who haven't ordered in 30+ days
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM (
                    SELECT o.customer_id
                    FROM om_market_orders o
                    WHERE o.partner_id = ?
                    GROUP BY o.customer_id
                    HAVING MAX(o.created_at) < NOW() - INTERVAL '30 days'
                ) sub
            ");
            $stmt->execute([$partner_id]);
            return (int)$stmt->fetchColumn();

        case 'champions':
            // Top 20% customers by order count (at least 5 orders)
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM (
                    SELECT o.customer_id
                    FROM om_market_orders o
                    WHERE o.partner_id = ?
                    GROUP BY o.customer_id
                    HAVING COUNT(*) >= 5
                ) sub
            ");
            $stmt->execute([$partner_id]);
            return (int)$stmt->fetchColumn();

        default: // 'all'
            $stmt = $db->prepare($baseQuery);
            $stmt->execute([$partner_id]);
            return (int)$stmt->fetchColumn();
    }
}
