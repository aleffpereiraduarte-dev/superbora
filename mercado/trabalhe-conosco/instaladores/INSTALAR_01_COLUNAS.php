<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * 🔧 INSTALADOR 1 - Colunas do Banco de Dados
 * Upload em: /mercado/trabalhe-conosco/INSTALAR_01_COLUNAS.php
 * 
 * Adiciona colunas faltando em om_market_workers:
 * - password_hash, verification_code, verified_at (login)
 * - bank_pix_key, bank_pix_type, bank_name, bank_agency, bank_account (PIX)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 1 - Colunas</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #fff; padding: 30px; min-height: 100vh; }
.container { max-width: 800px; margin: 0 auto; }
h1 { color: #667eea; }
.card { background: rgba(255,255,255,0.05); border-radius: 16px; padding: 25px; margin-bottom: 20px; border: 1px solid rgba(255,255,255,0.1); }
.ok { color: #00b894; }
.erro { color: #e74c3c; }
.aviso { color: #f39c12; }
.step { padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
pre { background: #0f0f1a; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 0.85rem; }
.btn { display: inline-block; padding: 15px 30px; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; margin-top: 20px; }
</style></head><body>";

echo "<div class='container'>";
echo "<h1>🔧 Instalador 1 - Colunas do Banco</h1>";
echo "<p style='opacity:0.7;'>Adiciona colunas faltando para login e PIX</p>";

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='card'><h3>✅ Conexão OK</h3></div>";
    
    // Verificar colunas existentes
    $existingCols = $pdo->query("DESCRIBE om_market_workers")->fetchAll(PDO::FETCH_COLUMN);
    
    // Colunas a adicionar
    $columnsToAdd = [
        // Login
        'password_hash' => "VARCHAR(255) DEFAULT NULL COMMENT 'Hash da senha'",
        'verification_code' => "VARCHAR(10) DEFAULT NULL COMMENT 'Código de verificação SMS/Email'",
        'verification_code_expires' => "DATETIME DEFAULT NULL COMMENT 'Expiração do código'",
        'verified_at' => "DATETIME DEFAULT NULL COMMENT 'Data de verificação'",
        'verified_phone' => "TINYINT(1) DEFAULT 0 COMMENT 'Telefone verificado'",
        'verified_email' => "TINYINT(1) DEFAULT 0 COMMENT 'Email verificado'",
        'last_login_at' => "DATETIME DEFAULT NULL COMMENT 'Último login'",
        'login_attempts' => "INT DEFAULT 0 COMMENT 'Tentativas de login'",
        'blocked_until' => "DATETIME DEFAULT NULL COMMENT 'Bloqueado até'",
        
        // Dados bancários PIX
        'bank_pix_key' => "VARCHAR(100) DEFAULT NULL COMMENT 'Chave PIX'",
        'bank_pix_type' => "ENUM('cpf','cnpj','email','phone','random') DEFAULT NULL COMMENT 'Tipo da chave PIX'",
        'bank_name' => "VARCHAR(100) DEFAULT NULL COMMENT 'Nome do banco'",
        'bank_agency' => "VARCHAR(20) DEFAULT NULL COMMENT 'Agência'",
        'bank_account' => "VARCHAR(30) DEFAULT NULL COMMENT 'Conta'",
        'bank_account_type' => "ENUM('corrente','poupanca') DEFAULT NULL COMMENT 'Tipo de conta'",
        'bank_holder_name' => "VARCHAR(150) DEFAULT NULL COMMENT 'Nome do titular'",
        'bank_holder_cpf' => "VARCHAR(14) DEFAULT NULL COMMENT 'CPF do titular'",
        
        // Configurações do worker
        'accept_offers_auto' => "TINYINT(1) DEFAULT 0 COMMENT 'Aceitar ofertas automaticamente'",
        'max_distance_km' => "INT DEFAULT 10 COMMENT 'Distância máxima para ofertas'",
        'preferred_stores' => "TEXT DEFAULT NULL COMMENT 'Lojas preferidas (JSON)'",
        'work_mode' => "ENUM('shopping','delivery','both') DEFAULT 'both' COMMENT 'Modo de trabalho (Full Service)'",
        
        // Métricas rápidas
        'today_orders' => "INT DEFAULT 0 COMMENT 'Pedidos hoje'",
        'today_earnings' => "DECIMAL(10,2) DEFAULT 0 COMMENT 'Ganhos hoje'",
        'week_orders' => "INT DEFAULT 0 COMMENT 'Pedidos na semana'",
        'week_earnings' => "DECIMAL(10,2) DEFAULT 0 COMMENT 'Ganhos na semana'",
        
        // Notificações
        'fcm_token' => "VARCHAR(500) DEFAULT NULL COMMENT 'Token Firebase para push'",
        'notify_new_offers' => "TINYINT(1) DEFAULT 1 COMMENT 'Notificar novas ofertas'",
        'notify_promotions' => "TINYINT(1) DEFAULT 1 COMMENT 'Notificar promoções'",
    ];
    
    echo "<div class='card'>";
    echo "<h3>📊 Adicionando Colunas</h3>";
    
    $added = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($columnsToAdd as $col => $definition) {
        echo "<div class='step'>";
        
        if (in_array($col, $existingCols)) {
            echo "<span class='aviso'>⏭️</span> <code>$col</code> - Já existe";
            $skipped++;
        } else {
            try {
                $sql = "ALTER TABLE om_market_workers ADD COLUMN $col $definition";
                $pdo->exec($sql);
                echo "<span class='ok'>✅</span> <code>$col</code> - Adicionada";
                $added++;
            } catch (Exception $e) {
                echo "<span class='erro'>❌</span> <code>$col</code> - Erro: " . $e->getMessage();
                $errors++;
            }
        }
        echo "</div>";
    }
    
    echo "</div>";
    
    // Resumo
    echo "<div class='card'>";
    echo "<h3>📋 Resumo</h3>";
    echo "<div class='step'><span class='ok'>✅</span> $added colunas adicionadas</div>";
    echo "<div class='step'><span class='aviso'>⏭️</span> $skipped colunas já existiam</div>";
    if ($errors > 0) {
        echo "<div class='step'><span class='erro'>❌</span> $errors erros</div>";
    }
    echo "</div>";
    
    // Verificar estrutura final
    echo "<div class='card'>";
    echo "<h3>🔍 Estrutura Atual</h3>";
    $cols = $pdo->query("DESCRIBE om_market_workers")->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Total: <strong>" . count($cols) . " colunas</strong></p>";
    
    // Mostrar colunas importantes
    $importantCols = ['password_hash', 'verification_code', 'verified_at', 'bank_pix_key', 'bank_pix_type', 'work_mode'];
    echo "<h4>Colunas críticas:</h4>";
    foreach ($importantCols as $c) {
        $exists = in_array($c, array_column($cols, 'Field'));
        $icon = $exists ? '✅' : '❌';
        echo "<div>$icon <code>$c</code></div>";
    }
    echo "</div>";
    
    echo "<div class='card'>";
    echo "<h3>✅ Instalação Concluída!</h3>";
    echo "<p>Próximo passo: <a href='INSTALAR_02_PAGINAS.php' class='btn'>Instalar Páginas →</a></p>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div class='card'><p class='erro'>❌ Erro: " . $e->getMessage() . "</p></div>";
}

echo "<p style='margin-top:30px;opacity:0.5;text-align:center;'>⚠️ Delete este arquivo após usar</p>";
echo "</div></body></html>";
?>
