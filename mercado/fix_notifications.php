<?php
require_once __DIR__ . '/config/database.php';
/**
 * Verificar e corrigir tabela om_notifications
 */

try {
    $pdo = getPDO();
    
    echo "<h1>🔧 Verificar Tabela om_notifications</h1>";
    
    // 1. Ver estrutura atual
    echo "<h2>📊 Estrutura Atual:</h2>";
    try {
        $cols = $pdo->query("DESCRIBE om_notifications")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='8' style='border-collapse:collapse'>";
        echo "<tr style='background:#333;color:#fff'><th>Campo</th><th>Tipo</th><th>Null</th><th>Default</th></tr>";
        foreach ($cols as $c) {
            echo "<tr>
                    <td><strong>{$c['Field']}</strong></td>
                    <td>{$c['Type']}</td>
                    <td>{$c['Null']}</td>
                    <td>{$c['Default']}</td>
                  </tr>";
        }
        echo "</table>";
        
        // Listar colunas existentes
        $existing_cols = array_column($cols, 'Field');
        echo "<p>Colunas: " . implode(", ", $existing_cols) . "</p>";
        
    } catch (Exception $e) {
        echo "<p>❌ Tabela não existe, vou criar...</p>";
        
        $pdo->exec("
            CREATE TABLE om_notifications (
                notification_id INT AUTO_INCREMENT PRIMARY KEY,
                user_type ENUM('customer', 'shopper', 'delivery', 'partner', 'admin') NOT NULL,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT,
                type VARCHAR(50) DEFAULT 'general',
                data JSON,
                status ENUM('unread', 'read', 'dismissed') DEFAULT 'unread',
                read_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_user (user_type, user_id),
                INDEX idx_status (status),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "<p>✅ Tabela criada!</p>";
    }
    
    // 2. Verificar se precisa adicionar colunas
    echo "<h2>🔧 Verificando colunas necessárias...</h2>";
    
    $needed_cols = [
        'type' => "VARCHAR(50) DEFAULT 'general'",
        'data' => "JSON NULL",
        'user_type' => "VARCHAR(20) DEFAULT 'customer'",
        'user_id' => "INT DEFAULT 0",
        'title' => "VARCHAR(255) NULL",
        'body' => "TEXT NULL",
        'status' => "VARCHAR(20) DEFAULT 'unread'"
    ];
    
    $cols = $pdo->query("DESCRIBE om_notifications")->fetchAll(PDO::FETCH_ASSOC);
    $existing_cols = array_column($cols, 'Field');
    
    foreach ($needed_cols as $col => $definition) {
        if (!in_array($col, $existing_cols)) {
            try {
                $pdo->exec("ALTER TABLE om_notifications ADD COLUMN $col $definition");
                echo "<p>✅ Coluna <strong>$col</strong> adicionada!</p>";
            } catch (Exception $e) {
                echo "<p>⚠️ Erro ao adicionar $col: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p>✅ Coluna <strong>$col</strong> já existe</p>";
        }
    }
    
    // 3. Ver dados existentes
    echo "<h2>📋 Dados existentes:</h2>";
    $notifs = $pdo->query("SELECT * FROM om_notifications ORDER BY notification_id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if ($notifs) {
        echo "<pre>" . print_r($notifs, true) . "</pre>";
    } else {
        echo "<p>Tabela vazia</p>";
    }
    
    // 4. Testar INSERT
    echo "<h2>🧪 Testando INSERT:</h2>";
    try {
        $pdo->prepare("
            INSERT INTO om_notifications (user_type, user_id, title, body, type, data, status, created_at)
            VALUES ('shopper', 80, 'Teste', 'Mensagem teste', 'new_offer', ?, 'unread', NOW())
        ")->execute([json_encode(['test' => true])]);
        
        $id = $pdo->lastInsertId();
        echo "<p>✅ INSERT funcionou! ID: $id</p>";
        
        // Limpar teste
        $pdo->exec("DELETE FROM om_notifications WHERE notification_id = $id");
        echo "<p>✅ Registro de teste removido</p>";
        
    } catch (Exception $e) {
        echo "<p>❌ Erro no INSERT: " . $e->getMessage() . "</p>";
    }
    
    echo "<h2>✅ Pronto! Teste o simulador novamente.</h2>";
    
} catch (PDOException $e) {
    echo "<h2>❌ Erro:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
