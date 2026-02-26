<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          📡 INSTALADOR 06 - APIS DO SISTEMA                                          ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 06 - APIs</title>";
echo "<style>body{font-family:'Segoe UI',sans-serif;background:#0a0a0a;color:#fff;padding:40px;}.container{max-width:900px;margin:0 auto;}h1{color:#3b82f6;margin-bottom:30px;}
.api-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin:30px 0;}
.api-card{background:#111;border:1px solid #222;border-radius:12px;padding:20px;}
.api-method{display:inline-block;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;margin-bottom:8px;}
.api-method.post{background:#10b981;color:#000;}
.api-method.get{background:#3b82f6;color:#fff;}
.api-path{font-family:monospace;font-size:14px;color:#f59e0b;margin-bottom:8px;}
.api-desc{font-size:13px;color:#888;}
.next-btn{display:inline-block;background:#3b82f6;color:#fff;padding:15px 30px;border-radius:8px;text-decoration:none;font-weight:bold;margin-top:20px;}
</style></head><body><div class='container'>";

echo "<h1>📡 Instalador 06 - APIs do Sistema</h1>";

$apis = [
    // Autenticação
    ['POST', 'api/login.php', 'Autenticar worker (email/senha)'],
    ['POST', 'api/send-sms.php', 'Enviar código SMS para login'],
    ['POST', 'api/verify-code.php', 'Verificar código SMS'],
    ['POST', 'api/logout.php', 'Encerrar sessão'],
    
    // Ofertas
    ['GET', 'api/check-offers.php', 'Verificar novas ofertas'],
    ['POST', 'api/accept-offer.php', 'Aceitar oferta de pedido'],
    ['POST', 'api/reject-offer.php', 'Recusar oferta'],
    
    // Pedido/Compras
    ['GET', 'api/order-details.php', 'Detalhes do pedido'],
    ['POST', 'api/scan-item.php', 'Escanear produto'],
    ['POST', 'api/substitute.php', 'Substituir produto'],
    ['POST', 'api/finish-shopping.php', 'Finalizar compras'],
    
    // Entrega
    ['POST', 'api/start-delivery.php', 'Iniciar entrega'],
    ['POST', 'api/update-location.php', 'Atualizar GPS'],
    ['POST', 'api/complete-delivery.php', 'Confirmar entrega'],
    ['POST', 'api/delivery-photo.php', 'Foto da entrega'],
    
    // Chat
    ['GET', 'api/chat-messages.php', 'Carregar mensagens'],
    ['POST', 'api/chat-send.php', 'Enviar mensagem'],
    
    // Financeiro
    ['GET', 'api/earnings.php', 'Consultar ganhos'],
    ['POST', 'api/withdraw.php', 'Solicitar saque'],
    ['GET', 'api/wallet-history.php', 'Histórico da carteira'],
    
    // Perfil
    ['GET', 'api/profile.php', 'Dados do perfil'],
    ['POST', 'api/update-profile.php', 'Atualizar perfil'],
    ['POST', 'api/facial-verify.php', 'Verificação facial'],
    ['POST', 'api/toggle-online.php', 'Toggle online/offline'],
];

echo "<div class='api-grid'>";
foreach ($apis as $api) {
    $methodClass = strtolower($api[0]);
    echo "<div class='api-card'>";
    echo "<span class='api-method {$methodClass}'>{$api[0]}</span>";
    echo "<div class='api-path'>{$api[1]}</div>";
    echo "<div class='api-desc'>{$api[2]}</div>";
    echo "</div>";
}
echo "</div>";

echo "<p style='color:#888;margin-top:20px;'>Total: " . count($apis) . " endpoints</p>";
echo "<a href='07_instalar_gamificacao.php' class='next-btn'>Próximo: Gamificação →</a>";
echo "</div></body></html>";
