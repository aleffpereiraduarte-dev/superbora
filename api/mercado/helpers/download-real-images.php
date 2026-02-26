<?php
/**
 * Download real product/partner images from Unsplash
 * Maps product names to appropriate search terms and downloads JPGs
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config/database.php';

set_time_limit(600);
ini_set('memory_limit', '512M');

$db = getDB();

$uploadDir = '/var/www/html/uploads';
$productsDir = "$uploadDir/products";
$logosDir = "$uploadDir/logos";
$bannersDir = "$uploadDir/banners";

// Ensure dirs exist
foreach ([$productsDir, $logosDir, $bannersDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// Product name -> Unsplash photo ID mapping
// Using specific Unsplash photo IDs for consistent, high-quality results
$productImageMap = [
    // Supermarket basics
    'Arroz' => 'https://images.unsplash.com/photo-1586201375761-83865001e31c?w=400&h=300&fit=crop',
    'Feijão' => 'https://images.unsplash.com/photo-1551462147-ff29053bfc14?w=400&h=300&fit=crop',
    'Leite Integral' => 'https://images.unsplash.com/photo-1563636619-e9143da7973b?w=400&h=300&fit=crop',
    'Leite Desnatado' => 'https://images.unsplash.com/photo-1550583724-b2692b85b150?w=400&h=300&fit=crop',
    'Coca-Cola' => 'https://images.unsplash.com/photo-1554866585-cd94860890b7?w=400&h=300&fit=crop',
    'Água Mineral' => 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=400&h=300&fit=crop',
    'Suco de Laranja' => 'https://images.unsplash.com/photo-1621506289937-a8e4df240d0b?w=400&h=300&fit=crop',
    'Queijo Mussarela' => 'https://images.unsplash.com/photo-1486297678162-eb2a19b0a32d?w=400&h=300&fit=crop',
    'Iogurte' => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=400&h=300&fit=crop',
    'Pão Francês' => 'https://images.unsplash.com/photo-1549931319-a545753467c8?w=400&h=300&fit=crop',
    'Pão de Forma' => 'https://images.unsplash.com/photo-1589367920969-ab8e050bbb04?w=400&h=300&fit=crop',
    'Picanha' => 'https://images.unsplash.com/photo-1603048297172-c92544798d5a?w=400&h=300&fit=crop',
    'Frango' => 'https://images.unsplash.com/photo-1604503468506-a8da13d82571?w=400&h=300&fit=crop',
    'Banana' => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?w=400&h=300&fit=crop',
    'Tomate' => 'https://images.unsplash.com/photo-1546470427-0d4db154cdb8?w=400&h=300&fit=crop',
    'Detergente' => 'https://images.unsplash.com/photo-1585421514284-efb74c2b69ba?w=400&h=300&fit=crop',
    'Sabão em Pó' => 'https://images.unsplash.com/photo-1610557892470-55d9e80c0bce?w=400&h=300&fit=crop',
    'Açúcar' => 'https://images.unsplash.com/photo-1558642452-9d2a7deb7f62?w=400&h=300&fit=crop',
    'Café' => 'https://images.unsplash.com/photo-1559056199-641a0ac8b55e?w=400&h=300&fit=crop',
    'Óleo de Soja' => 'https://images.unsplash.com/photo-1474979266404-7f28db3e3d4b?w=400&h=300&fit=crop',

    // Burger King
    'Whopper' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400&h=300&fit=crop',
    'Whopper Jr' => 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=400&h=300&fit=crop',
    'Combo Whopper' => 'https://images.unsplash.com/photo-1594212699903-ec8a3eca50f5?w=400&h=300&fit=crop',
    'Combo Whopper Jr' => 'https://images.unsplash.com/photo-1572802419224-296b0aeee0d9?w=400&h=300&fit=crop',
    'Combo Stacker' => 'https://images.unsplash.com/photo-1553979459-d2229ba7433b?w=400&h=300&fit=crop',
    'Stacker' => 'https://images.unsplash.com/photo-1586190848861-99aa4a171e90?w=400&h=300&fit=crop',
    'Chicken Jr' => 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?w=400&h=300&fit=crop',
    'Batata Frita' => 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=400&h=300&fit=crop',
    'Onion Rings' => 'https://images.unsplash.com/photo-1639024471283-03518883512d?w=400&h=300&fit=crop',
    'Sundae' => 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?w=400&h=300&fit=crop',
    'Brownie' => 'https://images.unsplash.com/photo-1564355808539-22fda35bed7e?w=400&h=300&fit=crop',
    'Guarana' => 'https://images.unsplash.com/photo-1625772299848-391b6a87d7b3?w=400&h=300&fit=crop',

    // Pizzaria
    'Pizza Margherita' => 'https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=400&h=300&fit=crop',
    'Pizza Calabresa' => 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=400&h=300&fit=crop',
    'Pizza Portuguesa' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=400&h=300&fit=crop',
    'Pizza Mussarela' => 'https://images.unsplash.com/photo-1571407970349-bc81e7e96d47?w=400&h=300&fit=crop',
    'Pizza Frango' => 'https://images.unsplash.com/photo-1588315029754-2dd089d39a1a?w=400&h=300&fit=crop',
    'Pizza A Moda' => 'https://images.unsplash.com/photo-1593560708920-61dd98c46a4e?w=400&h=300&fit=crop',
    'Pizza Pepperoni' => 'https://images.unsplash.com/photo-1628840042765-356cda07504e?w=400&h=300&fit=crop',
    'Pizza Quattro' => 'https://images.unsplash.com/photo-1595708684082-a173bb3a06c5?w=400&h=300&fit=crop',

    // Sushi
    'Sashimi Salmao' => 'https://images.unsplash.com/photo-1579871494447-9811cf80d66c?w=400&h=300&fit=crop',
    'Sashimi Atum' => 'https://images.unsplash.com/photo-1580822184713-fc5400e7fe10?w=400&h=300&fit=crop',
    'Niguiri' => 'https://images.unsplash.com/photo-1553621042-f6e147245754?w=400&h=300&fit=crop',
    'Uramaki' => 'https://images.unsplash.com/photo-1617196034796-73dfa7b1fd56?w=400&h=300&fit=crop',
    'Hot Roll' => 'https://images.unsplash.com/photo-1579584425555-c3ce17fd4351?w=400&h=300&fit=crop',
    'Temaki Salmao' => 'https://images.unsplash.com/photo-1611143669185-af224c5e3252?w=400&h=300&fit=crop',
    'Temaki Atum' => 'https://images.unsplash.com/photo-1562802378-063ec186a863?w=400&h=300&fit=crop',
    'Combo Casal' => 'https://images.unsplash.com/photo-1540648639573-8c848de23f0a?w=400&h=300&fit=crop',
    'Combo Family' => 'https://images.unsplash.com/photo-1583623025817-d180a2221d0a?w=400&h=300&fit=crop',

    // Acai
    'Acai 300' => 'https://images.unsplash.com/photo-1590301157890-4810ed352733?w=400&h=300&fit=crop',
    'Acai 500' => 'https://images.unsplash.com/photo-1615478503562-ec2d8aa0e24e?w=400&h=300&fit=crop',
    'Acai 700' => 'https://images.unsplash.com/photo-1606168094336-48f205276929?w=400&h=300&fit=crop',
    'Bowl Tropical' => 'https://images.unsplash.com/photo-1511690743698-d9d18f7e20f1?w=400&h=300&fit=crop',
    'Bowl Proteico' => 'https://images.unsplash.com/photo-1546039907-7fa05f864c02?w=400&h=300&fit=crop',
    'Bowl Kids' => 'https://images.unsplash.com/photo-1502741338009-cac2772e18bc?w=400&h=300&fit=crop',
    'Sorvete de Acai' => 'https://images.unsplash.com/photo-1497034825429-c343d7c6a68f?w=400&h=300&fit=crop',
    'Cupuacu' => 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?w=400&h=300&fit=crop',

    // Padaria
    'Pao Frances' => 'https://images.unsplash.com/photo-1549931319-a545753467c8?w=400&h=300&fit=crop',
    'Pao de Queijo' => 'https://images.unsplash.com/photo-1598733596893-426e3b3d6bd0?w=400&h=300&fit=crop',
    'Pao Integral' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?w=400&h=300&fit=crop',
    'Coxinha' => 'https://images.unsplash.com/photo-1630409351241-e90e7f5e434d?w=400&h=300&fit=crop',
    'Pastel' => 'https://images.unsplash.com/photo-1604467707321-70d009801bf0?w=400&h=300&fit=crop',
    'Empada' => 'https://images.unsplash.com/photo-1509722747041-616f39b57569?w=400&h=300&fit=crop',
    'Esfiha' => 'https://images.unsplash.com/photo-1565299507177-b0ac66763828?w=400&h=300&fit=crop',
    'Bolo de Chocolate' => 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=400&h=300&fit=crop',
    'Sonho' => 'https://images.unsplash.com/photo-1558303068-42dfb4a2a8a3?w=400&h=300&fit=crop',
    'Cafe Expresso' => 'https://images.unsplash.com/photo-1510707577719-ae7c14805e3a?w=400&h=300&fit=crop',
    'Suco Natural' => 'https://images.unsplash.com/photo-1622597467836-f3285f2131b8?w=400&h=300&fit=crop',

    // Confeitaria
    'Bolo Red Velvet' => 'https://images.unsplash.com/photo-1586788680434-30d324b2d46f?w=400&h=300&fit=crop',
    'Bolo de Cenoura' => 'https://images.unsplash.com/photo-1621303837174-89787a7d4729?w=400&h=300&fit=crop',
    'Bolo Prestigio' => 'https://images.unsplash.com/photo-1606890737304-57a1ca8a5b62?w=400&h=300&fit=crop',
    'Brigadeiro' => 'https://images.unsplash.com/photo-1541783245831-57d6fb0926d3?w=400&h=300&fit=crop',
    'Bem-Casado' => 'https://images.unsplash.com/photo-1558303068-42dfb4a2a8a3?w=400&h=300&fit=crop',
    'Trufa' => 'https://images.unsplash.com/photo-1548741487-18d363dc4469?w=400&h=300&fit=crop',
    'Torta Holandesa' => 'https://images.unsplash.com/photo-1464305795204-6f5bbfc7fb81?w=400&h=300&fit=crop',
    'Torta de Limao' => 'https://images.unsplash.com/photo-1519915028121-7d3463d20b13?w=400&h=300&fit=crop',

    // Farmacia
    'Dipirona' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=400&h=300&fit=crop',
    'Ibuprofeno' => 'https://images.unsplash.com/photo-1471864190281-a93a3070b6de?w=400&h=300&fit=crop',
    'Paracetamol' => 'https://images.unsplash.com/photo-1550572017-edd951aa8f72?w=400&h=300&fit=crop',
    'Shampoo' => 'https://images.unsplash.com/photo-1585232350437-abc46af42537?w=400&h=300&fit=crop',
    'Desodorante' => 'https://images.unsplash.com/photo-1556228578-0d85b1a4d571?w=400&h=300&fit=crop',
    'Creme Dental' => 'https://images.unsplash.com/photo-1559589688-6ba6beafe000?w=400&h=300&fit=crop',
    'Vitamina C' => 'https://images.unsplash.com/photo-1584017911766-d451b3d0e843?w=400&h=300&fit=crop',
    'Omega 3' => 'https://images.unsplash.com/photo-1577401239170-897942555fb3?w=400&h=300&fit=crop',
    'Dorflex' => 'https://images.unsplash.com/photo-1587854692152-cbe660dbde88?w=400&h=300&fit=crop',
    'Buscopan' => 'https://images.unsplash.com/photo-1471864190281-a93a3070b6de?w=400&h=300&fit=crop',
    'Loratadina' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=400&h=300&fit=crop',
    'Protetor Solar' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?w=400&h=300&fit=crop',
    'Serum' => 'https://images.unsplash.com/photo-1620916566398-39f1143ab7be?w=400&h=300&fit=crop',
    'Hidratante' => 'https://images.unsplash.com/photo-1556228578-0d85b1a4d571?w=400&h=300&fit=crop',
    'Perfume' => 'https://images.unsplash.com/photo-1541643600914-78b084683601?w=400&h=300&fit=crop',

    // Pet
    'Racao' => 'https://images.unsplash.com/photo-1589924691995-400dc9ecc119?w=400&h=300&fit=crop',
    'Sache Cao' => 'https://images.unsplash.com/photo-1601758228041-f3b2795255f1?w=400&h=300&fit=crop',
    'Coleira' => 'https://images.unsplash.com/photo-1583337130417-13571a247faa?w=400&h=300&fit=crop',
    'Brinquedo Mordedor' => 'https://images.unsplash.com/photo-1535930749574-1399327ce78f?w=400&h=300&fit=crop',
    'Shampoo Pet' => 'https://images.unsplash.com/photo-1583947215259-38e31be8751f?w=400&h=300&fit=crop',
    'Tapete Higienico' => 'https://images.unsplash.com/photo-1587300003388-59208cc962cb?w=400&h=300&fit=crop',

    // Conveniencia
    'Cerveja Heineken' => 'https://images.unsplash.com/photo-1608270586620-248524c67de9?w=400&h=300&fit=crop',
    'Cerveja Brahma' => 'https://images.unsplash.com/photo-1535958636474-b021ee887b13?w=400&h=300&fit=crop',
    'Red Bull' => 'https://images.unsplash.com/photo-1527960471264-932f39eb5846?w=400&h=300&fit=crop',
    'Doritos' => 'https://images.unsplash.com/photo-1600952841320-db92ec4047ca?w=400&h=300&fit=crop',
    'Amendoim' => 'https://images.unsplash.com/photo-1567095761054-7a02e69e5b2b?w=400&h=300&fit=crop',
    'Picolé' => 'https://images.unsplash.com/photo-1488900128323-21503983a07e?w=400&h=300&fit=crop',
    'Sorvete Pote' => 'https://images.unsplash.com/photo-1497034825429-c343d7c6a68f?w=400&h=300&fit=crop',

    // Acougue
    'Alcatra' => 'https://images.unsplash.com/photo-1588168333986-5078d3ae3976?w=400&h=300&fit=crop',
    'Fraldinha' => 'https://images.unsplash.com/photo-1607623814075-e51df1bdc82f?w=400&h=300&fit=crop',
    'Carne Moida' => 'https://images.unsplash.com/photo-1602470520998-f4a52199a3d6?w=400&h=300&fit=crop',
    'Linguica' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=400&h=300&fit=crop',
    'Costela Suina' => 'https://images.unsplash.com/photo-1544025162-d76694265947?w=400&h=300&fit=crop',
    'Peito de Frango' => 'https://images.unsplash.com/photo-1604503468506-a8da13d82571?w=400&h=300&fit=crop',
    'Coxa' => 'https://images.unsplash.com/photo-1587593810167-a84920ea0781?w=400&h=300&fit=crop',

    // Utilidades
    'Jogo de Panelas' => 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=400&h=300&fit=crop',
    'Faqueiro' => 'https://images.unsplash.com/photo-1530189095807-5e9e06b71310?w=400&h=300&fit=crop',
    'Pote Hermetico' => 'https://images.unsplash.com/photo-1584568694244-14fbdf83bd30?w=400&h=300&fit=crop',
    'Kit Limpeza' => 'https://images.unsplash.com/photo-1585421514284-efb74c2b69ba?w=400&h=300&fit=crop',
    'Vassoura' => 'https://images.unsplash.com/photo-1558618666-fcd25c85f82e?w=400&h=300&fit=crop',
    'Caixa Organizadora' => 'https://images.unsplash.com/photo-1558618666-fcd25c85f82e?w=400&h=300&fit=crop',
    'Cabide' => 'https://images.unsplash.com/photo-1558171813-4c088753af8f?w=400&h=300&fit=crop',

    // Espetinho
    'Espetinho de Picanha' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=400&h=300&fit=crop',
    'Espetinho de Frango' => 'https://images.unsplash.com/photo-1529193591184-b1d58069ecdd?w=400&h=300&fit=crop',
    'Espetinho Misto' => 'https://images.unsplash.com/photo-1544025162-d76694265947?w=400&h=300&fit=crop',
    'Espetinho de Queijo' => 'https://images.unsplash.com/photo-1531749668029-2db88e4276c7?w=400&h=300&fit=crop',
    'Porcao de Batata' => 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=400&h=300&fit=crop',
    'Porcao de Mandioca' => 'https://images.unsplash.com/photo-1599487488170-d11ec9c172f0?w=400&h=300&fit=crop',
    'Porcao Calabresa' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=400&h=300&fit=crop',
    'Caipirinha' => 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?w=400&h=300&fit=crop',
    'Cerveja Original' => 'https://images.unsplash.com/photo-1535958636474-b021ee887b13?w=400&h=300&fit=crop',
    'Refrigerante Lata' => 'https://images.unsplash.com/photo-1581636625402-29b2a704ef13?w=400&h=300&fit=crop',
];

// Partner logo/banner mapping
$partnerImageMap = [
    'Mercado Central GV' => [
        'logo' => 'https://images.unsplash.com/photo-1604719312566-8912e9227c6a?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?w=800&h=300&fit=crop',
    ],
    'Supermercado Economia GV' => [
        'logo' => 'https://images.unsplash.com/photo-1578916171728-46686eac8d58?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1534723452862-4c874018d66d?w=800&h=300&fit=crop',
    ],
    'Mercado Express Paulista' => [
        'logo' => 'https://images.unsplash.com/photo-1601599561213-832382fd07ba?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?w=800&h=300&fit=crop',
    ],
    'Super Moema' => [
        'logo' => 'https://images.unsplash.com/photo-1604719312566-8912e9227c6a?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1604719312566-8912e9227c6a?w=800&h=300&fit=crop',
    ],
    'Hiper BH Savassi' => [
        'logo' => 'https://images.unsplash.com/photo-1578916171728-46686eac8d58?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?w=800&h=300&fit=crop',
    ],
    'Burger King GV' => [
        'logo' => 'https://images.unsplash.com/photo-1586816001966-79b736744398?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=800&h=300&fit=crop',
    ],
    'Pizzaria Napoli' => [
        'logo' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=800&h=300&fit=crop',
    ],
    'Sushi Yama' => [
        'logo' => 'https://images.unsplash.com/photo-1579871494447-9811cf80d66c?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1553621042-f6e147245754?w=800&h=300&fit=crop',
    ],
    'Acai da Terra' => [
        'logo' => 'https://images.unsplash.com/photo-1590301157890-4810ed352733?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1615478503562-ec2d8aa0e24e?w=800&h=300&fit=crop',
    ],
    'Padaria Pao Quente' => [
        'logo' => 'https://images.unsplash.com/photo-1549931319-a545753467c8?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?w=800&h=300&fit=crop',
    ],
    'Confeitaria Doce Mel' => [
        'logo' => 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1486427944544-d2c246c4df6e?w=800&h=300&fit=crop',
    ],
    'Drogaria Saude' => [
        'logo' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1631549916768-4f8c1398ae6d?w=800&h=300&fit=crop',
    ],
    'Farma Bem' => [
        'logo' => 'https://images.unsplash.com/photo-1587854692152-cbe660dbde88?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1576602976047-174e57a47881?w=800&h=300&fit=crop',
    ],
    'Pet Amigo' => [
        'logo' => 'https://images.unsplash.com/photo-1587300003388-59208cc962cb?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1548199973-03cce0bbc87b?w=800&h=300&fit=crop',
    ],
    'Conveniencia 24h' => [
        'logo' => 'https://images.unsplash.com/photo-1604467707321-70d009801bf0?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1528698827591-e19cef51a992?w=800&h=300&fit=crop',
    ],
    'Acougue Boi Nobre' => [
        'logo' => 'https://images.unsplash.com/photo-1603048297172-c92544798d5a?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1607623814075-e51df1bdc82f?w=800&h=300&fit=crop',
    ],
    'Utilidades Casa' => [
        'logo' => 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1556228453-efd6c1ff04f6?w=800&h=300&fit=crop',
    ],
    'Espetinho do Joao' => [
        'logo' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1544025162-d76694265947?w=800&h=300&fit=crop',
    ],
    'Mercado OneMundo Admin' => [
        'logo' => 'https://images.unsplash.com/photo-1604719312566-8912e9227c6a?w=200&h=200&fit=crop',
        'banner' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?w=800&h=300&fit=crop',
    ],
];

function downloadImage($url, $savePath) {
    // SECURITY: Validate URL scheme and domain to prevent SSRF
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['https', 'http'], true)) {
        error_log("[download-images] SSRF blocked: invalid scheme in URL: $url");
        return false;
    }
    $host = parse_url($url, PHP_URL_HOST);
    $allowedHosts = ['images.unsplash.com', 'unsplash.com'];
    $hostAllowed = false;
    foreach ($allowedHosts as $ah) {
        if ($host === $ah || str_ends_with($host, '.' . $ah)) { $hostAllowed = true; break; }
    }
    if (!$hostAllowed) {
        error_log("[download-images] SSRF blocked: disallowed host: $host");
        return false;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 15,
            'user_agent' => 'SuperBora-ImageDownloader/1.0',
            'follow_location' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data && strlen($data) > 1000) {
        file_put_contents($savePath, $data);
        return true;
    }
    return false;
}

function findBestMatch($productName, $imageMap) {
    // Try exact match first
    foreach ($imageMap as $key => $url) {
        if (stripos($productName, $key) !== false) {
            return $url;
        }
    }
    return null;
}

echo "=== Downloading real product images ===\n\n";

// Download product images
$products = $db->query("SELECT product_id, name FROM om_market_products WHERE status = '1' ORDER BY product_id")->fetchAll();

$downloaded = 0;
$failed = 0;

foreach ($products as $p) {
    $url = findBestMatch($p['name'], $productImageMap);
    if (!$url) {
        echo "  [SKIP] {$p['product_id']} - {$p['name']} (no mapping)\n";
        $failed++;
        continue;
    }

    $filename = "prod_{$p['product_id']}.jpg";
    $savePath = "$productsDir/$filename";
    $dbPath = "/uploads/products/$filename";

    echo "  Downloading {$p['name']}... ";

    if (downloadImage($url, $savePath)) {
        $db->prepare("UPDATE om_market_products SET image = ? WHERE product_id = ?")->execute([$dbPath, $p['product_id']]);
        echo "OK (" . round(filesize($savePath) / 1024) . "KB)\n";
        $downloaded++;
    } else {
        echo "FAILED\n";
        $failed++;
    }

    usleep(200000); // 200ms delay to be nice to Unsplash
}

echo "\nProducts: $downloaded downloaded, $failed failed\n";

// Download partner images
echo "\n=== Downloading partner images ===\n\n";

$partners = $db->query("SELECT partner_id, name FROM om_market_partners WHERE status = '1' ORDER BY partner_id")->fetchAll();

$partnerDl = 0;
foreach ($partners as $p) {
    $mapping = $partnerImageMap[$p['name']] ?? null;
    if (!$mapping) {
        echo "  [SKIP] {$p['partner_id']} - {$p['name']} (no mapping)\n";
        continue;
    }

    // Logo
    $logoFile = "partner_{$p['partner_id']}.jpg";
    $logoPath = "$logosDir/$logoFile";
    $logoDb = "/uploads/logos/$logoFile";

    echo "  Logo for {$p['name']}... ";
    if (downloadImage($mapping['logo'], $logoPath)) {
        $db->prepare("UPDATE om_market_partners SET logo = ? WHERE partner_id = ?")->execute([$logoDb, $p['partner_id']]);
        echo "OK\n";
        $partnerDl++;
    } else {
        echo "FAILED\n";
    }

    // Banner
    $bannerFile = "banner_{$p['partner_id']}.jpg";
    $bannerPath = "$bannersDir/$bannerFile";
    $bannerDb = "/uploads/banners/$bannerFile";

    echo "  Banner for {$p['name']}... ";
    if (downloadImage($mapping['banner'], $bannerPath)) {
        $db->prepare("UPDATE om_market_partners SET banner = ? WHERE partner_id = ?")->execute([$bannerDb, $p['partner_id']]);
        echo "OK\n";
    } else {
        echo "FAILED\n";
    }

    usleep(300000); // 300ms delay
}

echo "\nPartners: $partnerDl logos + banners downloaded\n";
echo "\nDone! Clear API cache: rm -rf /tmp/api_cache/*\n";
