<?php
require_once __DIR__ . '/../config/database.php';
/**
 * 📸 API DE FOTOS DO PEDIDO
 */
header("Content-Type: application/json");
session_start();

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(array("success" => false, "error" => "DB Error")));
}

// Helper de notificações
if (file_exists(__DIR__ . "/../includes/cliente_notifications.php")) {
    require_once __DIR__ . "/../includes/cliente_notifications.php";
}

$action = isset($_POST["action"]) ? $_POST["action"] : (isset($_GET["action"]) ? $_GET["action"] : "");

$upload_dir = __DIR__ . "/../uploads/order_photos/";
$upload_url = "/mercado/uploads/order_photos/";

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // UPLOAD DE FOTO
    // ══════════════════════════════════════════════════════════════════════════
    case "upload":
        $order_id = isset($_POST["order_id"]) ? intval($_POST["order_id"]) : 0;
        $uploaded_by = isset($_POST["uploaded_by"]) ? $_POST["uploaded_by"] : "shopper";
        $uploaded_by_id = isset($_POST["uploaded_by_id"]) ? intval($_POST["uploaded_by_id"]) : 0;
        $photo_type = isset($_POST["photo_type"]) ? $_POST["photo_type"] : "products";
        $caption = isset($_POST["caption"]) ? trim($_POST["caption"]) : null;
        
        if (!$order_id) {
            echo json_encode(array("success" => false, "error" => "order_id required"));
            exit;
        }
        
        if (!isset($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
            echo json_encode(array("success" => false, "error" => "Nenhuma foto enviada"));
            exit;
        }
        
        $file = $_FILES["photo"];
        
        // Validar tipo
        $allowed_types = array("image/jpeg", "image/png", "image/webp");
        if (!in_array($file["type"], $allowed_types)) {
            echo json_encode(array("success" => false, "error" => "Formato inválido. Use JPG, PNG ou WebP"));
            exit;
        }
        
        // Validar tamanho (max 10MB)
        if ($file["size"] > 10 * 1024 * 1024) {
            echo json_encode(array("success" => false, "error" => "Arquivo muito grande (máx 10MB)"));
            exit;
        }
        
        // Gerar nome único
        $ext = pathinfo($file["name"], PATHINFO_EXTENSION);
        $filename = "order_{$order_id}_{$photo_type}_" . time() . "_" . uniqid() . "." . $ext;
        $filepath = $upload_dir . $filename;
        
        // Criar diretório se não existe
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Mover arquivo
        if (!move_uploaded_file($file["tmp_name"], $filepath)) {
            echo json_encode(array("success" => false, "error" => "Erro ao salvar foto"));
            exit;
        }
        
        // Criar thumbnail
        $thumb_filename = "thumb_" . $filename;
        $thumb_path = $upload_dir . $thumb_filename;
        createThumbnail($filepath, $thumb_path, 300);
        
        // Salvar no banco
        $stmt = $pdo->prepare("
            INSERT INTO om_order_photos 
            (order_id, uploaded_by, uploaded_by_id, photo_type, photo_path, thumbnail_path, caption)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            $order_id, $uploaded_by, $uploaded_by_id, $photo_type,
            $upload_url . $filename,
            $upload_url . $thumb_filename,
            $caption
        ));
        
        $photo_id = $pdo->lastInsertId();
        
        // Mensagem no chat
        $type_labels = array(
            "products" => "📸 Foto dos produtos",
            "receipt" => "🧾 Comprovante de compra",
            "delivery_proof" => "📦 Comprovante de entrega",
            "issue" => "⚠️ Foto do problema"
        );
        $label = isset($type_labels[$photo_type]) ? $type_labels[$photo_type] : "📸 Foto";
        
        $msg = "$label\n\n[foto anexada]";
        if ($caption) $msg .= "\n\n💬 $caption";
        
        $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, attachment_url) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(array($order_id, $uploaded_by, $uploaded_by_id, $msg, $upload_url . $filename));
        
        // Notificar cliente
        if ($uploaded_by == "shopper" && function_exists("notificarCliente")) {
            notificarCliente($pdo, $order_id, "mensagem_chat", array("message" => "Nova foto do shopper"));
        }
        
        echo json_encode(array(
            "success" => true,
            "photo_id" => $photo_id,
            "photo_url" => $upload_url . $filename,
            "thumbnail_url" => $upload_url . $thumb_filename
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // UPLOAD VIA BASE64 (para câmera mobile)
    // ══════════════════════════════════════════════════════════════════════════
    case "upload_base64":
        $input = json_decode(file_get_contents("php://input"), true);
        
        $order_id = isset($input["order_id"]) ? intval($input["order_id"]) : 0;
        $uploaded_by = isset($input["uploaded_by"]) ? $input["uploaded_by"] : "shopper";
        $uploaded_by_id = isset($input["uploaded_by_id"]) ? intval($input["uploaded_by_id"]) : 0;
        $photo_type = isset($input["photo_type"]) ? $input["photo_type"] : "products";
        $caption = isset($input["caption"]) ? trim($input["caption"]) : null;
        $base64 = isset($input["photo_base64"]) ? $input["photo_base64"] : "";
        
        if (!$order_id || !$base64) {
            echo json_encode(array("success" => false, "error" => "Dados incompletos"));
            exit;
        }
        
        // Extrair dados do base64
        if (preg_match("/^data:image\/(jpeg|png|webp);base64,(.+)$/", $base64, $matches)) {
            $ext = $matches[1] == "jpeg" ? "jpg" : $matches[1];
            $data = base64_decode($matches[2]);
        } else {
            echo json_encode(array("success" => false, "error" => "Formato base64 inválido"));
            exit;
        }
        
        // Validar tamanho
        if (strlen($data) > 10 * 1024 * 1024) {
            echo json_encode(array("success" => false, "error" => "Arquivo muito grande"));
            exit;
        }
        
        // Gerar nome e salvar
        $filename = "order_{$order_id}_{$photo_type}_" . time() . "_" . uniqid() . "." . $ext;
        $filepath = $upload_dir . $filename;
        
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        if (!file_put_contents($filepath, $data)) {
            echo json_encode(array("success" => false, "error" => "Erro ao salvar"));
            exit;
        }
        
        // Criar thumbnail
        $thumb_filename = "thumb_" . $filename;
        createThumbnail($filepath, $upload_dir . $thumb_filename, 300);
        
        // Salvar no banco
        $stmt = $pdo->prepare("
            INSERT INTO om_order_photos 
            (order_id, uploaded_by, uploaded_by_id, photo_type, photo_path, thumbnail_path, caption)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array(
            $order_id, $uploaded_by, $uploaded_by_id, $photo_type,
            $upload_url . $filename,
            $upload_url . $thumb_filename,
            $caption
        ));
        
        $photo_id = $pdo->lastInsertId();
        
        // Mensagem no chat
        $type_labels = array("products" => "📸 Foto dos produtos", "receipt" => "🧾 Comprovante", "delivery_proof" => "📦 Entrega", "issue" => "⚠️ Problema");
        $label = isset($type_labels[$photo_type]) ? $type_labels[$photo_type] : "📸 Foto";
        
        $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, attachment_url) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(array($order_id, $uploaded_by, $uploaded_by_id, $label, $upload_url . $filename));
        
        echo json_encode(array(
            "success" => true,
            "photo_id" => $photo_id,
            "photo_url" => $upload_url . $filename,
            "thumbnail_url" => $upload_url . $thumb_filename
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // LISTAR FOTOS DO PEDIDO
    // ══════════════════════════════════════════════════════════════════════════
    case "list":
        $order_id = isset($_GET["order_id"]) ? intval($_GET["order_id"]) : 0;
        $photo_type = isset($_GET["type"]) ? $_GET["type"] : null;
        
        $where = "WHERE order_id = ?";
        $params = array($order_id);
        
        if ($photo_type) {
            $where .= " AND photo_type = ?";
            $params[] = $photo_type;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM om_order_photos $where ORDER BY created_at DESC");
        $stmt->execute($params);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(array("success" => true, "photos" => $photos));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}

// Função para criar thumbnail
function createThumbnail($source, $dest, $max_size) {
    $info = getimagesize($source);
    if (!$info) return false;
    
    $type = $info[2];
    $width = $info[0];
    $height = $info[1];
    
    // Calcular novo tamanho
    if ($width > $height) {
        $new_width = $max_size;
        $new_height = intval($height * $max_size / $width);
    } else {
        $new_height = $max_size;
        $new_width = intval($width * $max_size / $height);
    }
    
    // Criar imagem
    switch ($type) {
        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($source); break;
        case IMAGETYPE_PNG: $src = imagecreatefrompng($source); break;
        case IMAGETYPE_WEBP: $src = imagecreatefromwebp($source); break;
        default: return false;
    }
    
    $thumb = imagecreatetruecolor($new_width, $new_height);
    
    // Preservar transparência
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
    }
    
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    // Salvar
    switch ($type) {
        case IMAGETYPE_JPEG: imagejpeg($thumb, $dest, 85); break;
        case IMAGETYPE_PNG: imagepng($thumb, $dest); break;
        case IMAGETYPE_WEBP: imagewebp($thumb, $dest, 85); break;
    }
    
    imagedestroy($src);
    imagedestroy($thumb);
    
    return true;
}
