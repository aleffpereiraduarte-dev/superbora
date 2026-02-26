<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();
try {
    $db = getDB(); OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireShopper(); $shopper_id = $payload['sub'] ?? $payload['uid'];
    $default_items = [["name"=>"Sacolas térmicas","checked"=>false],["name"=>"Celular carregado","checked"=>false],["name"=>"Documento com foto","checked"=>false],["name"=>"Uniforme/identificação","checked"=>false],["name"=>"Álcool gel","checked"=>false]];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = getInput();
        try {
            $db->prepare("INSERT INTO om_shopper_checklist (shopper_id, date, items, completed, created_at) VALUES (?,CURRENT_DATE,?,1,NOW()) ON DUPLICATE KEY UPDATE items=VALUES(items), completed=1")
                ->execute([$shopper_id, json_encode($input['items'] ?? $default_items)]);
        } catch(Exception $e) {
            $db->exec("CREATE TABLE IF NOT EXISTS om_shopper_checklist (id INT AUTO_INCREMENT PRIMARY KEY, shopper_id INT, date DATE, items JSON, completed TINYINT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY(shopper_id, date))");
            $db->prepare("INSERT INTO om_shopper_checklist (shopper_id, date, items, completed, created_at) VALUES (?,CURRENT_DATE,?,1,NOW())")
                ->execute([$shopper_id, json_encode($input['items'] ?? $default_items)]);
        }
        response(true, null, "Checklist salvo");
    }
    try {
        $stmt = $db->prepare("SELECT * FROM om_shopper_checklist WHERE shopper_id = ? AND date = CURRENT_DATE");
        $stmt->execute([$shopper_id]); $checklist = $stmt->fetch();
        if ($checklist) { $checklist['items'] = json_decode($checklist['items'], true); response(true, $checklist); }
    } catch(Exception $e) {}
    response(true, ['items' => $default_items, 'completed' => false]);
} catch (Exception $e) { error_log("[shopper/checklist] " . $e->getMessage()); response(false, null, "Erro", 500); }
