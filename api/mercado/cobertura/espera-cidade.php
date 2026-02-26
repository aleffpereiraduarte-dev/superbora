<?php
/**
 * API - Lista de Espera por Cidade
 *
 * POST /api/cobertura/espera-cidade.php
 * Body: { cep, city, state }
 * Cadastra interesse em receber cobertura
 */
require_once dirname(__DIR__) . '/config/database.php';
setCorsHeaders();

try {
    $pdo = getDB();

    // Table om_city_interest created via migration

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($method === 'POST') {
        // SECURITY: Rate limiting — max 5 POSTs per IP per hour
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!filter_var($clientIp, FILTER_VALIDATE_IP)) $clientIp = '0.0.0.0';
        $rlStmt = $pdo->prepare("SELECT COUNT(*) FROM om_city_interest WHERE ip_address = ? AND created_at > NOW() - INTERVAL '1 hour'");
        $rlStmt->execute([$clientIp]);
        if ((int)$rlStmt->fetchColumn() >= 5) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Muitas requisicoes. Aguarde 1 hora.']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $cep = preg_replace('/\D/', '', $input['cep'] ?? '');
        $city = trim($input['city'] ?? '');
        $state = strtoupper(trim($input['state'] ?? ''));

        // Se nao tem city/state, tentar buscar pelo CEP no ViaCEP
        if ((!$city || !$state) && strlen($cep) === 8) {
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $viaCep = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, $ctx);
            if ($viaCep) {
                $data = json_decode($viaCep, true);
                if ($data && empty($data['erro'])) {
                    $city = $city ?: ($data['localidade'] ?? '');
                    $state = $state ?: ($data['uf'] ?? '');
                }
            }
        }

        if (!$city && !$cep) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Informe CEP ou cidade']);
            exit;
        }

        // Normalizar cidade
        if ($city) {
            $city = mb_convert_case($city, MB_CASE_TITLE, 'UTF-8');
        }

        $ipAddress = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ipAddress && !filter_var($ipAddress, FILTER_VALIDATE_IP)) $ipAddress = null;

        // Inserir interesse
        $stmt = $pdo->prepare("
            INSERT INTO om_city_interest (city, state, cep, ip_address)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$city ?: 'Desconhecida', $state ?: '--', $cep ?: null, $ipAddress]);

        // Contar interessados na cidade
        $totalInterested = 0;
        if ($city) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_city_interest WHERE city = ? AND state = ?");
            $stmt->execute([$city, $state]);
            $totalInterested = (int)$stmt->fetchColumn();
        }

        echo json_encode([
            'success' => true,
            'message' => "Voce esta na lista de espera! Avisaremos quando chegarmos em {$city}/{$state}.",
            'city' => $city,
            'state' => $state,
            'total_interested' => $totalInterested,
        ]);
        exit;
    }

    // GET - Estatisticas (requer admin auth)
    if ($method === 'GET') {
        // SECURITY: Require admin auth for business intelligence data
        require_once dirname(__DIR__) . '/config/auth.php';
        try {
            requireAdminAuth();
        } catch (Exception $authErr) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Autenticacao admin necessaria']);
            exit;
        }
        $stmt = $pdo->query("
            SELECT city, state, COUNT(*) as total
            FROM om_city_interest
            WHERE city != 'Desconhecida'
            GROUP BY city, state
            ORDER BY total DESC
            LIMIT 20
        ");
        $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'cities' => $cities,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);

} catch (Exception $e) {
    http_response_code(500);
    error_log("[cobertura/espera-cidade] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor']);
}
