<?php
echo "<h1>TESTE - Arquivo atualizado em: " . date('Y-m-d H:i:s') . "</h1>";
echo "<p>Se voce ve isso, o servidor esta funcionando.</p>";
echo "<p>Agora vamos mostrar as primeiras linhas do conta.php:</p>";
echo "<pre>";
echo htmlspecialchars(file_get_contents(__DIR__ . '/conta.php', false, null, 0, 500));
echo "</pre>";
