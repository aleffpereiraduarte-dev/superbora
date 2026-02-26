<?php
/**
 * 🍳 HELPER DE RECEITAS PARA ONE
 */

function getRecipeResponse($pdo, $customer_id, $recipe_id) {
    // Validar parâmetros
    if (!is_numeric($recipe_id) || $recipe_id <= 0) {
        return null;
    }
    if (!is_numeric($customer_id) || $customer_id <= 0) {
        return null;
    }
    
    // Buscar receita
    $stmt = $pdo->prepare("SELECT * FROM om_one_recipes WHERE recipe_id = ?");
    $stmt->execute(array($recipe_id));
    $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$recipe) return null;
    
    // Ingredientes
    $stmt = $pdo->prepare("SELECT * FROM om_one_recipe_ingredients WHERE recipe_id = ? ORDER BY display_order");
    $stmt->execute(array($recipe_id));
    $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Montar texto
    $diff_emoji = array("facil" => "🟢", "medio" => "🟡", "dificil" => "🔴");
    $diff = isset($diff_emoji[$recipe["difficulty"]]) ? $diff_emoji[$recipe["difficulty"]] : "⚪";
    
    $text = "📖 **" . $recipe["name"] . "**\n\n";
    $text .= $recipe["description"] . "\n\n";
    $text .= "⏱️ " . $recipe["total_time_minutes"] . " min | ";
    $text .= "$diff " . ucfirst($recipe["difficulty"]) . " | ";
    $text .= "👥 " . $recipe["servings"] . " porções\n\n";
    
    $text .= "**📝 Ingredientes:**\n";
    foreach ($ingredients as $ing) {
        $qty = $ing["quantity"] ? $ing["quantity"] . " " . $ing["unit"] : "";
        $opt = $ing["is_optional"] ? " (opcional)" : "";
        $text .= "• " . $ing["name"] . ($qty ? " - $qty" : "") . "$opt\n";
    }
    
    $text .= "\nVocê tem esses ingredientes? Posso ver o que falta e já pedir pra você! 🛒";
    
    return array(
        "text" => $text,
        "type" => "recipe_card",
        "recipe" => $recipe,
        "ingredients" => $ingredients,
        "quick_replies" => array(
            array("id" => "have_all_" . $recipe_id, "label" => "✅ Tenho tudo!"),
            array("id" => "check_missing_" . $recipe_id, "label" => "📋 Ver o que falta"),
            array("id" => "start_cooking_" . $recipe_id, "label" => "👩‍🍳 Começar a fazer!")
        )
    );
}

function getNutritionResponse($pdo, $food) {
    $food_lower = mb_strtolower(trim($food));
    // Escapar caracteres especiais do LIKE
    $food_escaped = str_replace(['%', '_'], ['\\%', '\\_'], $food_lower);
    
    $stmt = $pdo->prepare("SELECT * FROM om_one_nutrition WHERE food_name_normalized LIKE ? ESCAPE '\\' LIMIT 1");
    $stmt->execute(array("%$food_escaped%"));
    $nutrition = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$nutrition) {
        return array(
            "text" => "Hmm, não encontrei informações sobre \"$food\" na minha base. 🤔\n\nPosso te ajudar com outro alimento?",
            "type" => "text"
        );
    }
    
    $text = "🥗 **" . $nutrition["food_name"] . "**\n";
    $text .= "📏 Porção: " . $nutrition["portion_size"] . "\n\n";
    
    $text .= "**Valores Nutricionais:**\n";
    $text .= "🔥 Calorias: " . $nutrition["calories"] . " kcal\n";
    $text .= "💪 Proteína: " . $nutrition["protein"] . "g\n";
    $text .= "🍞 Carboidratos: " . $nutrition["carbs"] . "g\n";
    $text .= "🧈 Gordura: " . $nutrition["fat"] . "g\n";
    $text .= "🌾 Fibra: " . $nutrition["fiber"] . "g\n";
    
    if ($nutrition["health_benefits"]) {
        $text .= "\n💚 " . $nutrition["health_benefits"];
    }
    
    return array(
        "text" => $text,
        "type" => "nutrition_card",
        "nutrition" => $nutrition
    );
}

function getCookingStepResponse($pdo, $recipe_id, $step_number) {
    $stmt = $pdo->prepare("SELECT * FROM om_one_recipe_steps WHERE recipe_id = ? AND step_number = ?");
    $stmt->execute(array($recipe_id, $step_number));
    $step = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$step) return null;
    
    // Total de passos
    $stmt = $pdo->prepare("SELECT MAX(step_number) FROM om_one_recipe_steps WHERE recipe_id = ?");
    $stmt->execute(array($recipe_id));
    $total_steps = $stmt->fetchColumn();
    
    $text = "👩‍🍳 **PASSO $step_number de $total_steps**\n";
    if ($step["title"]) $text .= "**" . $step["title"] . "**\n";
    $text .= "\n" . $step["instruction"];
    
    if ($step["tip"]) {
        $text .= "\n\n💡 **Dica:** " . $step["tip"];
    }
    
    $quick_replies = array();
    
    if ($step["timer_minutes"]) {
        $quick_replies[] = array(
            "id" => "start_timer_" . $step["timer_minutes"] . "_" . $step["step_id"],
            "label" => "⏱️ Timer " . $step["timer_minutes"] . " min"
        );
    }
    
    if ($step_number < $total_steps) {
        $quick_replies[] = array("id" => "next_step_" . $recipe_id . "_" . ($step_number + 1), "label" => "▶️ Próximo passo");
    } else {
        $quick_replies[] = array("id" => "finish_recipe_" . $recipe_id, "label" => "✅ Terminei!");
    }
    
    if ($step_number > 1) {
        $quick_replies[] = array("id" => "prev_step_" . $recipe_id . "_" . ($step_number - 1), "label" => "◀️ Voltar");
    }
    
    return array(
        "text" => $text,
        "type" => "cooking_step",
        "step" => $step,
        "current_step" => $step_number,
        "total_steps" => $total_steps,
        "has_timer" => (bool)$step["timer_minutes"],
        "timer_minutes" => $step["timer_minutes"],
        "quick_replies" => $quick_replies
    );
}

function suggestRecipes($pdo, $category = null, $limit = 3) {
    $where = "WHERE status = '1'";
    $params = array();
    
    if ($category) {
        $where .= " AND category = ?";
        $params[] = $category;
    }
    
    $params[] = $limit;
    
    // Método mais eficiente para seleção aleatória
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM om_one_recipes $where");
$count_stmt->execute(array_slice($params, 0, -1));
$total = $count_stmt->fetchColumn();

if ($total <= $limit) {
    $stmt = $pdo->prepare("SELECT recipe_id, name, total_time_minutes, difficulty, calories_per_serving FROM om_one_recipes $where LIMIT ?");
} else {
    $offset = mt_rand(0, max(0, $total - $limit));
    $stmt = $pdo->prepare("SELECT recipe_id, name, total_time_minutes, difficulty, calories_per_serving FROM om_one_recipes $where LIMIT ?, ?");
    $params[] = $offset;
}
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
