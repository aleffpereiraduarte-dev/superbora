<?php
/**
 * CALLBACK OAUTH - MELHOR ENVIO
 * Recebe o código de autorização e troca pelo token
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Desabilitado em produção
ini_set('log_errors', 1);

// Carregar variáveis de ambiente
if (file_exists(__DIR__ . '/.env')) {
    $envFile = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

$CLIENT_ID = $_ENV['MELHOR_ENVIO_CLIENT_ID'] ?? '21299';
$CLIENT_SECRET = $_ENV['MELHOR_ENVIO_CLIENT_SECRET'] ?? '';
$REDIRECT_URI = 'https://onemundo.com.br/melhor_envio_callback.php';

$DB = [
    'host' => $_ENV['DB_HOSTNAME'] ?? 'localhost',
    'name' => $_ENV['DB_DATABASE'] ?? 'love1',
    'user' => $_ENV['DB_USERNAME'] ?? 'love1',
    'pass' => $_ENV['DB_PASSWORD'] ?? ''
];

// Se recebeu código
$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';
$message = '';
$success = false;

if ($error) {
    $message = "Erro na autorização: " . htmlspecialchars($error);
} elseif ($code) {
    // Trocar código por token
    $ch = curl_init('https://melhorenvio.com.br/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $CLIENT_ID,
            'client_secret' => $CLIENT_SECRET,
            'redirect_uri' => $REDIRECT_URI,
            'code' => $code
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($httpCode === 200 && !empty($data['access_token'])) {
        // Salvar token no banco
        try {
            $pdo = new PDO("pgsql:host={$DB['host']};dbname={$DB['name']}", $DB['user'], $DB['pass']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Criar tabela se não existir
            $pdo->exec("CREATE TABLE IF NOT EXISTS om_full_config (
                id SERIAL PRIMARY KEY,
                config_key VARCHAR(100) NOT NULL UNIQUE,
                config_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            
            // Salvar tokens
            $stmt = $pdo->prepare("INSERT INTO om_full_config (config_key, config_value) VALUES (?, ?) 
                                   ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value");
            
            $stmt->execute(['melhor_envio_token', $data['access_token']]);
            $stmt->execute(['melhor_envio_refresh_token', $data['refresh_token'] ?? '']);
            $stmt->execute(['melhor_envio_token_expires', date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 2592000))]);
            
            $success = true;
            $message = "Token salvo com sucesso! Expira em " . ($data['expires_in'] ?? 2592000) / 86400 . " dias.";
        } catch (Exception $e) {
            error_log("[melhor_envio_callback] Erro ao salvar token: " . $e->getMessage());
            $message = "Erro ao salvar token. Tente novamente.";
        }
    } else {
        $message = "Erro ao obter token: " . ($data['error_description'] ?? $data['error'] ?? 'Erro desconhecido');
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Callback Melhor Envio - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', system-ui, sans-serif; 
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); 
            color: #e2e8f0; 
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            text-align: center;
        }
        .icon { font-size: 64px; margin-bottom: 20px; }
        h1 { font-size: 24px; margin-bottom: 16px; }
        p { color: #94a3b8; margin-bottom: 24px; line-height: 1.6; }
        .btn {
            display: inline-block;
            padding: 14px 28px;
            background: linear-gradient(135deg, #FF6B00, #FF8C00);
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
        }
        .success { color: #34d399; }
        .error { color: #f87171; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
            <div class="icon">✅</div>
            <h1 class="success">Autorização Concluída!</h1>
            <p><?= htmlspecialchars($message) ?></p>
            <p>O sistema de frete do Melhor Envio agora está funcionando com cálculo real de preços e prazos.</p>
        <?php elseif ($message): ?>
            <div class="icon">❌</div>
            <h1 class="error">Erro na Autorização</h1>
            <p><?= htmlspecialchars($message) ?></p>
        <?php else: ?>
            <div class="icon">🚚</div>
            <h1>Autorizar Melhor Envio</h1>
            <p>Clique no botão abaixo para autorizar o OneMundo a calcular fretes usando a API do Melhor Envio.</p>
            <?php
            $authUrl = 'https://melhorenvio.com.br/oauth/authorize?' . http_build_query([
                'client_id' => $CLIENT_ID,
                'redirect_uri' => $REDIRECT_URI,
                'response_type' => 'code',
                'scope' => 'cart-read cart-write shipping-calculate shipping-cancel shipping-checkout shipping-companies shipping-generate shipping-preview shipping-print shipping-share shipping-tracking'
            ]);
            ?>
            <a href="<?= htmlspecialchars($authUrl) ?>" class="btn">Autorizar Melhor Envio</a>
        <?php endif; ?>
        
        <br><br>
        <a href="index.php" style="color:#60a5fa;text-decoration:none;">← Voltar ao site</a>
    </div>
</body>
</html>
