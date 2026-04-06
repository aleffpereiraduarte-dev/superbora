<?php
// Forward all incoming calls directly to Aleff's US phone
header('Content-Type: text/xml');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
echo '<Say language="pt-BR" voice="Polly.Camila">Transferindo.</Say>';
echo '<Dial callerId="+551150391081" timeout="45">+19547077804</Dial>';
echo '<Say language="pt-BR" voice="Polly.Camila">Não foi possível completar a ligação.</Say>';
echo '</Response>';
