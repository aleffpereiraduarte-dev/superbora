<?php
/**
 * INSTALADOR DO BRAIN 1000+ v9.0
 *
 * Execute: php instalar_brain_1000.php
 * Ou acesse: /mercado/instalar_brain_1000.php?executar=1
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Só executa se chamado diretamente
if (php_sapi_name() !== 'cli' && !isset($_GET['executar'])) {
    echo "<h1>Instalador Brain ONE v9.0</h1>";
    echo "<p>Este script instalará 1000+ perguntas e respostas humanizadas no banco.</p>";
    echo "<p><a href='?executar=1'>Clique aqui para executar</a></p>";
    exit;
}

echo "═══════════════════════════════════════════════════════\n";
echo "    ONE BRAIN v9.0 - INSTALADOR DE 1000+ ENTRADAS\n";
echo "═══════════════════════════════════════════════════════\n\n";

// Carrega os arquivos de brain
$brain1 = include __DIR__ . '/one_brain_1000.php';
$brain2 = include __DIR__ . '/one_brain_extra.php';

// Combina
$brain_completo = array_merge($brain1, $brain2);

echo "Total de entradas carregadas: " . count($brain_completo) . "\n\n";

// Configuração do banco - AJUSTE CONFORME NECESSÁRIO
$dbConfig = [
    'host' => 'localhost',
    'name' => 'u315624178_onemundo',
    'user' => 'u315624178_onemundo',
    'pass' => '@Onemundo1'
];

// Tenta também ler do arquivo de config se existir
if (file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
    if (defined('DB_HOST')) $dbConfig['host'] = DB_HOST;
    if (defined('DB_NAME')) $dbConfig['name'] = DB_NAME;
    if (defined('DB_USER')) $dbConfig['user'] = DB_USER;
    if (defined('DB_PASS')) $dbConfig['pass'] = DB_PASS;
}

try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4",
        $dbConfig['user'],
        $dbConfig['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    echo "Conectado ao banco: {$dbConfig['name']}\n\n";

    // Verifica se a tabela existe
    $tables = $pdo->query("SHOW TABLES LIKE 'om_one_brain_universal'")->fetchAll();
    if (empty($tables)) {
        echo "ERRO: Tabela om_one_brain_universal não existe!\n";
        echo "Criando tabela...\n";

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS om_one_brain_universal (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pergunta VARCHAR(500) NOT NULL,
                resposta TEXT NOT NULL,
                categoria VARCHAR(50) DEFAULT 'geral',
                modulo VARCHAR(50) DEFAULT 'geral',
                origem VARCHAR(50) DEFAULT 'manual',
                ativo TINYINT DEFAULT 1,
                qualidade TINYINT DEFAULT 3,
                vezes_usada INT DEFAULT 0,
                criado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_pergunta (pergunta(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        echo "Tabela criada!\n\n";
    }

    // Prepara o statement
    $stmt = $pdo->prepare("
        INSERT INTO om_one_brain_universal
        (pergunta, resposta, categoria, modulo, origem, ativo, qualidade)
        VALUES (?, ?, ?, ?, 'brain-humanizado-v9', 1, 5)
        ON DUPLICATE KEY UPDATE
        resposta = VALUES(resposta),
        categoria = VALUES(categoria),
        modulo = VALUES(modulo),
        qualidade = 5,
        atualizado = NOW()
    ");

    $inserted = 0;
    $updated = 0;
    $errors = 0;
    $categorias = [];

    echo "Inserindo entradas...\n";

    foreach ($brain_completo as $i => $item) {
        try {
            $pergunta = mb_strtolower(trim($item['p']), 'UTF-8');
            $pergunta = preg_replace('/[?!.,]+$/', '', $pergunta);

            $stmt->execute([
                $pergunta,
                $item['r'],
                $item['categoria'],
                $item['modulo']
            ]);

            if ($stmt->rowCount() > 0) {
                $inserted++;
            }

            // Conta categorias
            if (!isset($categorias[$item['categoria']])) {
                $categorias[$item['categoria']] = 0;
            }
            $categorias[$item['categoria']]++;

            // Mostra progresso a cada 100
            if (($i + 1) % 100 == 0) {
                echo "... " . ($i + 1) . " processadas\n";
            }

        } catch (Exception $e) {
            $errors++;
            error_log("Erro ao inserir: " . $e->getMessage() . " - Pergunta: " . ($item['p'] ?? 'N/A'));
        }
    }

    echo "\n═══════════════════════════════════════════════════════\n";
    echo "                    RESULTADO\n";
    echo "═══════════════════════════════════════════════════════\n\n";

    echo "Total processado: " . count($brain_completo) . "\n";
    echo "Inseridas/Atualizadas: $inserted\n";
    echo "Erros: $errors\n\n";

    echo "Por categoria:\n";
    arsort($categorias);
    foreach ($categorias as $cat => $count) {
        echo "  - $cat: $count\n";
    }

    // Conta total no banco
    $total = $pdo->query("SELECT COUNT(*) as total FROM om_one_brain_universal WHERE origem = 'brain-humanizado-v9'")->fetch();
    echo "\nTotal no banco (brain v9): " . $total['total'] . "\n";

    $totalGeral = $pdo->query("SELECT COUNT(*) as total FROM om_one_brain_universal")->fetch();
    echo "Total geral no banco: " . $totalGeral['total'] . "\n";

    echo "\n═══════════════════════════════════════════════════════\n";
    echo "    BRAIN v9.0 INSTALADO COM SUCESSO!\n";
    echo "═══════════════════════════════════════════════════════\n";

} catch (PDOException $e) {
    echo "ERRO DE CONEXÃO: " . $e->getMessage() . "\n";
    echo "\nVerifique as credenciais do banco no início do arquivo.\n";
}
