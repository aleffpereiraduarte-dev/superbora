<?php
/**
 * Simple call forwarding - forwards incoming calls to Aleff's phone
 */
header('Content-Type: text/xml');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<Response>';
echo '<Say language="pt-BR" voice="Polly.Camila">Transferindo sua ligação, aguarde um momento.</Say>';
echo '<Dial callerId="+551150391081" timeout="30" record="record-from-answer-dual">+19547077804</Dial>';
echo '<Say language="pt-BR" voice="Polly.Camila">Desculpe, não foi possível completar a ligação. Tente novamente mais tarde.</Say>';
echo '</Response>';
