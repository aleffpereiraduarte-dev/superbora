<?php
$arquivo = __DIR__ . '/index.php';

// Backup
@mkdir(__DIR__ . '/backups', 0755, true);
copy($arquivo, __DIR__ . '/backups/index_' . date('Y-m-d_H-i-s') . '.php');

$conteudo = file_get_contents($arquivo);

// Fix: função toggleCart vazia
$antes = "function toggleCart() {

function goToCheckout()";

$depois = "function toggleCart() {
    document.getElementById('cartSidebar')?.classList.toggle('open');
    document.getElementById('cartOverlay')?.classList.toggle('open');
    document.body.style.overflow = document.getElementById('cartSidebar')?.classList.contains('open') ? 'hidden' : '';
}

function goToCheckout()";

$conteudo = str_replace($antes, $depois, $conteudo, $count);

file_put_contents($arquivo, $conteudo);

echo "<h1>Fix toggleCart</h1>";
echo "<p>Correções: $count</p>";

// Verificar balanço
$abre = substr_count($conteudo, '{');
$fecha = substr_count($conteudo, '}');
echo "<p>Balanço { }: " . ($abre - $fecha) . "</p>";

echo "<p><a href='/mercado/'>🛒 Testar</a></p>";
