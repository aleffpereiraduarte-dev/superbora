<?php
/**
 * POST /api/pagamento/pix/gerar.php
 * Gerar PIX com validações e segurança
 *
 * MELHORADO:
 * - Rate limiting
 * - Validação mais rigorosa
 * - CORS restrito
 * - Log de transações
 */

// CORS restrito
$allowedOrigins = ['https://onemundo.com.br', 'https://www.onemundo.com.br'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $origin);
} elseif (empty($origin) || strpos($origin, 'localhost') !== false) {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 2) . '/rate-limit/RateLimiter.php';

// Rate limiting: 10 PIX por minuto por IP
if (!RateLimiter::check(10, 60)) {
    exit;
}

try {
    $db = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $input = json_decode(file_get_contents("php://input"), true) ?: $_POST;

    // Sanitizar e validar entrada
    $tipo = preg_replace('/[^a-z_]/', '', $input["tipo"] ?? "pedido_mercado");
    $origem_id = (int)($input["origem_id"] ?? 0);
    $valor = floatval($input["valor"] ?? 0);
    $customer_id = (int)($input["customer_id"] ?? 0);

    // Validações
    $erros = [];

    if (!$origem_id) {
        $erros[] = "ID da origem é obrigatório";
    }

    if ($valor <= 0) {
        $erros[] = "Valor deve ser maior que zero";
    }

    if ($valor < 1) {
        $erros[] = "Valor mínimo para PIX é R$ 1,00";
    }

    if ($valor > 50000) {
        $erros[] = "Valor máximo para PIX é R$ 50.000,00";
    }

    // Validar tipo permitido
    $tiposPermitidos = ['pedido_mercado', 'corrida', 'entrega', 'assinatura', 'recarga'];
    if (!in_array($tipo, $tiposPermitidos)) {
        $erros[] = "Tipo de pagamento inválido";
    }

    if (!empty($erros)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => $erros[0],
            "errors" => $erros
        ]);
        exit;
    }

    // Verificar se já existe PIX pendente para mesma origem
    $stmt = $db->prepare("SELECT payment_id, pix_copia_cola, pix_expiracao FROM om_payments
                          WHERE tipo_origem = ? AND origem_id = ? AND status = 'pendente'
                          AND pix_expiracao > NOW()
                          ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$tipo, $origem_id]);
    $existente = $stmt->fetch();

    if ($existente) {
        // Retornar PIX existente
        echo json_encode([
            "success" => true,
            "message" => "PIX já gerado. Use o código abaixo.",
            "data" => [
                "payment_id" => $existente["payment_id"],
                "pix_copia_cola" => $existente["pix_copia_cola"],
                "expiracao" => $existente["pix_expiracao"],
                "reutilizado" => true
            ]
        ]);
        exit;
    }

    // Gerar novo PIX
    $txid = strtoupper(bin2hex(random_bytes(16)));
    $expiracao = date("Y-m-d H:i:s", strtotime("+30 minutes"));

    // Payload PIX (simplificado - em produção usar biblioteca certificada)
    // ATENÇÃO: Este é um payload de demonstração, não usar em produção sem certificação
    $chave_pix = "pagamentos@onemundo.com.br"; // Deve vir de config segura
    $pix_payload = "00020126580014br.gov.bcb.pix0136" . $txid . "520400005303986540" .
                   number_format($valor, 2, ".", "") . "5802BR5913OneMundo6014Gov Valadares62070503***6304";

    // Inserir no banco (prepared statement)
    $stmt = $db->prepare("INSERT INTO om_payments
                          (tipo_origem, origem_id, customer_id, valor_bruto, valor_liquido, metodo, gateway, gateway_id, pix_copia_cola, pix_expiracao, status, created_at)
                          VALUES (?, ?, ?, ?, ?, 'pix', 'pagarme', ?, ?, ?, 'pendente', NOW())");
    $stmt->execute([$tipo, $origem_id, $customer_id, $valor, $valor, $txid, $pix_payload, $expiracao]);

    $payment_id = $db->lastInsertId();

    // Log da transação
    logPayment($payment_id, $tipo, $origem_id, $valor, 'pix_gerado');

    echo json_encode([
        "success" => true,
        "message" => "PIX gerado! Pague em até 30 minutos.",
        "data" => [
            "payment_id" => (int)$payment_id,
            "txid" => $txid,
            "valor" => $valor,
            "valor_formatado" => "R$ " . number_format($valor, 2, ",", "."),
            "pix_copia_cola" => $pix_payload,
            "qr_code_base64" => "", // Implementar geração de QR Code
            "expiracao" => $expiracao,
            "expiracao_minutos" => 30
        ]
    ]);

} catch (Exception $e) {
    error_log("PIX gerar error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erro ao gerar PIX. Tente novamente."
    ]);
}

/**
 * Log de pagamentos para auditoria
 */
function logPayment($payment_id, $tipo, $origem_id, $valor, $evento) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $logEntry = date('Y-m-d H:i:s') . " | " .
                "Payment: $payment_id | " .
                "Tipo: $tipo | " .
                "Origem: $origem_id | " .
                "Valor: R$ " . number_format($valor, 2) . " | " .
                "Evento: $evento | " .
                "IP: $ip\n";

    $logFile = '/var/log/onemundo/payments.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
