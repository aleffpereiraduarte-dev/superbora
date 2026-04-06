<?php
/**
 * GET /api/mercado/intelligence/dynamic-filters.php?q=pizza
 * Returns contextual search filters based on the search term.
 *
 * Uses predefined static mappings for speed (no AI/DB needed).
 * Matches are fuzzy: "hamburguer", "burger", "hamburger" all match the burger filters.
 *
 * Response format:
 * {
 *   "success": true,
 *   "data": {
 *     "query": "pizza",
 *     "filters": [
 *       {
 *         "key": "sabor",
 *         "label": "Sabor",
 *         "icon": "pizza",
 *         "color": "#EA580C",
 *         "options": [
 *           { "value": "margherita", "label": "Margherita" },
 *           { "value": "calabresa", "label": "Calabresa" },
 *           ...
 *         ]
 *       },
 *       ...
 *     ]
 *   }
 * }
 */
require_once __DIR__ . "/../config/database.php";

// Fast: no DB, no auth, just static mappings
header('Cache-Control: public, max-age=300');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2 || strlen($q) > 200) {
    response(true, ['query' => $q, 'filters' => []]);
}

$qLower = mb_strtolower($q);

// ── Static filter mappings keyed by search term patterns ──

$FILTER_MAP = [
    // Pizza
    'pizza' => [
        [
            'key' => 'sabor',
            'label' => 'Sabor',
            'icon' => 'pizza',
            'color' => '#EA580C',
            'options' => [
                ['value' => 'margherita', 'label' => 'Margherita'],
                ['value' => 'calabresa', 'label' => 'Calabresa'],
                ['value' => '4 queijos', 'label' => '4 Queijos'],
                ['value' => 'portuguesa', 'label' => 'Portuguesa'],
                ['value' => 'frango catupiry', 'label' => 'Frango c/ Catupiry'],
                ['value' => 'pepperoni', 'label' => 'Pepperoni'],
                ['value' => 'napolitana', 'label' => 'Napolitana'],
                ['value' => 'mussarela', 'label' => 'Mussarela'],
            ],
        ],
        [
            'key' => 'tamanho',
            'label' => 'Tamanho',
            'icon' => 'ruler',
            'color' => '#7C3AED',
            'options' => [
                ['value' => 'broto', 'label' => 'Broto'],
                ['value' => 'media', 'label' => 'Media'],
                ['value' => 'grande', 'label' => 'Grande'],
                ['value' => 'familia', 'label' => 'Familia'],
            ],
        ],
        [
            'key' => 'borda',
            'label' => 'Borda',
            'icon' => 'circle',
            'color' => '#D97706',
            'options' => [
                ['value' => 'tradicional', 'label' => 'Tradicional'],
                ['value' => 'catupiry', 'label' => 'Catupiry'],
                ['value' => 'cheddar', 'label' => 'Cheddar'],
                ['value' => 'chocolate', 'label' => 'Chocolate'],
            ],
        ],
    ],

    // Hamburguer / Burger
    'burger' => [
        [
            'key' => 'proteina',
            'label' => 'Proteina',
            'icon' => 'drumstick',
            'color' => '#B45309',
            'options' => [
                ['value' => 'carne', 'label' => 'Carne'],
                ['value' => 'frango', 'label' => 'Frango'],
                ['value' => 'vegano', 'label' => 'Vegano'],
                ['value' => 'peixe', 'label' => 'Peixe'],
                ['value' => 'costela', 'label' => 'Costela'],
            ],
        ],
        [
            'key' => 'tamanho',
            'label' => 'Tamanho',
            'icon' => 'ruler',
            'color' => '#7C3AED',
            'options' => [
                ['value' => 'simples', 'label' => 'Simples'],
                ['value' => 'duplo', 'label' => 'Duplo'],
                ['value' => 'triplo', 'label' => 'Triplo'],
            ],
        ],
        [
            'key' => 'extras',
            'label' => 'Extras',
            'icon' => 'plus',
            'color' => '#059669',
            'options' => [
                ['value' => 'bacon', 'label' => 'Bacon'],
                ['value' => 'cheddar', 'label' => 'Cheddar'],
                ['value' => 'ovo', 'label' => 'Ovo'],
                ['value' => 'onion rings', 'label' => 'Onion Rings'],
            ],
        ],
    ],

    // Acai
    'acai' => [
        [
            'key' => 'tamanho',
            'label' => 'Tamanho',
            'icon' => 'ruler',
            'color' => '#7C3AED',
            'options' => [
                ['value' => '300ml', 'label' => '300ml'],
                ['value' => '500ml', 'label' => '500ml'],
                ['value' => '700ml', 'label' => '700ml'],
                ['value' => '1L', 'label' => '1 Litro'],
            ],
        ],
        [
            'key' => 'complementos',
            'label' => 'Complementos',
            'icon' => 'plus',
            'color' => '#059669',
            'options' => [
                ['value' => 'granola', 'label' => 'Granola'],
                ['value' => 'leite ninho', 'label' => 'Leite Ninho'],
                ['value' => 'banana', 'label' => 'Banana'],
                ['value' => 'morango', 'label' => 'Morango'],
                ['value' => 'paçoca', 'label' => 'Pacoca'],
                ['value' => 'leite condensado', 'label' => 'Leite Condensado'],
                ['value' => 'nutella', 'label' => 'Nutella'],
            ],
        ],
        [
            'key' => 'base',
            'label' => 'Base',
            'icon' => 'droplet',
            'color' => '#6D28D9',
            'options' => [
                ['value' => 'tradicional', 'label' => 'Tradicional'],
                ['value' => 'zero acucar', 'label' => 'Zero Acucar'],
                ['value' => 'com banana', 'label' => 'Com Banana'],
            ],
        ],
    ],

    // Bebida / Suco / Drink
    'bebida' => [
        [
            'key' => 'tamanho',
            'label' => 'Tamanho',
            'icon' => 'ruler',
            'color' => '#7C3AED',
            'options' => [
                ['value' => '300ml', 'label' => '300ml'],
                ['value' => '500ml', 'label' => '500ml'],
                ['value' => '1L', 'label' => '1 Litro'],
                ['value' => '2L', 'label' => '2 Litros'],
            ],
        ],
        [
            'key' => 'tipo',
            'label' => 'Tipo',
            'icon' => 'wine',
            'color' => '#DC2626',
            'options' => [
                ['value' => 'natural', 'label' => 'Natural'],
                ['value' => 'industrializado', 'label' => 'Industrializado'],
                ['value' => 'diet/zero', 'label' => 'Diet / Zero'],
            ],
        ],
        [
            'key' => 'temperatura',
            'label' => 'Temperatura',
            'icon' => 'droplet',
            'color' => '#0284C7',
            'options' => [
                ['value' => 'gelado', 'label' => 'Gelado'],
                ['value' => 'normal', 'label' => 'Natural'],
            ],
        ],
    ],

    // Farmacia
    'farmacia' => [
        [
            'key' => 'tipo',
            'label' => 'Tipo',
            'icon' => 'pill',
            'color' => '#0284C7',
            'options' => [
                ['value' => 'medicamento', 'label' => 'Medicamento'],
                ['value' => 'higiene', 'label' => 'Higiene'],
                ['value' => 'beleza', 'label' => 'Beleza'],
                ['value' => 'vitaminas', 'label' => 'Vitaminas'],
                ['value' => 'dermocosmeticos', 'label' => 'Dermocosmeticos'],
            ],
        ],
        [
            'key' => 'marca',
            'label' => 'Marca',
            'icon' => 'tag',
            'color' => '#9333EA',
            'options' => [
                ['value' => 'generico', 'label' => 'Generico'],
                ['value' => 'referencia', 'label' => 'Referencia'],
                ['value' => 'similar', 'label' => 'Similar'],
            ],
        ],
    ],

    // Mercado / Supermercado
    'mercado' => [
        [
            'key' => 'categoria',
            'label' => 'Categoria',
            'icon' => 'shopping-cart',
            'color' => '#16A34A',
            'options' => [
                ['value' => 'hortifruti', 'label' => 'Hortifruti'],
                ['value' => 'laticinios', 'label' => 'Laticinios'],
                ['value' => 'carnes', 'label' => 'Carnes'],
                ['value' => 'limpeza', 'label' => 'Limpeza'],
                ['value' => 'padaria', 'label' => 'Padaria'],
                ['value' => 'congelados', 'label' => 'Congelados'],
                ['value' => 'bebidas', 'label' => 'Bebidas'],
                ['value' => 'mercearia', 'label' => 'Mercearia'],
            ],
        ],
    ],

    // Sushi / Japonesa
    'sushi' => [
        [
            'key' => 'tipo',
            'label' => 'Tipo',
            'icon' => 'fish',
            'color' => '#0891B2',
            'options' => [
                ['value' => 'combo', 'label' => 'Combo'],
                ['value' => 'sashimi', 'label' => 'Sashimi'],
                ['value' => 'temaki', 'label' => 'Temaki'],
                ['value' => 'hot roll', 'label' => 'Hot Roll'],
                ['value' => 'uramaki', 'label' => 'Uramaki'],
            ],
        ],
        [
            'key' => 'tamanho',
            'label' => 'Quantidade',
            'icon' => 'ruler',
            'color' => '#7C3AED',
            'options' => [
                ['value' => '10 pecas', 'label' => '10 Pecas'],
                ['value' => '20 pecas', 'label' => '20 Pecas'],
                ['value' => '30 pecas', 'label' => '30 Pecas'],
                ['value' => '40 pecas', 'label' => '40+ Pecas'],
            ],
        ],
    ],

    // Sorvete / Ice cream
    'sorvete' => [
        [
            'key' => 'sabor',
            'label' => 'Sabor',
            'icon' => 'ice-cream',
            'color' => '#EC4899',
            'options' => [
                ['value' => 'chocolate', 'label' => 'Chocolate'],
                ['value' => 'morango', 'label' => 'Morango'],
                ['value' => 'baunilha', 'label' => 'Baunilha'],
                ['value' => 'napolitano', 'label' => 'Napolitano'],
                ['value' => 'flocos', 'label' => 'Flocos'],
            ],
        ],
        [
            'key' => 'tipo',
            'label' => 'Tipo',
            'icon' => 'droplet',
            'color' => '#06B6D4',
            'options' => [
                ['value' => 'massa', 'label' => 'Massa'],
                ['value' => 'picole', 'label' => 'Picole'],
                ['value' => 'acai', 'label' => 'Acai'],
                ['value' => 'frozen', 'label' => 'Frozen'],
            ],
        ],
    ],

    // Cafe
    'cafe' => [
        [
            'key' => 'tipo',
            'label' => 'Tipo',
            'icon' => 'coffee',
            'color' => '#6D4C41',
            'options' => [
                ['value' => 'espresso', 'label' => 'Espresso'],
                ['value' => 'cappuccino', 'label' => 'Cappuccino'],
                ['value' => 'latte', 'label' => 'Latte'],
                ['value' => 'americano', 'label' => 'Americano'],
                ['value' => 'mocha', 'label' => 'Mocha'],
                ['value' => 'macchiato', 'label' => 'Macchiato'],
            ],
        ],
        [
            'key' => 'tamanho',
            'label' => 'Tamanho',
            'icon' => 'ruler',
            'color' => '#7C3AED',
            'options' => [
                ['value' => 'pequeno', 'label' => 'Pequeno'],
                ['value' => 'medio', 'label' => 'Medio'],
                ['value' => 'grande', 'label' => 'Grande'],
            ],
        ],
    ],

    // Frango
    'frango' => [
        [
            'key' => 'preparo',
            'label' => 'Preparo',
            'icon' => 'flame',
            'color' => '#EF6C00',
            'options' => [
                ['value' => 'grelhado', 'label' => 'Grelhado'],
                ['value' => 'frito', 'label' => 'Frito'],
                ['value' => 'assado', 'label' => 'Assado'],
                ['value' => 'empanado', 'label' => 'Empanado'],
            ],
        ],
        [
            'key' => 'parte',
            'label' => 'Corte',
            'icon' => 'drumstick',
            'color' => '#B45309',
            'options' => [
                ['value' => 'peito', 'label' => 'Peito'],
                ['value' => 'coxa', 'label' => 'Coxa'],
                ['value' => 'sobrecoxa', 'label' => 'Sobrecoxa'],
                ['value' => 'asa', 'label' => 'Asa'],
                ['value' => 'inteiro', 'label' => 'Inteiro'],
            ],
        ],
    ],

    // Salada
    'salada' => [
        [
            'key' => 'tipo',
            'label' => 'Tipo',
            'icon' => 'salad',
            'color' => '#16A34A',
            'options' => [
                ['value' => 'caesar', 'label' => 'Caesar'],
                ['value' => 'tropical', 'label' => 'Tropical'],
                ['value' => 'grega', 'label' => 'Grega'],
                ['value' => 'caprese', 'label' => 'Caprese'],
            ],
        ],
        [
            'key' => 'proteina',
            'label' => 'Proteina',
            'icon' => 'drumstick',
            'color' => '#B45309',
            'options' => [
                ['value' => 'frango', 'label' => 'Frango'],
                ['value' => 'atum', 'label' => 'Atum'],
                ['value' => 'camarao', 'label' => 'Camarao'],
                ['value' => 'sem proteina', 'label' => 'Sem Proteina'],
            ],
        ],
    ],

    // Cerveja
    'cerveja' => [
        [
            'key' => 'tipo',
            'label' => 'Tipo',
            'icon' => 'wine',
            'color' => '#D97706',
            'options' => [
                ['value' => 'pilsen', 'label' => 'Pilsen'],
                ['value' => 'ipa', 'label' => 'IPA'],
                ['value' => 'lager', 'label' => 'Lager'],
                ['value' => 'stout', 'label' => 'Stout'],
                ['value' => 'witbier', 'label' => 'Witbier'],
                ['value' => 'sem alcool', 'label' => 'Sem Alcool'],
            ],
        ],
        [
            'key' => 'tamanho',
            'label' => 'Tamanho',
            'icon' => 'ruler',
            'color' => '#7C3AED',
            'options' => [
                ['value' => 'long neck', 'label' => 'Long Neck'],
                ['value' => 'lata', 'label' => 'Lata'],
                ['value' => '600ml', 'label' => '600ml'],
                ['value' => '1L', 'label' => '1 Litro'],
            ],
        ],
    ],

    // Doces
    'doces' => [
        [
            'key' => 'tipo',
            'label' => 'Tipo',
            'icon' => 'ice-cream',
            'color' => '#EC4899',
            'options' => [
                ['value' => 'bolo', 'label' => 'Bolo'],
                ['value' => 'brigadeiro', 'label' => 'Brigadeiro'],
                ['value' => 'torta', 'label' => 'Torta'],
                ['value' => 'brownie', 'label' => 'Brownie'],
                ['value' => 'churros', 'label' => 'Churros'],
                ['value' => 'pudim', 'label' => 'Pudim'],
            ],
        ],
    ],

    // Pet
    'pet' => [
        [
            'key' => 'animal',
            'label' => 'Animal',
            'icon' => 'paw',
            'color' => '#CA8A04',
            'options' => [
                ['value' => 'cachorro', 'label' => 'Cachorro'],
                ['value' => 'gato', 'label' => 'Gato'],
                ['value' => 'passaro', 'label' => 'Passaro'],
                ['value' => 'peixe', 'label' => 'Peixe'],
            ],
        ],
        [
            'key' => 'tipo',
            'label' => 'Tipo',
            'icon' => 'tag',
            'color' => '#9333EA',
            'options' => [
                ['value' => 'racao', 'label' => 'Racao'],
                ['value' => 'petisco', 'label' => 'Petisco'],
                ['value' => 'higiene', 'label' => 'Higiene'],
                ['value' => 'brinquedo', 'label' => 'Brinquedo'],
                ['value' => 'acessorio', 'label' => 'Acessorio'],
            ],
        ],
    ],
];

