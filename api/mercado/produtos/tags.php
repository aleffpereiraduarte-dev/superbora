<?php
/**
 * GET /api/mercado/produtos/tags.php
 * List all available dietary tags (predefined constants)
 * Returns: [{id, name, slug, icon}]
 */
require_once __DIR__ . "/../config/database.php";

header('Cache-Control: public, max-age=3600');

try {
    $tags = [
        ["id" => 1, "name" => "Vegano",       "slug" => "vegano",       "icon" => "leaf"],
        ["id" => 2, "name" => "Sem Gluten",    "slug" => "sem_gluten",   "icon" => "gf"],
        ["id" => 3, "name" => "Sem Lactose",   "slug" => "sem_lactose",  "icon" => "lf"],
        ["id" => 4, "name" => "Organico",      "slug" => "organico",     "icon" => "organic"],
        ["id" => 5, "name" => "Zero Acucar",   "slug" => "zero_acucar",  "icon" => "sugar-free"],
        ["id" => 6, "name" => "Integral",      "slug" => "integral",     "icon" => "grain"],
    ];

    response(true, ["tags" => $tags], "Tags disponiveis");

} catch (Exception $e) {
    error_log("[API Tags] Erro: " . $e->getMessage());
    response(false, null, "Erro ao listar tags", 500);
}
