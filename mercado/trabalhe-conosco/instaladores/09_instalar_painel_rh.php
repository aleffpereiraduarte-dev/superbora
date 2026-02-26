<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          👔 INSTALADOR 09 - PAINEL RH (APROVAR WORKERS)                              ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 09 - Painel RH</title>";
echo "<style>body{font-family:'Segoe UI',sans-serif;background:#0a0a0a;color:#fff;padding:40px;}.container{max-width:900px;margin:0 auto;}h1{color:#8b5cf6;margin-bottom:30px;}
.feature-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin:30px 0;}
.feature-card{background:#111;border:1px solid #222;border-radius:16px;padding:24px;}
.feature-icon{font-size:40px;margin-bottom:16px;}
.feature-title{font-size:18px;font-weight:700;color:#8b5cf6;margin-bottom:8px;}
.feature-list{font-size:14px;color:#888;line-height:1.8;}
.workflow{background:#111;border:1px solid #222;border-radius:16px;padding:30px;margin:30px 0;}
.workflow-title{font-size:18px;font-weight:700;margin-bottom:20px;text-align:center;}
.workflow-steps{display:flex;align-items:center;justify-content:center;gap:20px;flex-wrap:wrap;}
.workflow-step{text-align:center;padding:20px;}
.workflow-step-icon{font-size:32px;margin-bottom:8px;}
.workflow-step-text{font-size:13px;color:#888;}
.workflow-arrow{font-size:24px;color:#8b5cf6;}
.next-btn{display:inline-block;background:#8b5cf6;color:#fff;padding:15px 30px;border-radius:8px;text-decoration:none;font-weight:bold;margin-top:20px;}
</style></head><body><div class='container'>";

echo "<h1>👔 Instalador 09 - Painel RH para Aprovar Workers</h1>";

echo "<div class='feature-grid'>";
$features = [
    ['📋', 'Lista de Candidatos', 'Ver todos os cadastros pendentes, filtrar por tipo (Shopper/Delivery/Full), ordenar por data'],
    ['👤', 'Detalhes Completos', 'Visualizar todos os dados: pessoais, documentos enviados, selfie, verificação facial'],
    ['✅', 'Aprovar/Rejeitar', 'Aprovar candidato para trabalhar ou rejeitar com motivo específico'],
    ['📊', 'Dashboard', 'Estatísticas: pendentes, aprovados hoje, rejeitados, tempo médio de análise'],
];

foreach ($features as $f) {
    echo "<div class='feature-card'>";
    echo "<div class='feature-icon'>{$f[0]}</div>";
    echo "<div class='feature-title'>{$f[1]}</div>";
    echo "<div class='feature-list'>{$f[2]}</div>";
    echo "</div>";
}
echo "</div>";

echo "<div class='workflow'>";
echo "<div class='workflow-title'>🔄 Fluxo de Aprovação</div>";
echo "<div class='workflow-steps'>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>📝</div><div class='workflow-step-text'>Cadastro<br>enviado</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>⏳</div><div class='workflow-step-text'>Aguardando<br>análise</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>👔</div><div class='workflow-step-text'>RH<br>analisa</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>✅</div><div class='workflow-step-text'>Aprovado ou<br>Rejeitado</div></div>";
echo "<span class='workflow-arrow'>→</span>";
echo "<div class='workflow-step'><div class='workflow-step-icon'>📱</div><div class='workflow-step-text'>Worker<br>notificado</div></div>";
echo "</div></div>";

echo "<h2 style='margin-top:30px;'>📁 Arquivos:</h2>";
echo "<ul style='color:#888;line-height:2;'>";
echo "<li>rh/workers.php - Lista de candidatos</li>";
echo "<li>rh/worker-details.php - Detalhes do candidato</li>";
echo "<li>rh/api/approve.php - API aprovar</li>";
echo "<li>rh/api/reject.php - API rejeitar</li>";
echo "</ul>";

echo "<a href='10_instalar_finalizacao.php' class='next-btn'>Próximo: Finalização →</a>";
echo "</div></body></html>";
