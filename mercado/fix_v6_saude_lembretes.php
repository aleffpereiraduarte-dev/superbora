<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🔧 FIX v6 - SAÚDE & LEMBRETES
 * 
 * 1. Reconhece medicamentos (Mounjaro, Ozempic, etc)
 * 2. Sistema de lembretes com notificação WhatsApp
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix v6 - Saúde & Lembretes</title>";
echo "<style>body{font-family:system-ui;background:#0a0a0a;color:#e5e5e5;padding:20px;max-width:900px;margin:0 auto}h1{color:#22c55e}.card{background:#151515;border-radius:8px;padding:16px;margin:16px 0}.ok{color:#22c55e}.erro{color:#ef4444}pre{background:#0a0a0a;padding:10px;border-radius:6px;font-size:11px;overflow-x:auto}.btn{background:#22c55e;color:#000;border:none;padding:12px 24px;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px}table{width:100%;border-collapse:collapse;font-size:12px}td,th{padding:8px;border:1px solid #222;text-align:left}th{background:#1a1a1a}</style></head><body>";

echo "<h1>🔧 Fix v6 - Saúde & Lembretes</h1>";

$onePath = __DIR__ . '/one.php';

// Conectar banco
try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p class='ok'>✅ Banco conectado</p>";
} catch (Exception $e) {
    die("<p class='erro'>❌ Erro: {$e->getMessage()}</p>");
}

echo "<div class='card'>";
echo "<h3>🩺 Problemas a Corrigir</h3>";
echo "<table>
<tr><th>#</th><th>Problema</th><th>Solução</th></tr>
<tr><td>1</td><td>'Tomei Mounjaro' → 'Que delícia!'</td><td>Reconhecer medicamentos</td></tr>
<tr><td>2</td><td>Lembretes não funcionam</td><td>Criar tabela + sistema</td></tr>
<tr><td>3</td><td>Notificação WhatsApp</td><td>Integrar com API</td></tr>
</table>";
echo "</div>";

