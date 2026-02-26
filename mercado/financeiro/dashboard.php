<?php
session_start();
if(!isset($_SESSION["fin_user_id"])){header("Location: index.php");exit;}
$userName=$_SESSION["fin_user_name"];
$userRole=$_SESSION["fin_user_role"];
$perms=$_SESSION["fin_permissions"];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Financeiro OneMundo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root{--primary:#22c55e;--danger:#ef4444;--warning:#f59e0b;--info:#3b82f6;--purple:#8b5cf6;--bg:#0f172a;--card:#1e293b;--border:#334155;--text:#f1f5f9;--text2:#94a3b8}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
        .sidebar{position:fixed;left:0;top:0;width:250px;height:100vh;background:var(--card);border-right:1px solid var(--border);padding:20px 0;overflow-y:auto}
        .sidebar-logo{padding:0 20px 20px;border-bottom:1px solid var(--border);margin-bottom:20px;display:flex;align-items:center;gap:12px}
        .sidebar-logo .icon{width:45px;height:45px;background:linear-gradient(135deg,var(--primary),#16a34a);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff}
        .sidebar-logo h2{font-size:16px}
        .sidebar-logo span{font-size:12px;color:var(--text2)}
        .nav-section{padding:0 15px;margin-bottom:15px}
        .nav-title{font-size:11px;text-transform:uppercase;color:var(--text2);padding:10px;font-weight:600}
        .nav-item{display:flex;align-items:center;gap:12px;padding:12px 15px;color:var(--text2);text-decoration:none;border-radius:8px;margin-bottom:2px;transition:all 0.2s}
        .nav-item:hover,.nav-item.active{background:var(--border);color:var(--text)}
        .nav-item.active{background:linear-gradient(135deg,var(--primary),#16a34a);color:#fff}
        .nav-item i{width:20px;text-align:center}
        .main{margin-left:250px;padding:20px 30px}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px}
        .header h1{font-size:26px}
        .header-right{display:flex;align-items:center;gap:15px}
        .user-info{display:flex;align-items:center;gap:10px;background:var(--card);padding:8px 15px;border-radius:10px}
        .user-avatar{width:35px;height:35px;background:var(--primary);border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:bold}
        .btn-logout{background:var(--danger);color:#fff;border:none;padding:10px 15px;border-radius:8px;cursor:pointer;display:flex;align-items:center;gap:8px}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:30px}
        .stat-card{background:var(--card);border-radius:14px;padding:20px;border:1px solid var(--border)}
        .stat-card-header{display:flex;justify-content:space-between;margin-bottom:15px}
        .stat-icon{width:45px;height:45px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px}
        .stat-icon.green{background:rgba(34,197,94,0.15);color:var(--primary)}
        .stat-icon.red{background:rgba(239,68,68,0.15);color:var(--danger)}
        .stat-icon.blue{background:rgba(59,130,246,0.15);color:var(--info)}
        .stat-icon.yellow{background:rgba(245,158,11,0.15);color:var(--warning)}
        .stat-icon.purple{background:rgba(139,92,246,0.15);color:var(--purple)}
        .stat-value{font-size:26px;font-weight:700}
        .stat-label{color:var(--text2);font-size:13px;margin-top:5px}
        .section{background:var(--card);border-radius:14px;padding:20px;margin-bottom:20px;border:1px solid var(--border)}
        .section-title{font-size:16px;margin-bottom:20px;display:flex;align-items:center;gap:10px}
        .section-title i{color:var(--primary)}
        .grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
        .chart-container{height:250px}
        .table{width:100%;border-collapse:collapse}
        .table th,.table td{padding:12px;text-align:left;border-bottom:1px solid var(--border)}
        .table th{color:var(--text2);font-size:12px;text-transform:uppercase}
        .badge{padding:4px 10px;border-radius:6px;font-size:11px}
        .badge-success{background:rgba(34,197,94,0.15);color:var(--primary)}
        .badge-danger{background:rgba(239,68,68,0.15);color:var(--danger)}
        .badge-warning{background:rgba(245,158,11,0.15);color:var(--warning)}
        @media(max-width:1024px){.grid-2{grid-template-columns:1fr}}
        @media(max-width:768px){.sidebar{display:none}.main{margin-left:0}}
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-logo">
            <div class="icon"><i class="fas fa-dollar-sign"></i></div>
            <div><h2>Financeiro</h2><span>OneMundo</span></div>
        </div>
        <nav class="nav-section">
            <div class="nav-title">Principal</div>
            <a href="dashboard.php" class="nav-item active"><i class="fas fa-home"></i> Dashboard</a>
        </nav>
        <nav class="nav-section">
            <div class="nav-title">Operações</div>
            <a href="contas-pagar.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Contas a Pagar</a>
            <a href="contas-receber.php" class="nav-item"><i class="fas fa-hand-holding-usd"></i> Contas a Receber</a>
            <a href="tesouraria.php" class="nav-item"><i class="fas fa-university"></i> Tesouraria</a>
        </nav>
        <nav class="nav-section">
            <div class="nav-title">Cadastros</div>
            <a href="fornecedores.php" class="nav-item"><i class="fas fa-truck"></i> Fornecedores</a>
            <a href="centros-custo.php" class="nav-item"><i class="fas fa-sitemap"></i> Centros de Custo</a>
        </nav>
        <nav class="nav-section">
            <div class="nav-title">Relatórios</div>
            <a href="relatorios.php" class="nav-item"><i class="fas fa-chart-bar"></i> Relatórios</a>
        </nav>
    </aside>
    
    <main class="main">
        <header class="header">
            <div>
                <h1>Dashboard</h1>
                <p style="color:var(--text2);margin-top:5px" id="dateNow"></p>
            </div>
            <div class="header-right">
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($userName,0,1)); ?></div>
                    <div>
                        <div style="font-weight:600;font-size:14px"><?php echo htmlspecialchars($userName); ?></div>
                        <div style="font-size:11px;color:var(--text2)"><?php echo ucfirst($userRole); ?></div>
                    </div>
                </div>
                <button class="btn-logout" onclick="logout()"><i class="fas fa-sign-out-alt"></i></button>
            </div>
        </header>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-card-header"><div class="stat-icon green"><i class="fas fa-wallet"></i></div></div>
                <div class="stat-value" id="saldoBancario">R$ 0,00</div>
                <div class="stat-label">Saldo Bancário</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header"><div class="stat-icon blue"><i class="fas fa-arrow-up"></i></div></div>
                <div class="stat-value" id="receitasMes">R$ 0,00</div>
                <div class="stat-label">Receitas do Mês</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header"><div class="stat-icon red"><i class="fas fa-arrow-down"></i></div></div>
                <div class="stat-value" id="despesasMes">R$ 0,00</div>
                <div class="stat-label">Despesas do Mês</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header"><div class="stat-icon purple"><i class="fas fa-chart-line"></i></div></div>
                <div class="stat-value" id="resultadoMes">R$ 0,00</div>
                <div class="stat-label">Resultado do Mês</div>
            </div>
        </div>
        
        <div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
            <div class="stat-card" style="cursor:pointer">
                <div class="stat-card-header"><div class="stat-icon yellow"><i class="fas fa-calendar-day"></i></div></div>
                <div class="stat-value" style="font-size:20px" id="pagarHoje">R$ 0</div>
                <div class="stat-label">A Pagar Hoje</div>
            </div>
            <div class="stat-card" style="cursor:pointer">
                <div class="stat-card-header"><div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div></div>
                <div class="stat-value" style="font-size:20px" id="pagarAtrasado">R$ 0</div>
                <div class="stat-label">Pagtos Atrasados</div>
            </div>
            <div class="stat-card" style="cursor:pointer">
                <div class="stat-card-header"><div class="stat-icon purple"><i class="fas fa-clock"></i></div></div>
                <div class="stat-value" style="font-size:20px" id="aguardando">0</div>
                <div class="stat-label">Aguardando Aprovação</div>
            </div>
            <div class="stat-card" style="cursor:pointer">
                <div class="stat-card-header"><div class="stat-icon yellow"><i class="fas fa-hand-holding-usd"></i></div></div>
                <div class="stat-value" style="font-size:20px" id="receberAtrasado">R$ 0</div>
                <div class="stat-label">Recebtos Atrasados</div>
            </div>
        </div>
        
        <div class="grid-2">
            <div class="section">
                <h3 class="section-title"><i class="fas fa-chart-line"></i> Fluxo de Caixa (15 dias)</h3>
                <div class="chart-container"><canvas id="chartFluxo"></canvas></div>
            </div>
            <div class="section">
                <h3 class="section-title"><i class="fas fa-chart-pie"></i> Despesas por Categoria</h3>
                <div class="chart-container"><canvas id="chartCategorias"></canvas></div>
            </div>
        </div>
        
        <div class="section">
            <h3 class="section-title"><i class="fas fa-chart-bar"></i> Comparativo Mensal</h3>
            <div class="chart-container" style="height:280px"><canvas id="chartComparativo"></canvas></div>
        </div>
    </main>
    
    <script>
        function formatMoney(v){return new Intl.NumberFormat("pt-BR",{style:"currency",currency:"BRL"}).format(v||0)}
        
        document.getElementById("dateNow").textContent = new Date().toLocaleDateString("pt-BR",{weekday:"long",day:"numeric",month:"long",year:"numeric"});
        
        let chartFluxo, chartCategorias, chartComparativo;
        
        async function loadDashboard(){
            const r = await fetch("api/dashboard.php?action=getSummary");
            const d = await r.json();
            if(d.success){
                document.getElementById("saldoBancario").textContent = formatMoney(d.data.saldo_bancario);
                document.getElementById("receitasMes").textContent = formatMoney(d.data.receitas_mes);
                document.getElementById("despesasMes").textContent = formatMoney(d.data.despesas_mes);
                document.getElementById("resultadoMes").textContent = formatMoney(d.data.resultado_mes);
                document.getElementById("resultadoMes").style.color = d.data.resultado_mes >= 0 ? "var(--primary)" : "var(--danger)";
                document.getElementById("pagarHoje").textContent = formatMoney(d.data.pagar_hoje);
                document.getElementById("pagarAtrasado").textContent = formatMoney(d.data.pagar_atrasado);
                document.getElementById("aguardando").textContent = d.data.aguardando_aprovacao;
                document.getElementById("receberAtrasado").textContent = formatMoney(d.data.receber_atrasado);
            }
        }
        
        async function loadCashFlow(){
            const r = await fetch("api/dashboard.php?action=getCashFlow&days=15");
            const d = await r.json();
            if(d.success && chartFluxo){
                chartFluxo.data.labels = d.cash_flow.map(x => {const dt=new Date(x.date+"T00:00:00");return dt.toLocaleDateString("pt-BR",{day:"2-digit",month:"2-digit"})});
                chartFluxo.data.datasets[0].data = d.cash_flow.map(x => x.to_receive);
                chartFluxo.data.datasets[1].data = d.cash_flow.map(x => x.to_pay);
                chartFluxo.update();
            }
        }
        
        async function loadExpensesByCategory(){
            const r = await fetch("api/dashboard.php?action=getExpensesByCategory");
            const d = await r.json();
            if(d.success && chartCategorias){
                chartCategorias.data.labels = d.expenses.map(x => x.category);
                chartCategorias.data.datasets[0].data = d.expenses.map(x => x.total);
                chartCategorias.update();
            }
        }
        
        async function loadMonthlyComparison(){
            const r = await fetch("api/dashboard.php?action=getMonthlyComparison&months=6");
            const d = await r.json();
            if(d.success && chartComparativo){
                chartComparativo.data.labels = d.comparison.map(x => {const[y,m]=x.month.split("-");const ms=["Jan","Fev","Mar","Abr","Mai","Jun","Jul","Ago","Set","Out","Nov","Dez"];return ms[parseInt(m)-1]+"/"+y.slice(2)});
                chartComparativo.data.datasets[0].data = d.comparison.map(x => x.income);
                chartComparativo.data.datasets[1].data = d.comparison.map(x => x.expense);
                chartComparativo.update();
            }
        }
        
        function initCharts(){
            const cfg = {responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:"#94a3b8"}}},scales:{x:{ticks:{color:"#94a3b8"},grid:{color:"#334155"}},y:{ticks:{color:"#94a3b8"},grid:{color:"#334155"}}}};
            
            chartFluxo = new Chart(document.getElementById("chartFluxo"),{type:"line",data:{labels:[],datasets:[{label:"A Receber",data:[],borderColor:"#22c55e",backgroundColor:"rgba(34,197,94,0.1)",fill:true,tension:0.4},{label:"A Pagar",data:[],borderColor:"#ef4444",backgroundColor:"rgba(239,68,68,0.1)",fill:true,tension:0.4}]},options:cfg});
            
            chartCategorias = new Chart(document.getElementById("chartCategorias"),{type:"doughnut",data:{labels:[],datasets:[{data:[],backgroundColor:["#22c55e","#3b82f6","#f59e0b","#ef4444","#8b5cf6","#ec4899","#06b6d4","#84cc16"]}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:"right",labels:{color:"#94a3b8"}}}}});
            
            chartComparativo = new Chart(document.getElementById("chartComparativo"),{type:"bar",data:{labels:[],datasets:[{label:"Receitas",data:[],backgroundColor:"#22c55e"},{label:"Despesas",data:[],backgroundColor:"#ef4444"}]},options:cfg});
        }
        
        async function logout(){await fetch("api/auth.php?action=logout");location.href="index.php";}
        
        document.addEventListener("DOMContentLoaded",function(){
            initCharts();
            loadDashboard();
            loadCashFlow();
            loadExpensesByCategory();
            loadMonthlyComparison();
            setInterval(loadDashboard,60000);
        });
    </script>
</body>
</html>