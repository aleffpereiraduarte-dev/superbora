<?php
// Patch para adicionar memória pessoal ao one.php

$file = '/var/www/html/mercado/one.php';
$content = file_get_contents($file);

$oldCode = '            // Informações de memória
            $objetivo = $_SESSION[\'one_memoria\'][\'objetivo_conversa\'] ?? null;';

$newCode = '            // ═══ MEMÓRIA PESSOAL DO BANCO ═══
            $memoriaDB = [];
            $nomeCliente = null;
            $customerId = $_SESSION["customer_id"] ?? 0;
            $sessionIdMem = session_id();
            try {
                if ($customerId > 0) {
                    $stmtMem = $this->pdo->prepare("SELECT categoria, chave, valor FROM om_one_memoria_pessoal WHERE customer_id = ?");
                    $stmtMem->execute([$customerId]);
                } else {
                    $stmtMem = $this->pdo->prepare("SELECT categoria, chave, valor FROM om_one_memoria_pessoal WHERE session_id = ? AND customer_id = 0");
                    $stmtMem->execute([$sessionIdMem]);
                }
                while ($row = $stmtMem->fetch(PDO::FETCH_ASSOC)) {
                    $memoriaDB[$row["categoria"]][$row["chave"]] = $row["valor"];
                }
                $nomeCliente = $memoriaDB["pessoal"]["nome"] ?? $memoriaDB["nome"]["nome"] ?? null;
            } catch (Exception $e) {}
            $novosDados = one_memoria_extrair($msg);
            if (!empty($novosDados)) {
                foreach ($novosDados as $cat => $itens) {
                    foreach ($itens as $chave => $valor) {
                        try {
                            $stmtSave = $this->pdo->prepare("INSERT INTO om_one_memoria_pessoal (customer_id, session_id, categoria, chave, valor, fonte) VALUES (?, ?, ?, ?, ?, \'conversa\') ON DUPLICATE KEY UPDATE valor = VALUES(valor), vezes_mencionado = vezes_mencionado + 1");
                            $stmtSave->execute([$customerId, $sessionIdMem, $cat, $chave, $valor]);
                            $memoriaDB[$cat][$chave] = $valor;
                            if ($chave === "nome") $nomeCliente = $valor;
                        } catch (Exception $e) {}
                    }
                }
            }
            if ($nomeCliente) $memoriaTexto = "Nome: $nomeCliente. ";
            if (!empty($memoriaDB["familia"])) foreach ($memoriaDB["familia"] as $k => $v) $memoriaTexto .= ucfirst($k) . ": $v. ";
            if (!empty($memoriaDB["restricao"])) foreach ($memoriaDB["restricao"] as $k => $v) $memoriaTexto .= ucfirst($k) . ": $v. ";
            // ═══ FIM MEMÓRIA DO BANCO ═══
            
            // Informações de memória
            $objetivo = $_SESSION[\'one_memoria\'][\'objetivo_conversa\'] ?? null;';

$content = str_replace($oldCode, $newCode, $content);
file_put_contents($file, $content);
echo "Patch aplicado!\n";
