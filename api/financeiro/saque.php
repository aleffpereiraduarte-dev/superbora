<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * API: Solicitar Saque
 * POST /api/financeiro/saque.php
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticação de Shopper ou Motorista
 *
 * Body: {
 *   "valor": 50.00,
 *   "pix_tipo": "cpf",
 *   "pix_chave": "12345678900"
 * }
 *
 * SEGURANÇA:
 * - ✅ Autenticação obrigatória (shopper ou motorista)
 * - ✅ Verificação de saldo DENTRO da transação
 * - ✅ Lock para evitar race condition (saques duplos)
 * - ✅ Prepared statements (sem SQL injection)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/config/auth.php';

try {
    $pdo = getDB();

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICAÇÃO - Detectar tipo de usuário do token
    // ═══════════════════════════════════════════════════════════════════
    $auth = om_auth();
    $token = $auth->getTokenFromRequest();

    if (!$token) {
        echo json_encode(['success' => false, 'error' => 'Token de autenticação obrigatório', 'code' => 'UNAUTHORIZED']);
        http_response_code(401);
        exit;
    }

    $payload = $auth->validateToken($token);
    if (!$payload) {
        echo json_encode(['success' => false, 'error' => 'Token inválido ou expirado', 'code' => 'UNAUTHORIZED']);
        http_response_code(401);
        exit;
    }

    $tipo = $payload['type']; // 'shopper' ou 'motorista'
    $id = $payload['uid'];

    // Validar que é um tipo permitido para saque
    if (!in_array($tipo, ['shopper', 'motorista'])) {
        echo json_encode(['success' => false, 'error' => 'Tipo de usuário não pode solicitar saque']);
        exit;
    }

    // Verificar se shopper foi aprovado pelo RH
    if ($tipo === 'shopper' && !$auth->isShopperApproved($id)) {
        echo json_encode(['success' => false, 'error' => 'Seu cadastro ainda não foi aprovado pelo RH']);
        exit;
    }

    $valor = floatval($input['valor'] ?? 0);
    $pix_tipo = $input['pix_tipo'] ?? null;
    $pix_chave = trim($input['pix_chave'] ?? '');

    // ═══════════════════════════════════════════════════════════════════
    // VALIDAÇÕES DE ENTRADA
    // ═══════════════════════════════════════════════════════════════════
    $valor_minimo = 20.00;
    if ($valor < $valor_minimo) {
        echo json_encode(['success' => false, 'error' => "Valor mínimo para saque: R$ " . number_format($valor_minimo, 2, ',', '.')]);
        exit;
    }

    if (!$pix_chave) {
        echo json_encode(['success' => false, 'error' => 'Chave PIX obrigatória']);
        exit;
    }

    // Validar tipo de chave PIX
    $tipos_pix_validos = ['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'];
    if ($pix_tipo && !in_array($pix_tipo, $tipos_pix_validos)) {
        echo json_encode(['success' => false, 'error' => 'Tipo de chave PIX inválido']);
        exit;
    }

    // Determinar tabelas baseado no tipo (whitelist para evitar SQL injection)
    $tabela_saldo = $tipo === 'motorista' ? 'om_motorista_saldo' : 'om_shopper_saldo';
    $tabela_wallet = $tipo === 'motorista' ? 'om_motorista_wallet' : 'om_shopper_wallet';
    $campo_id = $tipo === 'motorista' ? 'motorista_id' : 'shopper_id';

    // ═══════════════════════════════════════════════════════════════════
    // TRANSAÇÃO COM LOCKS - Verificações DENTRO da transação
    // ═══════════════════════════════════════════════════════════════════
    $pdo->beginTransaction();

    try {
        // LOCK no registro de saldo para evitar race condition
        $stmt = $pdo->prepare("SELECT * FROM $tabela_saldo WHERE $campo_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $saldo_row = $stmt->fetch();

        $saldo = floatval($saldo_row['saldo_disponivel'] ?? 0);

        if ($valor > $saldo) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Saldo insuficiente',
                'saldo_disponivel' => $saldo,
                'valor_solicitado' => $valor
            ]);
            exit;
        }

        // Verificar se já tem saque pendente (dentro da transação)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM om_saques
            WHERE usuario_tipo = ? AND usuario_id = ?
            AND status IN ('solicitado', 'em_analise', 'aprovado', 'processando')
            FOR UPDATE
        ");
        $stmt->execute([$tipo, $id]);

        if ($stmt->fetchColumn() > 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Você já tem um saque pendente. Aguarde o processamento.']);
            exit;
        }

        // Calcular taxa (grátis por enquanto, mas preparado para futuro)
        $taxa = 0;
        $valor_liquido = $valor - $taxa;

        // Criar solicitação de saque
        $stmt = $pdo->prepare("
            INSERT INTO om_saques
            (usuario_tipo, usuario_id, valor_solicitado, taxa_saque, valor_liquido, pix_tipo, pix_chave, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'solicitado', NOW())
        ");
        $stmt->execute([$tipo, $id, $valor, $taxa, $valor_liquido, $pix_tipo, $pix_chave]);
        $saque_id = $pdo->lastInsertId();

        // Bloquear saldo (mover de disponível para bloqueado)
        $stmt = $pdo->prepare("
            UPDATE $tabela_saldo SET
                saldo_disponivel = saldo_disponivel - ?,
                saldo_bloqueado = saldo_bloqueado + ?
            WHERE $campo_id = ?
        ");
        $stmt->execute([$valor, $valor, $id]);

        // Buscar novo saldo
        $stmt = $pdo->prepare("SELECT saldo_disponivel, saldo_bloqueado FROM $tabela_saldo WHERE $campo_id = ?");
        $stmt->execute([$id]);
        $novo_saldo = $stmt->fetch();

        // Registrar na wallet
        $stmt = $pdo->prepare("
            INSERT INTO $tabela_wallet
            ($campo_id, tipo, valor, saldo_anterior, saldo_posterior, referencia_tipo, referencia_id, descricao, status, created_at)
            VALUES (?, 'saque', ?, ?, ?, 'om_saques', ?, 'Solicitação de saque', 'pendente', NOW())
        ");
        $stmt->execute([$id, -$valor, $saldo, $novo_saldo['saldo_disponivel'], $saque_id]);

        $pdo->commit();

        // Log de auditoria
        logFinanceiroAudit(
            'create',
            'saque',
            $saque_id,
            null,
            ['valor' => $valor, 'pix_tipo' => $pix_tipo],
            "Solicitação de saque de R$ " . number_format($valor, 2, ',', '.')
        );

        echo json_encode([
            'success' => true,
            'message' => 'Saque solicitado com sucesso!',
            'saque' => [
                'id' => $saque_id,
                'valor_solicitado' => $valor,
                'taxa' => $taxa,
                'valor_liquido' => $valor_liquido,
                'pix' => [
                    'tipo' => $pix_tipo,
                    'chave' => substr($pix_chave, 0, 4) . '****' . substr($pix_chave, -2)
                ],
                'status' => 'solicitado',
                'previsao' => 'Até 24 horas úteis'
            ],
            'novo_saldo' => [
                'disponivel' => floatval($novo_saldo['saldo_disponivel']),
                'bloqueado' => floatval($novo_saldo['saldo_bloqueado'])
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[saque.php] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar saque. Tente novamente.']);
}
