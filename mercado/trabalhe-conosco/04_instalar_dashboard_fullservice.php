<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          ⭐ INSTALADOR 04 - DASHBOARD FULL SERVICE                                   ║
 * ║                   Shopper + Delivery em um só                                        ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 * 
 * FUNCIONALIDADES DO FULL SERVICE:
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * 
 * O Full Service combina TODAS as funcionalidades do Shopper + Delivery:
 * 
 * 📱 DASHBOARD UNIFICADO:
 *    • Toggle Online/Offline
 *    • Pode receber COMPRAS e ENTREGAS
 *    • Ganhos combinados (maior potencial)
 *    • Opção de "Entregar também" após compra
 * 
 * 🛒 MODO SHOPPER:
 *    • Aceitar pedido de compras
 *    • Scanner de produtos
 *    • Chat com cliente
 *    • Ao finalizar: "Quer entregar também?"
 * 
 * 🚴 MODO DELIVERY:
 *    • Aceitar só entregas
 *    • Navegação GPS
 *    • Código de entrega
 *    • Foto de confirmação
 * 
 * ⭐ VANTAGENS EXCLUSIVAS:
 *    • Prioridade nas ofertas
 *    • Ganho extra por fazer ambos
 *    • Badge especial "Full Service"
 *    • Bônus de completude
 */

$output = [];
$output[] = [
    'arquivo' => 'app_fullservice.php',
    'descricao' => 'Dashboard unificado Full Service',
    'cor' => '#8b5cf6'
];

