<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO - INSTALADOR DA EVOLUCAO MARKETPLACE
 * Execute este arquivo para criar todas as tabelas necessarias
 * ══════════════════════════════════════════════════════════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalacao OneMundo</title>";
echo "<style>body{font-family:sans-serif;padding:40px;background:#0f172a;color:#e2e8f0;}";
echo ".ok{color:#10b981;}.err{color:#ef4444;}.warn{color:#f59e0b;}";
echo "h1{color:#10b981;}pre{background:#1e293b;padding:15px;border-radius:8px;overflow-x:auto;}";
echo ".btn{display:inline-block;padding:15px 30px;background:#10b981;color:white;text-decoration:none;border-radius:8px;margin-top:20px;}</style></head><body>";

echo "<h1>🚀 Instalacao OneMundo - Evolucao Marketplace</h1>";

// Conectar ao banco
$_oc_root = dirname(dirname(__DIR__));
if (file_exists($_oc_root . '/config.php')) {
    require_once $_oc_root . '/config.php';
    $pdo = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "<p class='ok'>✓ Conectado ao banco de dados: " . DB_DATABASE . "</p>";
} else {
    die("<p class='err'>✗ Arquivo config.php nao encontrado</p>");
}

$erros = 0;
$ok = 0;

// Funcao para executar SQL
function executarSQL($pdo, $sql, $descricao) {
    global $erros, $ok;
    try {
        $pdo->exec($sql);
        echo "<p class='ok'>✓ {$descricao}</p>";
        $ok++;
        return true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false ||
            strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "<p class='warn'>⚠ {$descricao} (ja existe)</p>";
            return true;
        }
        echo "<p class='err'>✗ {$descricao}: " . $e->getMessage() . "</p>";
        $erros++;
        return false;
    }
}

echo "<h2>📦 Criando tabela om_vendedores...</h2>";

