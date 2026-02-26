<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * Tela Aguardando Aprovação - Trabalhe Conosco
 * /mercado/trabalhe-conosco/aguardando.php
 */
session_start();

$workerId = $_SESSION["worker_id"] ?? $_SESSION["pending_worker_id"] ?? 0;

if (!$workerId) {
    header("Location: login.php");
    exit;
}

// Buscar status
try {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT name, worker_type, application_status, created_at FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$worker) {
        header("Location: login.php");
        exit;
    }
    
    // Se já foi aprovado, redirecionar para o app
    if (in_array($worker["application_status"], ["approved", "active"])) {
        $_SESSION["worker_id"] = $workerId;
        header("Location: app.php");
        exit;
    }
    
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}

$statusMessages = [
    "draft" => ["📝", "Cadastro Incompleto", "Complete seu cadastro para continuar."],
    "submitted" => ["📤", "Enviado para Análise", "Seus documentos estão sendo analisados."],
    "analyzing" => ["🤖", "Em Análise", "Nossa equipe está verificando seus dados."],
    "pending_rh" => ["👥", "Aguardando RH", "Estamos finalizando sua aprovação."],
    "rejected" => ["❌", "Não Aprovado", "Infelizmente não foi possível aprovar seu cadastro."],
];

$status = $worker["application_status"] ?? "submitted";
$s = $statusMessages[$status] ?? $statusMessages["submitted"];

$typeLabels = ["shopper" => "Shopper", "driver" => "Entregador", "full_service" => "Full Service"];
$typeColors = ["shopper" => "#00b894", "driver" => "#e17055", "full_service" => "#6c5ce7"];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aguardando Aprovação - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            padding: 40px 20px;
            text-align: center;
        }
        .header h1 { font-size: 1.5rem; margin-bottom: 5px; }
        .header p { opacity: 0.9; }
        .container { padding: 20px; max-width: 500px; margin: 0 auto; }
        .status-card {
            background: #fff;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            margin-top: -40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .status-icon { font-size: 4rem; margin-bottom: 20px; }
        .status-title { font-size: 1.3rem; font-weight: 600; margin-bottom: 10px; color: #1a1a2e; }
        .status-desc { color: #666; margin-bottom: 30px; }
        .type-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #fff;
            background: <?= $typeColors[$worker["worker_type"]] ?? "#667eea" ?>;
        }
        .timeline {
            margin-top: 30px;
            text-align: left;
        }
        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px 0;
            border-left: 2px solid #e0e0e0;
            margin-left: 10px;
            padding-left: 25px;
            position: relative;
        }
        .timeline-item::before {
            content: "";
            position: absolute;
            left: -7px;
            top: 18px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #e0e0e0;
        }
        .timeline-item.done::before { background: #00b894; }
        .timeline-item.current::before { background: #667eea; animation: pulse 1s infinite; }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.3); }
        }
        .timeline-item.done { color: #00b894; }
        .timeline-item.current { color: #667eea; font-weight: 600; }
        .info-box {
            background: #f0f4ff;
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            text-align: left;
        }
        .info-box h4 { color: #667eea; margin-bottom: 10px; }
        .info-box p { color: #666; font-size: 0.9rem; line-height: 1.6; }
        .refresh-btn {
            display: block;
            width: 100%;
            padding: 15px;
            background: #667eea;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            margin-top: 20px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Olá, <?= htmlspecialchars($worker["name"] ?? "Worker") ?>!</h1>
        <p>Seu cadastro está em análise</p>
    </div>
    
    <div class="container">
        <div class="status-card">
            <div class="status-icon"><?= $s[0] ?></div>
            <div class="status-title"><?= $s[1] ?></div>
            <div class="status-desc"><?= $s[2] ?></div>
            <span class="type-badge"><?= $typeLabels[$worker["worker_type"]] ?? $worker["worker_type"] ?></span>
            
            <div class="timeline">
                <div class="timeline-item done">Cadastro realizado</div>
                <div class="timeline-item <?= in_array($status, ["submitted","analyzing","pending_rh"]) ? "done" : "" ?>">Documentos enviados</div>
                <div class="timeline-item <?= $status === "analyzing" ? "current" : ($status === "pending_rh" ? "done" : "") ?>">Análise de documentos</div>
                <div class="timeline-item <?= $status === "pending_rh" ? "current" : "" ?>">Aprovação final</div>
            </div>
            
            <div class="info-box">
                <h4>📱 Fique atento!</h4>
                <p>Você receberá uma notificação por SMS e e-mail assim que seu cadastro for aprovado. O processo geralmente leva até 48 horas úteis.</p>
            </div>
            
            <button class="refresh-btn" onclick="location.reload()">🔄 Atualizar Status</button>
        </div>
    </div>
    
    <script>
    // Auto-refresh a cada 30 segundos
    setTimeout(() => location.reload(), 30000);
    </script>
</body>
</html>