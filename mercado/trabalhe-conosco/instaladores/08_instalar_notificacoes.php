<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          🔔 INSTALADOR 08 - SISTEMA DE NOTIFICAÇÕES                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 08 - Notificações</title>";
echo "<style>body{font-family:'Segoe UI',sans-serif;background:#0a0a0a;color:#fff;padding:40px;}.container{max-width:800px;margin:0 auto;}h1{color:#ef4444;margin-bottom:30px;}
.notification{background:#111;border:1px solid #222;border-radius:12px;padding:16px;margin:12px 0;display:flex;gap:16px;align-items:flex-start;}
.notif-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;}
.notif-icon.order{background:rgba(16,185,129,0.15);}
.notif-icon.payment{background:rgba(59,130,246,0.15);}
.notif-icon.promo{background:rgba(245,158,11,0.15);}
.notif-icon.alert{background:rgba(239,68,68,0.15);}
.notif-content{flex:1;}
.notif-title{font-weight:600;margin-bottom:4px;}
.notif-text{font-size:14px;color:#888;}
.notif-time{font-size:12px;color:#555;margin-top:4px;}
.next-btn{display:inline-block;background:#ef4444;color:#fff;padding:15px 30px;border-radius:8px;text-decoration:none;font-weight:bold;margin-top:20px;}
</style></head><body><div class='container'>";

echo "<h1>🔔 Instalador 08 - Sistema de Notificações</h1>";

echo "<h2 style='margin:30px 0 16px;'>Tipos de Notificação:</h2>";

$notifs = [
    ['📦', 'order', 'Nova Oferta!', 'Pedido de R$ 45,90 disponível no Mercado Central', 'Agora'],
    ['💰', 'payment', 'Pagamento Recebido', 'R$ 127,50 foi depositado na sua conta', '2 min atrás'],
    ['🎁', 'promo', 'Promoção Especial', 'Complete 5 pedidos hoje e ganhe R$ 20 de bônus!', '15 min atrás'],
    ['⚠️', 'alert', 'Verificação Necessária', 'Sua verificação facial expira amanhã', '1 hora atrás'],
];

foreach ($notifs as $n) {
    echo "<div class='notification'>";
    echo "<div class='notif-icon {$n[1]}'>{$n[0]}</div>";
    echo "<div class='notif-content'>";
    echo "<div class='notif-title'>{$n[2]}</div>";
    echo "<div class='notif-text'>{$n[3]}</div>";
    echo "<div class='notif-time'>{$n[4]}</div>";
    echo "</div></div>";
}

echo "<h2 style='margin:40px 0 16px;'>Canais de Notificação:</h2>";
echo "<ul style='color:#888;line-height:2;'>";
echo "<li>✅ Push Notification (PWA)</li>";
echo "<li>✅ SMS (ofertas urgentes)</li>";
echo "<li>✅ Email (pagamentos, documentos)</li>";
echo "<li>✅ In-App (todas)</li>";
echo "</ul>";

echo "<a href='09_instalar_painel_rh.php' class='next-btn'>Próximo: Painel RH →</a>";
echo "</div></body></html>";