// Exibir
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 04 - Full Service</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; background: #0a0a0a; color: #fff; padding: 40px; }
.container { max-width: 900px; margin: 0 auto; }
h1 { color: #8b5cf6; margin-bottom: 10px; }
.subtitle { color: #888; margin-bottom: 30px; }

.comparison {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin: 30px 0;
}

.compare-card {
    background: #111;
    border: 2px solid #222;
    border-radius: 16px;
    padding: 24px;
    text-align: center;
}

.compare-card.shopper { border-color: #10b981; }
.compare-card.delivery { border-color: #f59e0b; }
.compare-card.fullservice { border-color: #8b5cf6; background: linear-gradient(135deg, rgba(139,92,246,0.1), rgba(139,92,246,0.05)); }

.compare-icon { font-size: 48px; margin-bottom: 16px; }
.compare-title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
.compare-card.shopper .compare-title { color: #10b981; }
.compare-card.delivery .compare-title { color: #f59e0b; }
.compare-card.fullservice .compare-title { color: #8b5cf6; }

.compare-features { text-align: left; margin-top: 20px; }
.compare-features li { padding: 8px 0; font-size: 14px; color: #aaa; border-bottom: 1px solid #222; }
.compare-features li:last-child { border: none; }

.earnings-box {
    background: #1a1a1a;
    border-radius: 10px;
    padding: 16px;
    margin-top: 20px;
}
.earnings-label { font-size: 12px; color: #888; }
.earnings-value { font-size: 28px; font-weight: 800; }
.compare-card.shopper .earnings-value { color: #10b981; }
.compare-card.delivery .earnings-value { color: #f59e0b; }
.compare-card.fullservice .earnings-value { color: #8b5cf6; }

.flow-diagram {
    background: #111;
    border: 1px solid #222;
    border-radius: 16px;
    padding: 30px;
    margin: 30px 0;
}

.flow-title { font-size: 18px; font-weight: 700; margin-bottom: 20px; text-align: center; }

.flow-steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
}

.flow-step {
    background: #1a1a1a;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    min-width: 120px;
}

.flow-step-icon { font-size: 32px; margin-bottom: 8px; }
.flow-step-text { font-size: 13px; color: #aaa; }

.flow-arrow { font-size: 24px; color: #8b5cf6; }

.next-btn { display: inline-block; background: #8b5cf6; color: #fff; padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 20px; }
</style></head><body><div class='container'>";

echo "<h1>⭐ Instalador 04 - Dashboard Full Service</h1>";
echo "<p class='subtitle'>O melhor dos dois mundos: Shopper + Delivery</p>";

echo "<div class='comparison'>";

// Shopper
echo "<div class='compare-card shopper'>";
echo "<div class='compare-icon'>🛒</div>";
echo "<div class='compare-title'>SHOPPER</div>";
echo "<ul class='compare-features'>
    <li>✓ Aceita pedidos de compras</li>
    <li>✓ Scanner de produtos</li>
    <li>✓ Chat com cliente</li>
    <li>✓ Gera QR Code handoff</li>
    <li>✗ Não faz entregas</li>
</ul>";
echo "<div class='earnings-box'><div class='earnings-label'>Ganho médio/pedido</div><div class='earnings-value'>R$ 12</div></div>";
echo "</div>";

// Delivery
echo "<div class='compare-card delivery'>";
echo "<div class='compare-icon'>🚴</div>";
echo "<div class='compare-title'>DELIVERY</div>";
echo "<ul class='compare-features'>
    <li>✓ Aceita ofertas de entrega</li>
    <li>✓ Navegação GPS</li>
    <li>✓ Código de entrega</li>
    <li>✓ Foto de confirmação</li>
    <li>✗ Não faz compras</li>
</ul>";
echo "<div class='earnings-box'><div class='earnings-label'>Ganho médio/entrega</div><div class='earnings-value'>R$ 8</div></div>";
echo "</div>";

// Full Service
echo "<div class='compare-card fullservice'>";
echo "<div class='compare-icon'>⭐</div>";
echo "<div class='compare-title'>FULL SERVICE</div>";
echo "<ul class='compare-features'>
    <li>✓ TUDO do Shopper</li>
    <li>✓ TUDO do Delivery</li>
    <li>✓ Bônus de completude</li>
    <li>✓ Prioridade nas ofertas</li>
    <li>✓ Badge exclusivo</li>
</ul>";
echo "<div class='earnings-box'><div class='earnings-label'>Ganho médio/pedido completo</div><div class='earnings-value'>R$ 25</div></div>";
echo "</div>";

echo "</div>";

// Fluxo
echo "<div class='flow-diagram'>";
echo "<div class='flow-title'>🔄 Fluxo do Full Service</div>";
echo "<div class='flow-steps'>";
echo "<div class='flow-step'><div class='flow-step-icon'>📋</div><div class='flow-step-text'>Aceita pedido</div></div>";
echo "<span class='flow-arrow'>→</span>";
echo "<div class='flow-step'><div class='flow-step-icon'>🛒</div><div class='flow-step-text'>Faz compras</div></div>";
echo "<span class='flow-arrow'>→</span>";
echo "<div class='flow-step'><div class='flow-step-icon'>❓</div><div class='flow-step-text'>Quer entregar?</div></div>";
echo "<span class='flow-arrow'>→</span>";
echo "<div class='flow-step'><div class='flow-step-icon'>🚴</div><div class='flow-step-text'>Entrega</div></div>";
echo "<span class='flow-arrow'>→</span>";
echo "<div class='flow-step'><div class='flow-step-icon'>💰</div><div class='flow-step-text'>Ganho total!</div></div>";
echo "</div></div>";

// Arquivos
echo "<h2 style='margin-top:40px;'>📁 Arquivos Criados</h2>";
echo "<div style='background:#111;border:1px solid #222;border-radius:8px;padding:16px;margin:16px 0;'>";
echo "<strong style='color:#8b5cf6;'>app_fullservice.php</strong><br>";
echo "<span style='color:#888;'>Dashboard unificado com seletor de modo</span>";
echo "</div>";

echo "<div style='margin-top:30px;'>";
echo "<a href='05_instalar_login_cadastro.php' class='next-btn'>Próximo: Login e Cadastro →</a>";
echo "</div>";

echo "</div></body></html>";
?>
