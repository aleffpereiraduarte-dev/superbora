<?php
require_once __DIR__ . '/../config/database.php';
/**
 * 🍳 API DE RECEITAS E NUTRIÇÃO
 */
header("Content-Type: application/json; charset=utf-8");
session_start();

try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die(json_encode(array("success" => false, "error" => "DB Error")));
}

$input = json_decode(file_get_contents("php://input"), true);
$action = isset($input["action"]) ? $input["action"] : (isset($_GET["action"]) ? $_GET["action"] : "");
$customer_id = isset($input["customer_id"]) ? intval($input["customer_id"]) : (isset($_SESSION["customer_id"]) ? intval($_SESSION["customer_id"]) : 0);

switch ($action) {
    
    // ══════════════════════════════════════════════════════════════════════════
    // LISTAR RECEITAS
    // ══════════════════════════════════════════════════════════════════════════
    case "list":
        $category = isset($_GET["category"]) ? $_GET["category"] : null;
        $difficulty = isset($_GET["difficulty"]) ? $_GET["difficulty"] : null;
        $search = isset($_GET["search"]) ? $_GET["search"] : null;
        $limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 10;
        
        $where = "WHERE status = '1'";
        $params = array();
        
        if ($category) {
            $where .= " AND category = ?";
            $params[] = $category;
        }
        if ($difficulty) {
            $where .= " AND difficulty = ?";
            $params[] = $difficulty;
        }
        if ($search) {
            $where .= " AND (name LIKE ? OR description LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $stmt = $pdo->prepare("SELECT * FROM om_one_recipes $where ORDER BY made_count DESC, avg_rating DESC LIMIT $limit");
        $stmt->execute($params);
        $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($recipes as &$recipe) {
            if ($recipe["tags"]) $recipe["tags"] = json_decode($recipe["tags"], true);
        }
        
        echo json_encode(array("success" => true, "recipes" => $recipes));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // DETALHES DA RECEITA
    // ══════════════════════════════════════════════════════════════════════════
    case "get":
        $recipe_id = isset($_GET["recipe_id"]) ? intval($_GET["recipe_id"]) : 0;
        $slug = isset($_GET["slug"]) ? $_GET["slug"] : null;
        
        if ($slug) {
            $stmt = $pdo->prepare("SELECT * FROM om_one_recipes WHERE slug = ?");
            $stmt->execute(array($slug));
        } else {
            $stmt = $pdo->prepare("SELECT * FROM om_one_recipes WHERE recipe_id = ?");
            $stmt->execute(array($recipe_id));
        }
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$recipe) {
            echo json_encode(array("success" => false, "error" => "Receita não encontrada"));
            exit;
        }
        
        if ($recipe["tags"]) $recipe["tags"] = json_decode($recipe["tags"], true);
        
        // Ingredientes
        $stmt = $pdo->prepare("SELECT * FROM om_one_recipe_ingredients WHERE recipe_id = ? ORDER BY display_order");
        $stmt->execute(array($recipe["recipe_id"]));
        $recipe["ingredients"] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Passos
        $stmt = $pdo->prepare("SELECT * FROM om_one_recipe_steps WHERE recipe_id = ? ORDER BY step_number");
        $stmt->execute(array($recipe["recipe_id"]));
        $recipe["steps"] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Incrementar view
        $pdo->prepare("UPDATE om_one_recipes SET view_count = view_count + 1 WHERE recipe_id = ?")->execute(array($recipe["recipe_id"]));
        
        echo json_encode(array("success" => true, "recipe" => $recipe));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // BUSCAR POR INGREDIENTES
    // ══════════════════════════════════════════════════════════════════════════
    case "find_by_ingredients":
        $ingredients = isset($input["ingredients"]) ? $input["ingredients"] : array();
        
        if (empty($ingredients)) {
            echo json_encode(array("success" => false, "error" => "Informe os ingredientes"));
            exit;
        }
        
        // Buscar receitas que usam esses ingredientes
        $placeholders = implode(",", array_fill(0, count($ingredients), "?"));
        
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   COUNT(DISTINCT ri.ingredient_id) as matching_ingredients,
                   (SELECT COUNT(*) FROM om_one_recipe_ingredients WHERE recipe_id = r.recipe_id AND is_optional = 0) as required_ingredients
            FROM om_one_recipes r
            JOIN om_one_recipe_ingredients ri ON r.recipe_id = ri.recipe_id
            WHERE ri.name LIKE CONCAT(\"%\", ?, \"%\")
            " . (count($ingredients) > 1 ? str_repeat(" OR ri.name LIKE CONCAT(\"%\", ?, \"%\")", count($ingredients) - 1) : "") . "
            GROUP BY r.recipe_id
            ORDER BY matching_ingredients DESC, r.difficulty ASC
            LIMIT 10
        ");
        $stmt->execute($ingredients);
        $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($recipes as &$recipe) {
            if ($recipe["tags"]) $recipe["tags"] = json_decode($recipe["tags"], true);
            
            // Calcular porcentagem de match
            $recipe["match_percent"] = round(($recipe["matching_ingredients"] / max(1, $recipe["required_ingredients"])) * 100);
        }
        
        echo json_encode(array("success" => true, "recipes" => $recipes));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // VERIFICAR INGREDIENTES FALTANTES
    // ══════════════════════════════════════════════════════════════════════════
    case "check_ingredients":
        $recipe_id = isset($input["recipe_id"]) ? intval($input["recipe_id"]) : 0;
        $have = isset($input["have"]) ? $input["have"] : array();
        
        $stmt = $pdo->prepare("SELECT * FROM om_one_recipe_ingredients WHERE recipe_id = ?");
        $stmt->execute(array($recipe_id));
        $all_ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $have_lower = array_map("mb_strtolower", $have);
        
        $have_list = array();
        $missing_list = array();
        $optional_list = array();
        
        foreach ($all_ingredients as $ing) {
            $ing_lower = mb_strtolower($ing["name"]);
            $found = false;
            
            foreach ($have_lower as $h) {
                if (strpos($ing_lower, $h) !== false || strpos($h, $ing_lower) !== false) {
                    $found = true;
                    break;
                }
            }
            
            if ($found) {
                $have_list[] = $ing;
            } elseif ($ing["is_optional"]) {
                $optional_list[] = $ing;
            } else {
                $missing_list[] = $ing;
            }
        }
        
        echo json_encode(array(
            "success" => true,
            "have" => $have_list,
            "missing" => $missing_list,
            "optional" => $optional_list,
            "can_make" => count($missing_list) == 0
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // NUTRIÇÃO
    // ══════════════════════════════════════════════════════════════════════════
    case "nutrition":
        $food = isset($_GET["food"]) ? mb_strtolower(trim($_GET["food"])) : "";
        
        if (!$food) {
            echo json_encode(array("success" => false, "error" => "Informe o alimento"));
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM om_one_nutrition WHERE food_name_normalized LIKE ? LIMIT 1");
        $stmt->execute(array("%$food%"));
        $nutrition = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($nutrition) {
            if ($nutrition["vitamins"]) $nutrition["vitamins"] = json_decode($nutrition["vitamins"], true);
            if ($nutrition["minerals"]) $nutrition["minerals"] = json_decode($nutrition["minerals"], true);
        }
        
        echo json_encode(array(
            "success" => true,
            "found" => (bool)$nutrition,
            "nutrition" => $nutrition
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // INICIAR TIMER
    // ══════════════════════════════════════════════════════════════════════════
    case "start_timer":
        $label = isset($input["label"]) ? $input["label"] : "Timer";
        $minutes = isset($input["minutes"]) ? intval($input["minutes"]) : 5;
        $recipe_id = isset($input["recipe_id"]) ? intval($input["recipe_id"]) : null;
        $step_id = isset($input["step_id"]) ? intval($input["step_id"]) : null;
        
        $duration_seconds = $minutes * 60;
        $ends_at = date("Y-m-d H:i:s", time() + $duration_seconds);
        
        $stmt = $pdo->prepare("
            INSERT INTO om_one_active_timers (customer_id, recipe_id, step_id, label, duration_seconds, ends_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(array($customer_id, $recipe_id, $step_id, $label, $duration_seconds, $ends_at));
        
        echo json_encode(array(
            "success" => true,
            "timer_id" => $pdo->lastInsertId(),
            "ends_at" => $ends_at
        ));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // LISTAR TIMERS ATIVOS
    // ══════════════════════════════════════════════════════════════════════════
    case "get_timers":
        $stmt = $pdo->prepare("
            SELECT * FROM om_one_active_timers 
            WHERE customer_id = ? AND status = \"running\" AND ends_at > NOW()
            ORDER BY ends_at ASC
        ");
        $stmt->execute(array($customer_id));
        $timers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($timers as &$timer) {
            $timer["remaining_seconds"] = max(0, strtotime($timer["ends_at"]) - time());
        }
        
        echo json_encode(array("success" => true, "timers" => $timers));
        break;
    
    // ══════════════════════════════════════════════════════════════════════════
    // MARCAR RECEITA COMO FEITA
    // ══════════════════════════════════════════════════════════════════════════
    case "mark_made":
        $recipe_id = isset($input["recipe_id"]) ? intval($input["recipe_id"]) : 0;
        $rating = isset($input["rating"]) ? intval($input["rating"]) : null;
        $notes = isset($input["notes"]) ? trim($input["notes"]) : null;
        
        $stmt = $pdo->prepare("INSERT INTO om_one_customer_recipes (customer_id, recipe_id, rating, notes) VALUES (?, ?, ?, ?)");
        $stmt->execute(array($customer_id, $recipe_id, $rating, $notes));
        
        // Atualizar contador da receita
        $pdo->prepare("UPDATE om_one_recipes SET made_count = made_count + 1 WHERE recipe_id = ?")->execute(array($recipe_id));
        
        // Atualizar média se tiver rating
        if ($rating) {
            $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating FROM om_one_customer_recipes WHERE recipe_id = ? AND rating IS NOT NULL");
            $stmt->execute(array($recipe_id));
            $avg = $stmt->fetchColumn();
            $pdo->prepare("UPDATE om_one_recipes SET avg_rating = ? WHERE recipe_id = ?")->execute(array($avg, $recipe_id));
        }
        
        echo json_encode(array("success" => true));
        break;
    
    default:
        echo json_encode(array("success" => false, "error" => "Invalid action"));
}