if (isset($_POST['aplicar'])) {
    
    echo "<div class='card'><h3>⚡ Aplicando Fix v6...</h3>";
    
    // 1. CRIAR TABELA DE LEMBRETES
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS om_one_lembretes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            tipo VARCHAR(50) DEFAULT 'geral',
            mensagem VARCHAR(500) NOT NULL,
            horario_lembrete DATETIME NOT NULL,
            recorrente ENUM('nao','diario','semanal','mensal') DEFAULT 'nao',
            notificado TINYINT DEFAULT 0,
            canal VARCHAR(20) DEFAULT 'whatsapp',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_horario (horario_lembrete, notificado),
            INDEX idx_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<p class='ok'>✅ Tabela om_one_lembretes criada</p>";
    
    // 2. BACKUP DO ONE.PHP
    $backup = $onePath . '.bkp_v6_' . date('His');
    copy($onePath, $backup);
    echo "<p class='ok'>✅ Backup: " . basename($backup) . "</p>";
    
    $conteudo = file_get_contents($onePath);
    
    // 3. CÓDIGO PARA SAÚDE/MEDICAMENTOS
    $codigoSaude = '
            // ═══ FIX v6: SAÚDE E MEDICAMENTOS ═══
            $medicamentos = [
                \'mounjaro\' => [\'tipo\' => \'diabetes/emagrecimento\', \'uso\' => \'injeção semanal\'],
                \'ozempic\' => [\'tipo\' => \'diabetes/emagrecimento\', \'uso\' => \'injeção semanal\'],
                \'wegovy\' => [\'tipo\' => \'emagrecimento\', \'uso\' => \'injeção semanal\'],
                \'saxenda\' => [\'tipo\' => \'emagrecimento\', \'uso\' => \'injeção diária\'],
                \'victoza\' => [\'tipo\' => \'diabetes\', \'uso\' => \'injeção diária\'],
                \'trulicity\' => [\'tipo\' => \'diabetes\', \'uso\' => \'injeção semanal\'],
                \'insulina\' => [\'tipo\' => \'diabetes\', \'uso\' => \'injeção\'],
                \'metformina\' => [\'tipo\' => \'diabetes\', \'uso\' => \'comprimido\'],
                \'losartana\' => [\'tipo\' => \'pressão alta\', \'uso\' => \'comprimido\'],
                \'atenolol\' => [\'tipo\' => \'pressão alta\', \'uso\' => \'comprimido\'],
                \'omeprazol\' => [\'tipo\' => \'estômago\', \'uso\' => \'comprimido\'],
                \'rivotril\' => [\'tipo\' => \'ansiedade\', \'uso\' => \'comprimido\'],
                \'fluoxetina\' => [\'tipo\' => \'antidepressivo\', \'uso\' => \'comprimido\'],
                \'sertralina\' => [\'tipo\' => \'antidepressivo\', \'uso\' => \'comprimido\'],
            ];
            
            // Detectar menção de medicamento
            $msgLower = mb_strtolower($msg, \'UTF-8\');
            foreach ($medicamentos as $remedio => $info) {
                if (strpos($msgLower, $remedio) !== false) {
                    // Detectar contexto: tomou, vai tomar, esqueceu
                    if (preg_match(\'/(tomei|usei|apliquei|fiz|dei)/i\', $msg)) {
                        $respostas = [
                            "Boa! Que bom que você tá cuidando da saúde! 💚 Como tá se sentindo?",
                            "Isso aí! Manter o tratamento em dia é importante. Tá tudo bem com você?",
                            "Ótimo que você lembrou! Quer que eu te ajude a lembrar das próximas doses?"
                        ];
                    } elseif (preg_match(\'/(esqueci|perdi|pulei)/i\', $msg)) {
                        $respostas = [
                            "Ih, acontece! Não se preocupa demais. Quer que eu te ajude a lembrar na próxima?",
                            "Relaxa, uma vez ou outra acontece. Posso criar um lembrete pra você!",
                            "Sem stress! Quer que eu te lembre no horário certo?"
                        ];
                    } elseif (preg_match(\'/(vou tomar|tenho que|preciso)/i\', $msg)) {
                        $respostas = [
                            "Boa! Quer que eu te lembre no horário certo?",
                            "Beleza! Posso te mandar um lembrete se quiser.",
                            "Combinado! Me fala o horário que eu te aviso."
                        ];
                    } else {
                        $respostas = [
                            "Entendi! É um medicamento importante. Tá seguindo direitinho o tratamento?",
                            "Sei! Quer que eu te ajude a lembrar dos horários?",
                            "Entendi! Qualquer coisa sobre seus horários de medicação, me avisa que eu ajudo!"
                        ];
                    }
                    
                    $resp = $respostas[array_rand($respostas)];
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'saude\', \'medicamento\' => $remedio]);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM SAÚDE E MEDICAMENTOS ═══
';
    
    // 4. CÓDIGO PARA LEMBRETES
    $codigoLembretes = '
            // ═══ FIX v6: SISTEMA DE LEMBRETES ═══
            if (preg_match(\'/(me lembra|me avisa|me notifica|lembrete|lembrar).*(daqui|em|às|as|hora|minuto)/i\', $msg)) {
                
                // Extrair tempo
                $minutos = 0;
                if (preg_match(\'/(\d+)\s*(hora|h)/i\', $msg, $m)) {
                    $minutos = intval($m[1]) * 60;
                }
                if (preg_match(\'/(\d+)\s*(minuto|min|m(?!e))/i\', $msg, $m)) {
                    $minutos += intval($m[1]);
                }
                
                // Se não especificou tempo mas mencionou lembrete
                if ($minutos == 0) {
                    $resp = "Claro! Em quanto tempo você quer que eu te lembre? (ex: 30 minutos, 2 horas)";
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'lembrete_pergunta\']);
                    $_SESSION[\'one_conversa\'][\'aguardando_tempo_lembrete\'] = $msg;
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
                
                // Extrair o que lembrar
                $oQueLembrar = preg_replace(\'/(me lembra|me avisa|lembrete|lembrar|daqui|em|às|as|\d+|hora|horas|minuto|minutos|h|min|que|de|tenho|preciso)/i\', \'\', $msg);
                $oQueLembrar = trim(preg_replace(\'/\s+/\', \' \', $oQueLembrar));
                
                if (empty($oQueLembrar)) {
                    $oQueLembrar = "Lembrete programado";
                }
                
                // Calcular horário
                $horario = date(\'Y-m-d H:i:s\', strtotime("+$minutos minutes"));
                
                // Salvar no banco
                if ($this->pdo && $this->customer_id) {
                    try {
                        $stmt = $this->pdo->prepare("INSERT INTO om_one_lembretes (customer_id, tipo, mensagem, horario_lembrete, canal) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$this->customer_id, \'geral\', $oQueLembrar, $horario, \'whatsapp\']);
                        
                        $tempoTexto = "";
                        if ($minutos >= 60) {
                            $h = floor($minutos / 60);
                            $m = $minutos % 60;
                            $tempoTexto = $h . " hora" . ($h > 1 ? "s" : "");
                            if ($m > 0) $tempoTexto .= " e $m minuto" . ($m > 1 ? "s" : "");
                        } else {
                            $tempoTexto = "$minutos minuto" . ($minutos > 1 ? "s" : "");
                        }
                        
                        $resp = "Combinado! ⏰ Vou te lembrar de \"$oQueLembrar\" daqui $tempoTexto (às " . date(\'H:i\', strtotime($horario)) . "). Pode deixar comigo! 💚";
                        
                    } catch (Exception $e) {
                        $resp = "Ih, deu um probleminha pra salvar o lembrete. Tenta de novo?";
                    }
                } else {
                    $resp = "Preciso que você esteja logado pra criar lembretes. Faz login e tenta de novo! 💚";
                }
                
                $this->salvar(\'one\', $resp, [\'fonte\' => \'lembrete\', \'minutos\' => $minutos]);
                return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
            }
            
            // Se estava aguardando tempo do lembrete
            if (!empty($_SESSION[\'one_conversa\'][\'aguardando_tempo_lembrete\'])) {
                $minutos = 0;
                if (preg_match(\'/(\d+)\s*(hora|h)/i\', $msg, $m)) {
                    $minutos = intval($m[1]) * 60;
                }
                if (preg_match(\'/(\d+)\s*(minuto|min)/i\', $msg, $m)) {
                    $minutos += intval($m[1]);
                }
                if (preg_match(\'/^(\d+)$/\', trim($msg), $m)) {
                    $minutos = intval($m[1]); // Assume minutos se só número
                }
                
                if ($minutos > 0) {
                    $oQueLembrar = $_SESSION[\'one_conversa\'][\'aguardando_tempo_lembrete\'];
                    $oQueLembrar = preg_replace(\'/(me lembra|me avisa|lembrete|lembrar|que|de|tenho|preciso)/i\', \'\', $oQueLembrar);
                    $oQueLembrar = trim(preg_replace(\'/\s+/\', \' \', $oQueLembrar));
                    if (empty($oQueLembrar)) $oQueLembrar = "Lembrete";
                    
                    $horario = date(\'Y-m-d H:i:s\', strtotime("+$minutos minutes"));
                    
                    if ($this->pdo && $this->customer_id) {
                        $this->pdo->prepare("INSERT INTO om_one_lembretes (customer_id, tipo, mensagem, horario_lembrete, canal) VALUES (?, ?, ?, ?, ?)")
                            ->execute([$this->customer_id, \'geral\', $oQueLembrar, $horario, \'whatsapp\']);
                    }
                    
                    unset($_SESSION[\'one_conversa\'][\'aguardando_tempo_lembrete\']);
                    
                    $resp = "Pronto! ⏰ Lembrete criado pra daqui $minutos minutos. Te aviso! 💚";
                    $this->salvar(\'one\', $resp, [\'fonte\' => \'lembrete_confirmado\']);
                    return [\'success\' => true, \'response\' => $resp, \'carrinho\' => $this->getCarrinho(), \'total\' => $this->getTotal(), \'itens\' => count($_SESSION[\'one_conversa\'][\'carrinho\'] ?? [])];
                }
            }
            // ═══ FIM SISTEMA DE LEMBRETES ═══
';
    
    // Inserir códigos
    $inseridos = 0;
    
    // Verificar se já existe
    if (strpos($conteudo, 'FIX v6: SAÚDE E MEDICAMENTOS') !== false) {
        echo "<p class='ok'>⚠️ Fix de saúde já existe</p>";
    } else {
        // Encontrar onde inserir
        $marcador = '// ═══ RESPOSTA SOBRE NOME DO CLIENTE ═══';
        if (strpos($conteudo, $marcador) !== false) {
            $conteudo = str_replace($marcador, $codigoSaude . "\n" . $codigoLembretes . "\n            " . $marcador, $conteudo);
            echo "<p class='ok'>✅ Código de saúde inserido</p>";
            echo "<p class='ok'>✅ Código de lembretes inserido</p>";
            $inseridos = 2;
        } else {
            // Tenta outros marcadores
            $marcadores = [
                '// ═══ FIX v4: MEGA DETECTOR ═══',
                '// ═══ FIX: LIMPAR CONTEXTO PRESO ═══',
                'function processar($msg)'
            ];
            foreach ($marcadores as $m) {
                if (strpos($conteudo, $m) !== false) {
                    $conteudo = str_replace($m, $codigoSaude . "\n" . $codigoLembretes . "\n            " . $m, $conteudo);
                    echo "<p class='ok'>✅ Códigos inseridos antes de: $m</p>";
                    $inseridos = 2;
                    break;
                }
            }
        }
    }
    
    // Salvar
    file_put_contents($onePath, $conteudo);
    
    // Verificar sintaxe
    $check = shell_exec("php -l $onePath 2>&1");
    $sintaxeOk = strpos($check, 'No syntax errors') !== false;
    
    echo "</div>";
    
    if ($sintaxeOk && $inseridos > 0) {
        echo "<div class='card' style='border:2px solid #22c55e'>";
        echo "<h3 class='ok'>✅ FIX v6 APLICADO!</h3>";
        echo "<br><b>Agora a ONE:</b>";
        echo "<ul>
            <li>🩺 Reconhece medicamentos (Mounjaro, Ozempic, etc)</li>
            <li>⏰ Cria lembretes com horário</li>
            <li>📱 Preparado pra WhatsApp</li>
        </ul>";
        echo "<br><b>Testa:</b>";
        echo "<ul>
            <li>\"tomei mounjaro hoje\"</li>
            <li>\"me lembra de beber água em 30 minutos\"</li>
            <li>\"lembrete: reunião daqui 2 horas\"</li>
        </ul>";
        echo "<p><a href='one.php' class='btn'>💬 Testar ONE</a></p>";
        echo "</div>";
    } elseif (!$sintaxeOk) {
        echo "<div class='card' style='border:2px solid #ef4444'>";
        echo "<h3 class='erro'>❌ Erro de Sintaxe</h3>";
        echo "<pre>$check</pre>";
        copy($backup, $onePath);
        echo "<p class='ok'>✅ Backup restaurado</p>";
        echo "</div>";
    } else {
        echo "<div class='card'><p class='erro'>❌ Não conseguiu inserir</p></div>";
    }
    
} else {
    echo "<div class='card' style='text-align:center'>";
    echo "<form method='post'>";
    echo "<button type='submit' name='aplicar' class='btn' style='font-size:16px;padding:14px 28px'>🔧 APLICAR FIX v6</button>";
    echo "</form>";
    echo "</div>";
}

echo "</body></html>";
