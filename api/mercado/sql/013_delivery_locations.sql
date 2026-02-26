-- ============================================================================
-- RASTREAMENTO EM TEMPO REAL - Tabela de Localizacoes de Entrega
-- ============================================================================
-- Armazena historico de localizacoes dos entregadores durante entregas
-- Para rastreamento em tempo real no mapa do cliente
-- Executar: mysql -u root -p love1 < 013_delivery_locations.sql
-- ============================================================================

-- Tabela principal de localizacoes
CREATE TABLE IF NOT EXISTS om_delivery_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL COMMENT 'ID do pedido sendo entregue',
    worker_id INT NOT NULL COMMENT 'ID do entregador (shopper_id)',
    latitude DECIMAL(10,8) NOT NULL COMMENT 'Latitude GPS',
    longitude DECIMAL(11,8) NOT NULL COMMENT 'Longitude GPS',
    heading INT DEFAULT NULL COMMENT 'Direcao em graus (0-360, norte=0)',
    speed DECIMAL(5,2) DEFAULT NULL COMMENT 'Velocidade em km/h',
    accuracy DECIMAL(6,2) DEFAULT NULL COMMENT 'Precisao GPS em metros',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_order (order_id),
    INDEX idx_worker (worker_id),
    INDEX idx_order_time (order_id, created_at DESC),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de status de tracking em tempo real (ultima posicao conhecida)
CREATE TABLE IF NOT EXISTS om_delivery_tracking_live (
    order_id INT PRIMARY KEY COMMENT 'ID do pedido',
    worker_id INT NOT NULL COMMENT 'ID do entregador',
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    heading INT DEFAULT NULL,
    speed DECIMAL(5,2) DEFAULT NULL,
    accuracy DECIMAL(6,2) DEFAULT NULL,
    eta_minutes INT DEFAULT NULL COMMENT 'ETA calculado em minutos',
    distance_km DECIMAL(6,2) DEFAULT NULL COMMENT 'Distancia restante em km',
    status ENUM('coletando', 'em_entrega', 'chegando', 'entregue') DEFAULT 'em_entrega',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_worker (worker_id),
    INDEX idx_status (status),
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar campos extras na tabela de shoppers se nao existirem
ALTER TABLE om_market_shoppers
    ADD COLUMN IF NOT EXISTS veiculo VARCHAR(50) DEFAULT NULL COMMENT 'Tipo de veiculo: moto, carro, bicicleta',
    ADD COLUMN IF NOT EXISTS placa VARCHAR(20) DEFAULT NULL COMMENT 'Placa do veiculo',
    ADD COLUMN IF NOT EXISTS cor_veiculo VARCHAR(30) DEFAULT NULL COMMENT 'Cor do veiculo',
    ADD COLUMN IF NOT EXISTS avaliacao_media DECIMAL(2,1) DEFAULT 5.0 COMMENT 'Avaliacao media do entregador';

-- Procedure para limpar localizacoes antigas (mais de 7 dias)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS cleanup_old_locations()
BEGIN
    DELETE FROM om_delivery_locations
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

    DELETE FROM om_delivery_tracking_live
    WHERE updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    AND status = 'entregue';
END //
DELIMITER ;

-- Event para limpeza automatica (executar diariamente)
CREATE EVENT IF NOT EXISTS evt_cleanup_locations
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO CALL cleanup_old_locations();
