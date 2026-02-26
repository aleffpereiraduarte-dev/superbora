<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * Verificação SMS/Email - Trabalhe Conosco
 * /mercado/trabalhe-conosco/verificar.php
 */
session_start();

if (!isset($_SESSION["pending_worker_id"])) {
    header("Location: cadastro.php");
    exit;
}

$workerId = $_SESSION["pending_worker_id"];
$phone = $_SESSION["pending_phone"] ?? "";
$email = $_SESSION["pending_email"] ?? "";

// Processar verificação
$error = "";
$success = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $code = $_POST["code"] ?? "";
    
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->prepare("SELECT verification_code, verification_code_expires FROM om_market_workers WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($worker && $worker["verification_code"] === $code) {
            if (strtotime($worker["verification_code_expires"]) > time()) {
                // Código válido
                $stmt = $pdo->prepare("UPDATE om_market_workers SET 
                    verified_at = NOW(), 
                    verified_phone = 1,
                    verification_code = NULL 
                    WHERE worker_id = ?");
                $stmt->execute([$workerId]);
                
                $success = true;
                $_SESSION["worker_verified"] = true;
                
                // Redirecionar após 2 segundos
                header("Refresh: 2; url=documentos.php");
            } else {
                $error = "Código expirado. Solicite um novo.";
            }
        } else {
            $error = "Código inválido.";
        }
    } catch (Exception $e) {
        $error = "Erro ao verificar: " . $e->getMessage();
    }
}

// Reenviar código
if (isset($_GET["resend"])) {
    try {
        $pdo = getPDO();
        
        $newCode = str_pad(rand(0, 999999), 6, "0", STR_PAD_LEFT);
        $expires = date("Y-m-d H:i:s", strtotime("+10 minutes"));
        
        $stmt = $pdo->prepare("UPDATE om_market_workers SET verification_code = ?, verification_code_expires = ? WHERE worker_id = ?");
        $stmt->execute([$newCode, $expires, $workerId]);
        
        // TODO: Enviar SMS/Email com o código
        // Por enquanto, mostrar na tela para teste
        $_SESSION["debug_code"] = $newCode;
        
        header("Location: verificar.php?sent=1");
        exit;
    } catch (Exception $e) {
        $error = "Erro ao reenviar: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Verificar - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 20px;
            padding: 40px 30px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .icon { font-size: 4rem; margin-bottom: 20px; }
        h1 { font-size: 1.5rem; margin-bottom: 10px; color: #1a1a2e; }
        p { color: #666; margin-bottom: 30px; }
        .phone { font-weight: bold; color: #667eea; }
        .code-inputs {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 30px;
        }
        .code-inputs input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: bold;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            outline: none;
            transition: all 0.2s;
        }
        .code-inputs input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
        }
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn:disabled { opacity: 0.5; }
        .resend {
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .error { background: #fee; color: #c00; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .success { background: #efe; color: #080; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
        .debug { background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; margin-top: 20px; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
            <div class="icon">✅</div>
            <h1>Verificado!</h1>
            <p>Redirecionando para upload de documentos...</p>
        <?php else: ?>
            <div class="icon">📱</div>
            <h1>Verificar Telefone</h1>
            <p>Digite o código de 6 dígitos enviado para<br><span class="phone"><?= substr($phone, 0, 4) ?>****<?= substr($phone, -2) ?></span></p>
            
            <?php if ($error): ?>
                <div class="error"><?= $error ?></div>
            <?php endif; ?>
            
            <?php if (isset($_GET["sent"])): ?>
                <div class="success">Código reenviado!</div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="code-inputs">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric" autofocus>
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric">
                </div>
                <input type="hidden" name="code" id="fullCode">
                <button type="submit" class="btn" id="submitBtn" disabled>Verificar</button>
            </form>
            
            <a href="?resend=1" class="resend">Não recebeu? Reenviar código</a>
            
            <?php if (isset($_SESSION["debug_code"])): ?>
                <div class="debug">🔧 Debug: Código = <strong><?= $_SESSION["debug_code"] ?></strong></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
    const inputs = document.querySelectorAll(".code-inputs input");
    const fullCode = document.getElementById("fullCode");
    const submitBtn = document.getElementById("submitBtn");
    
    inputs.forEach((input, index) => {
        input.addEventListener("input", (e) => {
            if (e.target.value && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
            updateCode();
        });
        
        input.addEventListener("keydown", (e) => {
            if (e.key === "Backspace" && !e.target.value && index > 0) {
                inputs[index - 1].focus();
            }
        });
        
        input.addEventListener("paste", (e) => {
            e.preventDefault();
            const paste = e.clipboardData.getData("text").replace(/\D/g, "").slice(0, 6);
            paste.split("").forEach((char, i) => {
                if (inputs[i]) inputs[i].value = char;
            });
            updateCode();
        });
    });
    
    function updateCode() {
        const code = Array.from(inputs).map(i => i.value).join("");
        fullCode.value = code;
        submitBtn.disabled = code.length !== 6;
    }
    </script>
</body>
</html>