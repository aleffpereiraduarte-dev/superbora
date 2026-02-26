<?php
/**
 * Processar Alertas de Estoque
 *
 * Executar via CRON a cada 5 minutos:
 * 0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /var/www/html/api/mercado/produtos/processar-alertas-estoque.php
 *
 * Verifica produtos que voltaram ao estoque e notifica usuários cadastrados
 */

// Validar acesso: CLI direto ou HTTP com X-Cron-Key header
if (php_sapi_name() === 'cli') {
    // Allow direct CLI execution (cron job)
} else {
    $CRON_SECRET = getenv('CRON_SECRET');
    if (!$CRON_SECRET || !isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($CRON_SECRET, $_SERVER['HTTP_X_CRON_KEY'])) {
        http_response_code(403);
        die('Acesso negado');
    }
}

require_once dirname(dirname(dirname(__DIR__))) . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Buscar alertas não notificados para produtos com estoque
    $stmt = $pdo->query("
        SELECT a.*, p.quantity, pd.name as product_name
        FROM om_product_stock_alerts a
        JOIN oc_product p ON a.product_id = p.product_id
        JOIN oc_product_description pd ON a.product_id = pd.product_id AND pd.language_id = 2
        WHERE a.notified = 0 AND p.quantity > 0
        LIMIT 100
    ");
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $failed = 0;

    foreach ($alerts as $alert) {
        $success = sendStockAlert($alert);

        if ($success) {
            // Marcar como notificado
            $pdo->prepare("UPDATE om_product_stock_alerts SET notified = 1, notified_at = NOW() WHERE id = ?")
                ->execute([$alert['id']]);
            $sent++;
        } else {
            $failed++;
        }
    }

    $result = [
        'success' => true,
        'processed' => count($alerts),
        'sent' => $sent,
        'failed' => $failed,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    if (php_sapi_name() === 'cli') {
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode($result);
    }

} catch (Exception $e) {
    error_log("[processar-alertas-estoque] Erro: " . $e->getMessage());
    $error = ['success' => false, 'message' => 'Erro interno do servidor'];
    if (php_sapi_name() === 'cli') {
        echo json_encode($error, JSON_PRETTY_PRINT) . "\n";
        exit(1);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode($error);
    }
}

/**
 * Enviar notificação de disponibilidade
 */
function sendStockAlert(array $alert): bool
{
    $email = $alert['email'];
    $productName = htmlspecialchars($alert['product_name'], ENT_QUOTES, 'UTF-8');
    $productId = $alert['product_id'];

    // Construir link do produto
    $productUrl = "https://onemundo.com.br/index.php?route=product/product&product_id={$productId}";

    // Email HTML
    $subject = "🎉 {$productName} está disponível!";

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #FF6B00, #E55D00); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .product-name { font-size: 20px; font-weight: bold; color: #333; margin-bottom: 15px; }
        .message { color: #666; line-height: 1.6; margin-bottom: 25px; }
        .cta-button { display: inline-block; background: linear-gradient(135deg, #FF6B00, #E55D00); color: white; padding: 14px 32px; border-radius: 50px; text-decoration: none; font-weight: bold; }
        .footer { background: #f9f9f9; padding: 20px; text-align: center; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Produto Disponível!</h1>
        </div>
        <div class="content">
            <div class="product-name">{$productName}</div>
            <div class="message">
                Ótimas notícias! O produto que você estava esperando voltou ao estoque.
                <br><br>
                Corra para garantir o seu antes que acabe novamente!
            </div>
            <a href="{$productUrl}" class="cta-button">Ver Produto</a>
        </div>
        <div class="footer">
            <p>Você recebeu este email porque se cadastrou para ser notificado sobre este produto.</p>
            <p>OneMundo - Seu marketplace de confiança</p>
        </div>
    </div>
</body>
</html>
HTML;

    // Enviar email
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: OneMundo <noreply@onemundo.com.br>',
        'Reply-To: contato@onemundo.com.br',
        'X-Mailer: PHP/' . phpversion()
    ];

    return @mail($email, $subject, $htmlBody, implode("\r\n", $headers));
}
