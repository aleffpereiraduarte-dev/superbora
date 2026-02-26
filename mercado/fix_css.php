<?php
$arquivo = __DIR__ . '/one.php';
$css = '
/* ONE ULTRA v5.0 - PREMIUM WHITE/BLACK */
body{background:#fff!important}
.one-header{background:#fff!important;border-bottom:1px solid #e5e5e5!important;box-shadow:none!important}
.one-header::after{display:none!important}
.one-avatar{background:#000!important;border:none!important;box-shadow:none!important;border-radius:50%!important}
.one-info h1{color:#000!important}
.one-info p{color:#737373!important}
.one-status{background:#f5f5f5!important;border:1px solid #e5e5e5!important;color:#525252!important}
.chat-container{background:#fafafa!important}
.message.one .message-avatar,.message:not(.user) .message-avatar{background:#000!important;border:none!important}
.message.user .message-avatar{background:#e5e5e5!important;border:none!important}
.message.one .bubble,.message:not(.user) .bubble{background:#fff!important;color:#171717!important;border:1px solid #e5e5e5!important}
.message.user .bubble{background:#000!important;color:#fff!important;border:none!important}
.input-container{background:#fff!important;border-top:1px solid #e5e5e5!important}
.input-wrapper{background:#f5f5f5!important;border:1px solid #e5e5e5!important}
.input-wrapper:focus-within{background:#fff!important;border-color:#000!important}
.send-btn,#sendBtn{background:#000!important;color:#fff!important;box-shadow:none!important}
.mic-btn,#micBtn{background:#fff!important;border:1px solid #e5e5e5!important;color:#525252!important}
.quick-actions button{background:#fff!important;border:1px solid #e5e5e5!important;color:#525252!important}
.typing-indicator .dot{background:#000!important}
';

$conteudo = file_get_contents($arquivo);

// Remove CSS antigo se existir
$conteudo = preg_replace('/\/\* ONE ULTRA v5\.0.*?(?=\n:root|\n\/\*\s*═)/s', '', $conteudo);

// Encontra a linha 14415 (o </style> correto do chat)
$linhas = explode("\n", $conteudo);
$linhas[14413] = $css . "\n" . $linhas[14413]; // Insere antes do </style>

file_put_contents($arquivo, implode("\n", $linhas));
echo "SUCESSO! CSS inserido na posicao correta!\n";
