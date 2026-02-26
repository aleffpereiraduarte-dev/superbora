<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          🚴 INSTALADOR 03 - DASHBOARD DO DELIVERY                                    ║
 * ║                   Funcionalidades Estilo Uber/iFood                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 03 - Delivery</title>";
echo "<style>body{font-family:'Segoe UI',sans-serif;background:#0a0a0a;color:#fff;padding:40px;}.container{max-width:900px;margin:0 auto;}h1{color:#f59e0b;margin-bottom:10px;}
.subtitle{color:#888;margin-bottom:30px;}
.feature-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin:30px 0;}
.feature-card{background:#111;border:1px solid #222;border-radius:12px;padding:20px;}
.feature-icon{font-size:32px;margin-bottom:10px;}
.feature-title{font-weight:600;margin-bottom:8px;color:#f59e0b;}
.feature-list{font-size:13px;color:#888;}
.feature-list li{margin:4px 0;}
.workflow{background:#111;border:1px solid #222;border-radius:16px;padding:30px;margin:30px 0;}
.workflow-title{font-size:18px;font-weight:700;margin-bottom:20px;text-align:center;}
.workflow-steps{display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;}
.workflow-step{text-align:center;padding:16px;}
.workflow-step-icon{font-size:32px;margin-bottom:8px;}
.workflow-step-text{font-size:12px;color:#888;}
.workflow-arrow{font-size:20px;color:#f59e0b;}
.next-btn{display:inline-block;background:#f59e0b;color:#000;padding:15px 30px;border-radius:8px;text-decoration:none;font-weight:bold;margin-top:20px;}
</style></head><body><div class='container'>";

echo "<h1>🚴 Instalador 03 - Dashboard do Delivery</h1>";
echo "<p class='subtitle'>Funcionalidades estilo Uber/iFood - Tema Laranja</p>";

echo "<div class='feature-grid'>";

$features = [
    ['📱', 'Dashboard com Mapa', ['Toggle Online/Offline', 'Mapa em tempo real', 'Zonas de demanda (cores)', 'Ofertas disponíveis']],
    ['🗺️', 'Navegação GPS', ['Rota otimizada', 'Integração Waze/Maps', 'ETA dinâmico', 'Tracking em tempo real']],
    ['📦', 'Coleta do Pedido', ['Escanear QR Code', 'Verificar handoff', 'Conferir sacolas', 'Foto da coleta']],
    ['🏠', 'Entrega ao Cliente', ['Navegação até destino', 'Código de verificação', 'Foto da entrega', 'Chat com cliente']],
    ['💰', 'Ganhos', ['Por entrega + km rodado', 'Bônus de zona', 'Gorjetas', 'Saque instantâneo']],
    ['⚠️', 'Problemas', ['Cliente ausente', 'Endereço errado', 'Acidente', 'Botão de emergência']]
];

foreach ($features as $f) {
    echo "<div class='feature-card'>";
    echo "<div class='feature-icon'>{$f[0]}</div>";
    echo "<div class='feature-title'>{$f[1]}</div>";
    echo "<ul class='feature-list'>";
    foreach ($f[2] as $item) {
        echo "<li>✓ $item</li>";
    }
    echo "</ul></div>";
}

echo "</div>";

// Workflow
echo "<div class='workflow'>";
echo "<div class='workflow-title'>🔄 Fluxo de uma Entrega</div>";
echo "<div class='workflow-steps'>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>📋</div><div class='workflow-step-text'>Oferta<br>recebida</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>✅</div><div class='workflow-step-text'>Aceitar<br>corrida</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>🏪</div><div class='workflow-step-text'>Ir ao<br>mercado</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>📱</div><div class='workflow-step-text'>Escanear<br>QR Code</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>🚗</div><div class='workflow-step-text'>Entregar<br>ao cliente</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>🔢</div><div class='workflow-step-text'>Código<br>entrega</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>💰</div><div class='workflow-step-text'>Ganho<br>creditado!</div></div>";
echo "</div></div>";

echo "<h2 style='margin-top:40px;'>📁 Arquivos Criados</h2>";
echo "<div style='background:#111;border:1px solid #222;border-radius:8px;padding:16px;margin:16px 0;'>";
echo "<strong style='color:#f59e0b;'>app_delivery.php</strong> - Dashboard principal<br>";
echo "<strong style='color:#f59e0b;'>navegacao.php</strong> - Tela de navegação GPS<br>";
echo "<strong style='color:#f59e0b;'>coleta.php</strong> - Scanner QR e conferência<br>";
echo "<strong style='color:#f59e0b;'>confirmar-entrega.php</strong> - Código e foto<br>";
echo "</div>";

echo "<a href='04_instalar_dashboard_fullservice.php' class='next-btn'>Próximo: Dashboard Full Service →</a>";
echo "</div></body></html>";
