<?php
require_once __DIR__ . '/config/database.php';
/**
 * Ativar Shoppers de Teste
 */

try {
    $pdo = getPDO();
    
    echo "<h1>🛠️ Configurar Shoppers de Teste</h1>";
    
    // 1. Ver situação atual
    echo "<h2>📊 Situação Atual:</h2>";
    $shoppers = $pdo->query("
        SELECT shopper_id, name, partner_id, status, is_online, is_busy, can_deliver, 
               funcao, last_seen
        FROM om_market_shoppers 
        WHERE partner_id = 2
        ORDER BY shopper_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse'>";
    echo "<tr style='background:#333;color:#fff'>
            <th>ID</th><th>Nome</th><th>Partner</th><th>Status</th>
            <th>Online</th><th>Busy</th><th>Can Deliver</th><th>Função</th>
          </tr>";
    
    foreach ($shoppers as $s) {
        $status_color = $s['status'] == 1 ? '#afa' : '#faa';
        $online_color = $s['is_online'] == 1 ? '#afa' : '#faa';
        echo "<tr>
                <td>{$s['shopper_id']}</td>
                <td>{$s['name']}</td>
                <td>{$s['partner_id']}</td>
                <td style='background:$status_color'>{$s['status']}</td>
                <td style='background:$online_color'>{$s['is_online']}</td>
                <td>{$s['is_busy']}</td>
                <td>{$s['can_deliver']}</td>
                <td>{$s['funcao']}</td>
              </tr>";
    }
    echo "</table>";
    
    // 2. Ativar shoppers do partner_id = 2
    if (isset($_GET['ativar'])) {
        echo "<h2>🔧 Ativando Shoppers...</h2>";
        
        // Ativar e colocar online
        $updated = $pdo->exec("
            UPDATE om_market_shoppers 
            SET status = '1', 
                is_online = 1, 
                is_busy = 0,
                last_seen = NOW()
            WHERE partner_id = 2
        ");
        echo "<p>✅ $updated shoppers ativados e colocados online!</p>";
        
        // Colocar 2 como can_deliver = 1 (faz os dois)
        $pdo->exec("
            UPDATE om_market_shoppers 
            SET can_deliver = 1
            WHERE partner_id = 2
            LIMIT 2
        ");
        echo "<p>✅ 2 shoppers configurados como Shopper+Delivery!</p>";
        
        // Adicionar localização de teste (perto do mercado)
        $pdo->exec("
            UPDATE om_market_shoppers 
            SET current_lat = -19.9130, 
                current_lng = -43.9560,
                lat = -19.9130,
                lng = -43.9560
            WHERE partner_id = 2
        ");
        echo "<p>✅ Localização de teste adicionada!</p>";
        
        echo "<p><a href='?'>🔄 Atualizar página</a></p>";
    }
    
    // 3. Ver situação após
    if (isset($_GET['ativar'])) {
        echo "<h2>📊 Situação Após Ativação:</h2>";
        $shoppers = $pdo->query("
            SELECT shopper_id, name, partner_id, status, is_online, is_busy, can_deliver
            FROM om_market_shoppers 
            WHERE partner_id = 2
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' cellpadding='8' style='border-collapse:collapse'>";
        echo "<tr style='background:#333;color:#fff'>
                <th>ID</th><th>Nome</th><th>Status</th><th>Online</th><th>Can Deliver</th>
              </tr>";
        
        foreach ($shoppers as $s) {
            echo "<tr>
                    <td>{$s['shopper_id']}</td>
                    <td>{$s['name']}</td>
                    <td style='background:#afa'>{$s['status']}</td>
                    <td style='background:#afa'>{$s['is_online']}</td>
                    <td style='background:" . ($s['can_deliver'] ? '#afa' : '#ffa') . "'>{$s['can_deliver']}</td>
                  </tr>";
        }
        echo "</table>";
    }
    
    // Botão de ativar
    if (!isset($_GET['ativar'])) {
        echo "<br><br>";
        echo "<a href='?ativar=1' style='
            display: inline-block;
            padding: 15px 30px;
            background: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 18px;
        '>✅ ATIVAR SHOPPERS DE TESTE</a>";
    }
    
    echo "<br><br>";
    echo "<p><strong>Após ativar, volte ao simulador e teste novamente!</strong></p>";
    
} catch (PDOException $e) {
    echo "<h2>❌ Erro:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
