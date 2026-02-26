<?php
require_once __DIR__ . '/config/database.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre>";
echo "=== DEBUG PRECOS ===\n\n";

// Sessao
session_name('OCSESSID');
session_start();
$partner_id = $_SESSION['market_partner_id'] ?? 100;
echo "Partner: #$partner_id\n";

// Conexao
echo "Conectando...\n";
$conn = getMySQLi();
if ($conn->connect_error) {
    die("ERRO CONEXAO: " . $conn->connect_error);
}
echo "OK conectado\n\n";

// Verificar se tabela existe
echo "Verificando tabela om_market_products_sale...\n";
$r = $conn->query("SHOW TABLES LIKE 'om_market_products_sale'");
if (!$r) {
    echo "ERRO: " . $conn->error . "\n";
} else {
    if ($r->num_rows > 0) {
        echo "Tabela EXISTE\n";
    } else {
        echo "Tabela NAO EXISTE!\n";
    }
}

echo "\n";

// Contar registros
echo "Contando produtos com preco IA...\n";
$r = $conn->query("SELECT COUNT(*) as c FROM om_market_products_sale");
if (!$r) {
    echo "ERRO: " . $conn->error . "\n";
} else {
    $row = $r->fetch_assoc();
    echo "Total geral: " . $row['c'] . "\n";
}

$r = $conn->query("SELECT COUNT(*) as c FROM om_market_products_sale WHERE partner_id = $partner_id");
if (!$r) {
    echo "ERRO: " . $conn->error . "\n";
} else {
    $row = $r->fetch_assoc();
    echo "Para partner #$partner_id: " . $row['c'] . "\n";
}

echo "\n";

// Contar produtos parceiro
echo "Contando produtos do parceiro...\n";
$r = $conn->query("SELECT COUNT(*) as c FROM om_market_products_price WHERE partner_id = $partner_id");
if (!$r) {
    echo "ERRO: " . $conn->error . "\n";
} else {
    $row = $r->fetch_assoc();
    echo "Produtos parceiro: " . $row['c'] . "\n";
}

echo "\n";

// Amostra simples
echo "Amostra de precos (om_market_products_price):\n";
$r = $conn->query("SELECT product_id, price FROM om_market_products_price WHERE partner_id = $partner_id LIMIT 3");
if (!$r) {
    echo "ERRO: " . $conn->error . "\n";
} else {
    while ($row = $r->fetch_assoc()) {
        echo "  Produto #{$row['product_id']}: R$ {$row['price']}\n";
    }
}

echo "\n";

// Estrutura da tabela sale
echo "Estrutura om_market_products_sale:\n";
$r = $conn->query("DESCRIBE om_market_products_sale");
if (!$r) {
    echo "ERRO: " . $conn->error . "\n";
} else {
    while ($row = $r->fetch_assoc()) {
        echo "  - {$row['Field']} ({$row['Type']})\n";
    }
}

echo "\n=== FIM ===\n";
echo "</pre>";

$conn->close();
?>
