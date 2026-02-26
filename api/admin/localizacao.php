<?php
/**
 * API ADMIN - LOCALIZAÇÃO DO PRODUTO
 *
 * GET  ?action=get&product_id=X - Buscar dados
 * POST ?action=save - Salvar localização
 * GET  ?action=warehouses - Listar warehouses
 *
 * REQUER AUTENTICAÇÃO: admin deve estar logado no OpenCart
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// CORS - Apenas domínios permitidos
$allowedOrigins = ['https://onemundo.com.br', 'https://www.onemundo.com.br'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Credentials: true");
}

// Headers de segurança
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit(0);

require_once dirname(dirname(__DIR__)) . "/config.php";

// ====== AUTENTICAÇÃO ADMIN ======
session_start();

// Verifica se há sessão de admin do OpenCart
$isAuthenticated = false;

// Método 1: Sessão do OpenCart Admin
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    $isAuthenticated = true;
}

// Método 2: Token Bearer no header (para chamadas API)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$isAuthenticated && preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = $matches[1];
    // Verifica token contra o banco de dados ou JWT
    // Por agora, aceita um token de admin configurado no .env
    $adminToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
    if ($adminToken && hash_equals($adminToken, $token)) {
        $isAuthenticated = true;
    }
}

// Se não autenticado, retorna 401
if (!$isAuthenticated) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Acesso não autorizado. Faça login como administrador.'
    ]);
    exit;
}
// ====== FIM AUTENTICAÇÃO ======

$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
$db->set_charset("utf8mb4");
$prefix = DB_PREFIX;

$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$action = $input["action"] ?? $_GET["action"] ?? "";

switch ($action) {
    case "get":
        getLocalizacao($db, $prefix);
        break;
    case "save":
        saveLocalizacao($db, $prefix, $input);
        break;
    case "warehouses":
        getWarehouses($db, $prefix);
        break;
    case "cep":
        consultarCep();
        break;
    default:
        echo json_encode(["success" => false, "error" => "Ação inválida"]);
}

$db->close();

function getLocalizacao($db, $prefix) {
    $product_id = intval($_GET["product_id"] ?? 0);
    if (!$product_id) {
        echo json_encode(["success" => false, "error" => "product_id obrigatório"]);
        return;
    }
    
    // Buscar dados do produto
    $sql = "SELECT
        p.product_id, p.model, p.sku, p.onsku,
        p.cep_localizacao, p.cidade_localizacao, p.uf_localizacao,
        p.warehouse_id,
        pd.name,
        w.name as warehouse_name, w.city as warehouse_city, w.cep as warehouse_cep,
        pvp.seller_id,
        pvs.store_name as seller_name, pvs.store_zipcode as seller_cep,
        pvs.store_city as seller_city, pvs.store_state as seller_uf
    FROM {$prefix}product p
    LEFT JOIN {$prefix}product_description pd ON pd.product_id = p.product_id AND pd.language_id = 1
    LEFT JOIN {$prefix}wms_warehouse w ON w.warehouse_id = p.warehouse_id
    LEFT JOIN {$prefix}purpletree_vendor_products pvp ON pvp.product_id = p.product_id
    LEFT JOIN {$prefix}purpletree_vendor_stores pvs ON pvs.seller_id = pvp.seller_id
    WHERE p.product_id = ?";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $produto = $result ? $result->fetch_assoc() : null;
    
    if (!$produto) {
        echo json_encode(["success" => false, "error" => "Produto não encontrado"]);
        return;
    }
    
    // Buscar unidades ONSKU
    $unidades = [];
    $sqlUnits = "SELECT
        u.*,
        w.name as wh_name, w.city as wh_city, w.cep as wh_cep
    FROM {$prefix}om_product_units u
    LEFT JOIN {$prefix}wms_warehouse w ON w.warehouse_id = u.warehouse_id
    WHERE u.product_id = ?
    ORDER BY u.unit_id DESC";

    $stmtUnits = $db->prepare($sqlUnits);
    $stmtUnits->bind_param("i", $product_id);
    $stmtUnits->execute();
    $resUnits = $stmtUnits->get_result();
    while ($u = $resUnits->fetch_assoc()) {
        $unidades[] = $u;
    }
    
    // Determinar CEP de origem atual
    $cep_origem = "";
    $origem_tipo = "";
    $origem_info = "";
    
    // Prioridade: 1. Manual no produto, 2. Unidade no CD, 3. Vendedor
    if (!empty($produto["cep_localizacao"])) {
        $cep_origem = $produto["cep_localizacao"];
        $origem_tipo = "manual";
        $origem_info = "Definido manualmente";
        if ($produto["cidade_localizacao"]) {
            $origem_info .= " - " . $produto["cidade_localizacao"] . "/" . $produto["uf_localizacao"];
        }
    } elseif (!empty($produto["warehouse_id"]) && !empty($produto["warehouse_cep"])) {
        $cep_origem = $produto["warehouse_cep"];
        $origem_tipo = "warehouse";
        $origem_info = "CD " . $produto["warehouse_name"] . " - " . $produto["warehouse_city"];
    } elseif (!empty($unidades)) {
        // Verificar se alguma unidade está no CD
        foreach ($unidades as $u) {
            if ($u["localizacao_tipo"] === "cd" && !empty($u["wh_cep"])) {
                $cep_origem = $u["wh_cep"];
                $origem_tipo = "unidade_cd";
                $origem_info = "Unidade no CD " . $u["wh_name"];
                break;
            }
        }
    }
    
    // Fallback: vendedor
    if (!$cep_origem && !empty($produto["seller_cep"])) {
        $cep_origem = $produto["seller_cep"];
        $origem_tipo = "vendedor";
        $origem_info = "Vendedor: " . $produto["seller_name"] . " - " . $produto["seller_city"] . "/" . $produto["seller_uf"];
    }
    
    // Fallback final: SP
    if (!$cep_origem) {
        $cep_origem = "01310100";
        $origem_tipo = "padrao";
        $origem_info = "São Paulo (padrão do sistema)";
    }
    
    echo json_encode([
        "success" => true,
        "produto" => [
            "product_id" => (int)$produto["product_id"],
            "name" => $produto["name"],
            "model" => $produto["model"],
            "sku" => $produto["sku"],
            "onsku" => $produto["onsku"]
        ],
        "localizacao" => [
            "cep" => $produto["cep_localizacao"],
            "cidade" => $produto["cidade_localizacao"],
            "uf" => $produto["uf_localizacao"],
            "warehouse_id" => $produto["warehouse_id"] ? (int)$produto["warehouse_id"] : null
        ],
        "origem_calculada" => [
            "cep" => preg_replace("/\D/", "", $cep_origem),
            "tipo" => $origem_tipo,
            "info" => $origem_info
        ],
        "vendedor" => $produto["seller_id"] ? [
            "id" => (int)$produto["seller_id"],
            "nome" => $produto["seller_name"],
            "cep" => $produto["seller_cep"],
            "cidade" => $produto["seller_city"],
            "uf" => $produto["seller_uf"]
        ] : null,
        "warehouse" => $produto["warehouse_id"] ? [
            "id" => (int)$produto["warehouse_id"],
            "nome" => $produto["warehouse_name"],
            "cidade" => $produto["warehouse_city"],
            "cep" => $produto["warehouse_cep"]
        ] : null,
        "unidades" => array_map(function($u) {
            return [
                "unit_id" => (int)$u["unit_id"],
                "om_sku" => $u["om_sku"],
                "status" => $u["status"],
                "localizacao_tipo" => $u["localizacao_tipo"],
                "localizacao_cep" => $u["localizacao_cep"],
                "warehouse" => $u["wh_name"],
                "tracking" => $u["tracking_code"]
            ];
        }, $unidades)
    ]);
}

function saveLocalizacao($db, $prefix, $input) {
    $product_id = intval($input["product_id"] ?? 0);
    if (!$product_id) {
        echo json_encode(["success" => false, "error" => "product_id obrigatório"]);
        return;
    }
    
    $cep = preg_replace("/\D/", "", $input["cep_localizacao"] ?? "");
    $cidade = $input["cidade_localizacao"] ?? "";
    $uf = strtoupper($input["uf_localizacao"] ?? "");
    $warehouse_id = intval($input["warehouse_id"] ?? 0);

    // Usar prepared statement para atualização segura
    $sql = "UPDATE {$prefix}product SET
            cep_localizacao = ?,
            cidade_localizacao = ?,
            uf_localizacao = ?,
            warehouse_id = ?
            WHERE product_id = ?";

    $stmt = $db->prepare($sql);

    // Se tem CEP, usar os valores; senão, usar NULL
    $cep_val = $cep ?: null;
    $cidade_val = $cep ? $cidade : null;
    $uf_val = $cep ? $uf : null;
    $warehouse_val = $warehouse_id ?: null;

    $stmt->bind_param("sssii", $cep_val, $cidade_val, $uf_val, $warehouse_val, $product_id);

    if ($stmt->execute()) {
        echo json_encode([
            "success" => true, 
            "message" => $cep ? "Localização salva: $cep - $cidade/$uf" : "Localização removida (usando automático)"
        ]);
    } else {
        echo json_encode(["success" => false, "error" => $db->error]);
    }
}

function getWarehouses($db, $prefix) {
    $sql = "SELECT warehouse_id, name, city, state, cep FROM {$prefix}wms_warehouse WHERE status = 1 ORDER BY state, city";
    $result = $db->query($sql);
    
    $warehouses = [];
    while ($row = $result->fetch_assoc()) {
        $warehouses[] = [
            "id" => (int)$row["warehouse_id"],
            "name" => $row["name"],
            "city" => $row["city"],
            "state" => $row["state"],
            "cep" => $row["cep"]
        ];
    }
    
    echo json_encode(["success" => true, "warehouses" => $warehouses]);
}

function consultarCep() {
    $cep = preg_replace("/\D/", "", $_GET["cep"] ?? "");
    if (strlen($cep) !== 8) {
        echo json_encode(["success" => false, "error" => "CEP inválido"]);
        return;
    }
    
    $response = @file_get_contents("https://viacep.com.br/ws/$cep/json/");
    if ($response) {
        $data = json_decode($response, true);
        if (!isset($data["erro"])) {
            echo json_encode([
                "success" => true,
                "cep" => $data["cep"],
                "cidade" => $data["localidade"],
                "uf" => $data["uf"],
                "logradouro" => $data["logradouro"],
                "bairro" => $data["bairro"]
            ]);
            return;
        }
    }
    echo json_encode(["success" => false, "error" => "CEP não encontrado"]);
}
