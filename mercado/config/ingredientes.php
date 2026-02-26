<?php
/**
 * Ingredientes Configuration - OneMundo ONE (AI)
 * Lista de ingredientes para sugestões de receitas
 */

// Categorias de ingredientes
$INGREDIENTES = [
    'proteinas' => [
        'Frango', 'Carne bovina', 'Carne suína', 'Peixe', 'Camarão',
        'Ovo', 'Tofu', 'Linguiça', 'Bacon', 'Presunto'
    ],
    'carboidratos' => [
        'Arroz', 'Macarrão', 'Batata', 'Pão', 'Farinha de trigo',
        'Mandioca', 'Inhame', 'Quinoa', 'Aveia', 'Milho'
    ],
    'verduras' => [
        'Alface', 'Couve', 'Espinafre', 'Brócolis', 'Rúcula',
        'Agrião', 'Repolho', 'Acelga', 'Chicória', 'Mostarda'
    ],
    'legumes' => [
        'Tomate', 'Cenoura', 'Cebola', 'Alho', 'Pimentão',
        'Abobrinha', 'Berinjela', 'Pepino', 'Beterraba', 'Chuchu'
    ],
    'frutas' => [
        'Banana', 'Maçã', 'Laranja', 'Limão', 'Abacaxi',
        'Morango', 'Manga', 'Mamão', 'Melancia', 'Uva'
    ],
    'laticinios' => [
        'Leite', 'Queijo', 'Manteiga', 'Creme de leite', 'Iogurte',
        'Requeijão', 'Cream cheese', 'Leite condensado', 'Nata'
    ],
    'temperos' => [
        'Sal', 'Pimenta', 'Orégano', 'Manjericão', 'Alecrim',
        'Tomilho', 'Coentro', 'Salsinha', 'Cebolinha', 'Cominho'
    ],
    'graos' => [
        'Feijão', 'Lentilha', 'Grão de bico', 'Ervilha', 'Soja'
    ]
];

// Ingredientes mais populares
$INGREDIENTES_POPULARES = [
    'Frango', 'Arroz', 'Feijão', 'Ovo', 'Tomate', 'Cebola', 
    'Alho', 'Batata', 'Macarrão', 'Queijo'
];

// Retorna todos os ingredientes em array flat
function getAllIngredients() {
    global $INGREDIENTES;
    $all = [];
    foreach ($INGREDIENTES as $category => $items) {
        $all = array_merge($all, $items);
    }
    return $all;
}

// Busca ingredientes por termo
function searchIngredients($term) {
    $all = getAllIngredients();
    $term = strtolower($term);
    return array_filter($all, function($item) use ($term) {
        return strpos(strtolower($item), $term) !== false;
    });
}
