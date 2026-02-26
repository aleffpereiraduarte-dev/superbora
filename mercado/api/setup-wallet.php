<?php
/**
 * OneMundo - Setup Wallet Tables
 * Execute este script para criar as tabelas necessarias e ativar a extensao
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getPDO();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexao: ' . $e->getMessage()]);
    exit;
}

$results = [];

// ═══════════════════════════════════════════════════════════════════════════
// 1. Criar/Atualizar tabela om_wallet
// ═══════════════════════════════════════════════════════════════════════════
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `om_wallet` (
        `wallet_id` INT(11) NOT NULL AUTO_INCREMENT,
        `customer_id` INT(11) NOT NULL,
        `balance` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
        `cashback_balance` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
        `total_deposited` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
        `total_spent` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
        `total_cashback_earned` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
        `status` TINYINT(1) NOT NULL DEFAULT '1',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`wallet_id`),
        UNIQUE KEY `customer_id` (`customer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = "Tabela om_wallet criada/verificada";
} catch (Exception $e) {
    $results[] = "Erro om_wallet: " . $e->getMessage();
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. Criar/Atualizar tabela om_wallet_transactions
// ═══════════════════════════════════════════════════════════════════════════
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `om_wallet_transactions` (
        `transaction_id` INT(11) NOT NULL AUTO_INCREMENT,
        `customer_id` INT(11) NOT NULL,
        `type` ENUM('credit','debit') NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL,
        `balance_used` DECIMAL(15,2) DEFAULT '0.00',
        `cashback_used` DECIMAL(15,2) DEFAULT '0.00',
        `order_id` INT(11) DEFAULT NULL,
        `description` VARCHAR(255) DEFAULT NULL,
        `balance_after` DECIMAL(15,2) DEFAULT '0.00',
        `status` VARCHAR(50) DEFAULT 'completed',
        `reference_type` VARCHAR(50) DEFAULT NULL,
        `reference_id` INT(11) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`transaction_id`),
        KEY `customer_id` (`customer_id`),
        KEY `order_id` (`order_id`),
        KEY `type` (`type`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = "Tabela om_wallet_transactions criada/verificada";
} catch (Exception $e) {
    $results[] = "Erro om_wallet_transactions: " . $e->getMessage();
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. Criar tabela om_wallet_deposits
// ═══════════════════════════════════════════════════════════════════════════
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `om_wallet_deposits` (
        `deposit_id` INT(11) NOT NULL AUTO_INCREMENT,
        `customer_id` INT(11) NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL,
        `payment_method` VARCHAR(50) NOT NULL DEFAULT 'pix',
        `payment_id` VARCHAR(100) DEFAULT NULL,
        `pix_code` TEXT DEFAULT NULL,
        `pix_qrcode` TEXT DEFAULT NULL,
        `status` ENUM('pending','completed','failed','expired') DEFAULT 'pending',
        `expires_at` DATETIME DEFAULT NULL,
        `completed_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`deposit_id`),
        KEY `customer_id` (`customer_id`),
        KEY `status` (`status`),
        KEY `payment_id` (`payment_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = "Tabela om_wallet_deposits criada/verificada";
} catch (Exception $e) {
    $results[] = "Erro om_wallet_deposits: " . $e->getMessage();
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. Registrar extensao de pagamento no OpenCart
// ═══════════════════════════════════════════════════════════════════════════
try {
    // Verificar se ja existe
    $stmt = $pdo->prepare("SELECT * FROM oc_extension WHERE type = 'payment' AND code = 'onemundo_wallet'");
    $stmt->execute();

    if (!$stmt->fetch()) {
        $pdo->exec("INSERT INTO oc_extension (type, code) VALUES ('payment', 'onemundo_wallet')");
        $results[] = "Extensao onemundo_wallet registrada";
    } else {
        $results[] = "Extensao onemundo_wallet ja existe";
    }
} catch (Exception $e) {
    $results[] = "Erro ao registrar extensao: " . $e->getMessage();
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. Configurar opcoes padrao
// ═══════════════════════════════════════════════════════════════════════════
$default_settings = [
    'payment_onemundo_wallet_status' => '1',
    'payment_onemundo_wallet_total' => '0',
    'payment_onemundo_wallet_order_status_id' => '2', // Processando
    'payment_onemundo_wallet_geo_zone_id' => '0',
    'payment_onemundo_wallet_sort_order' => '1',
    'payment_onemundo_wallet_show_zero' => '0',
    'payment_onemundo_wallet_allow_partial' => '0',
    'payment_onemundo_wallet_cashback_percent' => '2' // 2% de cashback
];

try {
    foreach ($default_settings as $key => $value) {
        // Verificar se ja existe
        $stmt = $pdo->prepare("SELECT * FROM oc_setting WHERE `key` = ? AND store_id = 0");
        $stmt->execute([$key]);

        if (!$stmt->fetch()) {
            $pdo->prepare("INSERT INTO oc_setting (store_id, `code`, `key`, `value`, serialized) VALUES (0, 'payment_onemundo_wallet', ?, ?, 0)")
                ->execute([$key, $value]);
        }
    }
    $results[] = "Configuracoes padrao aplicadas";
} catch (Exception $e) {
    $results[] = "Erro ao configurar: " . $e->getMessage();
}

// ═══════════════════════════════════════════════════════════════════════════
// 6. Criar carteira para clientes existentes
// ═══════════════════════════════════════════════════════════════════════════
try {
    $pdo->exec("INSERT IGNORE INTO om_wallet (customer_id, balance, cashback_balance, created_at)
        SELECT customer_id, 0, 0, NOW()
        FROM oc_customer
        WHERE customer_id NOT IN (SELECT customer_id FROM om_wallet)");

    $count = $pdo->query("SELECT COUNT(*) FROM om_wallet")->fetchColumn();
    $results[] = "Total de carteiras: $count";
} catch (Exception $e) {
    $results[] = "Erro ao criar carteiras: " . $e->getMessage();
}

// ═══════════════════════════════════════════════════════════════════════════
// Resultado final
// ═══════════════════════════════════════════════════════════════════════════
echo json_encode([
    'success' => true,
    'message' => 'Setup do Cartao OneMundo concluido!',
    'results' => $results,
    'next_steps' => [
        'Acesse o admin > Extensoes > Pagamentos > Cartao OneMundo para configurar',
        'Clientes poderao ver sua carteira em Minha Conta > Cartao OneMundo',
        'No checkout, a opcao de pagamento aparecera para clientes com saldo'
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
