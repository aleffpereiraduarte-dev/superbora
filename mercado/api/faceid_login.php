<?php
require_once __DIR__ . '/../config/database.php';
/**
 * Face ID Login API - OneMundo Mercado
 * Usa os descritores faciais do site principal para login unificado
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();

// Threshold para match de rosto
$MATCH_THRESHOLD = 0.5;

$pdo = getPDO();

// Receber dados
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$face_descriptor = $data['face_descriptor'] ?? [];
$quality_score = $data['quality_score'] ?? 0.5;

// Validar descriptor
if (empty($face_descriptor) || count($face_descriptor) !== 128) {
    die(json_encode(['success' => false, 'message' => 'Dados faciais inválidos']));
}

// Função para calcular distância euclidiana
function calcDistance($d1, $d2) {
    if (count($d1) !== 128 || count($d2) !== 128) return 1;

    $sum = 0;
    for ($i = 0; $i < 128; $i++) {
        $diff = $d1[$i] - $d2[$i];
        $sum += $diff * $diff;
    }

    return sqrt($sum);
}

// Buscar usuários com Face ID no site principal
$stmt = $pdo->query("
    SELECT f.*, c.customer_id, c.firstname, c.lastname, c.email, c.telephone
    FROM oc_om_face_data f
    JOIN oc_customer c ON f.customer_id = c.customer_id
    WHERE f.status = '1' AND c.status = '1'
");

$match = null;
$best_dist = 1;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stored = json_decode($row['avg_descriptor'] ?: $row['face_descriptor'], true);
    if (!$stored) continue;

    $dist = calcDistance($face_descriptor, $stored);

    // Threshold adaptativo baseado na confiança
    $conf = $row['confidence_score'] ?? 0.5;
    $threshold = 0.5 + (0.1 * (1 - $conf));

    if ($dist < $threshold && $dist < $best_dist) {
        $best_dist = $dist;
        $match = $row;
    }
}

if ($match) {
    // Encontrou match! Agora verificar/criar conta no Mercado
    $email = $match['email'];
    $nome = $match['firstname'] . ' ' . $match['lastname'];
    $telefone = $match['telephone'] ?? '';

    // Verificar se já existe no Mercado
    $stmt = $pdo->prepare("SELECT * FROM om_market_customers WHERE email = ?");
    $stmt->execute([$email]);
    $mercadoUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mercadoUser) {
        // Criar conta automaticamente no Mercado (SSO)
        $stmt = $pdo->prepare("INSERT INTO om_market_customers (name, email, phone, password_hash, created_at) VALUES (?, ?, ?, ?, NOW())");
        // Senha aleatória - usuário usa Face ID para logar
        $randomPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmt->execute([$nome, $email, $telefone, $randomPass]);

        $customerId = $pdo->lastInsertId();
        $customerName = $match['firstname'];
    } else {
        $customerId = $mercadoUser['customer_id'];
        $customerName = explode(' ', $mercadoUser['name'])[0];
    }

    // Criar sessão do Mercado
    $_SESSION['customer_id'] = $customerId;
    $_SESSION['customer_name'] = $customerName;
    $_SESSION['customer_email'] = $email;
    $_SESSION['login_method'] = 'faceid';

    // Atualizar uso do Face ID no site principal
    $pdo->prepare("UPDATE oc_om_face_data SET last_used = NOW(), use_count = use_count + 1 WHERE face_id = ?")->execute([$match['face_id']]);

    echo json_encode([
        'success' => true,
        'message' => "Bem-vindo(a), {$customerName}!",
        'name' => $customerName,
        'redirect' => '/mercado/index.php'
    ]);

} else {
    echo json_encode([
        'success' => false,
        'message' => 'Rosto não reconhecido. Cadastre seu Face ID em onemundo.com.br primeiro.'
    ]);
}
