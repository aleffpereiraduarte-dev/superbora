<?php
// Carrega variáveis de ambiente (MUST be before ratelimit/redis to set REDIS_PASSWORD)
if (file_exists(__DIR__ . '/../../../.env')) {
    $envFile = file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value), '"\'');
        }
    }
}
// Sentry error monitoring (loads early to catch all errors)
require_once __DIR__ . '/sentry.php';

// Rate limiting
require_once __DIR__ . '/ratelimit.php';
applyRateLimit();


// Database credentials - PostgreSQL ONLY (MySQL port 3306 is blocked on remote)
// Force PostgreSQL settings - ignore MySQL config from main .env
define("DB_DRIVER", "pgsql");
define("DB_HOST", $_ENV["DB_HOSTNAME"] ?? getenv("DB_HOSTNAME") ?: "localhost");
define("DB_PORT", $_ENV["DB_PORT"] ?? getenv("DB_PORT") ?: "5432");
define("DB_NAME", $_ENV["DB_NAME"] ?? getenv("DB_NAME") ?: "love1");
define("DB_USER", $_ENV["DB_USERNAME"] ?? getenv("DB_USERNAME") ?: "");
define("DB_PASS", $_ENV["DB_PASSWORD"] ?? getenv("DB_PASSWORD") ?: "");

function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            // Use PostgreSQL (MySQL port 3306 is blocked on remote server)
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
            // Require SSL for remote database connections
            $isLocalDB = in_array(DB_HOST, ['localhost', '127.0.0.1', '::1'], true);
            if (!$isLocalDB) {
                $dsn .= ";sslmode=require";
            }
            $db = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            $db->exec("SET client_encoding TO 'UTF8'");
        } catch (PDOException $e) {
            // SECURITY: Log error details but don't expose them to client
            error_log("[Database] Connection failed: " . $e->getMessage());
            error_log("[Database] Host: " . DB_HOST . ", Database: " . DB_NAME);

            // Return generic error to client (don't expose internal details)
            http_response_code(503);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode([
                "success" => false,
                "message" => "Servico temporariamente indisponivel. Tente novamente em alguns minutos.",
                "data" => null,
                "timestamp" => date("c")
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $db;
}

function response($success, $data = null, $message = "", $code = 200) {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");

    // Sanitizar mensagens de erro para não expor detalhes internos
    // Mensagens 500 sempre são sanitizadas (erros internos não devem vazar detalhes)
    if ($code >= 500 && !empty($message)) {
        // Log do erro real para debugging
        $endpoint = $_SERVER['REQUEST_URI'] ?? 'unknown';
        error_log("[API Error {$code}] {$endpoint}: {$message}");
        // Mensagem genérica para o cliente
        $message = "Erro interno do servidor. Tente novamente.";
    }

    echo json_encode(["success" => $success, "message" => $message, "data" => $data, "timestamp" => date("c")], JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    $json = file_get_contents("php://input");
    return json_decode($json, true) ?: $_POST;
}

/**
 * Set proper CORS headers (replaces wildcard Access-Control-Allow-Origin: *)
 * Call this at the top of any endpoint that needs CORS.
 * Also handles OPTIONS preflight.
 */
function setCorsHeaders(): void {
    $allowedOrigins = array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'https://superbora.com.br,https://www.superbora.com.br,https://onemundo.com.br,https://www.onemundo.com.br'));

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Dev/localhost origins always allowed (Expo dev server, Next.js dev, etc.)
    // SECURITY: Use exact matching to prevent origin spoofing (e.g. http://localhost.evil.com)
    $devAllowedOrigins = [
        'http://localhost:8081',
        'http://localhost:19006',
        'http://localhost:3000',
        'http://localhost:5173',
    ];

    // Detectar se e uma rota do BoraUm (app nativo pode enviar qualquer origin ou nenhum)
    $isBoraUmRoute = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/mercado/boraum/') !== false;

    // Whitelist adicional para app BoraUm (capacitor, cordova, file://, localhost)
    $boraUmAllowedOrigins = [
        'capacitor://localhost',
        'ionic://localhost',
        'http://localhost',
        'https://localhost',
        'http://localhost:8100',
        'file://',
    ];

    $isBoraUmOrigin = false;
    if ($isBoraUmRoute && !empty($origin)) {
        $isBoraUmOrigin = in_array($origin, $boraUmAllowedOrigins, true);
    }

    $isDevOrigin = !empty($origin) && in_array($origin, $devAllowedOrigins, true);

    if (in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: " . $origin);
    } elseif ($isDevOrigin) {
        // Dev/localhost origin (Expo, Next.js dev servers)
        header("Access-Control-Allow-Origin: " . $origin);
    } elseif ($isBoraUmOrigin) {
        // App nativo BoraUm com origin valido
        header("Access-Control-Allow-Origin: " . $origin);
    } elseif (empty($origin)) {
        // Same-origin ou app nativo (sem header Origin) - nao define header
        // Nao usar * para evitar problemas com credentials
    }

    // Prevent cache poisoning when origin varies dynamically
    header("Vary: Origin");

    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key, X-Passageiro-Id, X-Passageiro-Telefone, X-Passageiro-Nome");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");

    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        http_response_code(200);
        exit;
    }
}

function gerarCodigo($tamanho = 6) {
    // SECURITY: Use cryptographically secure random instead of md5(uniqid())
    return strtoupper(bin2hex(random_bytes((int)ceil($tamanho / 2))));
}

/**
 * Sanitize a string for safe output (prevent XSS in JSON responses)
 * Strips HTML tags and trims whitespace.
 */
function sanitizeOutput(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

/**
 * Extrai customer_id do token JWT (Bearer). Retorna 0 se nao autenticado.
 */
function getCustomerIdFromToken(): int {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$header) {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
    }
    $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : $header;
    if (!$token) return 0;

    $parts = explode('.', $token);
    if (count($parts) !== 2) return 0;

    $jwtSecret = $_ENV['JWT_SECRET'] ?? '';
    if (empty($jwtSecret)) {
        // SECURITY: No fallback — refuse to authenticate without proper JWT_SECRET
        error_log("[Auth] JWT_SECRET not configured — rejecting token");
        return 0;
    }
    $expectedSig = hash_hmac('sha256', $parts[0], $jwtSecret);
    if (!hash_equals($expectedSig, $parts[1])) return 0;

    $payload = json_decode(base64_decode($parts[0]), true);
    if (!$payload) return 0;
    if (($payload['exp'] ?? 0) < time()) return 0;

    // Validate issuer and audience if present (defense-in-depth for homegrown JWT)
    if (isset($payload['iss']) && $payload['iss'] !== 'superbora') return 0;
    if (isset($payload['aud']) && $payload['aud'] !== 'superbora-api') return 0;

    // Accept customer tokens only (user_id or uid), reject partner/shopper/admin tokens
    $tokenType = $payload['type'] ?? '';
    if ($tokenType && $tokenType !== 'customer') return 0;

    return (int)($payload['user_id'] ?? $payload['uid'] ?? $payload['customer_id'] ?? 0);
}

/**
 * Exige autenticacao de customer. Retorna customer_id ou aborta com 401.
 */
function requireCustomerAuth(): int {
    $cid = getCustomerIdFromToken();
    if (!$cid) {
        response(false, null, "Autenticacao obrigatoria", 401);
    }
    return $cid;
}
