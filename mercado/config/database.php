<?php
/**
 * Configuracao do Banco de Dados - OneMundo Mercado
 * Suporta MySQL e PostgreSQL
 * SEGURO: Le credenciais do .env
 */

// Funcao para ler .env
function loadEnv($path = null) {
    if ($path === null) {
        $path = dirname(__DIR__) . '/.env';
    }
    if (!file_exists($path)) return [];

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $vars = [];

    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // Remove aspas se existirem
        if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
            $value = $m[2];
        }

        $vars[$name] = $value;
    }

    return $vars;
}

// Carregar variaveis de ambiente
$envPath = dirname(__DIR__) . '/.env';
$env = loadEnv($envPath);

// Definir constantes de banco de dados
if (!defined('DB_DRIVER')) {
    define('DB_DRIVER', $env['DB_DRIVER'] ?? 'pgsql');  // Default: PostgreSQL
    define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
    define('DB_PORT', $env['DB_PORT'] ?? (DB_DRIVER === 'pgsql' ? '5432' : '3306'));
    define('DB_NAME', $env['DB_NAME'] ?? 'love1');
    define('DB_USER', $env['DB_USER'] ?? 'root');
    define('DB_PASS', $env['DB_PASS'] ?? '');
    define('DB_CHARSET', $env['DB_CHARSET'] ?? 'utf8');
}

// API Keys
if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', $env['OPENAI_API_KEY'] ?? '');
    define('CLAUDE_API_KEY', $env['CLAUDE_API_KEY'] ?? '');
    define('GROQ_API_KEY', $env['GROQ_API_KEY'] ?? '');
    define('SERPER_API_KEY', $env['SERPER_API_KEY'] ?? '');
}

// Twilio (SMS/WhatsApp Verification)
if (!defined('TWILIO_SID')) {
    define('TWILIO_SID', $env['TWILIO_SID'] ?? '');
    define('TWILIO_TOKEN', $env['TWILIO_TOKEN'] ?? '');
    define('TWILIO_PHONE', $env['TWILIO_PHONE'] ?? '');
}

// Ambiente
if (!defined('APP_ENV')) {
    define('APP_ENV', $env['APP_ENV'] ?? 'production');
    define('APP_DEBUG', ($env['APP_DEBUG'] ?? 'false') === 'true');
}

/**
 * Retorna conexao PDO singleton
 * Suporta MySQL e PostgreSQL
 */
function getPDO() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            if (DB_DRIVER === 'pgsql') {
                // PostgreSQL
                $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
            } else {
                // MySQL
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
                ];
            }

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // PostgreSQL: Set client encoding
            if (DB_DRIVER === 'pgsql') {
                $pdo->exec("SET client_encoding TO 'UTF8'");
            }

        } catch (PDOException $e) {
            if (APP_DEBUG) {
                throw $e;
            }
            error_log("Database connection failed: " . $e->getMessage());
            die("Erro de conexao com o banco de dados");
        }
    }

    return $pdo;
}

/**
 * Verifica se esta usando PostgreSQL
 */
function isPostgreSQL() {
    return DB_DRIVER === 'pgsql';
}

/**
 * Verifica se esta usando MySQL
 */
function isMySQL() {
    return DB_DRIVER === 'mysql';
}

/**
 * INSERT IGNORE compativel com ambos os bancos
 * PostgreSQL: ON CONFLICT DO NOTHING
 * MySQL: INSERT IGNORE
 */
function dbInsertIgnore($table, $data) {
    $pdo = getPDO();
    $columns = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $columns);

    if (isPostgreSQL()) {
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ") ON CONFLICT DO NOTHING";
    } else {
        $sql = "INSERT IGNORE INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($data as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    return $stmt->execute();
}

/**
 * UPSERT compativel com ambos os bancos
 * PostgreSQL: ON CONFLICT (key) DO UPDATE
 * MySQL: ON DUPLICATE KEY UPDATE
 */
function dbUpsert($table, $data, $conflictKey = 'id') {
    $pdo = getPDO();
    $columns = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $columns);
    $updates = array_filter($columns, fn($c) => $c !== $conflictKey);
    $updateParts = array_map(fn($c) => "{$c} = :{$c}", $updates);

    if (isPostgreSQL()) {
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        if (!empty($updateParts)) {
            $sql .= " ON CONFLICT ({$conflictKey}) DO UPDATE SET " . implode(', ', $updateParts);
        } else {
            $sql .= " ON CONFLICT ({$conflictKey}) DO NOTHING";
        }
    } else {
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        if (!empty($updateParts)) {
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $updates));
        }
    }

    $stmt = $pdo->prepare($sql);
    foreach ($data as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    return $stmt->execute();
}

/**
 * INSERT e retorna o ID gerado
 * Compativel com MySQL e PostgreSQL
 */
function dbInsertGetId($table, $data, $primaryKey = 'id') {
    $pdo = getPDO();
    $columns = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $columns);

    $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

    if (isPostgreSQL()) {
        $sql .= " RETURNING {$primaryKey}";
        $stmt = $pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        return $row[$primaryKey] ?? null;
    } else {
        $stmt = $pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $pdo->lastInsertId();
    }
}

/**
 * LIMIT/OFFSET compativel
 * (Ambos usam a mesma sintaxe, mas wrapper para consistencia)
 */
function dbLimit($limit, $offset = 0) {
    return "LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
}

/**
 * NOW() compativel
 */
function dbNow() {
    return isPostgreSQL() ? "NOW()" : "NOW()";
}

/**
 * Boolean para SQL
 */
function dbBool($value) {
    if (isPostgreSQL()) {
        return $value ? 'TRUE' : 'FALSE';
    }
    return $value ? '1' : '0';
}

/**
 * Retorna conexao MySQLi singleton (apenas para MySQL)
 * @deprecated Use getPDO() para compatibilidade
 */
function getMySQLi() {
    if (isPostgreSQL()) {
        throw new Exception("MySQLi nao disponivel - usando PostgreSQL. Use getPDO() em vez disso.");
    }

    static $mysqli = null;

    if ($mysqli === null) {
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);

        if ($mysqli->connect_error) {
            if (APP_DEBUG) {
                die("Connection failed: " . $mysqli->connect_error);
            }
            error_log("MySQLi connection failed: " . $mysqli->connect_error);
            die("Erro de conexao com o banco de dados");
        }

        $mysqli->set_charset(DB_CHARSET);
    }

    return $mysqli;
}

// Retornar array de config para compatibilidade
return [
    'driver'   => DB_DRIVER,
    'host'     => DB_HOST,
    'port'     => DB_PORT,
    'dbname'   => DB_NAME,
    'username' => DB_USER,
    'password' => DB_PASS,
    'charset'  => DB_CHARSET,
];