// Criar tabela om_vendedores se nao existir
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_vendedores (
    vendedor_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    tipo_pessoa ENUM('fisica', 'juridica') NOT NULL DEFAULT 'fisica',
    cpf VARCHAR(14) DEFAULT NULL,
    cnpj VARCHAR(18) DEFAULT NULL,
    razao_social VARCHAR(255) DEFAULT NULL,
    nome_loja VARCHAR(100) NOT NULL,
    slug VARCHAR(100) DEFAULT NULL,
    descricao TEXT DEFAULT NULL,
    logo VARCHAR(255) DEFAULT NULL,
    banner VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    telefone VARCHAR(20) DEFAULT NULL,
    whatsapp VARCHAR(20) DEFAULT NULL,
    cep VARCHAR(10) DEFAULT NULL,
    endereco VARCHAR(255) DEFAULT NULL,
    numero VARCHAR(20) DEFAULT NULL,
    complemento VARCHAR(100) DEFAULT NULL,
    bairro VARCHAR(100) DEFAULT NULL,
    cidade VARCHAR(100) DEFAULT NULL,
    estado VARCHAR(2) DEFAULT NULL,
    latitude DECIMAL(10,8) DEFAULT NULL,
    longitude DECIMAL(11,8) DEFAULT NULL,
    pix_tipo ENUM('cpf', 'cnpj', 'email', 'telefone', 'aleatoria') DEFAULT NULL,
    pix_chave VARCHAR(255) DEFAULT NULL,
    comissao_padrao DECIMAL(5,2) DEFAULT 10.00,
    prazo_envio INT DEFAULT 2,
    tipo_vendedor ENUM('simples', 'loja_oficial') DEFAULT 'simples',
    tem_loja_publica TINYINT(1) DEFAULT 0,
    nivel_verificacao ENUM('basico', 'completo') DEFAULT 'basico',
    score_interno DECIMAL(5,2) DEFAULT 5.00,
    selo_verificado TINYINT(1) DEFAULT 0,
    selo_oficial TINYINT(1) DEFAULT 0,
    total_vendas INT DEFAULT 0,
    total_pedidos INT DEFAULT 0,
    avaliacao_media DECIMAL(3,2) DEFAULT 0,
    total_avaliacoes INT DEFAULT 0,
    status ENUM('pendente', 'em_analise', 'aprovado', 'suspenso', 'inativo') DEFAULT 'pendente',
    data_aprovacao DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_slug (slug),
    INDEX idx_cidade (cidade, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_vendedores");

echo "<h2>📦 Adicionando colunas na tabela om_vendedores (se necessario)...</h2>";

// Alteracoes na tabela om_vendedores (para atualizar instalacoes antigas)
$alteracoes = [
    "ALTER TABLE om_vendedores ADD COLUMN tipo_vendedor ENUM('simples', 'loja_oficial') DEFAULT 'simples'" => "Adicionando tipo_vendedor",
    "ALTER TABLE om_vendedores ADD COLUMN tem_loja_publica TINYINT(1) DEFAULT 0" => "Adicionando tem_loja_publica",
    "ALTER TABLE om_vendedores ADD COLUMN nivel_verificacao ENUM('basico', 'completo') DEFAULT 'basico'" => "Adicionando nivel_verificacao",
    "ALTER TABLE om_vendedores ADD COLUMN score_interno DECIMAL(5,2) DEFAULT 5.00" => "Adicionando score_interno",
    "ALTER TABLE om_vendedores ADD COLUMN selo_verificado TINYINT(1) DEFAULT 0" => "Adicionando selo_verificado",
    "ALTER TABLE om_vendedores ADD COLUMN selo_oficial TINYINT(1) DEFAULT 0" => "Adicionando selo_oficial",
    "ALTER TABLE om_vendedores ADD COLUMN total_vendas INT DEFAULT 0" => "Adicionando total_vendas",
    "ALTER TABLE om_vendedores ADD COLUMN total_pedidos INT DEFAULT 0" => "Adicionando total_pedidos",
    "ALTER TABLE om_vendedores ADD COLUMN avaliacao_media DECIMAL(3,2) DEFAULT 0" => "Adicionando avaliacao_media",
    "ALTER TABLE om_vendedores ADD COLUMN total_avaliacoes INT DEFAULT 0" => "Adicionando total_avaliacoes",
];

foreach ($alteracoes as $sql => $desc) {
    executarSQL($pdo, $sql, $desc);
}

echo "<h2>📦 Criando tabelas novas...</h2>";

// Subpedidos por vendedor
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_order_sellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    seller_id INT NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    frete DECIMAL(10,2) NOT NULL DEFAULT 0,
    desconto DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(15,2) NOT NULL DEFAULT 0,
    comissao_percentual DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    comissao_valor DECIMAL(10,2) NOT NULL DEFAULT 0,
    valor_liquido DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('pendente','pago','em_separacao','enviado_ponto','no_ponto','em_transito','entregue','cancelado','devolvido') DEFAULT 'pendente',
    ponto_apoio_id INT DEFAULT NULL,
    metodo_envio VARCHAR(50) DEFAULT NULL,
    codigo_rastreio VARCHAR(100) DEFAULT NULL,
    qrcode_vendedor VARCHAR(100) DEFAULT NULL,
    qrcode_cliente VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_seller (seller_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_order_sellers");

// Balance do vendedor
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_seller_balance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    order_seller_id INT NOT NULL,
    valor_bruto DECIMAL(15,2) NOT NULL,
    comissao DECIMAL(10,2) NOT NULL,
    taxas DECIMAL(10,2) DEFAULT 0,
    valor_liquido DECIMAL(15,2) NOT NULL,
    status ENUM('pendente','em_transito','no_ponto','liberado','congelado','estornado','pago') DEFAULT 'pendente',
    data_previsao DATE DEFAULT NULL,
    data_liberacao DATETIME DEFAULT NULL,
    data_pagamento DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seller (seller_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_seller_balance");

// Disputas
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_seller_id INT DEFAULT NULL,
    customer_id INT NOT NULL,
    seller_id INT NOT NULL,
    tipo ENUM('nao_recebido','diferente_anunciado','defeito','arrependimento','devolucao','outro') NOT NULL,
    motivo TEXT NOT NULL,
    status ENUM('aberta','aguardando_vendedor','aguardando_cliente','em_analise','mediacao','resolvida_cliente','resolvida_vendedor','encerrada') DEFAULT 'aberta',
    evidencias JSON DEFAULT NULL,
    resolucao TEXT DEFAULT NULL,
    valor_reembolso DECIMAL(10,2) DEFAULT NULL,
    data_abertura DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_resolucao DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_disputes");

// Mensagens de disputa
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_dispute_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispute_id INT NOT NULL,
    sender_type ENUM('customer', 'seller', 'admin') NOT NULL,
    sender_id INT NOT NULL,
    mensagem TEXT NOT NULL,
    anexos JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dispute (dispute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_dispute_messages");

// Garantias
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_garantias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_product_id INT NOT NULL,
    customer_id INT NOT NULL,
    seller_id INT NOT NULL,
    product_id INT NOT NULL,
    tipo ENUM('garantia_loja','garantia_extendida','seguro_roubo','seguro_dano','seguro_quebra_acidental') NOT NULL,
    valor_produto DECIMAL(10,2) NOT NULL,
    valor_garantia DECIMAL(10,2) DEFAULT 0,
    valor_cobertura DECIMAL(10,2) NOT NULL,
    vigencia_inicio DATE NOT NULL,
    vigencia_fim DATE NOT NULL,
    status ENUM('ativa', 'utilizada', 'expirada', 'cancelada') DEFAULT 'ativa',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_garantias");

// Avaliacoes de loja
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_store_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT NOT NULL,
    nota_geral TINYINT NOT NULL,
    nota_atendimento TINYINT DEFAULT NULL,
    nota_embalagem TINYINT DEFAULT NULL,
    nota_prazo TINYINT DEFAULT NULL,
    titulo VARCHAR(100) DEFAULT NULL,
    comentario TEXT DEFAULT NULL,
    resposta TEXT DEFAULT NULL,
    status ENUM('pendente', 'aprovado', 'rejeitado') DEFAULT 'pendente',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_store_reviews");

// Afiliados
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_affiliates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    codigo VARCHAR(20) NOT NULL UNIQUE,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telefone VARCHAR(20) DEFAULT NULL,
    comissao_padrao DECIMAL(5,2) DEFAULT 5.00,
    pix_tipo ENUM('cpf', 'cnpj', 'email', 'telefone', 'aleatoria') DEFAULT NULL,
    pix_chave VARCHAR(255) DEFAULT NULL,
    status ENUM('pendente', 'ativo', 'suspenso', 'inativo') DEFAULT 'ativo',
    total_cliques INT DEFAULT 0,
    total_vendas INT DEFAULT 0,
    total_comissoes DECIMAL(15,2) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_affiliates");

// Vendas de afiliados
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_affiliate_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT NOT NULL,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    seller_id INT DEFAULT NULL,
    valor_venda DECIMAL(15,2) NOT NULL,
    comissao_percentual DECIMAL(5,2) NOT NULL,
    comissao_valor DECIMAL(10,2) NOT NULL,
    status ENUM('pendente', 'aprovada', 'paga', 'cancelada') DEFAULT 'pendente',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_affiliate (affiliate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_affiliate_sales");

// Cartoes tokenizados
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_customer_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    gateway ENUM('pagarme', 'mercadopago', 'asaas') NOT NULL,
    token VARCHAR(255) NOT NULL,
    card_id VARCHAR(100) DEFAULT NULL,
    bandeira VARCHAR(20) NOT NULL,
    ultimos_digitos VARCHAR(4) NOT NULL,
    nome_titular VARCHAR(100) NOT NULL,
    validade VARCHAR(7) NOT NULL,
    is_default TINYINT(1) DEFAULT 0,
    apelido VARCHAR(50) DEFAULT NULL,
    limite_compra_rapida DECIMAL(10,2) DEFAULT 500.00,
    compra_rapida_ativa TINYINT(1) DEFAULT 0,
    status ENUM('ativo', 'inativo', 'expirado') DEFAULT 'ativo',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_customer_cards");

// SLA Config
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_sla_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('preparacao', 'envio_ponto', 'entrega_local', 'entrega_nacional') NOT NULL,
    prazo_horas INT NOT NULL,
    compensacao_tipo ENUM('credito', 'cupom', 'percentual') DEFAULT 'credito',
    compensacao_valor DECIMAL(10,2) DEFAULT 0,
    ativo TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando tabela om_sla_config");

// Inserir SLAs padrao
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM om_sla_config");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO om_sla_config (tipo, prazo_horas, compensacao_tipo, compensacao_valor) VALUES
            ('preparacao', 24, 'credito', 5.00),
            ('envio_ponto', 48, 'credito', 5.00),
            ('entrega_local', 72, 'credito', 10.00),
            ('entrega_nacional', 168, 'credito', 10.00)
        ");
        echo "<p class='ok'>✓ SLAs padrao inseridos</p>";
    }
} catch (Exception $e) {}

// Pontos de apoio (garantir campos)
executarSQL($pdo, "
CREATE TABLE IF NOT EXISTS om_pontos_apoio (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendedor_id INT DEFAULT NULL,
    nome VARCHAR(100) NOT NULL,
    nome_fantasia VARCHAR(100) DEFAULT NULL,
    responsavel VARCHAR(100) DEFAULT NULL,
    telefone VARCHAR(20) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    cep VARCHAR(10) DEFAULT NULL,
    endereco VARCHAR(255) NOT NULL,
    numero VARCHAR(20) DEFAULT NULL,
    bairro VARCHAR(100) DEFAULT NULL,
    cidade VARCHAR(100) NOT NULL DEFAULT '',
    estado VARCHAR(2) NOT NULL DEFAULT '',
    latitude DECIMAL(10, 8) DEFAULT NULL,
    longitude DECIMAL(11, 8) DEFAULT NULL,
    horario_abertura TIME DEFAULT '08:00:00',
    horario_fechamento TIME DEFAULT '18:00:00',
    dias_funcionamento VARCHAR(50) DEFAULT 'seg-sab',
    capacidade_pacotes INT DEFAULT 50,
    pacotes_atuais INT DEFAULT 0,
    taxa_recebimento DECIMAL(10,2) DEFAULT 2.00,
    taxa_despacho DECIMAL(10,2) DEFAULT 3.00,
    dias_guarda_max INT DEFAULT 7,
    aceita_coleta TINYINT(1) DEFAULT 1,
    aceita_entrega TINYINT(1) DEFAULT 1,
    aceita_devolucao TINYINT(1) DEFAULT 1,
    status ENUM('pendente', 'ativo', 'inativo', 'suspenso') DEFAULT 'pendente',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cidade (cidade, estado),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "Criando/Verificando tabela om_pontos_apoio");

echo "<hr>";
echo "<h2>📊 Resultado</h2>";
echo "<p><strong class='ok'>Sucesso: {$ok}</strong></p>";
echo "<p><strong class='err'>Erros: {$erros}</strong></p>";

if ($erros == 0) {
    echo "<p class='ok' style='font-size:20px;'>✅ Instalacao concluida com sucesso!</p>";
} else {
    echo "<p class='warn' style='font-size:20px;'>⚠️ Instalacao concluida com alguns avisos. Verifique os erros acima.</p>";
}

echo "<a href='/mercado/' class='btn'>← Voltar ao Mercado</a>";
echo "<a href='/vendedor/' class='btn' style='margin-left:10px;'>Painel Vendedor →</a>";

echo "</body></html>";
