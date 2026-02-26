<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API Worker Register - Cadastro de Shoppers/Drivers/Full Service
 * POST: Cadastra novo worker
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = getPDO();
} catch (Exception $e) {
    die(json_encode(['success' => false, 'message' => 'Erro de conexão']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['success' => false, 'message' => 'Método não permitido']));
}

// Dados do formulário
$tipo = $_POST['tipo'] ?? 'shopper';
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
$cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
$birth_date = $_POST['birth_date'] ?? '';
$cep = preg_replace('/\D/', '', $_POST['cep'] ?? '');
$address = trim($_POST['address'] ?? '');
$password = $_POST['password'] ?? '';
$vehicle_type = $_POST['vehicle_type'] ?? null;
$plate = strtoupper(trim($_POST['plate'] ?? ''));
$model = trim($_POST['model'] ?? '');
$is_mei = isset($_POST['is_mei']) ? 1 : 0;

// Validações
if (empty($name) || empty($email) || empty($phone) || empty($cpf) || empty($password)) {
    die(json_encode(['success' => false, 'message' => 'Preencha todos os campos obrigatórios']));
}

if (strlen($cpf) !== 11) {
    die(json_encode(['success' => false, 'message' => 'CPF inválido']));
}

if (strlen($password) < 6) {
    die(json_encode(['success' => false, 'message' => 'Senha deve ter no mínimo 6 caracteres']));
}

// Verificar se email ou CPF já existe
$stmt = $pdo->prepare("SELECT worker_id FROM om_market_workers WHERE email = ? OR cpf = ?");
$stmt->execute([$email, $cpf]);
if ($stmt->fetch()) {
    die(json_encode(['success' => false, 'message' => 'E-mail ou CPF já cadastrado']));
}

// Definir tipo de worker
$is_shopper = in_array($tipo, ['shopper', 'full']) ? 1 : 0;
$is_driver = in_array($tipo, ['driver', 'full']) ? 1 : 0;
$worker_type = $tipo === 'full' ? 'fullservice' : $tipo;

// Upload de arquivos
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/workers/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$uploads = [];
$fileFields = ['doc_frente', 'doc_verso', 'selfie', 'comprovante', 'crlv'];

foreach ($fileFields as $field) {
    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
        $filename = $cpf . '_' . $field . '_' . time() . '.' . $ext;
        $filepath = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES[$field]['tmp_name'], $filepath)) {
            $uploads[$field] = '/uploads/workers/' . $filename;
        }
    }
}

try {
    $pdo->beginTransaction();
    
    // Inserir worker
    $stmt = $pdo->prepare("
        INSERT INTO om_market_workers (
            name, email, phone, cpf, birth_date, cep, address,
            password, worker_type, is_shopper, is_driver, is_mei,
            status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    
    $stmt->execute([
        $name, $email, $phone, $cpf, $birth_date, $cep, $address,
        password_hash($password, PASSWORD_DEFAULT),
        $worker_type, $is_shopper, $is_driver, $is_mei
    ]);
    
    $workerId = $pdo->lastInsertId();
    
    // Inserir documentos
    $docTypes = [
        'doc_frente' => 'rg_frente',
        'doc_verso' => 'rg_verso',
        'selfie' => 'selfie',
        'comprovante' => 'comprovante_residencia',
        'crlv' => 'crlv'
    ];
    
    foreach ($uploads as $field => $url) {
        $stmt = $pdo->prepare("
            INSERT INTO om_worker_documents (worker_id, doc_type, file_url, status, created_at)
            VALUES (?, ?, ?, 'pendente', NOW())
        ");
        $stmt->execute([$workerId, $docTypes[$field], $url]);
    }
    
    // Inserir veículo se necessário
    if ($vehicle_type && in_array($tipo, ['driver', 'full'])) {
        $stmt = $pdo->prepare("
            INSERT INTO om_worker_vehicles (worker_id, vehicle_type, plate, model, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$workerId, $vehicle_type, $plate, $model]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Cadastro realizado com sucesso!',
        'worker_id' => $workerId
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar: ' . $e->getMessage()]);
}
