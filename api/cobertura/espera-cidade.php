<?php
/**
 * API - Lista de Espera por Cidade
 *
 * POST /api/cobertura/espera-cidade.php
 * Body: { cep, city, state }
 * Cadastra interesse em receber cobertura
 */
require_once dirname(__DIR__) . '/includes/cors.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(__DIR__)) . '/database.php';

try {
    $pdo = getConnection();

    // Criar tabela se nao existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_city_interest (
        id SERIAL PRIMARY KEY,
        city VARCHAR(100) NOT NULL,
        state CHAR(2) NOT NULL,
        cep VARCHAR(10) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($method === 'POST') {
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

        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

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

    // GET - Estatisticas
    if ($method === 'GET') {
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
