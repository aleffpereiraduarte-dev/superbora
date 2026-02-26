<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          📦 INSTALADOR 01 - TABELAS DO BANCO DE DADOS                                ║
 * ║                   OneMundo Workers System v3.0                                       ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 * 
 * Cria todas as tabelas necessárias para o sistema de workers:
 * - om_workers (dados principais)
 * - om_worker_vehicles (veículos)
 * - om_worker_documents (documentos)
 * - om_worker_bank_accounts (dados bancários)
 * - om_worker_wallet (carteira/transações)
 * - om_worker_ratings (avaliações)
 * - om_worker_notifications (notificações)
 * - om_worker_schedules (agenda)
 * - om_worker_challenges (desafios/gamificação)
 * - om_worker_zones (zonas de atuação)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Sao_Paulo');

// Conexão
$conn = getMySQLi();
if ($conn->connect_error) die("❌ Erro de conexão: " . $conn->connect_error);
$conn->set_charset('utf8mb4');

$resultados = [];
$erros = [];

// ═══════════════════════════════════════════════════════════════════════════════
// TABELAS PRINCIPAIS
// ═══════════════════════════════════════════════════════════════════════════════

$tabelas = [
    // 1. Tabela principal de workers
    'om_workers' => "
        CREATE TABLE IF NOT EXISTS om_workers (
            worker_id INT AUTO_INCREMENT PRIMARY KEY,
            
            -- Dados pessoais
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE,
            phone VARCHAR(20) UNIQUE NOT NULL,
            cpf VARCHAR(14) UNIQUE,
            rg VARCHAR(20),
            birth_date DATE,
            gender ENUM('M','F','O') DEFAULT 'M',
            photo VARCHAR(255),
            
            -- Tipo de worker
            worker_type ENUM('shopper','delivery','fullservice') DEFAULT 'shopper',
            is_shopper TINYINT(1) DEFAULT 1,
            is_delivery TINYINT(1) DEFAULT 0,
            
            -- Autenticação
            password_hash VARCHAR(255),
            
            -- Status
            status ENUM('pendente','em_analise','aprovado','rejeitado','bloqueado','inativo') DEFAULT 'pendente',
            rejection_reason TEXT,
            approved_at DATETIME,
            approved_by INT,
            
            -- Operacional
            is_online TINYINT(1) DEFAULT 0,
            is_paused TINYINT(1) DEFAULT 0,
            pause_until DATETIME,
            last_seen DATETIME,
            last_location_lat DECIMAL(10,8),
            last_location_lng DECIMAL(11,8),
            
            -- Verificação facial
            facial_photo VARCHAR(255),
            last_facial_verification DATETIME,
            facial_verified TINYINT(1) DEFAULT 0,
            
            -- Métricas
            rating DECIMAL(3,2) DEFAULT 5.00,
            total_orders INT DEFAULT 0,
            total_deliveries INT DEFAULT 0,
            completed_orders INT DEFAULT 0,
            cancelled_orders INT DEFAULT 0,
            acceptance_rate DECIMAL(5,2) DEFAULT 100.00,
            
            -- Financeiro
            balance DECIMAL(12,2) DEFAULT 0.00,
            pending_balance DECIMAL(12,2) DEFAULT 0.00,
            total_earnings DECIMAL(12,2) DEFAULT 0.00,
            
            -- Gamificação
            level INT DEFAULT 1,
            xp_points INT DEFAULT 0,
            badge VARCHAR(50) DEFAULT 'bronze',
            
            -- Referral
            referral_code VARCHAR(10) UNIQUE,
            referred_by INT,
            
            -- Parceiro vinculado (se exclusivo)
            partner_id INT,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
            
            -- Índices
            INDEX idx_phone (phone),
            INDEX idx_cpf (cpf),
            INDEX idx_status (status),
            INDEX idx_type (worker_type),
            INDEX idx_online (is_online),
            INDEX idx_location (last_location_lat, last_location_lng),
            INDEX idx_partner (partner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 2. Veículos dos workers
    'om_worker_vehicles' => "
        CREATE TABLE IF NOT EXISTS om_worker_vehicles (
            vehicle_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            
            -- Tipo
            vehicle_type ENUM('bike','moto','carro','van') NOT NULL,
            
            -- Dados do veículo (para moto/carro)
            brand VARCHAR(50),
            model VARCHAR(50),
            year INT,
            color VARCHAR(30),
            plate VARCHAR(10),
            renavam VARCHAR(20),
            
            -- Documentos
            crlv_url VARCHAR(255),
            crlv_status ENUM('pendente','aprovado','rejeitado') DEFAULT 'pendente',
            
            -- Status
            is_active TINYINT(1) DEFAULT 1,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            INDEX idx_worker (worker_id),
            INDEX idx_type (vehicle_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 3. Documentos dos workers
    'om_worker_documents' => "
        CREATE TABLE IF NOT EXISTS om_worker_documents (
            document_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            
            -- Tipo de documento
            doc_type ENUM('cpf','rg','cnh','comprovante_residencia','selfie','antecedentes','mei','outro') NOT NULL,
            
            -- Arquivo
            file_url VARCHAR(255) NOT NULL,
            file_name VARCHAR(100),
            
            -- Status
            status ENUM('pendente','aprovado','rejeitado') DEFAULT 'pendente',
            rejection_reason VARCHAR(255),
            reviewed_by INT,
            reviewed_at DATETIME,
            
            -- Validade
            expires_at DATE,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            INDEX idx_worker (worker_id),
            INDEX idx_type (doc_type),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 4. Dados bancários
    'om_worker_bank_accounts' => "
        CREATE TABLE IF NOT EXISTS om_worker_bank_accounts (
            account_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL UNIQUE,
            
            -- Banco
            bank_code VARCHAR(10),
            bank_name VARCHAR(50),
            
            -- Conta
            account_type ENUM('corrente','poupanca') DEFAULT 'corrente',
            agency VARCHAR(10),
            agency_digit VARCHAR(2),
            account_number VARCHAR(20),
            account_digit VARCHAR(2),
            
            -- PIX
            pix_key VARCHAR(100),
            pix_key_type ENUM('cpf','email','phone','random'),
            
            -- Titular
            holder_name VARCHAR(100),
            holder_cpf VARCHAR(14),
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 5. Transações da carteira
    'om_worker_wallet' => "
        CREATE TABLE IF NOT EXISTS om_worker_wallet (
            transaction_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            
            -- Pedido relacionado
            order_id INT,
            
            -- Tipo
            type ENUM('earning','bonus','tip','adjustment','withdrawal','refund','penalty') NOT NULL,
            
            -- Valores
            amount DECIMAL(10,2) NOT NULL,
            balance_after DECIMAL(12,2),
            
            -- Descrição
            description VARCHAR(255),
            
            -- Status (para saques)
            status ENUM('pending','completed','failed','cancelled') DEFAULT 'completed',
            
            -- Método de saque
            withdrawal_method VARCHAR(20),
            withdrawal_reference VARCHAR(100),
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            INDEX idx_worker (worker_id),
            INDEX idx_type (type),
            INDEX idx_status (status),
            INDEX idx_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 6. Avaliações
    'om_worker_ratings' => "
        CREATE TABLE IF NOT EXISTS om_worker_ratings (
            rating_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            order_id INT,
            
            -- Quem avaliou
            rated_by ENUM('customer','partner','system') DEFAULT 'customer',
            customer_id INT,
            
            -- Avaliação
            rating TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
            
            -- Tags
            tags JSON,
            
            -- Comentário
            comment TEXT,
            
            -- Resposta do worker
            worker_response TEXT,
            
            -- Status
            is_visible TINYINT(1) DEFAULT 1,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            INDEX idx_worker (worker_id),
            INDEX idx_rating (rating),
            INDEX idx_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 7. Notificações
    'om_worker_notifications' => "
        CREATE TABLE IF NOT EXISTS om_worker_notifications (
            notification_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            
            -- Tipo
            type ENUM('order','payment','promotion','system','rating','challenge','alert') NOT NULL,
            
            -- Conteúdo
            title VARCHAR(100) NOT NULL,
            message TEXT,
            icon VARCHAR(50),
            
            -- Dados extras (JSON)
            data JSON,
            
            -- Ação
            action_url VARCHAR(255),
            
            -- Status
            is_read TINYINT(1) DEFAULT 0,
            read_at DATETIME,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            INDEX idx_worker (worker_id),
            INDEX idx_unread (worker_id, is_read),
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 8. Agenda/Horários
    'om_worker_schedules' => "
        CREATE TABLE IF NOT EXISTS om_worker_schedules (
            schedule_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            
            -- Dia da semana (0=Dom, 1=Seg, ..., 6=Sab)
            day_of_week TINYINT NOT NULL,
            
            -- Horários
            start_time TIME,
            end_time TIME,
            
            -- Ativo
            is_active TINYINT(1) DEFAULT 1,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            UNIQUE KEY unique_schedule (worker_id, day_of_week),
            INDEX idx_worker (worker_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 9. Desafios e Gamificação
    'om_worker_challenges' => "
        CREATE TABLE IF NOT EXISTS om_worker_challenges (
            challenge_id INT AUTO_INCREMENT PRIMARY KEY,
            
            -- Info do desafio
            title VARCHAR(100) NOT NULL,
            description TEXT,
            icon VARCHAR(50),
            
            -- Tipo
            type ENUM('daily','weekly','monthly','special','achievement') DEFAULT 'daily',
            
            -- Meta
            target_type ENUM('orders','deliveries','rating','earnings','streak','speed') NOT NULL,
            target_value INT NOT NULL,
            
            -- Recompensa
            reward_type ENUM('xp','cash','badge','multiplier') DEFAULT 'xp',
            reward_value DECIMAL(10,2) NOT NULL,
            
            -- Período
            starts_at DATETIME,
            ends_at DATETIME,
            
            -- Status
            is_active TINYINT(1) DEFAULT 1,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 10. Progresso nos desafios
    'om_worker_challenge_progress' => "
        CREATE TABLE IF NOT EXISTS om_worker_challenge_progress (
            progress_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            challenge_id INT NOT NULL,
            
            -- Progresso
            current_value INT DEFAULT 0,
            
            -- Status
            is_completed TINYINT(1) DEFAULT 0,
            completed_at DATETIME,
            
            -- Recompensa recebida
            reward_claimed TINYINT(1) DEFAULT 0,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            FOREIGN KEY (challenge_id) REFERENCES om_worker_challenges(challenge_id) ON DELETE CASCADE,
            UNIQUE KEY unique_progress (worker_id, challenge_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 11. Zonas de atuação
    'om_worker_zones' => "
        CREATE TABLE IF NOT EXISTS om_worker_zones (
            zone_id INT AUTO_INCREMENT PRIMARY KEY,
            
            -- Info
            name VARCHAR(50) NOT NULL,
            description TEXT,
            
            -- Localização (centro)
            center_lat DECIMAL(10,8) NOT NULL,
            center_lng DECIMAL(11,8) NOT NULL,
            radius_km INT DEFAULT 5,
            
            -- GeoJSON do polígono (opcional)
            polygon JSON,
            
            -- Multiplicadores
            base_multiplier DECIMAL(3,2) DEFAULT 1.00,
            current_multiplier DECIMAL(3,2) DEFAULT 1.00,
            
            -- Demanda
            demand_level ENUM('low','normal','high','surge') DEFAULT 'normal',
            
            -- Status
            is_active TINYINT(1) DEFAULT 1,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 12. Workers em zonas
    'om_worker_zone_preferences' => "
        CREATE TABLE IF NOT EXISTS om_worker_zone_preferences (
            pref_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            zone_id INT NOT NULL,
            
            -- Prioridade
            priority INT DEFAULT 1,
            
            -- Ativo
            is_active TINYINT(1) DEFAULT 1,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            FOREIGN KEY (zone_id) REFERENCES om_worker_zones(zone_id) ON DELETE CASCADE,
            UNIQUE KEY unique_pref (worker_id, zone_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 13. Histórico de localização
    'om_worker_location_history' => "
        CREATE TABLE IF NOT EXISTS om_worker_location_history (
            log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            
            -- Localização
            lat DECIMAL(10,8) NOT NULL,
            lng DECIMAL(11,8) NOT NULL,
            accuracy DECIMAL(6,2),
            
            -- Velocidade/direção
            speed DECIMAL(6,2),
            heading DECIMAL(5,2),
            
            -- Timestamp
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_worker (worker_id),
            INDEX idx_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 14. Verificações faciais
    'om_worker_facial_verifications' => "
        CREATE TABLE IF NOT EXISTS om_worker_facial_verifications (
            verification_id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            
            -- Tipo
            type ENUM('cadastro','daily','random','suspicious') NOT NULL,
            
            -- Foto
            photo_url VARCHAR(255),
            
            -- Resultado
            match_score DECIMAL(5,2),
            is_match TINYINT(1),
            
            -- Status
            status ENUM('pending','approved','rejected','manual_review') DEFAULT 'pending',
            reviewed_by INT,
            
            -- IP/Device
            ip_address VARCHAR(45),
            user_agent TEXT,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (worker_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            INDEX idx_worker (worker_id),
            INDEX idx_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 15. Referências (indicações)
    'om_worker_referrals' => "
        CREATE TABLE IF NOT EXISTS om_worker_referrals (
            referral_id INT AUTO_INCREMENT PRIMARY KEY,
            
            -- Quem indicou
            referrer_id INT NOT NULL,
            
            -- Quem foi indicado
            referred_id INT,
            referred_phone VARCHAR(20),
            referred_name VARCHAR(100),
            
            -- Status
            status ENUM('pending','registered','approved','paid','expired') DEFAULT 'pending',
            
            -- Recompensa
            referrer_reward DECIMAL(10,2) DEFAULT 50.00,
            referred_reward DECIMAL(10,2) DEFAULT 25.00,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            registered_at DATETIME,
            approved_at DATETIME,
            paid_at DATETIME,
            
            FOREIGN KEY (referrer_id) REFERENCES om_workers(worker_id) ON DELETE CASCADE,
            INDEX idx_referrer (referrer_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    // 16. Log de atividades
    'om_worker_activity_log' => "
        CREATE TABLE IF NOT EXISTS om_worker_activity_log (
            log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            
            -- Ação
            action VARCHAR(50) NOT NULL,
            description TEXT,
            
            -- Dados extras
            data JSON,
            
            -- IP/Device
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_worker (worker_id),
            INDEX idx_action (action),
            INDEX idx_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "
];

// ═══════════════════════════════════════════════════════════════════════════════
// EXECUÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 01 - Tabelas</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; background: #0a0a0a; color: #fff; padding: 40px; }
.container { max-width: 800px; margin: 0 auto; }
h1 { color: #10b981; margin-bottom: 30px; }
.success { color: #10b981; }
.error { color: #ef4444; }
.table-item { background: #111; border: 1px solid #222; border-radius: 8px; padding: 15px; margin: 10px 0; }
.table-name { font-weight: bold; color: #fff; }
.status { float: right; }
.summary { background: linear-gradient(135deg, #10b981, #059669); padding: 20px; border-radius: 12px; margin-top: 30px; text-align: center; }
</style></head><body><div class='container'>";

echo "<h1>📦 Instalador 01 - Tabelas do Banco de Dados</h1>";
echo "<p style='color:#888;'>Criando " . count($tabelas) . " tabelas para o sistema de workers...</p>";

$criadas = 0;
$existentes = 0;

foreach ($tabelas as $nome => $sql) {
    echo "<div class='table-item'>";
    echo "<span class='table-name'>$nome</span>";
    
    // Verificar se já existe
    $check = $conn->query("SHOW TABLES LIKE '$nome'");
    if ($check->num_rows > 0) {
        echo "<span class='status' style='color:#f59e0b;'>⚠️ Já existe</span>";
        $existentes++;
    } else {
        if ($conn->query($sql)) {
            echo "<span class='status success'>✅ Criada</span>";
            $criadas++;
        } else {
            echo "<span class='status error'>❌ Erro: " . $conn->error . "</span>";
            $erros[] = $nome . ": " . $conn->error;
        }
    }
    
    echo "</div>";
}

echo "<div class='summary'>";
echo "<h2>📊 Resumo</h2>";
echo "<p>✅ Tabelas criadas: <strong>$criadas</strong></p>";
echo "<p>⚠️ Tabelas existentes: <strong>$existentes</strong></p>";
echo "<p>❌ Erros: <strong>" . count($erros) . "</strong></p>";
echo "</div>";

if (count($erros) > 0) {
    echo "<div style='background:#1a0000;border:1px solid #ef4444;padding:15px;border-radius:8px;margin-top:20px;'>";
    echo "<h3 style='color:#ef4444;'>Erros:</h3>";
    foreach ($erros as $erro) {
        echo "<p style='color:#fca5a5;'>• $erro</p>";
    }
    echo "</div>";
}

echo "<div style='margin-top:30px;text-align:center;'>";
echo "<a href='02_instalar_ferramentas_shopper.php' style='display:inline-block;background:#10b981;color:#fff;padding:15px 30px;border-radius:8px;text-decoration:none;font-weight:bold;'>Próximo: Instalar Ferramentas do Shopper →</a>";
echo "</div>";

echo "</div></body></html>";

$conn->close();
?>
