<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          🏆 INSTALADOR 07 - GAMIFICAÇÃO                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 07 - Gamificação</title>";
echo "<style>body{font-family:'Segoe UI',sans-serif;background:#0a0a0a;color:#fff;padding:40px;}.container{max-width:900px;margin:0 auto;}h1{color:#f59e0b;margin-bottom:30px;}
.feature-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin:30px 0;}
.feature-card{background:#111;border:1px solid #222;border-radius:16px;padding:24px;text-align:center;}
.feature-icon{font-size:48px;margin-bottom:16px;}
.feature-title{font-size:18px;font-weight:700;margin-bottom:8px;color:#f59e0b;}
.feature-desc{font-size:14px;color:#888;}
.badges{display:flex;gap:12px;justify-content:center;margin-top:30px;flex-wrap:wrap;}
.badge{background:#1a1a1a;border:2px solid #333;border-radius:12px;padding:16px 24px;text-align:center;}
.badge-icon{font-size:32px;margin-bottom:8px;}
.badge-name{font-weight:600;font-size:14px;}
.badge.bronze{border-color:#cd7f32;}.badge.silver{border-color:#c0c0c0;}.badge.gold{border-color:#ffd700;}.badge.diamond{border-color:#b9f2ff;}
.next-btn{display:inline-block;background:#f59e0b;color:#000;padding:15px 30px;border-radius:8px;text-decoration:none;font-weight:bold;margin-top:20px;}
</style></head><body><div class='container'>";

echo "<h1>🏆 Instalador 07 - Sistema de Gamificação</h1>";

echo "<div class='feature-grid'>";
$features = [
    ['🎯', 'Desafios Diários', 'Complete X pedidos, ganhe bônus em dinheiro ou XP'],
    ['📊', 'Ranking Semanal', 'Compita com outros workers e ganhe prêmios'],
    ['⚡', 'Streak', 'Dias consecutivos trabalhando = multiplicador de XP'],
    ['🎁', 'Conquistas', 'Desbloqueie badges por marcos atingidos'],
    ['📈', 'Níveis', 'Suba de nível e desbloqueie vantagens exclusivas'],
    ['💎', 'Multiplicadores', 'Zonas com alta demanda pagam mais'],
];

foreach ($features as $f) {
    echo "<div class='feature-card'>";
    echo "<div class='feature-icon'>{$f[0]}</div>";
    echo "<div class='feature-title'>{$f[1]}</div>";
    echo "<div class='feature-desc'>{$f[2]}</div>";
    echo "</div>";
}
echo "</div>";

echo "<h2 style='margin-top:40px;text-align:center;'>🏅 Sistema de Badges</h2>";
echo "<div class='badges'>";
echo "<div class='badge bronze'><div class='badge-icon'>🥉</div><div class='badge-name'>Bronze</div></div>";
echo "<div class='badge silver'><div class='badge-icon'>🥈</div><div class='badge-name'>Prata</div></div>";
echo "<div class='badge gold'><div class='badge-icon'>🥇</div><div class='badge-name'>Ouro</div></div>";
echo "<div class='badge diamond'><div class='badge-icon'>💎</div><div class='badge-name'>Diamante</div></div>";
echo "</div>";

echo "<div style='margin-top:40px;text-align:center;'>";
echo "<a href='08_instalar_notificacoes.php' class='next-btn'>Próximo: Notificações →</a>";
echo "</div></div></body></html>";
