<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
session_name("OCSESSID");
session_start();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = $_POST["email"] ?? "";
    $senha = $_POST["senha"] ?? "";
    
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4", "root", DB_PASSWORD);
        $stmt = $pdo->prepare("SELECT delivery_id, name FROM om_market_delivery WHERE email = ?");
        $stmt->execute([$email]);
        $d = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($d) {
            $_SESSION["delivery_id"] = $d["delivery_id"];
            header("Location: index.php");
            exit;
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login Delivery</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#0f172a;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#1e293b;border-radius:20px;padding:40px;max-width:400px;width:100%}
h1{text-align:center;margin-bottom:30px;color:#10b981}
input{width:100%;padding:14px;border:none;border-radius:10px;margin-bottom:16px;font-size:16px}
button{width:100%;padding:16px;background:#10b981;color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer}
</style>
</head>
<body>
<div class="card">
<h1>🚴 Delivery</h1>
<form method="post">
<input type="email" name="email" placeholder="Email" required>
<input type="password" name="senha" placeholder="Senha" required>
<button type="submit">Entrar</button>
</form>
</div>
</body>
</html>