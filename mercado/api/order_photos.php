<?php
/**
 * API Fotos do Pedido
 * Actions: upload, get, delete
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$action = $_REQUEST["action"] ?? "";
$upload_dir = dirname(__DIR__) . "/uploads/order_photos/";

// Criar pasta se nao existir
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

switch ($action) {
    case "upload":
        $order_id = (int)($_POST["order_id"] ?? 0);
        $photo_type = $_POST["photo_type"] ?? "general"; // bags, products, delivery, general
        $uploaded_by_type = $_POST["uploaded_by_type"] ?? ""; // shopper, delivery, customer
        $uploaded_by_id = (int)($_POST["uploaded_by_id"] ?? 0);

        if (!$order_id || !isset($_FILES["photo"])) {
            echo json_encode(["success" => false, "error" => "Order ID e foto obrigatorios"]);
            exit;
        }

        $file = $_FILES["photo"];
        $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

        if (!in_array($ext, ["jpg","jpeg","png","webp"])) {
            echo json_encode(["success" => false, "error" => "Formato invalido. Use: jpg, png, webp"]);
            exit;
        }

        $filename = $order_id . "_" . $photo_type . "_" . time() . "." . $ext;
        $filepath = $upload_dir . $filename;

        if (move_uploaded_file($file["tmp_name"], $filepath)) {
            $photo_path = "/uploads/order_photos/" . $filename;

            $stmt = $pdo->prepare("INSERT INTO om_order_photos (order_id, photo_type, photo_path, uploaded_by_type, uploaded_by_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$order_id, $photo_type, $photo_path, $uploaded_by_type, $uploaded_by_id]);

            echo json_encode(["success" => true, "photo_id" => $pdo->lastInsertId(), "photo_path" => $photo_path]);
        } else {
            echo json_encode(["success" => false, "error" => "Erro ao salvar arquivo"]);
        }
        break;

    case "upload_base64":
        $order_id = (int)($_POST["order_id"] ?? 0);
        $photo_type = $_POST["photo_type"] ?? "general";
        $uploaded_by_type = $_POST["uploaded_by_type"] ?? "";
        $uploaded_by_id = (int)($_POST["uploaded_by_id"] ?? 0);
        $base64 = $_POST["photo_base64"] ?? "";

        if (!$order_id || !$base64) {
            echo json_encode(["success" => false, "error" => "Dados incompletos"]);
            exit;
        }

        // Decodificar base64
        if (preg_match("/^data:image\/(\w+);base64,/", $base64, $m)) {
            $ext = $m[1];
            $base64 = preg_replace("/^data:image\/\w+;base64,/", "", $base64);
        } else {
            $ext = "jpg";
        }

        $data = base64_decode($base64);
        $filename = $order_id . "_" . $photo_type . "_" . time() . "." . $ext;
        $filepath = $upload_dir . $filename;

        if (file_put_contents($filepath, $data)) {
            $photo_path = "/uploads/order_photos/" . $filename;

            $stmt = $pdo->prepare("INSERT INTO om_order_photos (order_id, photo_type, photo_path, uploaded_by_type, uploaded_by_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$order_id, $photo_type, $photo_path, $uploaded_by_type, $uploaded_by_id]);

            echo json_encode(["success" => true, "photo_id" => $pdo->lastInsertId(), "photo_path" => $photo_path]);
        } else {
            echo json_encode(["success" => false, "error" => "Erro ao salvar"]);
        }
        break;

    case "get":
        $order_id = (int)($_GET["order_id"] ?? 0);
        $photo_type = $_GET["photo_type"] ?? "";

        $sql = "SELECT * FROM om_order_photos WHERE order_id = ?";
        $params = [$order_id];

        if ($photo_type) {
            $sql .= " AND photo_type = ?";
            $params[] = $photo_type;
        }
        $sql .= " ORDER BY uploaded_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "photos" => $photos]);
        break;

    case "delete":
        $photo_id = (int)($_POST["photo_id"] ?? 0);

        // Buscar path para deletar arquivo
        $stmt = $pdo->prepare("SELECT photo_path FROM om_order_photos WHERE photo_id = ?");
        $stmt->execute([$photo_id]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($photo) {
            $filepath = dirname(__DIR__) . $photo["photo_path"];
            if (file_exists($filepath)) unlink($filepath);

            $stmt = $pdo->prepare("DELETE FROM om_order_photos WHERE photo_id = ?");
            $stmt->execute([$photo_id]);

            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => "Foto nao encontrada"]);
        }
        break;

    default:
        echo json_encode(["success" => false, "actions" => ["upload","upload_base64","get","delete"]]);
}