// ── Alias map: multiple terms can match the same filter set ──

$ALIAS_MAP = [
    // Pizza
    'pizza' => 'pizza',
    'pizzas' => 'pizza',
    'pizzaria' => 'pizza',

    // Burger
    'burger' => 'burger',
    'burgers' => 'burger',
    'hamburguer' => 'burger',
    'hamburger' => 'burger',
    'hamburgueria' => 'burger',
    'lanche' => 'burger',
    'lanches' => 'burger',
    'x-burger' => 'burger',
    'x-salada' => 'burger',
    'x-tudo' => 'burger',
    'xburguer' => 'burger',
    'xburger' => 'burger',
    'cheeseburger' => 'burger',
    'smash' => 'burger',
    'smash burger' => 'burger',

    // Acai
    'acai' => 'acai',
    'açaí' => 'acai',
    'açai' => 'acai',
    'acaí' => 'acai',

    // Bebida
    'bebida' => 'bebida',
    'bebidas' => 'bebida',
    'suco' => 'bebida',
    'sucos' => 'bebida',
    'refrigerante' => 'bebida',
    'refri' => 'bebida',
    'drink' => 'bebida',
    'drinks' => 'bebida',
    'agua' => 'bebida',
    'água' => 'bebida',

    // Farmacia
    'farmacia' => 'farmacia',
    'farmácia' => 'farmacia',
    'farmacias' => 'farmacia',
    'drogaria' => 'farmacia',
    'remedio' => 'farmacia',
    'remedios' => 'farmacia',
    'medicamento' => 'farmacia',
    'medicamentos' => 'farmacia',

    // Mercado
    'mercado' => 'mercado',
    'mercados' => 'mercado',
    'supermercado' => 'mercado',
    'super' => 'mercado',
    'compras' => 'mercado',
    'feira' => 'mercado',

    // Sushi
    'sushi' => 'sushi',
    'japonesa' => 'sushi',
    'japa' => 'sushi',
    'sashimi' => 'sushi',
    'temaki' => 'sushi',
    'hot roll' => 'sushi',

    // Sorvete
    'sorvete' => 'sorvete',
    'sorvetes' => 'sorvete',
    'sorveteria' => 'sorvete',
    'gelato' => 'sorvete',
    'picole' => 'sorvete',

    // Cafe
    'cafe' => 'cafe',
    'café' => 'cafe',
    'cafeteria' => 'cafe',
    'coffee' => 'cafe',
    'cappuccino' => 'cafe',
    'espresso' => 'cafe',

    // Frango
    'frango' => 'frango',
    'frangos' => 'frango',
    'galinha' => 'frango',
    'chicken' => 'frango',

    // Salada
    'salada' => 'salada',
    'saladas' => 'salada',
    'saudavel' => 'salada',
    'saudável' => 'salada',
    'fit' => 'salada',
    'light' => 'salada',

    // Cerveja
    'cerveja' => 'cerveja',
    'cervejas' => 'cerveja',
    'chopp' => 'cerveja',
    'beer' => 'cerveja',

    // Doces
    'doces' => 'doces',
    'doce' => 'doces',
    'sobremesa' => 'doces',
    'sobremesas' => 'doces',
    'bolo' => 'doces',
    'bolos' => 'doces',
    'torta' => 'doces',
    'confeitaria' => 'doces',
    'brigadeiro' => 'doces',

    // Pet
    'pet' => 'pet',
    'pet shop' => 'pet',
    'petshop' => 'pet',
    'racao' => 'pet',
    'ração' => 'pet',
];

// ── Match logic: exact alias match first, then substring match ──

$matchedKey = null;

// 1. Exact alias match
if (isset($ALIAS_MAP[$qLower])) {
    $matchedKey = $ALIAS_MAP[$qLower];
}

// 2. Try matching as a substring (e.g., "pizza calabresa" should still get pizza filters)
if (!$matchedKey) {
    // Check if any alias is contained in the query or query contains alias
    foreach ($ALIAS_MAP as $alias => $key) {
        if (strlen($alias) >= 3 && strpos($qLower, $alias) !== false) {
            $matchedKey = $key;
            break;
        }
    }
}

// 3. Try the other direction: query is contained in an alias
if (!$matchedKey) {
    foreach ($ALIAS_MAP as $alias => $key) {
        if (strlen($qLower) >= 3 && strpos($alias, $qLower) !== false) {
            $matchedKey = $key;
            break;
        }
    }
}

$filters = $matchedKey && isset($FILTER_MAP[$matchedKey])
    ? $FILTER_MAP[$matchedKey]
    : [];

response(true, [
    'query' => $q,
    'matched' => $matchedKey,
    'filters' => $filters,
]);
