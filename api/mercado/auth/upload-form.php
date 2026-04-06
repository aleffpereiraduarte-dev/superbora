<?php
header('Content-Type: text/html');
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Upload</title></head>
<body style="background:#111;color:#fff;font-family:system-ui;padding:20px;text-align:center">
<h2 style="color:#f97316">Upload Screenshots</h2>
<form method="POST" action="upload-screenshot.php" enctype="multipart/form-data">
<input type="file" name="files[]" multiple accept="image/*" style="margin:20px 0;color:#fff"><br>
<button type="submit" style="background:#f97316;color:#fff;border:none;padding:14px 32px;border-radius:8px;font-size:16px;cursor:pointer">Enviar</button>
</form>
<?php
if (!empty($_GET['ok'])) {
    echo '<p style="color:#22c55e;margin-top:20px">Enviado com sucesso!</p>';
}
?>
</body></html>
