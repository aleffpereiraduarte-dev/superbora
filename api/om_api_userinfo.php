<?php
/**
 * INSTALAR API USER INFO NO OPENCART
 * Cria um controller que retorna nome + membership
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Desabilitado em produção
ini_set('log_errors', 1);

$BASE = __DIR__;
$messages = [];
$errors = [];

// Código do controller
$CONTROLLER_CODE = <<<'PHPCODE'
<?php
class ControllerApiUserinfo extends Controller {
    public function index() {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Access-Control-Allow-Origin: *');
        
        $json = [
            'success' => true,
            'logged' => false,
            'customer_id' => null,
            'firstname' => null,
            'lastname' => null,
            'email' => null,
            'membership' => null
        ];
        
        if ($this->customer->isLogged()) {
            $json['logged'] = true;
            $json['customer_id'] = $this->customer->getId();
            $json['firstname'] = $this->customer->getFirstName();
            $json['lastname'] = $this->customer->getLastName();
            $json['email'] = $this->customer->getEmail();
            $json['telephone'] = $this->customer->getTelephone();
            
            // Buscar membership
            $customer_id = (int)$this->customer->getId();
            $query = $this->db->query("
                SELECT 
                    m.level_id,
                    l.level_name,
                    l.icon,
                    l.color_primary,
                    l.color_secondary,
                    m.status,
                    m.start_date,
                    m.end_date,
                    l.shipping_discount,
                    l.points_multiplier
                FROM " . DB_PREFIX . "om_membership m
                LEFT JOIN " . DB_PREFIX . "om_membership_levels l ON m.level_id = l.level_id
                WHERE m.customer_id = '" . $customer_id . "'
                AND m.status = 'active'
                LIMIT 1
            ");
            
            if ($query->num_rows) {
                $m = $query->row;
                $json['membership'] = [
                    'level_id' => (int)$m['level_id'],
                    'level' => strtolower($m['level_name']),
                    'level_name' => $m['level_name'],
                    'icon' => $m['icon'],
                    'color_primary' => $m['color_primary'],
                    'color_secondary' => $m['color_secondary'],
                    'status' => $m['status'],
                    'shipping_discount' => (int)$m['shipping_discount'],
                    'points_multiplier' => (float)$m['points_multiplier']
                ];
            }
        }
        
        $this->response->setOutput(json_encode($json));
    }
}
PHPCODE;

function instalar() {
    global $CONTROLLER_CODE, $BASE, $messages, $errors;
    
    $controller_path = $BASE . '/catalog/controller/api/userinfo.php';
    $controller_dir = dirname($controller_path);
    
    // Criar diretório se não existir
    if (!is_dir($controller_dir)) {
        mkdir($controller_dir, 0755, true);
        $messages[] = "✅ Diretório /catalog/controller/api/ criado";
    }
    
    // Salvar controller
    if (file_put_contents($controller_path, $CONTROLLER_CODE)) {
        $messages[] = "✅ Controller userinfo.php criado!";
        return true;
    }
    
    $errors[] = "❌ Erro ao criar controller";
    return false;
}

function verificarStatus() {
    global $BASE;
    $controller_path = $BASE . '/catalog/controller/api/userinfo.php';
    return [
        'installed' => file_exists($controller_path)
    ];
}

$status = verificarStatus();

if (isset($_POST['instalar'])) {
    instalar();
    $status = verificarStatus();
}

// Testar API se instalada
$api_result = null;
if ($status['installed'] && isset($_GET['test'])) {
    $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/index.php?route=api/userinfo';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_COOKIE, $_SERVER['HTTP_COOKIE'] ?? '');
    $api_result = curl_exec($ch);
    curl_close($ch);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalar API UserInfo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, sans-serif; background: #0f172a; color: #fff; padding: 20px; min-height: 100vh; }
        .container { max-width: 600px; margin: 0 auto; }
        h1 { font-size: 24px; margin-bottom: 8px; }
        .subtitle { color: #94a3b8; margin-bottom: 30px; }
        .card { background: rgba(255,255,255,0.05); border-radius: 16px; padding: 24px; margin-bottom: 20px; }
        .status { padding: 12px 20px; border-radius: 10px; display: inline-block; font-weight: 600; }
        .status.ok { background: rgba(34,197,94,0.2); color: #22c55e; }
        .status.no { background: rgba(251,191,36,0.2); color: #fbbf24; }
        .msg { padding: 12px 16px; border-radius: 10px; margin: 10px 0; font-size: 13px; }
        .msg.ok { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #22c55e; }
        .btn { padding: 16px 32px; border: none; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; margin: 5px; }
        .btn-primary { background: linear-gradient(135deg, #FF6A00, #FF8C00); color: #fff; }
        .btn-secondary { background: rgba(255,255,255,0.1); color: #fff; }
        pre { background: #1e293b; padding: 12px; border-radius: 8px; font-size: 11px; overflow-x: auto; margin-top: 10px; color: #22c55e; }
        .info { background: rgba(59,130,246,0.1); padding: 16px; border-radius: 10px; margin: 16px 0; font-size: 13px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔌 API UserInfo</h1>
    <p class="subtitle">Retorna nome do usuário logado</p>
    
    <div class="card">
        <h3 style="margin-bottom:12px;">Status</h3>
        <div class="status <?php echo $status['installed'] ? 'ok' : 'no'; ?>">
            <?php echo $status['installed'] ? '✅ Instalado' : '⚠️ Não instalado'; ?>
        </div>
    </div>
    
    <?php if (!empty($messages)): ?>
    <div class="card">
        <?php foreach ($messages as $m): ?>
            <div class="msg ok"><?php echo $m; ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div class="info">
        <strong>Endpoint:</strong> /index.php?route=api/userinfo<br><br>
        <strong>Retorna:</strong> firstname, lastname, email, membership (level, color, discount)
    </div>
    
    <div class="card">
        <form method="POST" style="display:inline;">
            <button type="submit" name="instalar" class="btn btn-primary">
                🚀 <?php echo $status['installed'] ? 'Reinstalar' : 'Instalar'; ?> API
            </button>
        </form>
        
        <?php if ($status['installed']): ?>
        <a href="?test=1" class="btn btn-secondary">🧪 Testar API</a>
        <?php endif; ?>
    </div>
    
    <?php if ($api_result): ?>
    <div class="card">
        <h3 style="margin-bottom:12px;">Resultado do Teste:</h3>
        <pre><?php echo htmlspecialchars($api_result); ?></pre>
    </div>
    <?php endif; ?>
    
    <p style="margin-top:20px;text-align:center;">
        <a href="/" style="color:#FF6A00;">← Voltar ao site</a>
        &nbsp;|&nbsp;
        <a href="/om_fix_master.php" style="color:#FF6A00;">Fix Master →</a>
    </p>
</div>
</body>
</html>
